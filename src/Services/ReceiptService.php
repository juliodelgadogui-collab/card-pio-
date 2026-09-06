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
        $s=$pdo->prepare('SELECT o.*,t.name tenant_name,t.slug tenant_slug,t.settings tenant_settings,c.name customer_name,c.phone customer_phone,c.email customer_email,c.document customer_document,rt.name table_name,tb.label tab_label,u.name created_by_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN tabs tb ON tb.id=o.tab_id LEFT JOIN users u ON u.id=o.created_by WHERE o.id=? AND o.tenant_id=? LIMIT 1');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $allowed=Auth::can('payments.manage')||Auth::can('payments.view')||Auth::can('orders.view')||Auth::can('orders.manage');if(!$allowed&&Auth::can('orders.delivery')&&(int)($order['assigned_delivery_user_id']??0)===$userId)$allowed=true;if(!$allowed)throw new RuntimeException('Você não possui acesso ao comprovante deste pedido.');

        $items=$pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);$itemRows=$items->fetchAll();
        $modifiers=[];try{$m=$pdo->prepare('SELECT order_item_id,group_name_snapshot,option_name_snapshot,quantity,unit_price_delta_cents,total_delta_cents FROM order_item_modifiers WHERE tenant_id=? AND order_id=? ORDER BY id');$m->execute([$tenantId,$orderId]);foreach($m->fetchAll()as$row)$modifiers[(int)$row['order_item_id']][]=$row;}catch(\Throwable){$modifiers=[];}

        $paymentRows=[];try{$payments=$pdo->prepare('SELECT p.id,p.provider,p.provider_payment_id,p.amount_cents,p.currency,p.status,p.verified_at,p.created_at,COALESCE(pc.method,cm.method,CASE WHEN p.provider="manual" THEN "cash" ELSE p.provider END) method FROM payments p LEFT JOIN payment_collection_contexts pc ON pc.payment_id=p.id AND pc.tenant_id=p.tenant_id LEFT JOIN cash_movements cm ON cm.payment_id=p.id AND cm.tenant_id=p.tenant_id AND cm.type="sale" WHERE p.tenant_id=? AND p.order_id=? ORDER BY p.id');$payments->execute([$tenantId,$orderId]);$paymentRows=$payments->fetchAll();}catch(\Throwable){$payments=$pdo->prepare('SELECT id,provider,provider_payment_id,amount_cents,currency,status,verified_at,created_at,provider method FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id');$payments->execute([$tenantId,$orderId]);$paymentRows=$payments->fetchAll();}
        $paid=0;foreach($paymentRows as$payment)if(in_array((string)$payment['status'],['paid','partially_refunded'],true))$paid+=(int)$payment['amount_cents'];

        $tickets=[];$event=null;try{$t=$pdo->prepare('SELECT tk.id,tk.code,tk.qr_token,tk.status,e.id event_id,e.name event_name,e.starts_at,e.ends_at,e.venue,e.address,b.name batch_name,tt.name ticket_type_name FROM tickets tk JOIN events e ON e.id=tk.event_id JOIN ticket_batches b ON b.id=tk.batch_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE tk.tenant_id=? AND tk.order_id=? ORDER BY tk.id');$t->execute([$tenantId,$orderId]);$tickets=$t->fetchAll();}catch(\Throwable){try{$t=$pdo->prepare('SELECT tk.id,tk.code,tk.qr_token,tk.status,e.id event_id,e.name event_name,e.starts_at,e.ends_at,e.venue,e.address,b.name batch_name,NULL ticket_type_name FROM tickets tk JOIN events e ON e.id=tk.event_id JOIN ticket_batches b ON b.id=tk.batch_id WHERE tk.tenant_id=? AND tk.order_id=? ORDER BY tk.id');$t->execute([$tenantId,$orderId]);$tickets=$t->fetchAll();}catch(\Throwable){$tickets=[];}}
        if($tickets){$first=$tickets[0];$event=['id'=>(int)$first['event_id'],'name'=>(string)$first['event_name'],'starts_at'=>(string)$first['starts_at'],'ends_at'=>$first['ends_at']??null,'venue'=>$first['venue']??null,'address'=>$first['address']??null];}
        elseif(preg_match('/Evento\s*#(\d+)/i',(string)($order['notes']??''),$match)){$e=$pdo->prepare('SELECT id,name,starts_at,ends_at,venue,address FROM events WHERE id=? AND tenant_id=? LIMIT 1');$e->execute([(int)$match[1],$tenantId]);$event=$e->fetch()?:null;}

        $settings=json_decode((string)($order['tenant_settings']??'{}'),true);if(!is_array($settings))$settings=[];$paper=in_array((string)($settings['receipt_paper_width']??'80'),['58','80'],true)?(string)$settings['receipt_paper_width']:'80';
        $identity=[
            'trade_name'=>trim((string)($settings['receipt_trade_name']??''))?:((string)$order['tenant_name']),
            'legal_name'=>trim((string)($settings['receipt_legal_name']??'')),
            'document'=>trim((string)($settings['receipt_document']??'')),
            'state_registration'=>trim((string)($settings['receipt_state_registration']??'')),
            'municipal_registration'=>trim((string)($settings['receipt_municipal_registration']??'')),
            'address'=>trim((string)($settings['receipt_address']??'')),
            'phone'=>trim((string)($settings['receipt_phone']??($settings['whatsapp']??''))),
            'email'=>trim((string)($settings['receipt_email']??'')),
            'website'=>trim((string)($settings['receipt_website']??'')),
            'footer'=>trim((string)($settings['receipt_footer']??'Obrigado pela preferência!')),
            'paper_width'=>$paper,
            'show_order_qr'=>!array_key_exists('receipt_show_order_qr',$settings)||(bool)$settings['receipt_show_order_qr'],
            'show_ticket_qr'=>!array_key_exists('receipt_show_ticket_qr',$settings)||(bool)$settings['receipt_show_ticket_qr'],
            'logo_url'=>trim((string)($settings['receipt_logo_url']??($settings['menu_logo_url']??''))),
        ];

        return [
            'receipt_number'=>'EM-'.$tenantId.'-'.$orderId,'tenant_name'=>(string)$order['tenant_name'],'order'=>$order,'order_id'=>$orderId,'channel'=>(string)$order['channel'],'status'=>(string)$order['status'],'payment_status'=>(string)$order['payment_status'],'customer_name'=>(string)($order['customer_name']??''),'customer_phone'=>(string)($order['customer_phone']??''),'table_name'=>(string)($order['table_name']??''),'tab_label'=>(string)($order['tab_label']??''),'created_by_name'=>(string)($order['created_by_name']??''),'subtotal_cents'=>(int)$order['subtotal_cents'],'discount_cents'=>(int)$order['discount_cents'],'delivery_fee_cents'=>(int)$order['delivery_fee_cents'],'total_cents'=>(int)$order['total_cents'],'paid_cents'=>$paid,'created_at'=>(string)$order['created_at'],'items'=>$itemRows,'modifiers'=>$modifiers,'payments'=>$paymentRows,'tickets'=>$tickets,'event'=>$event,'identity'=>$identity,
        ];
    }

    public function paymentGroup(int $groupId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$groupId<1)throw new RuntimeException('Grupo de pagamento inválido.');
        if(!Auth::can('payments.manage')&&!Auth::can('tables.manage')&&!Auth::can('reports.view'))throw new RuntimeException('Você não possui acesso ao comprovante desta divisão.');
        $pdo=Database::connection();
        $g=$pdo->prepare('SELECT pg.*,t.name tenant_name,tb.label tab_label,rt.name table_name,u.name operator_name FROM payment_groups pg JOIN tenants t ON t.id=pg.tenant_id LEFT JOIN tabs tb ON tb.id=pg.tab_id LEFT JOIN restaurant_tables rt ON rt.id=tb.table_id LEFT JOIN users u ON u.id=pg.user_id WHERE pg.id=? AND pg.tenant_id=? LIMIT 1');$g->execute([$groupId,$tenantId]);$group=$g->fetch();if(!$group)throw new RuntimeException('Grupo de pagamento não encontrado.');if(!in_array((string)$group['status'],['paid','refunded','attention'],true))throw new RuntimeException('A divisão ainda não possui confirmação suficiente para emitir comprovante.');
        $a=$pdo->prepare('SELECT a.order_id,a.payment_id,a.amount_cents,p.provider,p.status payment_status,p.verified_at,o.total_cents order_total_cents,o.payment_status order_payment_status FROM payment_group_allocations a JOIN payments p ON p.id=a.payment_id AND p.tenant_id=a.tenant_id JOIN orders o ON o.id=a.order_id AND o.tenant_id=a.tenant_id WHERE a.tenant_id=? AND a.payment_group_id=? ORDER BY a.id');$a->execute([$tenantId,$groupId]);$allocations=$a->fetchAll();if(!$allocations)throw new RuntimeException('Grupo sem alocações financeiras.');
        $items=$pdo->prepare('SELECT pgi.order_item_id,pgi.order_id,pgi.amount_cents,oi.name_snapshot,oi.quantity,oi.total_cents FROM payment_group_items pgi JOIN order_items oi ON oi.id=pgi.order_item_id AND oi.order_id=pgi.order_id WHERE pgi.tenant_id=? AND pgi.payment_group_id=? ORDER BY pgi.id');$items->execute([$tenantId,$groupId]);$itemRows=$items->fetchAll();
        $confirmed=0;foreach($allocations as$row)if((string)$row['payment_status']==='paid')$confirmed+=(int)$row['amount_cents'];$metadata=json_decode((string)($group['metadata']??''),true);if(!is_array($metadata))$metadata=[];
        return ['receipt_number'=>'EM-G-'.$tenantId.'-'.$groupId,'tenant_name'=>(string)$group['tenant_name'],'group_id'=>$groupId,'tab_id'=>$group['tab_id']!==null?(int)$group['tab_id']:null,'table_name'=>(string)($group['table_name']??''),'tab_label'=>(string)($group['tab_label']??''),'operator_name'=>(string)($group['operator_name']??''),'split_type'=>(string)$group['split_type'],'method'=>(string)$group['method'],'provider'=>(string)$group['provider'],'status'=>(string)$group['status'],'amount_cents'=>(int)$group['amount_cents'],'confirmed_cents'=>$confirmed,'provider_payment_id'=>(string)($group['provider_payment_id']??''),'verified_at'=>(string)($group['verified_at']??''),'created_at'=>(string)$group['created_at'],'metadata'=>$metadata,'allocations'=>$allocations,'items'=>$itemRows];
    }
}
