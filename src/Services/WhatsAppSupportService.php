<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class WhatsAppSupportService
{
    private const FILTERS=['all','waiting','mine','human','unread','auto'];

    /** @return array<int,array<string,mixed>> */
    public function inbox(PDO $pdo,int $tenantId,string $filter='all',string $search='',int $limit=100):array
    {
        if($tenantId<1)return[];$filter=strtolower(trim($filter));if(!in_array($filter,self::FILTERS,true))$filter='all';
        $search=mb_substr(trim($search),0,100);$limit=max(10,min(200,$limit));$where=['c.tenant_id=?'];$params=[$tenantId];
        if($filter==='waiting')$where[]="c.mode='waiting_human'";
        elseif($filter==='mine'){$where[]="c.mode='human'";$where[]='c.assigned_user_id=?';$params[]=(int)(Auth::id()??0);}
        elseif($filter==='human')$where[]="c.mode IN ('waiting_human','human')";
        elseif($filter==='auto')$where[]="c.mode='auto'";
        elseif($filter==='unread')$where[]="EXISTS (SELECT 1 FROM whatsapp_messages um WHERE um.tenant_id=c.tenant_id AND um.conversation_id=c.id AND um.direction='inbound' AND (c.last_read_at IS NULL OR um.created_at>c.last_read_at))";
        if($search!==''){$like='%'.$search.'%';$where[]='(c.phone LIKE ? OR COALESCE(cu.name,\'\') LIKE ? OR COALESCE(cu.email,\'\') LIKE ?)';array_push($params,$like,$like,$like);}
        $sql="SELECT c.id,c.phone,c.customer_id,c.draft_order_id,c.active_order_id,c.mode,c.state,c.assigned_user_id,c.assigned_at,c.last_inbound_at,c.last_outbound_at,c.last_activity_at,c.last_read_at,cu.name customer_name,cu.email customer_email,u.name assigned_user_name,(SELECT m.message_text FROM whatsapp_messages m WHERE m.tenant_id=c.tenant_id AND m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_message,(SELECT m.direction FROM whatsapp_messages m WHERE m.tenant_id=c.tenant_id AND m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_direction,(SELECT m.message_type FROM whatsapp_messages m WHERE m.tenant_id=c.tenant_id AND m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) last_message_type,(SELECT COUNT(*) FROM whatsapp_messages m WHERE m.tenant_id=c.tenant_id AND m.conversation_id=c.id AND m.direction='inbound' AND (c.last_read_at IS NULL OR m.created_at>c.last_read_at)) unread_count,(SELECT o.status FROM orders o WHERE o.tenant_id=c.tenant_id AND o.id=COALESCE(c.active_order_id,c.draft_order_id) LIMIT 1) order_status,(SELECT o.total_cents FROM orders o WHERE o.tenant_id=c.tenant_id AND o.id=COALESCE(c.active_order_id,c.draft_order_id) LIMIT 1) order_total_cents FROM whatsapp_conversations c LEFT JOIN customers cu ON cu.id=c.customer_id AND cu.tenant_id=c.tenant_id LEFT JOIN users u ON u.id=c.assigned_user_id AND u.tenant_id=c.tenant_id WHERE ".implode(' AND ',$where)." ORDER BY CASE WHEN c.mode='waiting_human' THEN 0 WHEN c.mode='human' THEN 1 ELSE 2 END, unread_count DESC,c.last_activity_at DESC,c.id DESC LIMIT ".$limit;
        $q=$pdo->prepare($sql);$q->execute($params);return$q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @return array<string,mixed> */
    public function conversation(PDO $pdo,int $tenantId,int $conversationId,int $messageLimit=250):array
    {
        if($tenantId<1||$conversationId<1)throw new RuntimeException('Conversa inválida.');$messageLimit=max(20,min(500,$messageLimit));
        $q=$pdo->prepare('SELECT c.*,cu.name customer_name,cu.email customer_email,cu.phone customer_phone,cu.default_address,u.name assigned_user_name FROM whatsapp_conversations c LEFT JOIN customers cu ON cu.id=c.customer_id AND cu.tenant_id=c.tenant_id LEFT JOIN users u ON u.id=c.assigned_user_id AND u.tenant_id=c.tenant_id WHERE c.id=? AND c.tenant_id=? LIMIT 1');$q->execute([$conversationId,$tenantId]);$conversation=$q->fetch(PDO::FETCH_ASSOC);if(!$conversation)throw new RuntimeException('Conversa não encontrada.');
        $m=$pdo->prepare('SELECT * FROM (SELECT id,provider_message_id,outbox_id,direction,message_type,message_text,payload_json,status,provider_created_at,created_at,updated_at FROM whatsapp_messages WHERE tenant_id=? AND conversation_id=? ORDER BY id DESC LIMIT '.$messageLimit.') recent ORDER BY id ASC');$m->execute([$tenantId,$conversationId]);$messages=$m->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($messages as&$message)$message['media']=$this->media($message['payload_json']??null);unset($message);
        $customerId=(int)($conversation['customer_id']??0);$orders=[];$addresses=[];
        if($customerId>0){
            $o=$pdo->prepare('SELECT id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,delivery_address,notes,created_at,updated_at FROM orders WHERE tenant_id=? AND customer_id=? ORDER BY id DESC LIMIT 10');$o->execute([$tenantId,$customerId]);$orders=$o->fetchAll(PDO::FETCH_ASSOC)?:[];
            try{$addresses=(new CustomerAddressService())->list($pdo,$tenantId,$customerId,10);}catch(\Throwable){}
        }
        $activeOrderId=(int)($conversation['active_order_id']??0);if($activeOrderId<1)$activeOrderId=(int)($conversation['draft_order_id']??0);if($activeOrderId<1&&$orders)$activeOrderId=(int)$orders[0]['id'];
        $activeOrder=null;$items=[];if($activeOrderId>0){$o=$pdo->prepare('SELECT id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,delivery_address,notes,created_at,updated_at FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$o->execute([$activeOrderId,$tenantId]);$activeOrder=$o->fetch(PDO::FETCH_ASSOC)?:null;if($activeOrder){$i=$pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$i->execute([$activeOrderId]);$items=$i->fetchAll(PDO::FETCH_ASSOC)?:[];}}
        return['conversation'=>$conversation,'messages'=>$messages,'active_order'=>$activeOrder,'active_order_items'=>$items,'recent_orders'=>$orders,'addresses'=>$addresses];
    }

    public function markRead(PDO $pdo,int $tenantId,int $conversationId):void
    {
        if($tenantId<1||$conversationId<1)return;$pdo->prepare('UPDATE whatsapp_conversations SET last_read_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);
    }

    /** @return array<string,mixed> */
    public function assume(PDO $pdo,int $tenantId,int $conversationId,int $userId):array
    {
        if($tenantId<1||$conversationId<1||$userId<1)throw new RuntimeException('Atendimento inválido.');
        return Database::transaction(function(PDO $tx)use($tenantId,$conversationId,$userId):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conversa não encontrada.');
            $assigned=(int)($row['assigned_user_id']??0);if((string)$row['mode']==='human'&&$assigned>0&&$assigned!==$userId)throw new RuntimeException('Esta conversa já está sendo atendida por outra pessoa.');
            $resumeState=trim((string)($row['resume_state']??''));$resumeContext=$row['resume_context_json']??null;
            if($resumeState===''){$resumeState=$this->inferResumeState($row);$resumeContext=$row['context_json']??null;}
            $tx->prepare("UPDATE whatsapp_conversations SET resume_state=?,resume_context_json=?,mode='human',state='HUMAN',assigned_user_id=?,assigned_at=CURRENT_TIMESTAMP,last_read_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$resumeState,$resumeContext,$userId,$conversationId,$tenantId]);
            Auth::audit('whatsapp.support_assumed','whatsapp_conversation',(string)$conversationId,['previous_mode'=>(string)$row['mode'],'resume_state'=>$resumeState]);
            return['id'=>$conversationId,'mode'=>'human','assigned_user_id'=>$userId,'resume_state'=>$resumeState];
        });
    }

    /** @return array<string,mixed> */
    public function resumeAutomatic(PDO $pdo,int $tenantId,int $conversationId,int $userId):array
    {
        if($tenantId<1||$conversationId<1||$userId<1)throw new RuntimeException('Atendimento inválido.');
        return Database::transaction(function(PDO $tx)use($tenantId,$conversationId,$userId):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conversa não encontrada.');
            $assigned=(int)($row['assigned_user_id']??0);if((string)$row['mode']==='human'&&$assigned>0&&$assigned!==$userId&&!Auth::can('settings.manage'))throw new RuntimeException('Somente o responsável atual ou um administrador pode devolver esta conversa ao automático.');
            $resumeState=trim((string)($row['resume_state']??''));if($resumeState==='')$resumeState=$this->inferResumeState($row);$resumeContext=$row['resume_context_json']??$row['context_json']??null;
            $tx->prepare("UPDATE whatsapp_conversations SET mode='auto',state=?,context_json=?,resume_state=NULL,resume_context_json=NULL,assigned_user_id=NULL,assigned_at=NULL,last_read_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$resumeState,$resumeContext,$conversationId,$tenantId]);
            Auth::audit('whatsapp.support_auto_resumed','whatsapp_conversation',(string)$conversationId,['previous_mode'=>(string)$row['mode'],'restored_state'=>$resumeState]);
            return['id'=>$conversationId,'mode'=>'auto','state'=>$resumeState];
        });
    }

    /** @return array<string,mixed> */
    public function startConversation(PDO $pdo,int $tenantId,int $userId,string $phone,string $firstMessage=''):array
    {
        if($tenantId<1||$userId<1)throw new RuntimeException('Atendimento inválido.');
        $phone=$this->normalizePhone($phone);if($phone==='')throw new RuntimeException('Informe um WhatsApp válido com DDD.');
        $result=Database::transaction(function(PDO $tx)use($tenantId,$userId,$phone):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM whatsapp_conversations WHERE tenant_id=? AND phone=? LIMIT 1 FOR UPDATE'));$q->execute([$tenantId,$phone]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if($row){
                $assigned=(int)($row['assigned_user_id']??0);
                if((string)$row['mode']==='human'&&$assigned>0&&$assigned!==$userId&&!Auth::can('settings.manage'))throw new RuntimeException('Este número já está em atendimento com outra pessoa.');
                $resumeState=trim((string)($row['resume_state']??''));if($resumeState==='')$resumeState=$this->inferResumeState($row);
                $tx->prepare("UPDATE whatsapp_conversations SET resume_state=?,resume_context_json=COALESCE(resume_context_json,context_json),mode='human',state='HUMAN',assigned_user_id=?,assigned_at=CURRENT_TIMESTAMP,last_read_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$resumeState,$userId,(int)$row['id'],$tenantId]);
                $id=(int)$row['id'];
            }else{
                $customer=(new CustomerIdentityService())->findByPhone($tx,$tenantId,$phone);$customerId=(int)($customer['id']??0);
                $tx->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,customer_id,mode,state,context_json,resume_state,resume_context_json,assigned_user_id,assigned_at,last_read_at,last_activity_at) VALUES (?,?,?,'human','HUMAN',NULL,'WELCOME',NULL,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$tenantId,$phone,$customerId>0?$customerId:null,$userId]);
                $id=(int)$tx->lastInsertId();
            }
            Auth::audit('whatsapp.support_conversation_started','whatsapp_conversation',(string)$id,['phone_suffix'=>substr($phone,-4)]);
            return['id'=>$id,'mode'=>'human','assigned_user_id'=>$userId];
        });
        if(trim($firstMessage)!=='')$this->sendManual($pdo,$tenantId,(int)$result['id'],$userId,$firstMessage);
        return$result;
    }

    /** @return array<string,mixed> */
    public function sendManual(PDO $pdo,int $tenantId,int $conversationId,int $userId,string $text):array
    {
        $text=trim($text);if($tenantId<1||$conversationId<1||$userId<1)throw new RuntimeException('Atendimento inválido.');if($text==='')throw new RuntimeException('Digite uma mensagem antes de enviar.');if(mb_strlen($text)>4000)throw new RuntimeException('A mensagem deve ter no máximo 4.000 caracteres.');
        return Database::transaction(function(PDO $tx)use($tenantId,$conversationId,$userId,$text):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT id,phone,mode,assigned_user_id,active_order_id,draft_order_id FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conversa não encontrada.');
            if((string)$row['mode']!=='human')throw new RuntimeException('Assuma o atendimento antes de enviar mensagens manuais.');if((int)($row['assigned_user_id']??0)!==$userId)throw new RuntimeException('Esta conversa está atribuída a outro atendente.');
            $phone=$this->normalizePhone((string)$row['phone']);if($phone==='')throw new RuntimeException('Telefone da conversa inválido.');$orderId=(int)($row['active_order_id']??0);if($orderId<1)$orderId=(int)($row['draft_order_id']??0);$key=hash('sha256','support-manual|'.$tenantId.'|'.$conversationId.'|'.bin2hex(random_bytes(24)));
            $tx->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'support_manual',?,?,'desktop_queued',0,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$orderId>0?$orderId:null,$phone,$text,$key]);$outboxId=(int)$tx->lastInsertId();
            $meta=json_encode(['source'=>'support_center','user_id'=>$userId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$tx->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,outbox_id,direction,message_type,message_text,payload_json,status) VALUES (?,?,?,'outbound','text',?,?,'queued')")->execute([$tenantId,$conversationId,$outboxId,$text,$meta]);$messageId=(int)$tx->lastInsertId();
            $tx->prepare('UPDATE whatsapp_conversations SET last_outbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,last_read_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);
            Auth::audit('whatsapp.support_message_queued','whatsapp_conversation',(string)$conversationId,['outbox_id'=>$outboxId,'message_id'=>$messageId,'order_id'=>$orderId>0?$orderId:null]);
            return['conversation_id'=>$conversationId,'message_id'=>$messageId,'outbox_id'=>$outboxId,'status'=>'queued'];
        });
    }

    /** @return array<string,int> */
    public function counters(PDO $pdo,int $tenantId):array
    {
        $q=$pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN mode='waiting_human' THEN 1 ELSE 0 END) waiting,SUM(CASE WHEN mode='human' THEN 1 ELSE 0 END) human,SUM(CASE WHEN mode='human' AND assigned_user_id=? THEN 1 ELSE 0 END) mine,SUM(CASE WHEN EXISTS (SELECT 1 FROM whatsapp_messages m WHERE m.tenant_id=whatsapp_conversations.tenant_id AND m.conversation_id=whatsapp_conversations.id AND m.direction='inbound' AND (whatsapp_conversations.last_read_at IS NULL OR m.created_at>whatsapp_conversations.last_read_at)) THEN 1 ELSE 0 END) unread FROM whatsapp_conversations WHERE tenant_id=?");$q->execute([(int)(Auth::id()??0),$tenantId]);$r=$q->fetch(PDO::FETCH_ASSOC)?:[];return['total'=>(int)($r['total']??0),'waiting'=>(int)($r['waiting']??0),'human'=>(int)($r['human']??0),'mine'=>(int)($r['mine']??0),'unread'=>(int)($r['unread']??0)];
    }

    private function inferResumeState(array $row):string
    {
        $state=trim((string)($row['state']??''));if($state!==''&&!in_array($state,['WAITING_HUMAN','HUMAN'],true))return$state;
        $context=[];$raw=$row['context_json']??null;if(is_string($raw)&&trim($raw)!==''){$decoded=json_decode($raw,true);if(is_array($decoded))$context=$decoded;}
        if(isset($context['payment_step'])||isset($context['payment_id']))return'CHOOSING_PAYMENT';
        if(isset($context['fulfillment'])){if(isset($context['quoted_total_cents'])||isset($context['address_text']))return'CONFIRMING_FULFILLMENT';if((string)$context['fulfillment']==='delivery')return'SELECTING_ADDRESS';return'CHOOSING_FULFILLMENT';}
        if((int)($row['draft_order_id']??0)>0)return'CART';return'WELCOME';
    }

    /** @return array<string,string>|null */
    private function media(mixed $raw):?array
    {
        if(!is_string($raw)||trim($raw)==='')return null;$payload=json_decode($raw,true);if(!is_array($payload))return null;$url=trim((string)($payload['media_url']??$payload['url']??''));if($url==='')return null;$parts=parse_url($url);if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||trim((string)($parts['host']??''))==='')return null;return['url'=>$url,'type'=>mb_substr((string)($payload['media_type']??''),0,30),'filename'=>mb_substr((string)($payload['media_filename']??''),0,190)];
    }

    private function normalizePhone(string $value):string
    {
        $digits=preg_replace('/\D+/','',$value)??'';if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;return strlen($digits)>=12&&strlen($digits)<=13?$digits:'';
    }
}
