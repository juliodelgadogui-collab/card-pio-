<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class ReceiptService
{
    public function order(int $orderId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$orderId<1)throw new RuntimeException('Pedido inválido.');$pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.*,t.name tenant_name,c.name customer_name,c.phone customer_phone,rt.name table_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $allowed=Auth::can('payments.manage')||Auth::can('orders.view')||Auth::can('orders.manage');
        if(!$allowed&&Auth::can('orders.delivery')&&(int)($order['assigned_delivery_user_id']??0)===$userId)$allowed=true;
        if(!$allowed)throw new RuntimeException('Você não possui acesso ao comprovante deste pedido.');

        $items=$pdo->prepare('SELECT name_snapshot,quantity,unit_price_cents,total_cents FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);
        $payments=$pdo->prepare('SELECT p.id,p.provider,p.amount_cents,p.status,p.verified_at,p.payment_group_id,COALESCE(pc.method,cm.method,CASE WHEN p.provider="manual" THEN "cash" ELSE p.provider END) method FROM payments p LEFT JOIN payment_collection_contexts pc ON pc.payment_id=p.id AND pc.tenant_id=p.tenant_id LEFT JOIN cash_movements cm ON cm.payment_id=p.id AND cm.tenant_id=p.tenant_id AND cm.type="sale" WHERE p.tenant_id=? AND p.order_id=? AND p.status IN ("paid","refunded","partially_refunded","duplicate_paid") ORDER BY p.id');$payments->execute([$tenantId,$orderId]);$paymentRows=$payments->fetchAll();
        if(!$paymentRows)throw new RuntimeException('O pedido ainda não possui pagamento confirmado para comprovante.');
        $paid=0;foreach($paymentRows as $payment)if($payment['status']==='paid')$paid+=(int)$payment['amount_cents'];
        return [
            'receipt_number'=>'EM-'.$tenantId.'-'.$orderId,
            'tenant_name'=>(string)$order['tenant_name'],
            'order_id'=>$orderId,
            'channel'=>(string)$order['channel'],
            'status'=>(string)$order['status'],
            'payment_status'=>(string)$order['payment_status'],
            'customer_name'=>(string)($order['customer_name']??''),
            'customer_phone'=>(string)($order['customer_phone']??''),
            'table_name'=>(string)($order['table_name']??''),
            'subtotal_cents'=>(int)$order['subtotal_cents'],
            'discount_cents'=>(int)$order['discount_cents'],
            'delivery_fee_cents'=>(int)$order['delivery_fee_cents'],
            'total_cents'=>(int)$order['total_cents'],
            'paid_cents'=>$paid,
            'created_at'=>(string)$order['created_at'],
            'items'=>$items->fetchAll(),
            'payments'=>$paymentRows,
        ];
    }

    public function paymentGroup(int $groupId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$groupId<1)throw new RuntimeException('Grupo de pagamento inválido.');
        if(!Auth::can('payments.manage')&&!Auth::can('tables.manage')&&!Auth::can('reports.view'))throw new RuntimeException('Você não possui acesso ao comprovante desta divisão.');
        $pdo=Database::connection();
        $g=$pdo->prepare('SELECT pg.*,t.name tenant_name,tb.label tab_label,rt.name table_name,u.name operator_name FROM payment_groups pg JOIN tenants t ON t.id=pg.tenant_id LEFT JOIN tabs tb ON tb.id=pg.tab_id LEFT JOIN restaurant_tables rt ON rt.id=tb.table_id LEFT JOIN users u ON u.id=pg.user_id WHERE pg.id=? AND pg.tenant_id=? LIMIT 1');
        $g->execute([$groupId,$tenantId]);$group=$g->fetch();if(!$group)throw new RuntimeException('Grupo de pagamento não encontrado.');
        if(!in_array((string)$group['status'],['paid','refunded','attention'],true))throw new RuntimeException('A divisão ainda não possui confirmação suficiente para emitir comprovante.');

        $a=$pdo->prepare('SELECT a.order_id,a.payment_id,a.amount_cents,p.provider,p.status payment_status,p.verified_at,o.total_cents order_total_cents,o.payment_status order_payment_status FROM payment_group_allocations a JOIN payments p ON p.id=a.payment_id AND p.tenant_id=a.tenant_id JOIN orders o ON o.id=a.order_id AND o.tenant_id=a.tenant_id WHERE a.tenant_id=? AND a.payment_group_id=? ORDER BY a.id');
        $a->execute([$tenantId,$groupId]);$allocations=$a->fetchAll();if(!$allocations)throw new RuntimeException('Grupo sem alocações financeiras.');

        $items=$pdo->prepare('SELECT pgi.order_item_id,pgi.order_id,pgi.amount_cents,oi.name_snapshot,oi.quantity,oi.total_cents FROM payment_group_items pgi JOIN order_items oi ON oi.id=pgi.order_item_id AND oi.order_id=pgi.order_id WHERE pgi.tenant_id=? AND pgi.payment_group_id=? ORDER BY pgi.id');
        $items->execute([$tenantId,$groupId]);$itemRows=$items->fetchAll();

        $confirmed=0;foreach($allocations as $row)if((string)$row['payment_status']==='paid')$confirmed+=(int)$row['amount_cents'];
        $metadata=json_decode((string)($group['metadata']??''),true);if(!is_array($metadata))$metadata=[];
        return [
            'receipt_number'=>'EM-G-'.$tenantId.'-'.$groupId,
            'tenant_name'=>(string)$group['tenant_name'],
            'group_id'=>$groupId,
            'tab_id'=>$group['tab_id']!==null?(int)$group['tab_id']:null,
            'table_name'=>(string)($group['table_name']??''),
            'tab_label'=>(string)($group['tab_label']??''),
            'operator_name'=>(string)($group['operator_name']??''),
            'split_type'=>(string)$group['split_type'],
            'method'=>(string)$group['method'],
            'provider'=>(string)$group['provider'],
            'status'=>(string)$group['status'],
            'amount_cents'=>(int)$group['amount_cents'],
            'confirmed_cents'=>$confirmed,
            'provider_payment_id'=>(string)($group['provider_payment_id']??''),
            'verified_at'=>(string)($group['verified_at']??''),
            'created_at'=>(string)$group['created_at'],
            'metadata'=>$metadata,
            'allocations'=>$allocations,
            'items'=>$itemRows,
        ];
    }
}
