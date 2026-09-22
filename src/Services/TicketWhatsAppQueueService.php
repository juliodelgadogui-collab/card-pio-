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
            $queued+=$this->queuePaidOrder($pdo,$tenantId,(int)$row['order_id']);
        }
        return $queued;
    }

    public function queuePaidOrder(PDO $pdo,int $tenantId,int $orderId): int
    {
        if($tenantId<1||$orderId<1)return 0;
        $eventCutoff=gmdate('Y-m-d H:i:s',time()-86400);
        $q=$pdo->prepare('SELECT o.id order_id,o.public_token,c.name customer_name,c.phone customer_phone,
                                t.id ticket_id,t.code,t.qr_token,e.name event_name,e.starts_at,e.venue,e.address,
                                b.name batch_name,tt.name ticket_type_name,tt.access_area
                         FROM orders o
                         JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id
                         JOIN tickets t ON t.order_id=o.id AND t.tenant_id=o.tenant_id
                         JOIN events e ON e.id=t.event_id AND e.tenant_id=t.tenant_id
                         JOIN ticket_batches b ON b.id=t.batch_id
                         LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id
                         WHERE o.id=? AND o.tenant_id=? AND o.channel="event" AND o.payment_status="paid"
                           AND o.status<>"cancelled" AND t.status="paid"
                           AND (e.ends_at IS NULL OR e.ends_at>=CURRENT_TIMESTAMP)
                           AND (e.starts_at IS NULL OR e.starts_at>=?)
                         ORDER BY t.id ASC');
        $q->execute([$orderId,$tenantId,$eventCutoff]);$tickets=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$tickets)return 0;

        $recipient=$this->normalizePhone((string)($tickets[0]['customer_phone']??''));
        if($recipient==='')return 0;
        $queued=0;
        foreach($tickets as$row){
            $ticketId=(int)$row['ticket_id'];$qr=trim((string)$row['qr_token']);
            if($ticketId<1||$qr==='')continue;
            $key=hash('sha256','eventmenu-connect-ticket-media|tenant:'.$tenantId.'|ticket:'.$ticketId.'|v1');
            $exists=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');$exists->execute([$key]);
            if($exists->fetchColumn())continue;

            $customer=trim((string)($row['customer_name']??''));$event=trim((string)($row['event_name']??''));
            $ticketUrl=\app_absolute_url('ingresso.php?t='.rawurlencode($qr));
            $imageUrl=\app_absolute_url('ingresso-imagem.php?t='.rawurlencode($qr));
            $details=[];if(!empty($row['starts_at']))$details[]=date('d/m/Y · H:i',strtotime((string)$row['starts_at']));
            if(trim((string)($row['venue']??''))!=='')$details[]=trim((string)$row['venue']);
            $message='Olá'.($customer!==''?', '.$customer:'').'! 🎟️'."\n\n"
                .'Pagamento confirmado'.($event!==''?' para '.$event:'').'.'."\n"
                .'Ingresso: '.(string)$row['code'].($details?"\n".implode(' · ',$details):'')."\n"
                .'Apresente o QR Code desta imagem na entrada.'."\n"
                .'Ingresso online: '.$ticketUrl;
            $payload=json_encode(['media_type'=>'image','media_url'=>$imageUrl,'media_filename'=>'ingresso-'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$row['code']).'.png','media_mime'=>'image/png','ticket_id'=>$ticketId,'ticket_url'=>$ticketUrl],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            try{
                $insert=$pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,payload_json,status,available_at,idempotency_key) VALUES (?,?,?,?,?,?,"desktop_queued",CURRENT_TIMESTAMP,?)');
                $insert->execute([$tenantId,$orderId,self::EVENT_TYPE,$recipient,mb_substr($message,0,2000),$payload,$key]);$queued++;
            }catch(Throwable $e){$exists->execute([$key]);if(!$exists->fetchColumn())throw $e;}
        }
        return $queued;
    }

    private function normalizePhone(string $value): string
    {
        $digits=preg_replace('/\D+/','',$value)??'';$digits=ltrim($digits,'0');
        if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
        return preg_match('/^\d{12,15}$/',$digits)?$digits:'';
    }
}
