<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use Throwable;

final class WhatsAppCommerceStatusSyncService
{
    public function syncCurrentTenant(?PDO $pdo=null,?int $tenantId=null,int $limit=100):int
    {
        $pdo??=Database::connection();
        $tenantId??=(int)(Auth::tenantId()??0);
        if($tenantId<1)return 0;
        $limit=max(1,min(250,$limit));
        $since=gmdate('Y-m-d H:i:s',time()-86400);
        $sql="SELECT c.id conversation_id,c.active_order_id order_id,o.status,o.created_at
              FROM whatsapp_conversations c
              JOIN orders o ON o.id=c.active_order_id AND o.tenant_id=c.tenant_id
              WHERE c.tenant_id=?
                AND c.mode='auto'
                AND c.state IN ('CHOOSING_PAYMENT','ORDER_ACTIVE')
                AND o.order_source='WHATSAPP'
                AND o.payment_status='paid'
                AND o.status IN ('confirmed','preparing','ready','out_for_delivery','completed')
                AND o.created_at>=?
                AND NOT EXISTS (
                    SELECT 1 FROM whatsapp_outbox w
                    WHERE w.tenant_id=o.tenant_id AND w.order_id=o.id AND w.event_type='order_confirmed'
                )
              ORDER BY o.id
              LIMIT ".$limit;
        $q=$pdo->prepare($sql);$q->execute([$tenantId,$since]);$queued=0;
        $integration=new WhatsAppIntegrationService();
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            try{
                if($integration->queueOrderEvent($pdo,$tenantId,(int)$row['order_id'],'order_confirmed','payment-confirmed')!==null)$queued++;
            }catch(Throwable $e){error_log('[whatsapp-commerce-status-sync] '.$e::class.': '.$e->getMessage());}
        }
        return $queued;
    }
}
