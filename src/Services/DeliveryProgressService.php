<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryProgressService
{
    public function listMine():array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();$unitId=$shift['unit_id']!==null?(int)$shift['unit_id']:null;$pdo=Database::connection();
        $sql='SELECT o.id order_id,o.status order_status,dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at FROM orders o LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery","completed")';$args=[$tenantId,$userId];
        if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}
        $sql.=' ORDER BY o.id DESC LIMIT 200';$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();
    }

    public function pickup(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId):array{
            $order=$this->lockedAssignedOrder($pdo,$tenantId,$userId,$shift,$orderId);
            if($order['status']!=='ready')throw new RuntimeException('O pedido precisa estar pronto para retirada.');
            $progress=$this->lockedProgress($pdo,$tenantId,$orderId);
            if(!$progress){
                $pdo->prepare('INSERT INTO delivery_progress (tenant_id,order_id,delivery_user_id,unit_id,picked_up_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP)')->execute([$tenantId,$orderId,$userId,$order['unit_id']]);
                (new OrderHistoryService())->record($pdo,$tenantId,$orderId,'ready','ready','delivery_pickup','Pedido retirado no balcão pelo entregador.',$userId);
            }elseif(!$progress['picked_up_at']){
                $pdo->prepare('UPDATE delivery_progress SET delivery_user_id=?,unit_id=?,picked_up_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$userId,$order['unit_id'],$progress['id']]);
                (new OrderHistoryService())->record($pdo,$tenantId,$orderId,'ready','ready','delivery_pickup','Pedido retirado no balcão pelo entregador.',$userId);
            }
            Auth::audit('delivery.picked_up','order',(string)$orderId,['shift_id'=>(int)$shift['id'],'unit_id'=>$order['unit_id']]);return $this->progressByOrder($pdo,$tenantId,$orderId);
        });
    }

    public function startRoute(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();$pdo=Database::connection();
        $s=$pdo->prepare('SELECT dp.picked_up_at,o.status FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.order_id=? AND dp.delivery_user_id=? LIMIT 1');$s->execute([$tenantId,$orderId,$userId]);$row=$s->fetch();if(!$row||!$row['picked_up_at'])throw new RuntimeException('Retire o pedido no balcão antes de iniciar a rota.');

        $active=$pdo->prepare('SELECT o.id FROM orders o JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.tenant_id=? AND o.assigned_delivery_user_id=? AND o.channel="delivery" AND o.id<>? AND o.status="out_for_delivery" AND dp.route_started_at IS NOT NULL AND dp.arrived_at IS NULL AND dp.completed_at IS NULL LIMIT 1');
        $active->execute([$tenantId,$userId,$orderId]);$activeOrder=(int)$active->fetchColumn();
        if($activeOrder>0)throw new RuntimeException('Você já possui o pedido #'.$activeOrder.' em rota. Confirme a chegada antes de iniciar outra entrega.');

        if($row['status']==='ready')(new OrderService())->changeStatus($orderId,'out_for_delivery','delivery');elseif($row['status']!=='out_for_delivery')throw new RuntimeException('Pedido não está disponível para iniciar rota.');
        Database::transaction(function(PDO $tx)use($tenantId,$userId,$orderId):void{
            $p=$this->lockedProgress($tx,$tenantId,$orderId);if(!$p)throw new RuntimeException('Não foi possível carregar o andamento da entrega.');
            if(!$p['route_started_at'])$tx->prepare('UPDATE delivery_progress SET route_started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$p['id']]);
        });
        try{(new DeliveryPublicTrackingService())->issue($orderId);}catch(\Throwable$e){error_log('[delivery-tracking-auto] '.$e::class.': '.$e->getMessage());}
        try{(new DeliveryCustomerPushService())->sendOrderStatus($orderId,'out_for_delivery');}catch(\Throwable$e){error_log('[delivery-customer-route-push] '.$e::class.': '.$e->getMessage());}
        Auth::audit('delivery.route_started','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);return $this->progressByOrder(Database::connection(),$tenantId,$orderId);
    }

    public function arrive(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();
        $changed=false;
        $result=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId,&$changed):array{
            $order=$this->lockedAssignedOrder($pdo,$tenantId,$userId,$shift,$orderId);if($order['status']!=='out_for_delivery')throw new RuntimeException('Inicie a rota antes de marcar chegada.');
            $p=$this->lockedProgress($pdo,$tenantId,$orderId);if(!$p||!$p['route_started_at'])throw new RuntimeException('A rota ainda não foi iniciada.');
            if(!$p['arrived_at']){
                $pdo->prepare('UPDATE delivery_progress SET arrived_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$p['id']]);
                (new OrderHistoryService())->record($pdo,$tenantId,$orderId,'out_for_delivery','out_for_delivery','delivery_arrived','Entregador informou chegada ao endereço do cliente.',$userId);$changed=true;
            }
            Auth::audit('delivery.arrived','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);return $this->progressByOrder($pdo,$tenantId,$orderId);
        });
        if($changed){try{(new DeliveryCustomerPushService())->sendOrderStatus($orderId,'arrived');}catch(\Throwable$e){error_log('[delivery-customer-arrived-push] '.$e::class.': '.$e->getMessage());}}
        return$result;
    }

    public function complete(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();$pdo=Database::connection();
        $p=$pdo->prepare('SELECT arrived_at FROM delivery_progress WHERE tenant_id=? AND order_id=? AND delivery_user_id=? LIMIT 1');$p->execute([$tenantId,$orderId,$userId]);$arrived=$p->fetchColumn();if(!$arrived)throw new RuntimeException('Confirme que chegou ao cliente antes de concluir a entrega.');
        $order=(new OrderService())->changeStatus($orderId,'completed','delivery');
        $pdo->prepare('UPDATE delivery_progress SET completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND delivery_user_id=?')->execute([$tenantId,$orderId,$userId]);
        (new DeliveryPublicTrackingService())->revokeForOrder($tenantId,$orderId);
        Auth::audit('delivery.completed','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);return ['order'=>$order,'progress'=>$this->progressByOrder($pdo,$tenantId,$orderId)];
    }

    public function assertArrived(int $orderId):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return;$shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='delivery')return;
        $s=Database::connection()->prepare('SELECT dp.arrived_at FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.order_id=? AND dp.delivery_user_id=? AND o.assigned_delivery_user_id=? LIMIT 1');$s->execute([$tenantId,$orderId,$userId,$userId]);if(!$s->fetchColumn())throw new RuntimeException('Confirme que chegou ao cliente antes de receber o pagamento.');
    }

    private function deliveryContext():array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||!Auth::can('orders.delivery'))throw new RuntimeException('Você não tem acesso às entregas.');$shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='delivery')throw new RuntimeException('Inicie seu turno de entregas.');return [$tenantId,$userId,$shift];
    }

    private function lockedAssignedOrder(PDO $pdo,int $tenantId,int $userId,array $shift,int $orderId):array
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? AND channel="delivery" AND assigned_delivery_user_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId,$userId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Este pedido não está atribuído a você.');
        $unit=$shift['unit_id']!==null?(int)$shift['unit_id']:null;$orderUnit=$order['unit_id']!==null?(int)$order['unit_id']:null;
        if($unit!==null&&$unit!==$orderUnit)throw new RuntimeException('Este pedido pertence a outra unidade.');
        return $order;
    }

    private function lockedProgress(PDO $pdo,int $tenantId,int$orderId):?array
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM delivery_progress WHERE tenant_id=? AND order_id=? FOR UPDATE'));$s->execute([$tenantId,$orderId]);$row=$s->fetch();return $row?:null;
    }

    private function progressByOrder(PDO $pdo,int$tenantId,int$orderId):array
    {
        $s=$pdo->prepare('SELECT * FROM delivery_progress WHERE tenant_id=? AND order_id=? LIMIT 1');$s->execute([$tenantId,$orderId]);return $s->fetch()?:throw new RuntimeException('Não foi possível carregar o andamento da entrega.');
    }
}
