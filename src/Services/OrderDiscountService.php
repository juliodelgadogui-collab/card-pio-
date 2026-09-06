<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderDiscountService
{
    public function request(int $orderId,int $requestedCents,string $reason):array
    {
        Auth::requirePermission('discounts.request');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $reason=mb_substr(trim($reason),0,500);if($orderId<1||$requestedCents<=0||$reason==='')throw new RuntimeException('Informe pedido, valor e motivo do desconto.');
        $shift=(new WorkShiftService())->current();if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('Desconto só pode ser solicitado durante turno de Operação ou Pay.');
        $request=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId,$requestedCents,$reason):array{
            $order=$this->lockOrder($pdo,$tenantId,$orderId);$this->assertUnit($order,$shift);$this->assertDiscountable($pdo,$order);
            $base=(int)$order['discount_cents'];$max=max(0,(int)$order['subtotal_cents']-$base);if($requestedCents>$max)throw new RuntimeException('Desconto solicitado é maior que o valor disponível dos itens.');
            $pdo->prepare('UPDATE order_discount_requests SET status="cancelled",decided_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND status="pending"')->execute([$tenantId,$orderId]);
            $pdo->prepare('INSERT INTO order_discount_requests (tenant_id,order_id,requested_by,base_discount_cents,requested_cents,reason,status) VALUES (?,?,?,?,?, ?,"pending")')->execute([$tenantId,$orderId,$userId,$base,$requestedCents,$reason]);$id=(int)$pdo->lastInsertId();
            $q=$pdo->prepare('SELECT r.*,u.name requester_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=?');$q->execute([$id]);return $q->fetch()?:throw new RuntimeException('Falha ao registrar solicitação.');
        });
        Auth::audit('order.discount_requested','order',(string)$orderId,['request_id'=>(int)$request['id'],'requested_cents'=>$requestedCents,'reason'=>$reason,'unit_id'=>$shift['unit_id']??null]);
        try{(new NotificationService())->publishToPermission('discounts.approve',null,'discount.requested','Desconto solicitado · Pedido #'.$orderId,($request['requester_name']?:'Funcionário').' solicitou R$ '.number_format($requestedCents/100,2,',','.').' de desconto.','discount_request',(string)$request['id'],'discount:'.$request['id'].':approve','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $request;
    }

    public function approve(int $requestId):array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();$approverId=Auth::id();if(!$tenantId||!$approverId)throw new RuntimeException('Sessão inválida.');$shift=(new WorkShiftService())->current();if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('Aprovação exige turno de Operação ou Pay.');
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$approverId,$shift,$requestId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,u.name requester_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação de desconto não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');
            $order=$this->lockOrder($pdo,$tenantId,(int)$request['order_id']);$this->assertUnit($order,$shift);$this->assertDiscountable($pdo,$order);
            if((int)$order['discount_cents']!==(int)$request['base_discount_cents'])throw new RuntimeException('O desconto-base do pedido mudou. Solicite o desconto novamente.');
            $finalDiscount=(int)$request['base_discount_cents']+(int)$request['requested_cents'];if($finalDiscount>(int)$order['subtotal_cents'])throw new RuntimeException('Desconto excede o subtotal atual do pedido.');$newTotal=max(0,(int)$order['subtotal_cents']-$finalDiscount+(int)$order['delivery_fee_cents']);
            $pdo->prepare('UPDATE orders SET discount_cents=?,total_cents=? WHERE id=? AND tenant_id=?')->execute([$finalDiscount,$newTotal,$order['id'],$tenantId]);
            $pdo->prepare('UPDATE order_discount_requests SET status="approved",approved_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=? AND status="pending"')->execute([$approverId,$requestId]);
            $pdo->prepare('UPDATE order_discount_requests SET status="cancelled",decided_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND id<>? AND status="pending"')->execute([$tenantId,$order['id'],$requestId]);
            (new OrderHistoryService())->record($pdo,$tenantId,(int)$order['id'],(string)$order['status'],(string)$order['status'],'discount_approved','Desconto autorizado: R$ '.number_format((int)$request['requested_cents']/100,2,',','.').'. Motivo: '.$request['reason'],$approverId);
            return ['request_id'=>$requestId,'order_id'=>(int)$order['id'],'requested_by'=>(int)$request['requested_by'],'requester_name'=>(string)$request['requester_name'],'discount_cents'=>$finalDiscount,'total_cents'=>$newTotal,'approved_cents'=>(int)$request['requested_cents'],'status'=>'approved'];
        });
        Auth::audit('order.discount_approved','order',(string)$result['order_id'],['request_id'=>$requestId,'approved_cents'=>$result['approved_cents'],'total_cents'=>$result['total_cents'],'unit_id'=>$shift['unit_id']??null]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],null,'discount.approved','Desconto aprovado · Pedido #'.$result['order_id'],'Desconto de R$ '.number_format($result['approved_cents']/100,2,',','.').' aprovado. Novo total: R$ '.number_format($result['total_cents']/100,2,',','.').'.','order',(string)$result['order_id'],'discount:'.$requestId.':approved','success',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function reject(int $requestId,string $reason=''):array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();$approverId=Auth::id();if(!$tenantId||!$approverId)throw new RuntimeException('Sessão inválida.');$reason=mb_substr(trim($reason),0,500);
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$approverId,$requestId,$reason):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,u.name requester_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');
            $pdo->prepare('UPDATE order_discount_requests SET status="rejected",approved_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$approverId,$requestId]);
            return ['request_id'=>$requestId,'order_id'=>(int)$request['order_id'],'requested_by'=>(int)$request['requested_by'],'requested_cents'=>(int)$request['requested_cents'],'status'=>'rejected','reason'=>$reason];
        });
        Auth::audit('order.discount_rejected','order',(string)$result['order_id'],['request_id'=>$requestId,'reason'=>$reason]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],null,'discount.rejected','Desconto não aprovado · Pedido #'.$result['order_id'],$reason!==''?$reason:'A solicitação de desconto não foi aprovada.','order',(string)$result['order_id'],'discount:'.$requestId.':rejected','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function pending():array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$shift=(new WorkShiftService())->current();$unitId=$shift&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;
        $sql='SELECT r.id,r.order_id,r.base_discount_cents,r.requested_cents,r.reason,r.created_at,u.name requester_name,o.unit_id,o.subtotal_cents,o.discount_cents,o.delivery_fee_cents,o.total_cents,o.status order_status,o.payment_status FROM order_discount_requests r JOIN users u ON u.id=r.requested_by JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id WHERE r.tenant_id=? AND r.status="pending"';$args=[$tenantId];if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}$sql.=' ORDER BY r.created_at,r.id';$q=Database::connection()->prepare($sql);$q->execute($args);return $q->fetchAll();
    }

    public function forOrder(int $orderId):?array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return null;$q=Database::connection()->prepare('SELECT r.*,u.name requester_name,a.name approver_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by LEFT JOIN users a ON a.id=r.approved_by WHERE r.tenant_id=? AND r.order_id=? ORDER BY r.id DESC LIMIT 1');$q->execute([$tenantId,$orderId]);$row=$q->fetch();return $row?:null;
    }

    private function lockOrder(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$orderId,$tenantId]);return $q->fetch()?:throw new RuntimeException('Pedido não encontrado.');
    }

    private function assertDiscountable(PDO $pdo,array $order):void
    {
        if(in_array((string)$order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido encerrado não aceita desconto.');
        $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$order['tenant_id'],$order['id']]);if((int)$paid->fetchColumn()>0)throw new RuntimeException('Pedido já possui valor recebido e não pode ter o total alterado.');
        $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$order['tenant_id'],$order['id']]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Há uma cobrança em andamento. Encerre a cobrança antes de solicitar desconto.');
    }

    private function assertUnit(array $order,array $shift):void
    {
        $shiftUnit=$shift['unit_id']!==null?(int)$shift['unit_id']:null;$orderUnit=$order['unit_id']!==null?(int)$order['unit_id']:null;if($shiftUnit!==null&&$shiftUnit!==$orderUnit)throw new RuntimeException('Pedido pertence a outra unidade.');
    }
}
