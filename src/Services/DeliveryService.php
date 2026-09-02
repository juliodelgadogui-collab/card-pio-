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
        $change=Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$deliveryUserId):array{
            $s=$pdo->prepare('SELECT id,unit_id,channel,status,assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $s->execute([$orderId,$tenantId]);
            $order=$s->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['channel']!=='delivery')throw new RuntimeException('Entregador só pode ser atribuído a pedido de delivery.');
            if(in_array($order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido finalizado não pode ter a entrega alterada.');

            if($deliveryUserId!==null){
                $u=$pdo->prepare('SELECT u.id FROM users u WHERE u.id=? AND u.tenant_id=? AND u.role="delivery" AND u.status="active" AND (? IS NULL OR NOT EXISTS (SELECT 1 FROM user_units ux WHERE ux.user_id=u.id) OR EXISTS (SELECT 1 FROM user_units uu WHERE uu.user_id=u.id AND uu.unit_id=?))');
                $u->execute([$deliveryUserId,$tenantId,$order['unit_id'],$order['unit_id']]);
                if(!$u->fetchColumn())throw new RuntimeException('Entregador inválido, bloqueado ou sem acesso à unidade deste pedido.');
            }

            $previous=$order['assigned_delivery_user_id']!==null?(int)$order['assigned_delivery_user_id']:null;
            if($previous===$deliveryUserId)return['changed'=>false,'previous'=>$previous,'current'=>$deliveryUserId];
            $pdo->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryUserId,$orderId,$tenantId]);
            $event=$deliveryUserId===null?'unassigned':'assigned';
            $metadata=['previous_delivery_user_id'=>$previous,'delivery_user_id'=>$deliveryUserId,'unit_id'=>$order['unit_id']!==null?(int)$order['unit_id']:null];
            $pdo->prepare('INSERT INTO delivery_events (tenant_id,order_id,user_id,event_type,metadata) VALUES (?,?,?,?,?)')->execute([$tenantId,$orderId,Auth::id(),$event,json_encode($metadata,JSON_UNESCAPED_UNICODE)]);
            Auth::audit('order.delivery_'.$event,'order',(string)$orderId,$metadata);
            return['changed'=>true,'previous'=>$previous,'current'=>$deliveryUserId];
        });
        if(empty($change['changed']))return;
        $notifications=new NotificationService();
        if(!empty($change['current']))$notifications->push($tenantId,'order','Nova entrega atribuída','O pedido #'.$orderId.' foi atribuído a você.','order',$orderId,(int)$change['current']);
        if(!empty($change['previous'])&&$change['previous']!==$change['current'])$notifications->push($tenantId,'info','Entrega removida','O pedido #'.$orderId.' não está mais atribuído a você.','order',$orderId,(int)$change['previous']);
    }
}
