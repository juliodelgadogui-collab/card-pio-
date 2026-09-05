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
        $payments=$pdo->prepare('SELECT p.id,p.provider,p.amount_cents,p.status,p.verified_at,COALESCE(pc.method,cm.method,CASE WHEN p.provider="manual" THEN "cash" ELSE p.provider END) method FROM payments p LEFT JOIN payment_collection_contexts pc ON pc.payment_id=p.id AND pc.tenant_id=p.tenant_id LEFT JOIN cash_movements cm ON cm.payment_id=p.id AND cm.tenant_id=p.tenant_id AND cm.type="sale" WHERE p.tenant_id=? AND p.order_id=? AND p.status IN ("paid","refunded","partially_refunded","duplicate_paid") ORDER BY p.id');$payments->execute([$tenantId,$orderId]);$paymentRows=$payments->fetchAll();
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
}
