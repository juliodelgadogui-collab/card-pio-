<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppOrderReceivedSyncService
{
    public function syncCurrentTenant(int $limit=50): int
    {
        $tenantId=(int)(Auth::tenantId()??0);
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        return $this->syncForTenant(Database::connection(),$tenantId,$limit);
    }

    public function syncForTenant(PDO $pdo,int $tenantId,int $limit=50): int
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $limit=max(1,min(100,$limit));
        $sourceFilter=$this->hasColumn($pdo,'orders','order_source')?' AND COALESCE(o.order_source,\'\')<>\'WHATSAPP\'':'';
        $sql='SELECT o.id,o.status
              FROM orders o
              JOIN whatsapp_connections wc ON wc.tenant_id=o.tenant_id
              WHERE o.tenant_id=?
                AND wc.automation_enabled=1
                AND wc.automation_enabled_at IS NOT NULL
                AND o.created_at>=wc.automation_enabled_at
                AND o.channel IN (\'delivery\',\'pickup\',\'counter\')
                AND o.status IN (\'pending\',\'confirmed\')
                AND o.customer_id IS NOT NULL'
                .$sourceFilter.
              ' ORDER BY o.id DESC LIMIT '.($limit*3);
        $q=$pdo->prepare($sql);$q->execute([$tenantId]);
        $service=new WhatsAppIntegrationService();$queued=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as$row){
            if($queued>=$limit)break;
            $orderId=(int)$row['id'];
            $exists=$pdo->prepare("SELECT id FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_received' LIMIT 1");$exists->execute([$tenantId,$orderId]);
            if($exists->fetchColumn())continue;
            if((string)$row['status']==='confirmed'){
                $later=$pdo->prepare("SELECT id FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type IN ('order_confirmed','preparing','ready','out_for_delivery','delivered') LIMIT 1");$later->execute([$tenantId,$orderId]);
                if($later->fetchColumn())continue;
            }
            try{if($service->queueOrderEvent($pdo,$tenantId,$orderId,'order_received')!==null)$queued++;}
            catch(Throwable $e){error_log('[whatsapp-order-received-sync] '.$e::class.': '.$e->getMessage());}
        }
        return $queued;
    }

    private function hasColumn(PDO $pdo,string $table,string $column): bool
    {
        try{
            $driver=strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if($driver==='sqlite'){
                $q=$pdo->query('PRAGMA table_info('.$table.')');
                foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as$row)if(strtolower((string)($row['name']??''))===strtolower($column))return true;
                return false;
            }
            $q=$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE ".$pdo->quote($column));
            return(bool)$q->fetch(PDO::FETCH_ASSOC);
        }catch(Throwable){return false;}
    }
}
