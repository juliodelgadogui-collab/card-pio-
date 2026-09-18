<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderCancellationService
{
    public function request(int $orderId,string $reason):array
    {
        return $this->requestWithContext($orderId,$reason,null);
    }

    public function requestFromPanel(int $orderId,string $reason,int $unitId):array
    {
        if($unitId<1)throw new RuntimeException('Unidade inválida.');
        return $this->requestWithContext($orderId,$reason,$unitId);
    }

    private function requestWithContext(int $orderId,string $reason,?int $panelUnitId):array
    {
        Auth::requirePermission('cancellations.request');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $reason=mb_substr(trim($reason),0,500);if($orderId<1||$reason==='')throw new RuntimeException('Informe o pedido e o motivo do cancelamento.');
        $shift=$panelUnitId!==null?['unit_id'=>$panelUnitId,'mode'=>'panel']:(new WorkShiftService())->current();
        if(!$shift||($panelUnitId===null&&!in_array((string)$shift['mode'],['operation','pay','delivery'],true)))throw new RuntimeException('Solicitação de cancelamento exige turno operacional aberto.');

        $request=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId,$reason):array{
            $order=$this->lockOrder($pdo,$tenantId,$orderId);$this->assertUnit($order,$shift);$this->assertRequestable($pdo,$order);
            if(($shift['mode']??'')==='delivery'&&((int)($order['assigned_delivery_user_id']??0)!==$userId||$order['channel']!=='delivery'))throw new RuntimeException('No modo Delivery você só pode solicitar cancelamento de entrega atribuída a você.');
            $pdo->prepare('UPDATE order_cancellation_requests SET status="cancelled",decided_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND status="pending"')->execute([$tenantId,$orderId]);
            $pdo->prepare('INSERT INTO order_cancellation_requests (tenant_id,order_id,unit_id,requested_by,reason,status) VALUES (?,?,?,?,?,"pending")')->execute([$tenantId,$orderId,$order['unit_id'],$userId,$reason]);
            $id=(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT r.*,u.name requester_name FROM order_cancellation_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=?');$q->execute([$id]);return $q->fetch()?:throw new RuntimeException('Falha ao registrar solicitação.');
        });

        Auth::audit('order.cancellation_requested','order',(string)$orderId,['request_id'=>(int)$request['id'],'reason'=>$reason,'unit_id'=>$request['unit_id']]);
        try{(new NotificationService())->publishToPermission('cancellations.approve',null,'cancellation.requested','Cancelamento solicitado · Pedido #'.$orderId,($request['requester_name']?:'Funcionário').' solicitou cancelamento. Motivo: '.$reason,'cancellation_request',(string)$request['id'],'cancel:'.$request['id'].':approve','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $request;
    }

    public function approve(int $requestId):array
    {
        return $this->approveWithContext($requestId,null);
    }

    public function approveFromPanel(int $requestId,int $unitId):array
    {
        if($unitId<1)throw new RuntimeException('Unidade inválida.');
        return $this->approveWithContext($requestId,$unitId);
    }

    private function approveWithContext(int $requestId,?int $panelUnitId):array
    {
        Auth::requirePermission('cancellations.approve');$tenantId=Auth::tenantId();$deciderId=Auth::id();if(!$tenantId||!$deciderId)throw new RuntimeException('Sessão inválida.');
        $shift=$panelUnitId!==null?['unit_id'=>$panelUnitId,'mode'=>'panel']:(new WorkShiftService())->current();
        if(!$shift||($panelUnitId===null&&!in_array((string)$shift['mode'],['operation','pay'],true)))throw new RuntimeException('Aprovação de cancelamento exige turno de Operação ou Pay.');

        $result=Database::transaction(function(PDO $pdo)use($tenantId,$deciderId,$shift,$requestId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,u.name requester_name FROM order_cancellation_requests r JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação de cancelamento não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');
            $order=$this->lockOrder($pdo,$tenantId,(int)$request['order_id']);$this->assertUnit($order,$shift);$this->assertRequestable($pdo,$order);
            $current=(string)$order['status'];(new StockReservationService())->release($pdo,$tenantId,(int)$order['id']);(new LoyaltyPointsService())->releaseForOrder($pdo,$tenantId,(int)$order['id']);
            $pdo->prepare('UPDATE orders SET status="cancelled" WHERE id=? AND tenant_id=?')->execute([$order['id'],$tenantId]);
            (new MarketplaceCommissionService())->reverse($pdo,$tenantId,(int)$order['id'],'Cancelamento autorizado: '.(string)$request['reason']);
            $pdo->prepare('UPDATE order_cancellation_requests SET status="approved",decided_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=? AND status="pending"')->execute([$deciderId,$requestId]);
            $pdo->prepare('UPDATE order_cancellation_requests SET status="cancelled",decided_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND id<>? AND status="pending"')->execute([$tenantId,$order['id'],$requestId]);
            (new OrderHistoryService())->record($pdo,$tenantId,(int)$order['id'],$current,'cancelled','cancellation_approved','Cancelamento autorizado. Motivo: '.$request['reason'],$deciderId);
            return ['request_id'=>$requestId,'order_id'=>(int)$order['id'],'requested_by'=>(int)$request['requested_by'],'requester_name'=>(string)$request['requester_name'],'status'=>'approved','reason'=>(string)$request['reason']];
        });

        Auth::audit('order.cancellation_approved','order',(string)$result['order_id'],['request_id'=>$requestId,'reason'=>$result['reason'],'unit_id'=>$shift['unit_id']??null]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],null,'cancellation.approved','Cancelamento aprovado · Pedido #'.$result['order_id'],'O cancelamento foi autorizado e o pedido foi encerrado.','order',(string)$result['order_id'],'cancel:'.$requestId.':approved','success',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function reject(int $requestId,string $reason=''):array
    {
        return $this->rejectWithContext($requestId,$reason,null);
    }

    public function rejectFromPanel(int $requestId,string $reason,int $unitId):array
    {
        if($unitId<1)throw new RuntimeException('Unidade inválida.');
        return $this->rejectWithContext($requestId,$reason,$unitId);
    }

    private function rejectWithContext(int $requestId,string $reason,?int $panelUnitId):array
    {
        Auth::requirePermission('cancellations.approve');$tenantId=Auth::tenantId();$deciderId=Auth::id();if(!$tenantId||!$deciderId)throw new RuntimeException('Sessão inválida.');$reason=mb_substr(trim($reason),0,500);
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$deciderId,$requestId,$reason,$panelUnitId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT r.*,o.unit_id,u.name requester_name FROM order_cancellation_requests r JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id JOIN users u ON u.id=r.requested_by WHERE r.id=? AND r.tenant_id=? FOR UPDATE'));$q->execute([$requestId,$tenantId]);$request=$q->fetch();if(!$request)throw new RuntimeException('Solicitação não encontrada.');if($request['status']!=='pending')throw new RuntimeException('Esta solicitação já foi decidida.');
            if($panelUnitId!==null)$this->assertUnit(['unit_id'=>$request['unit_id']],['unit_id'=>$panelUnitId]);else{$shift=(new WorkShiftService())->current();if($shift)$this->assertUnit(['unit_id'=>$request['unit_id']],$shift);}
            $pdo->prepare('UPDATE order_cancellation_requests SET status="rejected",decided_by=?,decided_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$deciderId,$requestId]);
            return ['request_id'=>$requestId,'order_id'=>(int)$request['order_id'],'requested_by'=>(int)$request['requested_by'],'status'=>'rejected','reason'=>$reason];
        });
        Auth::audit('order.cancellation_rejected','order',(string)$result['order_id'],['request_id'=>$requestId,'reason'=>$reason,'unit_id'=>$panelUnitId]);
        try{(new NotificationService())->publishToUser((int)$result['requested_by'],null,'cancellation.rejected','Cancelamento não aprovado · Pedido #'.$result['order_id'],$reason!==''?$reason:'A solicitação de cancelamento não foi aprovada.','order',(string)$result['order_id'],'cancel:'.$requestId.':rejected','warning',gmdate('Y-m-d H:i:s',time()+43200));}catch(\Throwable){}
        return $result;
    }

    public function pending():array
    {
        $shift=(new WorkShiftService())->current();$unitId=$shift&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;
        return $this->pendingForUnit($unitId);
    }

    public function pendingForUnit(?int $unitId):array
    {
        Auth::requirePermission('cancellations.approve');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $sql='SELECT r.id,r.order_id,r.reason,r.created_at,r.unit_id,u.name requester_name,o.channel,o.status order_status,o.payment_status,o.total_cents,c.name customer_name FROM order_cancellation_requests r JOIN users u ON u.id=r.requested_by JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE r.tenant_id=? AND r.status="pending"';$args=[$tenantId];if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}$sql.=' ORDER BY r.created_at,r.id';$q=Database::connection()->prepare($sql);$q->execute($args);return$q->fetchAll();
    }

    public function forOrder(int $orderId):?array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return null;$q=Database::connection()->prepare('SELECT r.*,u.name requester_name,d.name decider_name FROM order_cancellation_requests r JOIN users u ON u.id=r.requested_by LEFT JOIN users d ON d.id=r.decided_by WHERE r.tenant_id=? AND r.order_id=? ORDER BY r.id DESC LIMIT 1');$q->execute([$tenantId,$orderId]);$row=$q->fetch();return$row?:null;
    }

    private function lockOrder(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$orderId,$tenantId]);return$q->fetch()?:throw new RuntimeException('Pedido não encontrado.');
    }

    private function assertRequestable(PDO $pdo,array $order):void
    {
        if(in_array((string)$order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido encerrado não aceita solicitação de cancelamento.');
        $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$order['tenant_id'],$order['id']]);if((int)$paid->fetchColumn()>0||$order['payment_status']==='paid')throw new RuntimeException('Pedido possui valor recebido. Faça o estorno antes de cancelar.');
        $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$order['tenant_id'],$order['id']]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Há uma cobrança em processamento. Aguarde ou encerre a cobrança antes do cancelamento.');
    }

    private function assertUnit(array $order,array $shift):void
    {
        $shiftUnit=isset($shift['unit_id'])&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;$orderUnit=isset($order['unit_id'])&&$order['unit_id']!==null?(int)$order['unit_id']:null;if($shiftUnit!==null&&$shiftUnit!==$orderUnit)throw new RuntimeException('Pedido pertence a outra unidade.');
    }
}
