<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryService
{
    public function assign(int $tenantId,int $orderId,?int $deliveryUserId):void
    {
        Auth::requirePermission('delivery.assign');
        Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$deliveryUserId):void{
            $s=$pdo->prepare('SELECT id,channel,status,assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $s->execute([$orderId,$tenantId]);
            $order=$s->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['channel']!=='delivery')throw new RuntimeException('Entregador só pode ser atribuído a pedido de delivery.');
            if(in_array($order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido finalizado não pode ter a entrega alterada.');

            if($deliveryUserId!==null){
                $u=$pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=? AND role="delivery" AND status="active"');
                $u->execute([$deliveryUserId,$tenantId]);
                if(!$u->fetchColumn())throw new RuntimeException('Entregador inválido ou bloqueado.');
            }

            $previous=$order['assigned_delivery_user_id']!==null?(int)$order['assigned_delivery_user_id']:null;
            if($previous===$deliveryUserId)return;
            $pdo->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryUserId,$orderId,$tenantId]);
            $event=$deliveryUserId===null?'unassigned':'assigned';
            $metadata=['previous_delivery_user_id'=>$previous,'delivery_user_id'=>$deliveryUserId];
            $pdo->prepare('INSERT INTO delivery_events (tenant_id,order_id,user_id,event_type,metadata) VALUES (?,?,?,?,?)')->execute([$tenantId,$orderId,Auth::id(),$event,json_encode($metadata,JSON_UNESCAPED_UNICODE)]);
            Auth::audit('order.delivery_'.$event,'order',(string)$orderId,$metadata);
        });
    }
}
