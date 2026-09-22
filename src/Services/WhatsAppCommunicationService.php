<?php
declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class WhatsAppCommunicationService
{
    public function list(PDO $pdo,int $tenantId):array
    {
        $s=$pdo->prepare('SELECT * FROM whatsapp_communications WHERE tenant_id=? ORDER BY id DESC LIMIT 100');$s->execute([$tenantId]);return $s->fetchAll();
    }

    public function events(PDO $pdo,int $tenantId):array
    {
        $s=$pdo->prepare('SELECT id,name,starts_at FROM events WHERE tenant_id=? ORDER BY starts_at DESC,id DESC LIMIT 200');$s->execute([$tenantId]);return $s->fetchAll();
    }

    public function preview(PDO $pdo,int $tenantId,array $data):array
    {
        return $this->resolveRecipients($pdo,$tenantId,$data);
    }

    public function createAndQueue(PDO $pdo,int $tenantId,?int $userId,array $data):array
    {
        $title=mb_substr(trim((string)($data['title']??'')),0,180);$message=mb_substr(trim((string)($data['message']??'')),0,4000);
        if($title===''||$message==='')throw new RuntimeException('Informe o título interno e a mensagem.');
        $audience=(string)($data['audience_type']??'manual');$allowed=['manual','customers','event_buyers'];if(!in_array($audience,$allowed,true))throw new RuntimeException('Público inválido.');
        $mediaType=(string)($data['media_type']??'');if(!in_array($mediaType,['','image','document'],true))throw new RuntimeException('Tipo de anexo inválido.');
        $mediaUrl=trim((string)($data['media_url']??''));if($mediaType!==''&&!filter_var($mediaUrl,FILTER_VALIDATE_URL))throw new RuntimeException('Informe uma URL HTTPS válida para o anexo.');
        if($mediaUrl!==''&&strtolower((string)parse_url($mediaUrl,PHP_URL_SCHEME))!=='https')throw new RuntimeException('O anexo deve usar HTTPS.');
        $recipients=$this->resolveRecipients($pdo,$tenantId,$data);if(!$recipients)throw new RuntimeException('Nenhum destinatário válido foi encontrado.');
        $audienceJson=json_encode(['event_id'=>(int)($data['event_id']??0)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $ins=$pdo->prepare('INSERT INTO whatsapp_communications (tenant_id,title,message_text,audience_type,audience_json,media_type,media_url,media_filename,media_mime,status,recipient_count,queued_count,created_by) VALUES (?,?,?,?,?,?,?,?,?,"queued",?,?,?)');
        $ins->execute([$tenantId,$title,$message,$audience,$audienceJson,$mediaType?:null,$mediaUrl?:null,mb_substr(trim((string)($data['media_filename']??'')),0,255)?:null,mb_substr(trim((string)($data['media_mime']??'')),0,120)?:null,count($recipients),count($recipients),$userId]);
        $communicationId=(int)$pdo->lastInsertId();$rec=$pdo->prepare('INSERT INTO whatsapp_communication_recipients (tenant_id,communication_id,customer_id,recipient,recipient_name,outbox_id,status) VALUES (?,?,?,?,?,?,"queued")');$out=$pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,payload_json,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,NULL,"communication",?,?,?,"desktop_queued",0,5,CURRENT_TIMESTAMP,?)');
        foreach($recipients as$row){$payload=null;if($mediaType!=='')$payload=json_encode(['media_type'=>$mediaType,'media_url'=>$mediaUrl,'media_filename'=>(string)($data['media_filename']??''),'media_mime'=>(string)($data['media_mime']??''),'communication_id'=>$communicationId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$key=hash('sha256','communication|'.$tenantId.'|'.$communicationId.'|'.$row['phone']);$out->execute([$tenantId,$row['phone'],$message,$payload,$key]);$outboxId=(int)$pdo->lastInsertId();$rec->execute([$tenantId,$communicationId,$row['customer_id']??null,$row['phone'],$row['name']??null,$outboxId]);}
        return ['id'=>$communicationId,'recipient_count'=>count($recipients)];
    }

    private function resolveRecipients(PDO $pdo,int $tenantId,array $data):array
    {
        $type=(string)($data['audience_type']??'manual');$rows=[];
        if($type==='customers'){$s=$pdo->prepare('SELECT id customer_id,name,phone FROM customers WHERE tenant_id=? AND phone IS NOT NULL AND TRIM(phone)<>"" ORDER BY id DESC LIMIT 5000');$s->execute([$tenantId]);$rows=$s->fetchAll();}
        elseif($type==='event_buyers'){$eventId=(int)($data['event_id']??0);if($eventId<1)throw new RuntimeException('Selecione o evento.');$s=$pdo->prepare('SELECT DISTINCT c.id customer_id,c.name,c.phone FROM orders o JOIN tickets t ON t.order_id=o.id AND t.tenant_id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id WHERE o.tenant_id=? AND t.event_id=? AND o.payment_status="paid" AND c.phone IS NOT NULL AND TRIM(c.phone)<>"" ORDER BY c.id DESC LIMIT 5000');$s->execute([$tenantId,$eventId]);$rows=$s->fetchAll();}
        else{$raw=preg_split('/[\r\n,;]+/',(string)($data['manual_recipients']??''))?:[];foreach($raw as$value)$rows[]=['customer_id'=>null,'name'=>null,'phone'=>$value];}
        $out=[];foreach($rows as$row){$phone=$this->normalizePhone((string)($row['phone']??''));if($phone==='')continue;$out[$phone]=['phone'=>$phone,'name'=>(string)($row['name']??''),'customer_id'=>isset($row['customer_id'])?(int)$row['customer_id']:null];}return array_values($out);
    }

    private function normalizePhone(string $phone):string
    {
        $digits=preg_replace('/\D+/','',$phone)??'';if(strlen($digits)<10)return'';if(!str_starts_with($digits,'55'))$digits='55'.$digits;return strlen($digits)>=12&&strlen($digits)<=13?$digits:'';
    }
}
