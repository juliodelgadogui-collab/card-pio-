<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class TicketWhatsAppQueueService
{
    private const EVENT_TYPE = 'ticket_issued';
    private const SCAN_LIMIT = 200;

    public function syncCurrentTenant(int $limit=20): int
    {
        $tenantId=(int)(Auth::tenantId()??0);
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        return $this->syncForTenant(Database::connection(),$tenantId,$limit);
    }

    public function syncForTenant(PDO $pdo,int $tenantId,int $limit=20): int
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $limit=max(1,min(100,$limit));
        $eventCutoff=gmdate('Y-m-d H:i:s',time()-86400);
        $sql='SELECT DISTINCT o.id order_id
              FROM orders o
              JOIN tickets t ON t.order_id=o.id AND t.tenant_id=o.tenant_id
              JOIN events e ON e.id=t.event_id AND e.tenant_id=t.tenant_id
              WHERE o.tenant_id=?
                AND o.channel="event"
                AND o.payment_status="paid"
                AND o.status<>"cancelled"
                AND t.status="paid"
                AND (e.ends_at IS NULL OR e.ends_at>=CURRENT_TIMESTAMP)
                AND (e.starts_at IS NULL OR e.starts_at>=?)
              ORDER BY o.id DESC
              LIMIT '.self::SCAN_LIMIT;
        $q=$pdo->prepare($sql);$q->execute([$tenantId,$eventCutoff]);
        $queued=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            if($queued>=$limit)break;
            if($this->queuePaidOrder($pdo,$tenantId,(int)$row['order_id']))$queued++;
        }
        return $queued;
    }

    public function queuePaidOrder(PDO $pdo,int $tenantId,int $orderId): bool
    {
        if($tenantId<1||$orderId<1)return false;
        $eventCutoff=gmdate('Y-m-d H:i:s',time()-86400);
        $q=$pdo->prepare('SELECT o.id order_id,o.public_token,o.payment_status,o.status,
                                c.name customer_name,c.phone customer_phone,e.name event_name
                         FROM orders o
                         JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id
                         JOIN tickets t ON t.order_id=o.id AND t.tenant_id=o.tenant_id
                         JOIN events e ON e.id=t.event_id AND e.tenant_id=t.tenant_id
                         WHERE o.id=? AND o.tenant_id=?
                           AND o.channel="event"
                           AND o.payment_status="paid"
                           AND o.status<>"cancelled"
                           AND t.status="paid"
                           AND (e.ends_at IS NULL OR e.ends_at>=CURRENT_TIMESTAMP)
                           AND (e.starts_at IS NULL OR e.starts_at>=?)
                         ORDER BY t.id ASC LIMIT 1');
        $q->execute([$orderId,$tenantId,$eventCutoff]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)return false;

        $recipient=$this->normalizePhone((string)($row['customer_phone']??''));
        $publicToken=trim((string)($row['public_token']??''));
        if($recipient===''||$publicToken==='')return false;

        $idempotencyKey=hash('sha256','eventmenu-connect-ticket|tenant:'.$tenantId.'|order:'.$orderId);
        $exists=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');
        $exists->execute([$idempotencyKey]);
        if($exists->fetchColumn())return false;

        $customer=trim((string)($row['customer_name']??''));
        $event=trim((string)($row['event_name']??''));
        $url=\app_absolute_url('evento-pedido.php?t='.rawurlencode($publicToken));
        $message='Olá'.($customer!==''?', '.$customer:'').'! 🎟️'."\n\n"
            .'Pagamento confirmado'.($event!==''?' para '.$event:'').'.'."\n"
            .'Seus ingressos estão prontos:'."\n"
            .$url."\n\n"
            .'Abra o link para visualizar seus ingressos e os QR Codes de entrada.';
        $message=mb_substr($message,0,2000);

        try{
            $insert=$pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,available_at,idempotency_key) VALUES (?,?,?,?,?,"desktop_queued",CURRENT_TIMESTAMP,?)');
            $insert->execute([$tenantId,$orderId,self::EVENT_TYPE,$recipient,$message,$idempotencyKey]);
            return true;
        }catch(Throwable $e){
            $exists->execute([$idempotencyKey]);
            if($exists->fetchColumn())return false;
            throw $e;
        }
    }

    private function normalizePhone(string $value): string
    {
        $digits=preg_replace('/\D+/','',$value)??'';
        $digits=ltrim($digits,'0');
        if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
        return preg_match('/^\d{12,15}$/',$digits)?$digits:'';
    }
}
