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
        [$tenantId,$userId,$shift]=$this->deliveryContext();
        $unitId=$shift['unit_id']!==null?(int)$shift['unit_id']:null;
        $pdo=Database::connection();

        // Materializa o vínculo operacional assim que um pedido pronto é atribuído.
        // Não marca retirada nem início de rota: estes eventos continuam dependendo
        // de ação explícita do entregador. A operação é idempotente.
        $sql='SELECT o.id,o.unit_id FROM orders o LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery") AND dp.id IS NULL';
        $args=[$tenantId,$userId];
        if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}else{$sql.=' AND o.unit_id IS NULL';}
        $missing=$pdo->prepare($sql);$missing->execute($args);
        foreach($missing->fetchAll() as $order){
            try{
                $insert=Database::portableSql($pdo,'INSERT IGNORE INTO delivery_progress (tenant_id,order_id,delivery_user_id,unit_id) VALUES (?,?,?,?)');
                $pdo->prepare($insert)->execute([$tenantId,(int)$order['id'],$userId,$order['unit_id']!==null?(int)$order['unit_id']:null]);
            }catch(\Throwable $e){
                // Corrida de sincronização/registro já criado: a consulta abaixo é a fonte final.
            }
        }

        $sql='SELECT o.id order_id,o.status order_status,dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at FROM orders o LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery","completed")';$args=[$tenantId,$userId];
        if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}else{$sql.=' AND o.unit_id IS NULL';}
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
        $s=$pdo->prepare('SELECT dp.picked_up_at,dp.route_started_at,o.status FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.order_id=? AND dp.delivery_user_id=? LIMIT 1');$s->execute([$tenantId,$orderId,$userId]);$row=$s->fetch();if(!$row||!$row['picked_up_at'])throw new RuntimeException('Retire o pedido no balcão antes de iniciar a rota.');
        $firstStart=empty($row['route_started_at']);
        if($row['status']==='ready')(new OrderService())->changeStatus($orderId,'out_for_delivery','delivery');elseif($row['status']!=='out_for_delivery')throw new RuntimeException('Pedido não está disponível para iniciar rota.');
        Database::transaction(function(PDO $tx)use($tenantId,$userId,$orderId):void{
            $p=$this->lockedProgress($tx,$tenantId,$orderId);if(!$p)throw new RuntimeException('Progresso da entrega não encontrado.');
            if(!$p['route_started_at'])$tx->prepare('UPDATE delivery_progress SET route_started_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$p['id']]);
        });
        Auth::audit('delivery.route_started','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);
        if($firstStart){
            try{
                $token=(new DeliveryTrackingService())->publicToken($tenantId,$orderId);
                $url=app_absolute_url('rastreio.php?t='.rawurlencode($token));
                if(!str_starts_with(strtolower($url),'https://'))throw new RuntimeException('APP_URL HTTPS é necessário para enviar o rastreamento.');
                $queued=(new CustomerCommunicationService())->queueDeliveryTracking($tenantId,$orderId,$url);
                Auth::audit('delivery.tracking_queued','order',(string)$orderId,['channels_queued'=>$queued]);
            }catch(\Throwable $e){Auth::audit('delivery.tracking_queue_failed','order',(string)$orderId,['error'=>mb_substr($e->getMessage(),0,240)]);}
        }
        return $this->progressByOrder(Database::connection(),$tenantId,$orderId);
    }

    public function arrive(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$orderId):array{
            $order=$this->lockedAssignedOrder($pdo,$tenantId,$userId,$shift,$orderId);if($order['status']!=='out_for_delivery')throw new RuntimeException('Inicie a rota antes de marcar chegada.');
            $p=$this->lockedProgress($pdo,$tenantId,$orderId);if(!$p||!$p['route_started_at'])throw new RuntimeException('Rota não iniciada.');
            if(!$p['arrived_at']){$pdo->prepare('UPDATE delivery_progress SET arrived_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$p['id']]);(new OrderHistoryService())->record($pdo,$tenantId,$orderId,'out_for_delivery','out_for_delivery','delivery_arrived','Entregador informou chegada ao endereço do cliente.',$userId);}
            Auth::audit('delivery.arrived','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);return $this->progressByOrder($pdo,$tenantId,$orderId);
        });
    }

    public function complete(int $orderId):array
    {
        [$tenantId,$userId,$shift]=$this->deliveryContext();$pdo=Database::connection();
        $p=$pdo->prepare('SELECT arrived_at FROM delivery_progress WHERE tenant_id=? AND order_id=? AND delivery_user_id=? LIMIT 1');$p->execute([$tenantId,$orderId,$userId]);$arrived=$p->fetchColumn();if(!$arrived)throw new RuntimeException('Marque CHEGUEI antes de concluir a entrega.');
        $order=(new OrderService())->changeStatus($orderId,'completed','delivery');
        $pdo->prepare('UPDATE delivery_progress SET completed_at=COALESCE(completed_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND delivery_user_id=?')->execute([$tenantId,$orderId,$userId]);
        Auth::audit('delivery.completed','order',(string)$orderId,['shift_id'=>(int)$shift['id']]);return ['order'=>$order,'progress'=>$this->progressByOrder($pdo,$tenantId,$orderId)];
    }

    public function assertArrived(int $orderId):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return;$shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='delivery')return;
        $s=Database::connection()->prepare('SELECT dp.arrived_at FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.order_id=? AND dp.delivery_user_id=? AND o.assigned_delivery_user_id=? LIMIT 1');$s->execute([$tenantId,$orderId,$userId,$userId]);if(!$s->fetchColumn())throw new RuntimeException('Marque CHEGUEI antes de receber o pagamento da entrega.');
    }

    private function deliveryContext():array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado ao Delivery.');$shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='delivery')throw new RuntimeException('Inicie um turno Delivery.');return [$tenantId,$userId,$shift];
    }

    private function lockedAssignedOrder(PDO $pdo,int $tenantId,int $userId,array $shift,int $orderId):array
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? AND channel="delivery" AND assigned_delivery_user_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId,$userId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não está atribuído a este entregador.');$unit=$shift['unit_id']!==null?(int)$shift['unit_id']:null;$orderUnit=$order['unit_id']!==null?(int)$order['unit_id']:null;if($unit!==$orderUnit)throw new RuntimeException('Pedido pertence a outra unidade.');return $order;
    }

    private function lockedProgress(PDO $pdo,int $tenantId,int $orderId):?array
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM delivery_progress WHERE tenant_id=? AND order_id=? FOR UPDATE'));$s->execute([$tenantId,$orderId]);$row=$s->fetch();return $row?:null;
    }

    private function progressByOrder(PDO $pdo,int $tenantId,int $orderId):array
    {
        $s=$pdo->prepare('SELECT dp.*,o.status AS order_status FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.order_id=? LIMIT 1');$s->execute([$tenantId,$orderId]);return $s->fetch()?:throw new RuntimeException('Progresso da entrega não encontrado.');
    }
}
