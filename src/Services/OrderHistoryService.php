<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderHistoryService
{
    public function record(PDO $pdo,int $tenantId,int $orderId,?string $fromStatus,string $toStatus,string $source,string $notes='',?int $userId=null):void
    {
        if($tenantId<1||$orderId<1||trim($toStatus)==='')throw new RuntimeException('Histórico de pedido inválido.');
        $userId??=Auth::id();$source=mb_substr(trim($source),0,40);$notes=mb_substr(trim($notes),0,500);if($source==='')$source='system';
        $s=$pdo->prepare('INSERT INTO order_status_history (tenant_id,order_id,user_id,from_status,to_status,source,notes) VALUES (?,?,?,?,?,?,?)');
        $s->execute([$tenantId,$orderId,$userId?:null,$fromStatus!==null?mb_substr($fromStatus,0,30):null,mb_substr($toStatus,0,30),$source,$notes?:null]);
        $historyId=(int)$pdo->lastInsertId();
        if(in_array(strtolower($toStatus),['confirmed','preparing','ready','out_for_delivery','completed','cancelled'],true)){
            try{
                $payload=json_encode(['order_id'=>$orderId,'status'=>strtolower($toStatus)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
                $dedupe='delivery-customer-push:'.$orderId.':'.strtolower($toStatus).':'.$historyId;
                $sql=Database::portableSql($pdo,'INSERT IGNORE INTO background_jobs (tenant_id,type,payload,dedupe_key,status,attempts,max_attempts,run_at) VALUES (?,"delivery.customer.push",?, ?,"pending",0,5,CURRENT_TIMESTAMP)');
                $pdo->prepare($sql)->execute([$tenantId,$payload,$dedupe]);
            }catch(\Throwable){/* notificação não bloqueia a operação do pedido */}
        }

        try{
            $normalized=strtolower(trim($toStatus));
            $event=$fromStatus===null?'order_received':match($normalized){
                'preparing'=>'preparing','ready'=>'ready','out_for_delivery'=>'out_for_delivery','completed'=>'delivered','cancelled'=>'cancelled',default=>null,
            };
            if($event!==null)(new WhatsAppIntegrationService())->queueOrderEvent($pdo,$tenantId,$orderId,$event,'history:'.$historyId);
        }catch(\Throwable){/* WhatsApp beta nunca bloqueia a transação principal */}
    }

    public function timeline(int $orderId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido inválido.');$pdo=Database::connection();
        $o=$pdo->prepare('SELECT id,assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $allowed=Auth::can('orders.view')||Auth::can('orders.manage')||Auth::can('orders.kitchen')||Auth::can('orders.dispatch')||Auth::can('payments.manage');
        if(!$allowed&&Auth::can('orders.delivery')&&(int)($order['assigned_delivery_user_id']??0)===(int)Auth::id())$allowed=true;
        if(!$allowed)throw new RuntimeException('Acesso negado ao histórico deste pedido.');
        $s=$pdo->prepare('SELECT h.id,h.from_status,h.to_status,h.source,h.notes,h.created_at,u.name user_name FROM order_status_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.tenant_id=? AND h.order_id=? ORDER BY h.id');$s->execute([$tenantId,$orderId]);return$s->fetchAll();
    }
}
