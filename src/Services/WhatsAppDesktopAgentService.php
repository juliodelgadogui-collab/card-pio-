<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class WhatsAppDesktopAgentService
{
    private const PROVIDER = 'desktop_baileys';
    private const STATUSES = ['disconnected','starting','qr','connected','reconnecting','error'];

    /** @return array<string,mixed> */
    public function heartbeat(string $deviceId,string $deviceLabel,string $status,string $phone='',string $error=''):array
    {
        $tenantId=$this->tenantId();
        $deviceHash=$this->deviceHash($deviceId);
        $deviceLabel=mb_substr(trim($deviceLabel),0,190);
        $status=strtolower(trim($status));
        if(!in_array($status,self::STATUSES,true))$status='error';
        $phone=$this->normalizePhone($phone);
        $error=mb_substr(trim($error),0,500);

        $pdo=Database::connection();
        (new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);

        $find=$pdo->prepare('SELECT id FROM whatsapp_desktop_agents WHERE tenant_id=? AND device_hash=? LIMIT 1');
        $find->execute([$tenantId,$deviceHash]);
        $id=(int)($find->fetchColumn()?:0);
        if($id>0){
            $s=$pdo->prepare('UPDATE whatsapp_desktop_agents SET device_label=?,status=?,phone_number=?,last_seen_at=CURRENT_TIMESTAMP,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');
            $s->execute([$deviceLabel?:null,$status,$phone?:null,$error?:null,$id,$tenantId]);
        }else{
            $s=$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,phone_number,engine,last_seen_at,last_error) VALUES (?,?,?,?,?,\'baileys\',CURRENT_TIMESTAMP,?)');
            $s->execute([$tenantId,$deviceHash,$deviceLabel?:null,$status,$phone?:null,$error?:null]);
            $id=(int)$pdo->lastInsertId();
            Auth::audit('whatsapp.desktop_registered','whatsapp_desktop_agent',(string)$id,['device_label'=>$deviceLabel]);
        }

        if($status==='connected'){
            $pdo->prepare('UPDATE whatsapp_connections SET provider=?,status=?,phone_number=?,last_connected_at=CASE WHEN status<>\'connected\' THEN CURRENT_TIMESTAMP ELSE last_connected_at END,last_seen_at=CURRENT_TIMESTAMP,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')
                ->execute([self::PROVIDER,$status,$phone?:null,$tenantId]);
        }else{
            $pdo->prepare('UPDATE whatsapp_connections SET provider=?,status=?,phone_number=?,last_seen_at=CURRENT_TIMESTAMP,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')
                ->execute([self::PROVIDER,$status,$phone?:null,$error?:null,$tenantId]);
        }

        return $this->state($deviceId);
    }

    /** @return array<string,mixed> */
    public function state(string $deviceId):array
    {
        $tenantId=$this->tenantId();$deviceHash=$this->deviceHash($deviceId);$pdo=Database::connection();
        $q=$pdo->prepare('SELECT id,device_label,status,phone_number,engine,last_seen_at,last_error,created_at,updated_at FROM whatsapp_desktop_agents WHERE tenant_id=? AND device_hash=? LIMIT 1');
        $q->execute([$tenantId,$deviceHash]);$agent=$q->fetch(PDO::FETCH_ASSOC)?:null;
        $c=$pdo->prepare('SELECT provider,status,phone_number,automation_enabled,commerce_enabled,last_connected_at,last_seen_at,last_error FROM whatsapp_connections WHERE tenant_id=? LIMIT 1');$c->execute([$tenantId]);$connection=$c->fetch(PDO::FETCH_ASSOC)?:null;
        $p=$pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND status IN (\'queued\',\'failed\',\'desktop_queued\',\'desktop_failed\') AND attempt_count<max_attempts');$p->execute([$tenantId]);
        return ['agent'=>$agent,'connection'=>$connection,'pending'=>(int)$p->fetchColumn(),'provider'=>self::PROVIDER];
    }

    /** @return array<int,array<string,mixed>> */
    public function claim(string $deviceId,int $limit=5):array
    {
        $tenantId=$this->tenantId();$deviceHash=$this->deviceHash($deviceId);$limit=max(1,min(20,$limit));
        $this->assertAgent($tenantId,$deviceHash);
        return Database::transaction(function(PDO $pdo)use($tenantId,$deviceHash,$limit):array{
            $pdo->prepare('UPDATE whatsapp_outbox SET status=\'desktop_failed\',locked_at=NULL,claim_token=NULL,claimed_by_device_hash=NULL,claim_expires_at=NULL,last_error=COALESCE(last_error,\'Lease do Desktop expirou.\'),updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND status=\'desktop_sending\' AND claim_expires_at IS NOT NULL AND claim_expires_at<CURRENT_TIMESTAMP')->execute([$tenantId]);
            $sql='SELECT id FROM whatsapp_outbox WHERE tenant_id=? AND status IN (\'queued\',\'failed\',\'desktop_queued\',\'desktop_failed\') AND attempt_count<max_attempts AND available_at<=CURRENT_TIMESTAMP ORDER BY id LIMIT '.$limit.' FOR UPDATE';
            $select=$pdo->prepare(Database::portableSql($pdo,$sql));$select->execute([$tenantId]);$ids=array_map('intval',$select->fetchAll(PDO::FETCH_COLUMN)?:[]);$out=[];
            foreach($ids as$id){
                $token=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+120);
                $u=$pdo->prepare('UPDATE whatsapp_outbox SET status=\'desktop_sending\',attempt_count=attempt_count+1,locked_at=CURRENT_TIMESTAMP,claim_token=?,claimed_by_device_hash=?,claim_expires_at=?,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status IN (\'queued\',\'failed\',\'desktop_queued\',\'desktop_failed\')');
                $u->execute([$token,$deviceHash,$expires,$id,$tenantId]);if($u->rowCount()!==1)continue;
                $this->syncConversationMessage($pdo,$tenantId,$id,'sending');
                $q=$pdo->prepare('SELECT id,order_id,event_type,recipient,message_text,payload_json,attempt_count,max_attempts,claim_token,claim_expires_at,created_at FROM whatsapp_outbox WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$id,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
                if($row){
                    $media=$this->mediaFromPayload($row['payload_json']??null);
                    unset($row['payload_json']);
                    $out[]=array_merge($row,$media);
                }
            }
            return$out;
        });
    }

    /** @return array<string,mixed> */
    public function acknowledge(string $deviceId,int $id,string $claimToken,string $externalMessageId=''):array
    {
        $tenantId=$this->tenantId();$deviceHash=$this->deviceHash($deviceId);$claimToken=$this->claimToken($claimToken);if($id<1)throw new RuntimeException('Mensagem inválida.');
        $externalMessageId=mb_substr(trim($externalMessageId),0,190);$pdo=Database::connection();
        $s=$pdo->prepare('UPDATE whatsapp_outbox SET status=\'sent\',provider=?,sent_at=CURRENT_TIMESTAMP,locked_at=NULL,external_message_id=?,last_error=NULL,claim_token=NULL,claimed_by_device_hash=NULL,claim_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status=\'desktop_sending\' AND claim_token=? AND claimed_by_device_hash=?');
        $s->execute([self::PROVIDER,$externalMessageId?:null,$id,$tenantId,$claimToken,$deviceHash]);if($s->rowCount()!==1)throw new RuntimeException('A reserva desta mensagem expirou ou pertence a outro computador.');
        $this->syncCommunicationRecipient($pdo,$tenantId,$id,'sent');
        $this->syncConversationMessage($pdo,$tenantId,$id,'sent',$externalMessageId);
        return['id'=>$id,'status'=>'sent','external_message_id'=>$externalMessageId?:null];
    }

    /** @return array<string,mixed> */
    public function fail(string $deviceId,int $id,string $claimToken,string $error):array
    {
        $tenantId=$this->tenantId();$deviceHash=$this->deviceHash($deviceId);$claimToken=$this->claimToken($claimToken);if($id<1)throw new RuntimeException('Mensagem inválida.');$error=mb_substr(trim($error),0,500);if($error==='')$error='Falha no envio local do WhatsApp.';
        return Database::transaction(function(PDO $pdo)use($tenantId,$deviceHash,$claimToken,$id,$error):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT attempt_count,max_attempts FROM whatsapp_outbox WHERE id=? AND tenant_id=? AND status=\'desktop_sending\' AND claim_token=? AND claimed_by_device_hash=? LIMIT 1 FOR UPDATE'));$q->execute([$id,$tenantId,$claimToken,$deviceHash]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('A reserva desta mensagem expirou ou pertence a outro computador.');
            $attempt=(int)$row['attempt_count'];$delay=min(3600,30*(2**max(0,$attempt-1)));$available=gmdate('Y-m-d H:i:s',time()+$delay);$terminal=$attempt>=(int)$row['max_attempts'];
            $s=$pdo->prepare('UPDATE whatsapp_outbox SET status=\'desktop_failed\',locked_at=NULL,available_at=?,last_error=?,claim_token=NULL,claimed_by_device_hash=NULL,claim_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND claim_token=? AND claimed_by_device_hash=?');$s->execute([$available,$error,$id,$tenantId,$claimToken,$deviceHash]);
            $this->syncCommunicationRecipient($pdo,$tenantId,$id,$terminal?'failed':'retrying');
            $this->syncConversationMessage($pdo,$tenantId,$id,$terminal?'failed':'retrying');
            return['id'=>$id,'status'=>'desktop_failed','attempt_count'=>$attempt,'max_attempts'=>(int)$row['max_attempts'],'available_at'=>$available];
        });
    }

    private function syncConversationMessage(PDO $pdo,int $tenantId,int $outboxId,string $status,string $providerMessageId=''):void
    {
        try{
            if($providerMessageId!==''){
                $pdo->prepare('UPDATE whatsapp_messages SET status=?,provider_message_id=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND outbox_id=?')->execute([$status,$providerMessageId,$tenantId,$outboxId]);
            }else{
                $pdo->prepare('UPDATE whatsapp_messages SET status=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND outbox_id=?')->execute([$status,$tenantId,$outboxId]);
            }
        }catch(\Throwable $e){error_log('[whatsapp-message-sync] '.$e::class.': '.$e->getMessage());}
    }

    private function syncCommunicationRecipient(PDO $pdo,int $tenantId,int $outboxId,string $status):void
    {
        try{
            $u=$pdo->prepare('UPDATE whatsapp_communication_recipients SET status=? WHERE tenant_id=? AND outbox_id=?');$u->execute([$status,$tenantId,$outboxId]);if($u->rowCount()<1)return;
            $q=$pdo->prepare('SELECT communication_id FROM whatsapp_communication_recipients WHERE tenant_id=? AND outbox_id=? LIMIT 1');$q->execute([$tenantId,$outboxId]);$communicationId=(int)$q->fetchColumn();if($communicationId<1)return;
            $counts=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN status=\'sent\' THEN 1 ELSE 0 END) sent,SUM(CASE WHEN status=\'failed\' THEN 1 ELSE 0 END) failed,SUM(CASE WHEN status IN (\'queued\',\'retrying\') THEN 1 ELSE 0 END) pending FROM whatsapp_communication_recipients WHERE tenant_id=? AND communication_id=?');$counts->execute([$tenantId,$communicationId]);$row=$counts->fetch(PDO::FETCH_ASSOC)?:[];
            $total=(int)($row['total']??0);$sent=(int)($row['sent']??0);$failed=(int)($row['failed']??0);$pending=(int)($row['pending']??0);$campaignStatus=$pending>0?'sending':($failed>0&&$sent===0?'failed':($failed>0?'partial':'sent'));
            $pdo->prepare('UPDATE whatsapp_communications SET status=?,recipient_count=?,queued_count=?,sent_count=?,failed_count=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$campaignStatus,$total,$pending,$sent,$failed,$communicationId,$tenantId]);
        }catch(\Throwable $e){error_log('[whatsapp-communication-sync] '.$e::class.': '.$e->getMessage());}
    }

    /** @return array<string,string> */
    private function mediaFromPayload(mixed $raw):array
    {
        if(!is_string($raw)||trim($raw)==='')return[];
        $payload=json_decode($raw,true);
        if(!is_array($payload))return[];

        $type=strtolower(trim((string)($payload['media_type']??'')));
        if($type==='pdf')$type='document';
        if(!in_array($type,['image','document'],true))return[];

        $url=trim((string)($payload['media_url']??''));
        $parts=$url!==''?parse_url($url):false;
        $scheme=is_array($parts)?strtolower((string)($parts['scheme']??'')):'';
        $host=is_array($parts)?trim((string)($parts['host']??'')):'';
        if(!in_array($scheme,['http','https'],true)||$host==='')return[];

        $filename=mb_substr(trim((string)($payload['media_filename']??'')),0,190);
        $mime=mb_substr(strtolower(trim((string)($payload['media_mime']??''))),0,120);
        if($type==='document'&&$filename==='')$filename='documento.pdf';
        if($type==='document'&&$mime==='')$mime='application/pdf';

        return[
            'media_type'=>$type,
            'media_url'=>$url,
            'media_filename'=>$filename,
            'media_mime'=>$mime,
        ];
    }

    private function assertAgent(int $tenantId,string $deviceHash):void
    {
        $q=Database::connection()->prepare('SELECT id FROM whatsapp_desktop_agents WHERE tenant_id=? AND device_hash=? LIMIT 1');$q->execute([$tenantId,$deviceHash]);if(!$q->fetchColumn())throw new RuntimeException('Este computador ainda não registrou o EventMenu WhatsApp Connect.');
    }
    private function tenantId():int{$tenantId=(int)(Auth::tenantId()??0);if($tenantId<1)throw new RuntimeException('Empresa inválida.');return$tenantId;}
    private function deviceHash(string $deviceId):string{$deviceId=trim($deviceId);if(strlen($deviceId)<8)throw new RuntimeException('Identificação do computador inválida.');return hash('sha256',$deviceId);}
    private function claimToken(string $value):string{$value=strtolower(trim($value));if(!preg_match('/^[a-f0-9]{64}$/',$value))throw new RuntimeException('Reserva de mensagem inválida.');return$value;}
    private function normalizePhone(string$value):string{$digits=preg_replace('/\D+/','',$value)??'';$digits=ltrim($digits,'0');if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;return preg_match('/^\d{12,15}$/',$digits)?$digits:'';}
}
