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
    private const TRACK_COMMANDS=['acompanhar pedido','acompanhar meu pedido'];
    private const FULFILLMENT_STATES=['CHOOSING_FULFILLMENT','ASKING_CUSTOMER_NAME','SELECTING_ADDRESS','ENTERING_ADDRESS','CONFIRMING_FULFILLMENT','CHOOSING_PAYMENT'];

    /** @return array<string,mixed> */
    public function receiveInbound(string $deviceId,array $input):array
    {
        $tenantId=(int)(Auth::tenantId()??0);if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $deviceId=trim($deviceId);if(strlen($deviceId)<8)throw new RuntimeException('Dispositivo inválido.');
        $providerId=mb_substr(trim((string)($input['provider_message_id']??'')),0,190);if($providerId===''||!preg_match('/^[A-Za-z0-9._:\-]{4,190}$/',$providerId))throw new RuntimeException('Identificação da mensagem inválida.');
        $phone=$this->normalizePhone((string)($input['phone']??''));if($phone==='')throw new RuntimeException('Telefone do remetente inválido.');
        $name=mb_substr(trim((string)($input['name']??'')),0,180);$type=strtolower(trim((string)($input['message_type']??'text')));if(!in_array($type,self::MESSAGE_TYPES,true))$type='unknown';$text=mb_substr(trim((string)($input['text']??'')),0,4000);$payload=$this->safePayload($input['payload']??null);$providerCreatedAt=$this->providerDate($input['timestamp']??null);$deviceHash=hash('sha256',$deviceId);

        return Database::transaction(function(PDO $pdo)use($tenantId,$deviceHash,$providerId,$phone,$name,$type,$text,$payload,$providerCreatedAt):array{
            $agent=$pdo->prepare('SELECT id FROM whatsapp_desktop_agents WHERE tenant_id=? AND device_hash=? LIMIT 1');$agent->execute([$tenantId,$deviceHash]);if(!$agent->fetchColumn())throw new RuntimeException('EventMenu Connect não registrado para esta empresa.');
            $duplicate=$pdo->prepare('SELECT conversation_id FROM whatsapp_messages WHERE tenant_id=? AND provider_message_id=? LIMIT 1');$duplicate->execute([$tenantId,$providerId]);$existingConversation=(int)($duplicate->fetchColumn()?:0);if($existingConversation>0)return ['duplicate'=>true,'conversation_id'=>$existingConversation,'reply_queued'=>false];

            $conversation=$this->lockConversation($pdo,$tenantId,$phone);
            if(!$conversation){try{$pdo->prepare('INSERT INTO whatsapp_conversations (tenant_id,phone,mode,state,last_inbound_at,last_activity_at) VALUES (?,?,' . "'auto','IDLE'" . ',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([$tenantId,$phone]);}catch(\PDOException){}$conversation=$this->lockConversation($pdo,$tenantId,$phone);if(!$conversation)throw new RuntimeException('Não foi possível abrir a conversa.');}
            $conversationId=(int)$conversation['id'];$payloadJson=$payload===null?null:json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            try{$pdo->prepare('INSERT INTO whatsapp_messages (tenant_id,conversation_id,provider_message_id,direction,message_type,message_text,payload_json,status,provider_created_at) VALUES (?,?,?,' . "'inbound'" . ',?,?,?,' . "'received'" . ',?)')->execute([$tenantId,$conversationId,$providerId,$type,$text!==''?$text:null,$payloadJson,$providerCreatedAt]);}
            catch(\PDOException $e){$duplicate->execute([$tenantId,$providerId]);$existingConversation=(int)($duplicate->fetchColumn()?:0);if($existingConversation>0)return ['duplicate'=>true,'conversation_id'=>$existingConversation,'reply_queued'=>false];throw $e;}
            $pdo->prepare('UPDATE whatsapp_conversations SET last_inbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);

            $mode=(string)($conversation['mode']??'auto');if(!in_array($mode,self::MODES,true))$mode='auto';if($mode==='human'||$mode==='waiting_human')return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>$mode,'reply_queued'=>false];
            if(!$this->commerceEnabled($pdo,$tenantId))return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>(string)($conversation['state']??'IDLE'),'reply_queued'=>false,'commerce_enabled'=>false];

            $normalized=$this->normalizeCommand($text);$state=(string)($conversation['state']??'IDLE');
            if(in_array($normalized,self::HUMAN_COMMANDS,true)||($state==='WELCOME'&&$normalized==='4'))return $this->transferToHuman($pdo,$tenantId,$conversationId,$phone,$providerId);
            if(in_array($normalized,self::TRACK_COMMANDS,true)||($normalized==='meu pedido'&&(int)($conversation['draft_order_id']??0)<1)||($state==='WELCOME'&&$normalized==='3')){$reply=$this->orderCommandMessage($pdo,$tenantId,$conversation);$queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'order_lookup',$reply);return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>$state,'reply_queued'=>$queued,'commerce_enabled'=>true];}

            $registration=new WhatsAppCustomerRegistrationService();
            if($registration->shouldHandle($pdo,$tenantId,$conversation,$phone)){
                try{$result=$registration->handle($pdo,$tenantId,$conversation,$text);}catch(RuntimeException $e){$queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'registration_validation',"⚠️ ".$e->getMessage()."\n\nDigite *ATENDENTE* se precisar de ajuda.");return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>$state,'reply_queued'=>$queued,'commerce_enabled'=>true,'registration'=>true,'validation_error'=>true];}
                $reply=(string)($result['reply']??'');$kind=(string)($result['kind']??'registration');$queued=$reply!==''?$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,$kind,$reply):false;
                return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>(string)($result['state']??$state),'reply_queued'=>$queued,'commerce_enabled'=>true,'registration'=>true,'registration_complete'=>(bool)($result['registration_complete']??false),'customer_id'=>$result['customer_id']??null];
            }

            if(in_array($normalized,self::MENU_COMMANDS,true)||$state==='IDLE'){$pdo->prepare("UPDATE whatsapp_conversations SET state='WELCOME',context_json=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$conversationId,$tenantId]);$reply=$this->welcomeMessage($name);$queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'welcome',$reply);return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>'WELCOME','reply_queued'=>$queued,'commerce_enabled'=>true];}
            if($state==='WELCOME'&&$normalized==='2'){$queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'repeat_soon',"🔁 Repetir último pedido será liberado na etapa avançada do WhatsApp Commerce.\n\nPor enquanto escolha:\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente");return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>'WELCOME','reply_queued'=>$queued,'commerce_enabled'=>true];}

            $stage2=in_array($state,self::FULFILLMENT_STATES,true);
            try{$result=$stage2?(new WhatsAppCommerceFulfillmentService())->handle($pdo,$tenantId,$conversation,$text):(new WhatsAppCommerceOrderService())->handle($pdo,$tenantId,$conversation,$text,$name);}
            catch(RuntimeException $e){try{Auth::audit('whatsapp.commerce_validation_error','whatsapp_conversation',(string)$conversationId,['state'=>$state,'error'=>mb_substr($e->getMessage(),0,300)]);}catch(Throwable){}$queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'commerce_validation',"⚠️ ".$e->getMessage()."\n\nDigite *MENU* para voltar ao início ou *ATENDENTE* para falar com uma pessoa.");return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>$state,'reply_queued'=>$queued,'commerce_enabled'=>true,'validation_error'=>true];}
            if(!empty($result['handled'])){if(!$stage2)$this->auditStage1Transition($pdo,$tenantId,$conversation,$result);$reply=(string)($result['reply']??'');$kind=(string)($result['kind']??'commerce');$queued=$reply!==''?$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,$kind,$reply):false;return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>(string)($result['state']??$state),'reply_queued'=>$queued,'commerce_enabled'=>true,'draft_order_id'=>$result['draft_order_id']??null,'active_order_id'=>$result['active_order_id']??null];}

            $queued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'fallback',"Não consegui entender sua resposta.\n\nDigite *MENU* para voltar ao início ou *ATENDENTE* para falar com uma pessoa.");return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'auto','state'=>$state,'reply_queued'=>$queued,'commerce_enabled'=>true];
        });
    }

    /** @return array<int,array<string,mixed>> */
    public function humanQueue(PDO $pdo,int $tenantId,int $limit=50):array
    {
        $limit=max(1,min(100,$limit));$sql="SELECT c.id,c.phone,c.mode,c.state,c.assigned_user_id,c.last_inbound_at,c.last_activity_at,u.name assigned_user_name,(SELECT m.message_text FROM whatsapp_messages m WHERE m.tenant_id=c.tenant_id AND m.conversation_id=c.id AND m.direction='inbound' ORDER BY m.id DESC LIMIT 1) last_message FROM whatsapp_conversations c LEFT JOIN users u ON u.id=c.assigned_user_id AND u.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.mode IN ('waiting_human','human') ORDER BY CASE WHEN c.mode='waiting_human' THEN 0 ELSE 1 END,c.last_activity_at DESC LIMIT ".$limit;$q=$pdo->prepare($sql);$q->execute([$tenantId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @return array<string,mixed> */
    public function setHumanMode(PDO $pdo,int $tenantId,int $conversationId,string $mode,?int $userId):array
    {
        if($tenantId<1||$conversationId<1)throw new RuntimeException('Conversa inválida.');if(!in_array($mode,['human','auto'],true))throw new RuntimeException('Modo de atendimento inválido.');
        return Database::transaction(function(PDO $tx)use($tenantId,$conversationId,$mode,$userId):array{$sql=Database::portableSql($tx,'SELECT * FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE');$q=$tx->prepare($sql);$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conversa não encontrada.');if($mode==='human'){if(!$userId)throw new RuntimeException('Usuário responsável inválido.');$tx->prepare("UPDATE whatsapp_conversations SET mode='human',state='HUMAN',assigned_user_id=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$userId,$conversationId,$tenantId]);}else{$next=(int)($row['draft_order_id']??0)>0?'CART':'WELCOME';$tx->prepare("UPDATE whatsapp_conversations SET mode='auto',state=?,context_json=NULL,assigned_user_id=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$next,$conversationId,$tenantId]);}return ['id'=>$conversationId,'mode'=>$mode,'previous_mode'=>(string)$row['mode']];});
    }

    /** @return array<string,mixed> */
    private function transferToHuman(PDO $pdo,int $tenantId,int $conversationId,string $phone,string $providerId):array
    {
        $pdo->prepare("UPDATE whatsapp_conversations SET mode='waiting_human',state='WAITING_HUMAN',assigned_user_id=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$conversationId,$tenantId]);$replyQueued=$this->queueReply($pdo,$tenantId,$conversationId,$phone,$providerId,'human_transfer','Certo! 👤 Seu atendimento foi encaminhado para a equipe. O atendimento automático ficará pausado enquanto você aguarda.');try{Auth::audit('whatsapp.conversation_waiting_human','whatsapp_conversation',(string)$conversationId,['phone_suffix'=>substr($phone,-4)]);}catch(Throwable){}return ['duplicate'=>false,'conversation_id'=>$conversationId,'mode'=>'waiting_human','state'=>'WAITING_HUMAN','reply_queued'=>$replyQueued,'commerce_enabled'=>true];
    }

    private function auditStage1Transition(PDO $pdo,int $tenantId,array $conversation,array $result):void
    {
        try{
            $conversationId=(int)($conversation['id']??0);$from=(string)($conversation['state']??'IDLE');$to=(string)($result['state']??$from);$oldDraftId=(int)($conversation['draft_order_id']??0);$newDraftId=(int)($result['draft_order_id']??0);$draftId=$newDraftId>0?$newDraftId:$oldDraftId;$kind=(string)($result['kind']??'');$context=[];$raw=$conversation['context_json']??null;if(is_string($raw)&&trim($raw)!==''){$decoded=json_decode($raw,true);if(is_array($decoded))$context=$decoded;}
            if((int)($conversation['customer_id']??0)<1&&$conversationId>0){$q=$pdo->prepare('SELECT customer_id FROM whatsapp_conversations WHERE id=? AND tenant_id=?');$q->execute([$conversationId,$tenantId]);$customerId=(int)($q->fetchColumn()?:0);if($customerId>0)Auth::audit('whatsapp.customer_identified','customer',(string)$customerId,['conversation_id'=>$conversationId]);}
            $event='';
            if($from==='WELCOME'&&$to==='SELECTING_CATEGORY'&&$draftId>0)$event='whatsapp.draft_created';
            elseif($from==='ENTERING_NOTE'&&$to==='CART'&&$draftId>0)$event='whatsapp.item_added';
            elseif($from==='CART'&&$to==='CHOOSING_FULFILLMENT'&&$draftId>0)$event='whatsapp.cart_finalized';
            elseif($kind==='cart_cancelled')$event='whatsapp.draft_cancelled';
            elseif($from==='CART'&&$to==='CART'&&($context['cart_action']??'')==='qty_value')$event='whatsapp.item_quantity_changed';
            elseif($from==='CART'&&$to==='CART'&&($context['cart_action']??'')==='remove_item')$event='whatsapp.item_removed';
            if($event!=='')Auth::audit($event,$draftId>0?'order':'whatsapp_conversation',(string)($draftId>0?$draftId:$conversationId),['conversation_id'=>$conversationId,'from_state'=>$from,'to_state'=>$to]);
        }catch(Throwable){}
    }

    /** @return array<string,mixed>|false */
    private function lockConversation(PDO $pdo,int $tenantId,string $phone):array|false
    { $sql=Database::portableSql($pdo,'SELECT * FROM whatsapp_conversations WHERE tenant_id=? AND phone=? LIMIT 1 FOR UPDATE');$q=$pdo->prepare($sql);$q->execute([$tenantId,$phone]);return $q->fetch(PDO::FETCH_ASSOC); }
    private function commerceEnabled(PDO $pdo,int $tenantId):bool
    { $connection=(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);return (int)($connection['commerce_enabled']??0)===1; }

    private function queueReply(PDO $pdo,int $tenantId,int $conversationId,string $phone,string $providerId,string $kind,string $message):bool
    {
        $key=hash('sha256','commerce-reply|'.$tenantId.'|'.$providerId.'|'.$kind);try{$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,NULL,'commerce_auto',?,?,'desktop_queued',0,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$phone,$message,$key]);$outboxId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,outbox_id,direction,message_type,message_text,status) VALUES (?,?,?,'outbound','text',?,'queued')")->execute([$tenantId,$conversationId,$outboxId,$message]);$pdo->prepare('UPDATE whatsapp_conversations SET last_outbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);return true;}catch(\PDOException $e){$q=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');$q->execute([$key]);if($q->fetchColumn())return false;throw $e;}
    }

    private function orderCommandMessage(PDO $pdo,int $tenantId,array $conversation):string
    {
        $orderId=(int)($conversation['active_order_id']??0);if($orderId<1){$q=$pdo->prepare("SELECT id FROM orders WHERE tenant_id=? AND customer_id=? AND status NOT IN ('draft','cancelled') ORDER BY id DESC LIMIT 1");$customerId=(int)($conversation['customer_id']??0);if($customerId>0){$q->execute([$tenantId,$customerId]);$orderId=(int)($q->fetchColumn()?:0);}}if($orderId<1)$orderId=(int)($conversation['draft_order_id']??0);if($orderId<1)return "Ainda não há um pedido vinculado a esta conversa.\n\nDigite *MENU* para voltar ao início ou *ATENDENTE* para falar com uma pessoa.";$q=$pdo->prepare('SELECT id,status,payment_status,total_cents FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)return "Não encontrei o pedido vinculado a esta conversa.\n\nDigite *MENU* para voltar ao início.";return 'Pedido #'.(int)$order['id']."\nStatus: ".$this->friendlyOrderStatus((string)$order['status'])."\nPagamento: ".$this->friendlyPaymentStatus((string)$order['payment_status'])."\nTotal: R$ ".number_format(((int)$order['total_cents'])/100,2,',','.')."\n\nDigite *MENU* para voltar ao início.";
    }

    private function friendlyOrderStatus(string $status):string
    { return match($status){'draft'=>'montando pedido','pending'=>'recebido','confirmed'=>'confirmado','preparing'=>'em preparação','ready'=>'pronto','served'=>'servido','out_for_delivery'=>'saiu para entrega','completed'=>'entregue/concluído','cancelled'=>'cancelado',default=>$status?:'desconhecido'}; }
    private function friendlyPaymentStatus(string $status):string
    { return match($status){'paid'=>'confirmado','pending'=>'aguardando confirmação','unpaid'=>'não pago','failed'=>'falhou','refunded'=>'estornado',default=>$status?:'não informado'}; }
    private function welcomeMessage(string $name):string
    { $hello=$name!==''?'Olá, '.$name.'! 👋':'Olá! 👋';return $hello."\n\nO que deseja fazer?\n\n1 - Fazer um pedido\n2 - Repetir último pedido (em breve)\n3 - Acompanhar pedido\n4 - Falar com atendente\n\nVocê também pode digitar *MENU*, *MEU PEDIDO*, *CANCELAR* ou *ATENDENTE* a qualquer momento."; }
    private function normalizeCommand(string $value):string
    { $value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:"); }
    private function normalizePhone(string $value):string
    { $digits=preg_replace('/\D+/','',$value)??'';$digits=ltrim($digits,'0');if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;return preg_match('/^\d{12,15}$/',$digits)?$digits:''; }
    /** @return array<string,mixed>|null */
    private function safePayload(mixed $payload):?array
    { if(!is_array($payload)||!$payload)return null;$encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(!is_string($encoded)||strlen($encoded)>16384)return ['truncated'=>true];return $payload; }
    private function providerDate(mixed $value):?string
    { if($value===null||$value==='')return null;if(is_numeric($value)){$timestamp=(int)$value;if($timestamp>20000000000)$timestamp=(int)floor($timestamp/1000);if($timestamp>0&&$timestamp<4102444800)return gmdate('Y-m-d H:i:s',$timestamp);return null;}$timestamp=strtotime((string)$value);return $timestamp===false?null:gmdate('Y-m-d H:i:s',$timestamp); }
}
