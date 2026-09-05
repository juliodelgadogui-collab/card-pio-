<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PaymentService
{
    private const PROVIDERS=['stripe','pagbank','mercadopago','manual'];

    public function create(int $orderId,string $provider,string $idempotencyKey,?int $amountCents=null):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();$provider=strtolower(trim($provider));
        if(!$tenantId||!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Empresa ou provedor inválido.');
        if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$provider,$idempotencyKey,$amountCents):array{
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$stmt->execute([$orderId,$tenantId]);$order=$stmt->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(in_array($order['status'],['cancelled','completed'],true))throw new RuntimeException('Pedido cancelado ou finalizado não pode receber nova cobrança.');
            $existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotencyKey]);if($payment=$existing->fetch())return $payment;
            $paid=$this->paidAmount($pdo,$tenantId,$orderId);$remaining=max(0,(int)$order['total_cents']-$paid);
            if($remaining<=0||$order['payment_status']==='paid')throw new RuntimeException('Pedido já está integralmente pago.');
            $amount=$amountCents??$remaining;if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor da parcela inválido. Saldo restante: R$ '.number_format($remaining/100,2,',','.').'.');
            if($provider!=='manual'){$gw=$pdo->prepare('SELECT id FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1');$gw->execute([$tenantId,$provider]);if(!$gw->fetchColumn())throw new RuntimeException('Gateway não está ativo para esta empresa.');}
            $open=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE'));$open->execute([$tenantId,$orderId]);if($payment=$open->fetch())return $payment;
            $stmt=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")');$stmt->execute([$tenantId,$orderId,$provider,$idempotencyKey,$amount]);$id=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND payment_status<>"paid"')->execute([$orderId]);
            Auth::audit('payment.created','payment',(string)$id,['order_id'=>$orderId,'provider'=>$provider,'amount_cents'=>$amount,'remaining_before_cents'=>$remaining]);
            return ['id'=>$id,'order_id'=>$orderId,'amount_cents'=>$amount,'remaining_before_cents'=>$remaining,'status'=>'created'];
        });
    }

    public function remaining(int $orderId,?int $tenantId=null):array
    {
        $tenantId??=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();
        $o=$pdo->prepare('SELECT id,total_cents,payment_status FROM orders WHERE id=? AND tenant_id=?');$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $paid=$this->paidAmount($pdo,$tenantId,$orderId);$remaining=max(0,(int)$order['total_cents']-$paid);
        return ['order_id'=>$orderId,'total_cents'=>(int)$order['total_cents'],'paid_cents'=>$paid,'remaining_cents'=>$remaining,'payment_status'=>(string)$order['payment_status']];
    }

    public function confirmVerified(array $verified):void
    {
        foreach(['tenant_id','order_id','provider','provider_payment_id','amount_cents','currency','account_reference'] as $key)if(!array_key_exists($key,$verified))throw new RuntimeException("Campo ausente: {$key}");
        $verified['provider']=strtolower((string)$verified['provider']);

        Database::transaction(function(PDO $pdo)use($verified):void{
            $tenantId=(int)$verified['tenant_id'];$orderId=(int)$verified['order_id'];$provider=(string)$verified['provider'];
            if(!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Provedor inválido.');
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$stmt->execute([$orderId,$tenantId]);$order=$stmt->fetch();if(!$order)throw new RuntimeException('Pedido inválido.');
            if(in_array($order['status'],['cancelled','completed'],true))throw new RuntimeException('Pedido cancelado ou finalizado não pode ser confirmado.');
            if(strtoupper((string)$verified['currency'])!=='BRL')throw new RuntimeException('Moeda divergente.');

            if($provider!=='manual'){
                $gw=$pdo->prepare(Database::portableSql($pdo,'SELECT account_reference FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 FOR UPDATE'));$gw->execute([$tenantId,$provider]);$account=$gw->fetchColumn();
                if($account===false)throw new RuntimeException('Gateway local não está ativo.');if((string)$account!==(string)$verified['account_reference'])throw new RuntimeException('Conta do recebedor divergente.');
            }

            $payment=$this->findLocalPayment($pdo,$tenantId,$orderId,$provider,$verified);if(!$payment)throw new RuntimeException('Cobrança local não encontrada.');
            if(in_array($payment['status'],['paid','duplicate_paid'],true))return;
            if((int)$payment['amount_cents']!==(int)$verified['amount_cents']||strtoupper((string)$payment['currency'])!=='BRL')throw new RuntimeException('Valor da parcela divergente.');
            $dupe=$pdo->prepare('SELECT id FROM payments WHERE provider=? AND provider_payment_id=? AND id<>? LIMIT 1');$dupe->execute([$provider,(string)$verified['provider_payment_id'],$payment['id']]);if($dupe->fetchColumn())throw new RuntimeException('Transação do provedor já vinculada a outra cobrança.');

            $paidBefore=$this->paidAmount($pdo,$tenantId,$orderId,(int)$payment['id']);$paidAfter=$paidBefore+(int)$payment['amount_cents'];$total=(int)$order['total_cents'];
            if($paidAfter>$total||$order['payment_status']==='paid'){
                $payload=$verified;$payload['duplicate_reason']='order_already_settled_or_overpaid';$payload['paid_before_cents']=$paidBefore;$payload['order_total_cents']=$total;
                $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="duplicate_paid",verified_at=CURRENT_TIMESTAMP,raw_payload=? WHERE id=?')->execute([(string)$verified['provider_payment_id'],json_encode($payload,JSON_UNESCAPED_UNICODE),$payment['id']]);
                Auth::audit('payment.duplicate_paid','payment',(string)$payment['id'],['order_id'=>$orderId,'provider'=>$provider,'paid_before_cents'=>$paidBefore,'attempted_cents'=>(int)$payment['amount_cents']]);return;
            }

            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="paid",verified_at=CURRENT_TIMESTAMP,raw_payload=? WHERE id=?')->execute([(string)$verified['provider_payment_id'],json_encode($verified,JSON_UNESCAPED_UNICODE),$payment['id']]);
            if($paidAfter<$total){$pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND tenant_id=?')->execute([$orderId,$tenantId]);Auth::audit('payment.partial_confirmed','payment',(string)$payment['id'],['order_id'=>$orderId,'paid_cents'=>$paidAfter,'remaining_cents'=>$total-$paidAfter]);return;}
            $pdo->prepare('UPDATE orders SET payment_status="paid",status=CASE WHEN status="pending" THEN "confirmed" ELSE status END WHERE id=?')->execute([$orderId]);
            $this->settleOrderEffects($pdo,$tenantId,$order,$orderId);Auth::audit('payment.order_settled','order',(string)$orderId,['final_payment_id'=>(int)$payment['id'],'total_cents'=>$total]);
        });

        $this->publishVerifiedNotification($verified);
    }

    private function publishVerifiedNotification(array $verified):void
    {
        $provider=(string)$verified['provider'];if($provider==='manual')return;$tenantId=(int)$verified['tenant_id'];$orderId=(int)$verified['order_id'];$providerId=(string)$verified['provider_payment_id'];
        try{
            $s=Database::connection()->prepare('SELECT id,amount_cents,status FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND provider_payment_id=? ORDER BY id DESC LIMIT 1');$s->execute([$tenantId,$orderId,$provider,$providerId]);$payment=$s->fetch();if(!$payment)return;
            $notifications=new NotificationService();$amount='R$ '.number_format(((int)$payment['amount_cents'])/100,2,',','.');$source=strtolower((string)($verified['source']??$verified['payment_method_type']??''));
            if($payment['status']==='duplicate_paid'){
                $notifications->publishToPermissionForTenant($tenantId,'refunds.manage',null,'payment.duplicate','Cobrança duplicada detectada','Uma segunda cobrança de '.$amount.' foi confirmada no pedido #'.$orderId.'. Revise o estorno da transação duplicada.','payment',(string)$payment['id'],'payment:'.$payment['id'].':duplicate','warning',gmdate('Y-m-d H:i:s',time()+604800));return;
            }
            if($payment['status']!=='paid')return;
            $title=str_contains($source,'pix')?'PIX recebido':(str_contains($source,'nfc')||str_contains($source,'card')?'Cartão aprovado':'Pagamento confirmado');
            $notifications->publishToPermissionForTenant($tenantId,'payments.manage',null,'payment.received',$title,$amount.' confirmado no pedido #'.$orderId.'.','payment',(string)$payment['id'],'payment:'.$payment['id'].':received','success',gmdate('Y-m-d H:i:s',time()+172800));
        }catch(\Throwable){}
    }

    private function findLocalPayment(PDO $pdo,int $tenantId,int $orderId,string $provider,array $verified):array|false
    {
        if(!empty($verified['payment_id'])){$s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM payments WHERE id=? AND tenant_id=? AND order_id=? AND provider=? FOR UPDATE'));$s->execute([(int)$verified['payment_id'],$tenantId,$orderId,$provider]);if($p=$s->fetch())return $p;}
        $providerId=(string)($verified['provider_payment_id']??'');if($providerId!==''){$s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND provider_payment_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$orderId,$provider,$providerId]);if($p=$s->fetch())return $p;}
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND status IN ("created","pending","authorized","failed","cancelled") ORDER BY id DESC LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$orderId,$provider]);return $s->fetch();
    }

    private function paidAmount(PDO $pdo,int $tenantId,int $orderId,?int $excludePaymentId=null):int
    {
        $sql='SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"';$args=[$tenantId,$orderId];if($excludePaymentId){$sql.=' AND id<>?';$args[]=$excludePaymentId;}$s=$pdo->prepare($sql);$s->execute($args);return (int)$s->fetchColumn();
    }

    private function settleOrderEffects(PDO $pdo,int $tenantId,array $order,int $orderId):void
    {
        $settlement='settlement:order:'.$orderId;(new StockReservationService())->consumeForSettlement($pdo,$tenantId,$orderId,$settlement);
        $tickets=$pdo->prepare(Database::portableSql($pdo,'SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND order_id=? AND status="reserved" GROUP BY batch_id FOR UPDATE'));$tickets->execute([$tenantId,$orderId]);
        foreach($tickets->fetchAll() as $row){$qty=(int)$row['qty'];$pdo->prepare(Database::portableSql($pdo,'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?),quantity_sold=quantity_sold+? WHERE id=?'))->execute([$qty,$qty,$row['batch_id']]);}
        $pdo->prepare('UPDATE tickets SET status="paid",reserved_until=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);
        if(!empty($order['coupon_id'])){
            $r=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM coupon_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE'));$r->execute([$tenantId,$orderId]);$reservation=$r->fetch();$insertSql=Database::portableSql($pdo,'INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)');
            if($reservation){$pdo->prepare('UPDATE coupon_reservations SET status="redeemed" WHERE id=?')->execute([$reservation['id']]);$pdo->prepare(Database::portableSql($pdo,'UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1),uses_count=uses_count+1 WHERE id=? AND tenant_id=?'))->execute([$order['coupon_id'],$tenantId]);$pdo->prepare($insertSql)->execute([$tenantId,$order['coupon_id'],$orderId,$order['customer_id']?:null,$order['discount_cents'],$settlement.':coupon']);}
            else{$red=$pdo->prepare($insertSql);$red->execute([$tenantId,$order['coupon_id'],$orderId,$order['customer_id']?:null,$order['discount_cents'],$settlement.':coupon']);if($red->rowCount()===1)$pdo->prepare('UPDATE coupons SET uses_count=uses_count+1 WHERE id=? AND tenant_id=?')->execute([$order['coupon_id'],$tenantId]);}
        }
        if(!empty($order['customer_id'])){$points=intdiv((int)$order['total_cents'],100);if($points>0){$ins=$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?,?,"earn",?)'));$ins->execute([$tenantId,$order['customer_id'],$orderId,$points,$settlement.':points']);if($ins->rowCount()===1)$pdo->prepare('UPDATE customers SET points=points+? WHERE id=? AND tenant_id=?')->execute([$points,$order['customer_id'],$tenantId]);}}
        if(!empty($order['promoter_id'])){$p=$pdo->prepare('SELECT commission_percent FROM promoters WHERE id=? AND tenant_id=? AND active=1');$p->execute([$order['promoter_id'],$tenantId]);$percent=$p->fetchColumn();if($percent!==false){$commission=(int)round((int)$order['total_cents']*((float)$percent/100));$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO promoter_commissions (tenant_id,promoter_id,order_id,amount_cents,status,idempotency_key) VALUES (?,?,?,?,"approved",?)'))->execute([$tenantId,$order['promoter_id'],$orderId,$commission,$settlement.':commission']);}}
    }
}
