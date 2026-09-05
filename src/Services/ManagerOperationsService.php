<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use PDO;
use RuntimeException;

final class ManagerOperationsService
{
    public function overview(): array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!Auth::can('reports.view')&&!Auth::can('orders.manage'))throw new RuntimeException('Acesso negado ao painel gerencial.');
        $pdo=Database::connection();

        $scalar=function(string $sql,array $args=[])use($pdo,$tenantId):int{
            $s=$pdo->prepare($sql);$s->execute(array_merge([$tenantId],$args));return (int)$s->fetchColumn();
        };

        $cutoff=gmdate('Y-m-d H:i:s',time()-900);
        $ordersNow=$scalar('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND status IN ("pending","confirmed","preparing","ready","out_for_delivery")');
        $kitchenDelayed=$scalar('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND status="preparing" AND updated_at < ?',[$cutoff]);
        $readyOrders=$scalar('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND status="ready"');
        $unassignedDelivery=$scalar('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND channel="delivery" AND status="ready" AND assigned_delivery_user_id IS NULL');
        $deliveryOnline=$scalar('SELECT COUNT(*) FROM work_shifts WHERE tenant_id=? AND mode="delivery" AND status="open"');
        $cashOpen=$scalar('SELECT COUNT(*) FROM cash_sessions WHERE tenant_id=? AND status="open"');
        $pendingPayments=$scalar('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND payment_status IN ("unpaid","pending") AND status NOT IN ("cancelled","completed")');
        $revenueToday=$scalar('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND status="paid" AND verified_at>=CURRENT_DATE');

        $alerts=[];
        if($kitchenDelayed>0)$alerts[]=['level'=>'warning','title'=>'Cozinha atrasada','message'=>$kitchenDelayed.' pedido(s) em preparo há mais de 15 minutos.'];
        if($unassignedDelivery>0)$alerts[]=['level'=>'warning','title'=>'Delivery sem entregador','message'=>$unassignedDelivery.' pedido(s) pronto(s) aguardando atribuição.'];
        if($pendingPayments>0)$alerts[]=['level'=>'info','title'=>'Pagamentos pendentes','message'=>$pendingPayments.' pedido(s) ainda aguardam recebimento.'];
        if(!$alerts)$alerts[]=['level'=>'ok','title'=>'Operação estável','message'=>'Nenhum alerta operacional crítico neste momento.'];

        return [
            'orders_now'=>$ordersNow,
            'kitchen_delayed'=>$kitchenDelayed,
            'ready_orders'=>$readyOrders,
            'unassigned_delivery'=>$unassignedDelivery,
            'delivery_online'=>$deliveryOnline,
            'cash_open'=>$cashOpen,
            'pending_payments'=>$pendingPayments,
            'revenue_today_cents'=>$revenueToday,
            'alerts'=>$alerts,
        ];
    }

    public function details(): array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!Auth::can('reports.view')&&!Auth::can('orders.manage'))throw new RuntimeException('Acesso negado ao painel gerencial.');
        $pdo=Database::connection();$cutoff=gmdate('Y-m-d H:i:s',time()-900);

        $cash=$pdo->prepare('SELECT cs.id,cs.opening_cash_cents,cs.opened_at,u.id user_id,u.name user_name FROM cash_sessions cs JOIN users u ON u.id=cs.user_id WHERE cs.tenant_id=? AND cs.status="open" ORDER BY cs.opened_at');
        $cash->execute([$tenantId]);

        $delivery=$pdo->prepare('SELECT ws.id shift_id,ws.user_id,u.name user_name,u.role,ws.started_at,(SELECT COUNT(*) FROM orders o WHERE o.tenant_id=ws.tenant_id AND o.assigned_delivery_user_id=ws.user_id AND o.status IN ("ready","out_for_delivery")) active_orders FROM work_shifts ws JOIN users u ON u.id=ws.user_id WHERE ws.tenant_id=? AND ws.mode="delivery" AND ws.status="open" AND u.status="active" ORDER BY ws.started_at');
        $delivery->execute([$tenantId]);$deliveryRows=[];
        foreach($delivery->fetchAll() as $row){
            $permissions=PermissionCatalog::effectiveForUser($tenantId,(int)$row['user_id'],(string)$row['role']);
            if(!in_array('orders.delivery',$permissions,true))continue;
            unset($row['role']);$deliveryRows[]=$row;
        }

        $problems=$pdo->prepare('SELECT o.id,o.channel,o.status,o.payment_status,o.total_cents,o.assigned_delivery_user_id,o.updated_at,c.name customer_name,u.name delivery_name,
            CASE WHEN o.status="preparing" AND o.updated_at<? THEN "kitchen_delay" WHEN o.channel="delivery" AND o.status="ready" AND o.assigned_delivery_user_id IS NULL THEN "delivery_unassigned" WHEN o.payment_status IN ("unpaid","pending") AND o.status NOT IN ("cancelled","completed") THEN "payment_pending" ELSE "attention" END problem_type
            FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id
            WHERE o.tenant_id=? AND ((o.status="preparing" AND o.updated_at<?) OR (o.channel="delivery" AND o.status="ready" AND o.assigned_delivery_user_id IS NULL) OR (o.payment_status IN ("unpaid","pending") AND o.status NOT IN ("cancelled","completed")))
            ORDER BY CASE WHEN o.status="preparing" AND o.updated_at<? THEN 0 WHEN o.channel="delivery" AND o.status="ready" AND o.assigned_delivery_user_id IS NULL THEN 1 ELSE 2 END,o.updated_at LIMIT 100');
        $problems->execute([$cutoff,$tenantId,$cutoff,$cutoff]);

        return ['cash_sessions'=>$cash->fetchAll(),'delivery_shifts'=>$deliveryRows,'problem_orders'=>$problems->fetchAll()];
    }

    public function transferDelivery(int $orderId,int $deliveryUserId): array
    {
        Auth::requirePermission('delivery.assign');$tenantId=Auth::tenantId();if(!$tenantId||$orderId<1||$deliveryUserId<1)throw new RuntimeException('Pedido ou entregador inválido.');
        $previousUserId=null;
        $order=Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$deliveryUserId,&$previousUserId):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['channel']!=='delivery'||!in_array($order['status'],['ready','out_for_delivery'],true))throw new RuntimeException('Somente Delivery pronto ou em rota pode ser transferido.');
            $u=$pdo->prepare('SELECT id,role,status FROM users WHERE id=? AND tenant_id=? LIMIT 1');$u->execute([$deliveryUserId,$tenantId]);$user=$u->fetch();if(!$user||$user['status']!=='active')throw new RuntimeException('Entregador indisponível.');
            $permissions=PermissionCatalog::effectiveForUser($tenantId,$deliveryUserId,(string)$user['role']);if(!in_array('orders.delivery',$permissions,true))throw new RuntimeException('Funcionário não possui permissão de Delivery.');
            $ws=$pdo->prepare('SELECT id FROM work_shifts WHERE tenant_id=? AND user_id=? AND mode="delivery" AND status="open" ORDER BY id DESC LIMIT 1');$ws->execute([$tenantId,$deliveryUserId]);if(!$ws->fetchColumn())throw new RuntimeException('Funcionário não está em turno Delivery.');
            $previousUserId=$order['assigned_delivery_user_id']?(int)$order['assigned_delivery_user_id']:null;
            $pdo->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryUserId,$orderId,$tenantId]);$order['assigned_delivery_user_id']=$deliveryUserId;
            Auth::audit('order.delivery_transferred','order',(string)$orderId,['from_user_id'=>$previousUserId,'to_user_id'=>$deliveryUserId,'source'=>'eventmenu_go_manager']);return $order;
        });
        $notifications=new NotificationService();$notifications->publishToUser($deliveryUserId,'delivery','delivery_transferred','Entrega transferida','O pedido #'.$orderId.' foi transferido para você.','order',(string)$orderId,'delivery-transfer:'.$orderId.':to:'.$deliveryUserId,'warning');
        if($previousUserId&&$previousUserId!==$deliveryUserId)$notifications->publishToUser($previousUserId,'delivery','delivery_removed','Entrega transferida','O pedido #'.$orderId.' foi transferido para outro entregador.','order',(string)$orderId,'delivery-transfer:'.$orderId.':from:'.$previousUserId,'info');
        return $order;
    }

    public function cancelUnpaidOrder(int $orderId): array
    {
        Auth::requirePermission('orders.manage');if($orderId<1)throw new RuntimeException('Pedido inválido.');
        $order=(new OrderService())->changeStatus($orderId,'cancelled','panel');
        Auth::audit('manager.order_cancelled','order',(string)$orderId,['source'=>'eventmenu_go_manager']);
        return $order;
    }
}
