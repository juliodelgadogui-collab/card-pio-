<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderDiscountService
{
    public function request(int $orderId,int $requestedValue,string $reason,string $discountType='fixed'):array
    {
        Auth::requirePermission('discounts.request');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $discountType=strtolower(trim($discountType));if(!in_array($discountType,['fixed','percent'],true))throw new RuntimeException('Tipo de desconto inválido.');
        $policy=(new DiscountPolicyService())->get($tenantId);if($discountType==='percent'&&!(bool)$policy['allow_percentage'])throw new RuntimeException('Desconto percentual está desativado para esta empresa.');
        $reason=mb_substr(trim($reason),0,500);if($orderId<1||$requestedValue<=0||((bool)$policy['require_reason']&&$reason===''))throw new RuntimeException('Informe pedido, desconto e motivo.');
        $shift=(new WorkShiftService())->current();if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('Abra um turno de Operação ou Pay para conceder desconto.');
        $role=(string)Auth::role();$autoLimit=(new DiscountPolicyService())->autoLimitBps($role,$policy);

        $request=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId,$requestedValue,$reason,$discountType,$autoLimit):array{
            $order=$this->lockOrder($pdo,$tenantId,$orderId);$this->assertUnit($order,$shift['unit_id']!==null?(int)$shift['unit_id']:null);$this->assertDiscountable($pdo,$order);
            $pricing=(new OrderPricingService())->recalculate($pdo,$tenantId,$orderId);$subtotal=(int)$pricing['subtotal_cents'];$base=(int)$pricing['discount_cents'];
            $requestedBps=$discountType==='percent'?min(10000,$requestedValue):($subtotal>0?(int)round(($requestedValue/$subtotal)*10000):0);
            $requestedCents=$discountType==='percent'?(int)round($subtotal*($requestedBps/10000)):$requestedValue;
            $max=max(0,$subtotal-$base);if($requestedCents<=0||$requestedCents>$max)throw new RuntimeException('Desconto maior que o valor ainda disponível nos itens.');
            $effectiveBps=$subtotal>0?(int)round(($requestedCents/$subtotal)*10000):10000;
            $autoApproved=$autoLimit>0&&$effectiveBps<=$autoLimit;
            $pdo->prepare('UPDATE order_discount_requests SET status="cancelled",decided_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND status="pending"')->execute([$tenantId,$orderId]);
            $status=$autoApproved?'approved':'pending';
            $pdo->prepare('INSERT INTO order_discount_requests (tenant_id,order_id,unit_id,requested_by,base_discount_cents,requested_cents,discount_type,requested_bps,applied_cents,auto_approved,reason,status,approved_by,decided_at) VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?,?)')
                ->execute([$tenantId,$orderId,$order['unit_id']?:null,$userId,$base,$requestedCents,$discountType,$discountType==='percent'?$requestedBps:0,$autoApproved?$requestedCents:0,$autoApproved?1:0,$reason,$status,$autoApproved?$userId:null,$autoApproved?date('Y-m-d H:i:s'):null]);
            $id=(int)$pdo->lastInsertId();$price=null;
            if($autoApproved){
                $price=(new OrderPricingService())->addAdjustment($pdo,$tenantId,$orderId,'discount','manual_discount','Desconto operacional',$requestedCents,$discountType==='percent'?$requestedBps:0,'discount-request:'.$id,$id,$userId,['reason'=>$reason,'auto_approved'=>true]);
                (new OrderHistoryService())->record($pdo,$tenantId,$orderId,(string)$order['status'],(string)$order['status'],'discount_auto_approved','Desconto aplicado automaticamente: R$ '.number_format($requestedCents/100,2,',','.').'. '.$reason,$userId);
            }
            $q=$pdo->prepare('SELECT r.*,u.name requester_name,a.name approver_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by LEFT JOIN users a ON a.id=r.approved_by WHERE r.id=?');$q->execute([$id]);$row=$q->fetch()?:throw new RuntimeException('Falha ao registrar desconto.');
            if($price)$row['total_cents']=$price['total_cents'];return $row;
        });

        Auth::audit($request['status']==='approved'?'order.discount_auto_approved':'order.discount_requested','order',(string)$orderId,['request_id'=>(int)$request['id'],'requested_cents'=>(int)$request['requested_cents'],'discount_type'=>$discountType,'requested_bps'=>(int)$request['requested_bps'],'reason'=>$reason,'unit_id'=>$shift['unit_id']??null]);
        if($request['status']==='pending'){
            try{(new NotificationService())->publishToPermission('discounts.approve',$shift['unit_id']??null,'discount.requested','Desconto solicitado · Pedido #'.$orderId,($request['requester_name']?:'Funcionário').' solicitou R$ '.number_format(((int)$request['requested_cents'])/100,2,',','.').' de desconto.','discount_request',(string)$request['id'],'discount:'.$request['id'].':approve','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        }
        return $request;
    }

    public function approve(int $requestId):array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();$approverId=Auth::id();if(!$tenantId||!$approverId)throw new RuntimeException('Sessão inválida.');
        $unitId=$this->currentUnitId();
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$approverId,$unitId,$requestId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,u.name requester_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação de desconto não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');
            $order=$this->lockOrder($pdo,$tenantId,(int)$request['order_id']);$this->assertUnit($order,$unitId);$this->assertDiscountable($pdo,$order);
            $pricing=(new OrderPricingService())->recalculate($pdo,$tenantId,(int)$order['id']);if((int)$pricing['discount_cents']!==(int)$request['base_discount_cents'])throw new RuntimeException('O preço do pedido mudou. Solicite o desconto novamente.');
            $price=(new OrderPricingService())->addAdjustment($pdo,$tenantId,(int)$order['id'],'discount','manual_discount','Desconto aprovado',(int)$request['requested_cents'],(int)$request['requested_bps'],'discount-request:'.$requestId,$requestId,$approverId,['reason'=>$request['reason']]);
            $pdo->prepare('UPDATE order_discount_requests SET status="approved",approved_by=?,applied_cents=requested_cents,decided_at=CURRENT_TIMESTAMP WHERE id=? AND status="pending"')->execute([$approverId,$requestId]);
            (new OrderHistoryService())->record($pdo,$tenantId,(int)$order['id'],(string)$order['status'],(string)$order['status'],'discount_approved','Desconto autorizado: R$ '.number_format((int)$request['requested_cents']/100,2,',','.').'. '.$request['reason'],$approverId);
            return ['request_id'=>$requestId,'order_id'=>(int)$order['id'],'requested_by'=>(int)$request['requested_by'],'requester_name'=>(string)$request['requester_name'],'discount_cents'=>$price['discount_cents'],'total_cents'=>$price['total_cents'],'approved_cents'=>(int)$request['requested_cents'],'status'=>'approved'];
        });
        Auth::audit('order.discount_approved','order',(string)$result['order_id'],['request_id'=>$requestId,'approved_cents'=>$result['approved_cents'],'total_cents'=>$result['total_cents'],'unit_id'=>$unitId]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],$unitId,'discount.approved','Desconto aprovado · Pedido #'.$result['order_id'],'Desconto de R$ '.number_format($result['approved_cents']/100,2,',','.').' aprovado. Novo total: R$ '.number_format($result['total_cents']/100,2,',','.').'.','order',(string)$result['order_id'],'discount:'.$requestId.':approved','success',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function reject(int $requestId,string $reason=''):array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();$approverId=Auth::id();if(!$tenantId||!$approverId)throw new RuntimeException('Sessão inválida.');$reason=mb_substr(trim($reason),0,500);$unitId=$this->currentUnitId();
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$approverId,$requestId,$reason,$unitId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,u.name requester_name,o.unit_id order_unit_id FROM order_discount_requests r JOIN users u ON u.id=r.requested_by JOIN orders o ON o.id=r.order_id WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');if($unitId!==null&&(int)$request['order_unit_id']!==$unitId)throw new RuntimeException('Pedido pertence a outra unidade.');
            $pdo->prepare('UPDATE order_discount_requests SET status="rejected",approved_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$approverId,$requestId]);
            return ['request_id'=>$requestId,'order_id'=>(int)$request['order_id'],'requested_by'=>(int)$request['requested_by'],'requested_cents'=>(int)$request['requested_cents'],'status'=>'rejected','reason'=>$reason];
        });
        Auth::audit('order.discount_rejected','order',(string)$result['order_id'],['request_id'=>$requestId,'reason'=>$reason,'unit_id'=>$unitId]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],$unitId,'discount.rejected','Desconto não aprovado · Pedido #'.$result['order_id'],$reason!==''?$reason:'A solicitação de desconto não foi aprovada.','order',(string)$result['order_id'],'discount:'.$requestId.':rejected','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function pending():array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$unitId=$this->currentUnitId();
        $sql='SELECT r.*,u.name requester_name,o.subtotal_cents,o.discount_cents,o.delivery_fee_cents,o.surcharge_cents,o.total_cents,o.status order_status,o.payment_status FROM order_discount_requests r JOIN users u ON u.id=r.requested_by JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id WHERE r.tenant_id=? AND r.status="pending"';$args=[$tenantId];if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}$sql.=' ORDER BY r.created_at,r.id';$q=Database::connection()->prepare($sql);$q->execute($args);return $q->fetchAll();
    }

    public function forOrder(int $orderId):?array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return null;$q=Database::connection()->prepare('SELECT r.*,u.name requester_name,a.name approver_name FROM order_discount_requests r JOIN users u ON u.id=r.requested_by LEFT JOIN users a ON a.id=r.approved_by WHERE r.tenant_id=? AND r.order_id=? ORDER BY r.id DESC LIMIT 1');$q->execute([$tenantId,$orderId]);$row=$q->fetch();return $row?:null;
    }

    public function policy():array{return (new DiscountPolicyService())->get();}

    private function lockOrder(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$orderId,$tenantId]);return $q->fetch()?:throw new RuntimeException('Pedido não encontrado.');
    }

    private function assertDiscountable(PDO $pdo,array $order):void
    {
        if(in_array((string)$order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido encerrado não aceita desconto.');
        $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$order['tenant_id'],$order['id']]);if((int)$paid->fetchColumn()>0)throw new RuntimeException('O pedido já possui recebimento. Desconto deve ser definido antes do primeiro pagamento.');
        $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$order['tenant_id'],$order['id']]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Existe uma cobrança em andamento. Cancele-a antes de alterar o preço.');
    }

    private function assertUnit(array $order,?int $unitId):void
    {
        $orderUnit=$order['unit_id']!==null?(int)$order['unit_id']:null;if($unitId!==null&&$orderUnit!==$unitId)throw new RuntimeException('Pedido pertence a outra unidade.');
    }

    private function currentUnitId():?int
    {
        $shift=(new WorkShiftService())->current();if($shift&&$shift['unit_id']!==null)return(int)$shift['unit_id'];
        try{$unit=(new OperatingUnitService())->current();return $unit?(int)$unit['id']:null;}catch(\Throwable){return null;}
    }
}
