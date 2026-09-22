<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCommerceService
{
    private const MODES=['auto','waiting_human','human'];
    private const MESSAGE_TYPES=['text','image','audio','video','document','sticker','contact','location','unknown'];
    private const HUMAN_COMMANDS=['atendente','humano','falar com atendente','quero falar com atendente','falar com humano','quero falar com humano'];
    private const MENU_COMMANDS=['menu','inicio','início','ajuda'];

    /** @return array<string,mixed> */
    public function receiveInbound(string $deviceId,array $input):array
    {
        $tenantId=(int)(Auth::tenantId()??0);
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $deviceId=trim($deviceId);
        if(strlen($deviceId)<8)throw new RuntimeException('Dispositivo inválido.');

        $providerId=mb_substr(trim((string)($input['provider_message_id']??'')),0,190);
        if($providerId===''||!preg_match('/^[A-Za-z0-9._:\-]{4,190}$/',$providerId))throw new RuntimeException('Identificação da mensagem inválida.');
        $phone=$this->normalizePhone((string)($input['phone']??''));
        if($phone==='')throw new RuntimeException('Telefone do remetente inválido.');
        $name=mb_substr(trim((string)($input['name']??'')),0,180);
        $type=strtolower(trim((string)($input['message_type']??'text')));
        if(!in_array($type,self::MESSAGE_TYPES,true))$type='unknown';
        $text=mb_substr(trim((string)($input['text']??'')),0,4000);
        $payload=$this->safePayload($input['payload']??null);
        $providerCreatedAt=$this->providerDate($input['timestamp']??null);
        $deviceHash=hash('sha256',$deviceId);

        return Database::transaction(function(PDO $pdo)use($tenantId,$deviceHash,$providerId,$phone,$name,$type,$text,$payload,$providerCreatedAt):array{
            $agent=$pdo->prepare('SELECT id FROM whatsapp_desktop_agents WHERE tenant_id=? AND device_hash=? LIMIT 1');
            $agent->execute([$tenantId,$deviceHash]);
            if(!$agent->fetchColumn())throw new RuntimeException('EventMenu Connect não registrado para esta empresa.');

            $duplicate=$pdo->prepare('SELECT conversation_id FROM whatsapp_messages WHERE tenant_id=? AND provider_message_id=? LIMIT 1');
            $duplicate->execute([$tenantId,$providerId]);
            $existingConversation=(int)($duplicate->fetchColumn()?:0);
            if($existingConversation>0)return ['duplicate'=>true,'conversation_id'=>$existingConversation,'reply_queued'=>false];

            $conversation=$this->lockConversation($pdo,$tenantId,$phone);
            if(!$conversation){
                try{
                    $pdo->prepare('INSERT INTO whatsapp_conversations (tenant_id,phone,mode,state,last_inbound_at,last_activity_at) VALUES (?,?,\'auto\',\'IDLE\',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([$tenantId,$phone]);
                }catch(\PDOException){
                    // Another worker may have created the conversation between SELECT and INSERT.
                }
                $conversation=$this->lockConversation($pdo,$tenantId,$phone);
                if(!$conversation)throw new RuntimeException('Não foi possível abrir a conversa.');
            }

            $conversationId=(int)$conversation['id'];
            $payloadJson=$payload===null?null:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            try{
                $pdo->prepare('INSERT INTO whatsapp_messages (tenant_id,conversation_id,provider_message_id,direction,message_type,message_text,payload_json,status,provider_created_at) VALUES (?,?,?,\'inbound\',?,?,?,\'received\',?)')
                    ->execute([$tenantId,$conversationId,$providerId,$type,$text!==''?$text:null,$payloadJson,$providerCreatedAt]);
            }catch(\PDOException $e){
                $duplicate->execute([$tenantId,$providerId]);
                $existingConversation=(int)($duplicate->fetchColumn()?:0);
                if($existingConversation>0)return ['duplicate'=>true,'conversation_id'=>$existingConversation,'reply_queued'=>false];
                throw $e;
            }

            $pdo->prepare('UPDATE whatsapp_conversations SET last_inbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);

            $mode=(string)($conversation['mode']??'auto');
            if(!in_array($mode,self::MODES,true))$mode='auto';
            if($mode==='human'||$mode==='waiting_human')return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>$mode,'reply_queued'=>false];

            $commerceEnabled=$this->commerceEnabled($pdo,$tenantId);
            if(!$commerceEnabled)return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>(string)($conversation['state']??'IDLE'),'reply_queued'=>false,'commerce_enabled'=>false];

            $normalized=$this->normalizeCommand($text);
            if(in_array($normalized,self::HUMAN_COMMANDS,true)){
                $pdo->prepare('UPDATE whatsapp_conversations SET mode=\'waiting_human\',state=\'WAITING_HUMAN\',assigned_user_id=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);
                $replyQueued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'human_transfer','Certo! 👤 Seu atendimento foi encaminhado para a equipe. O atendimento automático ficará pausado enquanto você aguarda.');
                try{Auth::audit('whatsapp.conversation_waiting_human','whatsapp_conversation',(string)$conversationId,['phone_suffix'=>substr($phone,-4)]);}catch(Throwable){}
                return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'waiting_human','state'=>'WAITING_HUMAN','reply_queued'=>$replyQueued,'commerce_enabled'=>true];
            }

            if(in_array($normalized,self::MENU_COMMANDS,true)||$normalized==='cancelar'||(string)($conversation['state']??'IDLE')==='IDLE'){
                $pdo->prepare('UPDATE whatsapp_conversations SET state=\'WELCOME\',context_json=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);
                $reply=$this->welcomeMessage($name);
                $queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'welcome',$reply);
                return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>'WELCOME','reply_queued'=>$queued,'commerce_enabled'=>true];
            }

            $queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'fallback',"Não consegui entender sua resposta.\n\nDigite *MENU* para voltar ao início ou *ATENDENTE* para falar com uma pessoa.");
            return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>(string)($conversation['state']??'WELCOME'),'reply_queued'=>$queued,'commerce_enabled'=>true];
        });
    }

    /** @return array<string,mixed>|false */
    private function lockConversation(PDO $pdo,int $tenantId,string $phone):array|false
    {
        $sql=Database::portableSql($pdo,'SELECT * FROM whatsapp_conversations WHERE tenant_id=? AND phone=? LIMIT 1 FOR UPDATE');
        $q=$pdo->prepare($sql);$q->execute([$tenantId,$phone]);return $q->fetch(PDO::FETCH_ASSOC);
    }

    private function commerceEnabled(PDO $pdo,int $tenantId):bool
    {
        $connection=(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);
        return (int)($connection['commerce_enabled']??0)===1;
    }

    private function queueReply(PDO $pdo,int $tenantId,int $conversationId,string $phone,string $providerId,string $kind,string $message):bool
    {
        $key=hash('sha256','commerce-reply|'.$tenantId.'|'.$providerId.'|'.$kind);
        try{
            $pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,NULL,\'commerce_auto\',?,?,\'desktop_queued\',0,5,CURRENT_TIMESTAMP,?)')
                ->execute([$tenantId,$phone,$message,$key]);
            $outboxId=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO whatsapp_messages (tenant_id,conversation_id,outbox_id,direction,message_type,message_text,status) VALUES (?,?,?,\'outbound\',\'text\',?,\'queued\')')
                ->execute([$tenantId,$conversationId,$outboxId,$message]);
            $pdo->prepare('UPDATE whatsapp_conversations SET last_outbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);
            return true;
        }catch(\PDOException $e){
            $q=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');$q->execute([$key]);
            if($q->fetchColumn())return false;
            throw $e;
        }
    }

    private function welcomeMessage(string $name):string
    {
        $hello=$name!==''?'Olá, '.$name.'! 👋':'Olá! 👋';
        return $hello."\n\nO atendimento do EventMenu pelo WhatsApp está ativo.\n\nDigite *ATENDENTE* a qualquer momento para falar com uma pessoa.\nDigite *MENU* para voltar ao início.";
    }

    private function normalizeCommand(string $value):string
    {
        $value=mb_strtolower(trim($value));
        $value=preg_replace('/\s+/u',' ',$value)??$value;
        return trim($value," \t\n\r\0\x0B.!?,;:");
    }

    private function normalizePhone(string $value):string
    {
        $digits=preg_replace('/\D+/','',$value)??'';$digits=ltrim($digits,'0');
        if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
        return preg_match('/^\d{12,15}$/',$digits)?$digits:'';
    }

    /** @return array<string,mixed>|null */
    private function safePayload(mixed $payload):?array
    {
        if(!is_array($payload)||!$payload)return null;
        $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($encoded)||strlen($encoded)>16384)return ['truncated'=>true];
        return $payload;
    }

    private function providerDate(mixed $value):?string
    {
        if($value===null||$value==='')return null;
        if(is_numeric($value)){
            $timestamp=(int)$value;
            if($timestamp>20000000000)$timestamp=(int)floor($timestamp/1000);
            if($timestamp>0&&$timestamp<4102444800)return gmdate('Y-m-d H:i:s',$timestamp);
            return null;
        }
        $timestamp=strtotime((string)$value);
        return $timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp);
    }
}
