<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
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
}
