<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryCashService
{
    public function collect(int $orderId,int $receivedCents):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado.');
        $shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='delivery')throw new RuntimeException('Inicie seu turno de Delivery antes de receber dinheiro.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$receivedCents,$shift):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($shift['unit_id']!==null&&($order['unit_id']===null||(int)$order['unit_id']!==(int)$shift['unit_id']))throw new RuntimeException('Este pedido pertence a outra unidade.');
            if($order['channel']!=='delivery'||(int)($order['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('Pedido não está atribuído a este entregador.');
            if($order['status']!=='out_for_delivery')throw new RuntimeException('O pedido precisa estar em rota para receber dinheiro.');
            if($order['payment_status']==='paid')return ['order_id'=>$orderId,'paid'=>true,'total_cents'=>(int)$order['total_cents'],'received_cents'=>$receivedCents,'change_cents'=>max(0,$receivedCents-(int)$order['total_cents'])];
            if(!in_array($order['payment_status'],['unpaid','failed'],true))throw new RuntimeException('Há uma cobrança eletrônica em andamento. Aguarde ou cancele antes de receber dinheiro.');
            $total=(int)$order['total_cents'];if($receivedCents<$total)throw new RuntimeException('Valor recebido é menor que o total do pedido.');$change=$receivedCents-$total;
            $active=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") LIMIT 1');$active->execute([$tenantId,$orderId]);if($active->fetchColumn())throw new RuntimeException('Existe uma cobrança em andamento para este pedido.');
            $key='delivery-cash:'.$tenantId.':'.$orderId;$p=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$p->execute([$tenantId,$key]);$payment=$p->fetch();
            if(!$payment){$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,"manual",?,?,"BRL","created")')->execute([$tenantId,$orderId,$key,$total]);$paymentId=(int)$pdo->lastInsertId();}
            else{$paymentId=(int)$payment['id'];if($payment['status']==='paid')return ['order_id'=>$orderId,'payment_id'=>$paymentId,'paid'=>true,'total_cents'=>$total,'received_cents'=>$receivedCents,'change_cents'=>$change];}
            (new PaymentService())->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'manual','provider_payment_id'=>'DELIVERY-CASH-'.$orderId,'amount_cents'=>$total,'currency'=>'BRL','account_reference'=>'manual','source'=>'delivery_cash']);
            (new WorkShiftService())->recordMovement($pdo,$tenantId,$userId,$orderId,$paymentId,'delivery_collection','cash','in',$total,'delivery-cash:payment:'.$paymentId,'Cliente entregou '.number_format($receivedCents/100,2,',','.').'; troco '.number_format($change/100,2,',','.'));
            Auth::audit('delivery.cash_received','order',(string)$orderId,['payment_id'=>$paymentId,'amount_cents'=>$total,'received_cents'=>$receivedCents,'change_cents'=>$change,'shift_id'=>(int)$shift['id'],'unit_id'=>$shift['unit_id']??null]);
            return ['order_id'=>$orderId,'payment_id'=>$paymentId,'paid'=>true,'total_cents'=>$total,'received_cents'=>$receivedCents,'change_cents'=>$change];
        });
    }

    public function outstanding(?int $shiftId=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$pdo=Database::connection();
        if($shiftId){$s=$pdo->prepare('SELECT * FROM work_shifts WHERE id=? AND tenant_id=?');$s->execute([$shiftId,$tenantId]);}else{$s=$pdo->prepare('SELECT * FROM work_shifts WHERE tenant_id=? AND user_id=? AND mode="delivery" ORDER BY id DESC LIMIT 1');$s->execute([$tenantId,$userId]);}$shift=$s->fetch();if(!$shift)throw new RuntimeException('Turno de entrega não encontrado.');
        if((int)$shift['user_id']!==$userId&&!in_array(Auth::role(),['admin','manager','super_admin'],true))throw new RuntimeException('Acesso negado.');
        $c=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN method="cash" AND direction="in" THEN amount_cents WHEN method="cash" AND direction="out" THEN -amount_cents ELSE 0 END),0) FROM work_shift_movements WHERE tenant_id=? AND work_shift_id=?');$c->execute([$tenantId,$shift['id']]);$cash=(int)$c->fetchColumn();
        $h=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM delivery_cash_handoffs WHERE tenant_id=? AND work_shift_id=? AND status="confirmed"');$h->execute([$tenantId,$shift['id']]);$confirmed=(int)$h->fetchColumn();
        return ['shift_id'=>(int)$shift['id'],'unit_id'=>$shift['unit_id']!==null?(int)$shift['unit_id']:null,'cash_collected_cents'=>$cash,'confirmed_handoff_cents'=>$confirmed,'outstanding_cents'=>max(0,$cash-$confirmed)];
    }

    public function createHandoff():array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();$shift=(new WorkShiftService())->current();if(!$tenantId||!$userId||!$shift||$shift['mode']!=='delivery')throw new RuntimeException('Turno de Delivery não está aberto.');
        $out=$this->outstanding((int)$shift['id']);if($out['outstanding_cents']<=0)throw new RuntimeException('Não há dinheiro pendente para entregar ao caixa.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$out):array{
            $pdo->prepare('UPDATE delivery_cash_handoffs SET status="cancelled" WHERE tenant_id=? AND work_shift_id=? AND status="pending"')->execute([$tenantId,$shift['id']]);
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$pdo->prepare('INSERT INTO delivery_cash_handoffs (tenant_id,work_shift_id,delivery_user_id,amount_cents,status,token_hash) VALUES (?,?,?,?,"pending",?)')->execute([$tenantId,$shift['id'],$userId,$out['outstanding_cents'],$hash]);$id=(int)$pdo->lastInsertId();Auth::audit('delivery.handoff_requested','delivery_cash_handoff',(string)$id,['amount_cents'=>$out['outstanding_cents'],'shift_id'=>(int)$shift['id'],'unit_id'=>$shift['unit_id']??null]);
            return ['id'=>$id,'token'=>$token,'qr_payload'=>\app_absolute_url('api-go.php?action=handoff-view&t='.rawurlencode($token)),'amount_cents'=>$out['outstanding_cents'],'status'=>'pending','unit_id'=>$shift['unit_id']!==null?(int)$shift['unit_id']:null,'unit_name'=>(string)($shift['unit_name']??'')];
        });
    }

    public function resolveHandoff(string $token):array
    {
        Auth::requirePermission('cash.manage');$tenantId=Auth::tenantId();$cashierId=Auth::id();if(!$tenantId||!$cashierId)throw new RuntimeException('Sessão inválida.');$hash=hash('sha256',trim($token));$pdo=Database::connection();
        $s=$pdo->prepare('SELECT h.*,u.name delivery_name,ws.unit_id,ou.name unit_name FROM delivery_cash_handoffs h JOIN users u ON u.id=h.delivery_user_id JOIN work_shifts ws ON ws.id=h.work_shift_id LEFT JOIN operating_units ou ON ou.id=ws.unit_id WHERE h.tenant_id=? AND h.token_hash=? LIMIT 1');$s->execute([$tenantId,$hash]);$row=$s->fetch();if(!$row)throw new RuntimeException('Repasse não encontrado.');
        $cash=$pdo->prepare('SELECT unit_id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$cash->execute([$tenantId,$cashierId]);$cashUnit=$cash->fetchColumn();if($cashUnit===false)throw new RuntimeException('Abra o caixa antes de receber o repasse.');
        if($row['unit_id']!==null&&($cashUnit===null||(int)$cashUnit!==(int)$row['unit_id']))throw new RuntimeException('Este repasse pertence a outra unidade.');
        return $row;
    }

    public function confirmHandoff(string $token):array
    {
        Auth::requirePermission('cash.manage');$tenantId=Auth::tenantId();$cashierId=Auth::id();if(!$tenantId||!$cashierId)throw new RuntimeException('Sessão inválida.');$hash=hash('sha256',trim($token));
        return Database::transaction(function(PDO $pdo)use($tenantId,$cashierId,$hash):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT h.*,u.name delivery_name,ws.unit_id,ou.name unit_name FROM delivery_cash_handoffs h JOIN users u ON u.id=h.delivery_user_id JOIN work_shifts ws ON ws.id=h.work_shift_id LEFT JOIN operating_units ou ON ou.id=ws.unit_id WHERE h.tenant_id=? AND h.token_hash=? FOR UPDATE'));$s->execute([$tenantId,$hash]);$h=$s->fetch();if(!$h)throw new RuntimeException('Repasse não encontrado.');if($h['status']==='confirmed')return $h;if($h['status']!=='pending')throw new RuntimeException('Repasse não está pendente.');
            $cs=$pdo->prepare(Database::portableSql($pdo,'SELECT id,unit_id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$cs->execute([$tenantId,$cashierId]);$cashSession=$cs->fetch();if(!$cashSession)throw new RuntimeException('O caixa precisa estar aberto para confirmar o recebimento.');
            if($h['unit_id']!==null&&($cashSession['unit_id']===null||(int)$cashSession['unit_id']!==(int)$h['unit_id']))throw new RuntimeException('O caixa e o entregador estão em unidades diferentes.');
            $pdo->prepare('UPDATE delivery_cash_handoffs SET status="confirmed",confirmed_by=?,confirmed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$cashierId,$h['id']]);
            $wm=Database::portableSql($pdo,'INSERT IGNORE INTO work_shift_movements (tenant_id,work_shift_id,user_id,type,method,direction,amount_cents,notes,idempotency_key) VALUES (?,?,?,"cash_handoff","cash","out",?,?,?)');$pdo->prepare($wm)->execute([$tenantId,$h['work_shift_id'],$h['delivery_user_id'],$h['amount_cents'],'Repasse confirmado pelo caixa #'.$cashSession['id'],'handoff:'.$h['id'].':delivery']);
            $cm=Database::portableSql($pdo,'INSERT IGNORE INTO cash_movements (tenant_id,cash_session_id,user_id,type,method,direction,amount_cents,notes,idempotency_key) VALUES (?,?,?,"delivery_handoff","cash","in",?,?,?)');$pdo->prepare($cm)->execute([$tenantId,$cashSession['id'],$cashierId,$h['amount_cents'],'Recebido de '.$h['delivery_name'],'handoff:'.$h['id'].':cash']);
            Auth::audit('delivery.handoff_confirmed','delivery_cash_handoff',(string)$h['id'],['amount_cents'=>(int)$h['amount_cents'],'delivery_user_id'=>(int)$h['delivery_user_id'],'cash_session_id'=>(int)$cashSession['id'],'unit_id'=>$h['unit_id']??null]);$h['status']='confirmed';$h['confirmed_by']=$cashierId;return $h;
        });
    }
}
