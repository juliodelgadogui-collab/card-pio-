<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppIntegrationService
{
    private const EVENTS = [
        'order_received' => 'Olá {nome}! Recebemos o seu pedido #{pedido}. Total: {valor}.',
        'order_confirmed' => 'Olá {nome}! Seu pedido #{pedido} foi confirmado. {previsao}',
        'payment_confirmed' => 'Pagamento do pedido #{pedido} confirmado. Obrigado, {cliente}!',
        'preparing' => 'Seu pedido #{pedido} já está sendo preparado.',
        'ready' => 'Seu pedido #{pedido} está pronto.',
        'out_for_delivery' => 'Seu pedido #{pedido} saiu para entrega. {link}',
        'delivered' => 'Pedido #{pedido} entregue. Obrigado por pedir com a gente!',
        'cancelled' => 'O pedido #{pedido} foi cancelado. Se precisar, fale com o estabelecimento.',
    ];
    private const VARIABLES = ['pedido','cliente','nome','status','total','valor','previsao','link','restaurante'];
    private const ORDER_LIFECYCLE_EVENTS=['order_received','order_confirmed','preparing','ready','out_for_delivery','delivered','cancelled'];

    /** @return array<string,string> */
    public function defaultTemplates(): array{return self::EVENTS;}

    /** @return array<string,mixed> */
    public function ensureConnection(PDO $pdo,int $tenantId):array
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $q=$pdo->prepare('SELECT * FROM whatsapp_connections WHERE tenant_id=? LIMIT 1');$q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row){
            $sessionKey=bin2hex(random_bytes(32));
            $pdo->prepare('INSERT INTO whatsapp_connections (tenant_id,provider,session_key,status,automation_enabled) VALUES (?,"eventmenu_connect",?,"disconnected",0)')->execute([$tenantId,$sessionKey]);
            $q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        }elseif(!in_array(strtolower((string)($row['provider']??'')),['desktop_baileys','eventmenu_connect'],true)){
            $pdo->prepare('UPDATE whatsapp_connections SET provider="eventmenu_connect",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$tenantId]);
            $q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        }
        $this->ensureTemplates($pdo,$tenantId);
        return is_array($row)?$row:[];
    }

    public function ensureTemplates(PDO $pdo,int $tenantId):void
    {
        $find=$pdo->prepare('SELECT event_type FROM whatsapp_event_templates WHERE tenant_id=?');$find->execute([$tenantId]);
        $existing=array_fill_keys(array_map('strval',$find->fetchAll(PDO::FETCH_COLUMN)),true);
        $insert=$pdo->prepare('INSERT INTO whatsapp_event_templates (tenant_id,event_type,enabled,message_template) VALUES (?,?,0,?)');
        foreach(self::EVENTS as$event=>$message)if(!isset($existing[$event]))$insert->execute([$tenantId,$event,$message]);
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(PDO $pdo,int $tenantId):array
    {
        $this->ensureConnection($pdo,$tenantId);
        $q=$pdo->prepare('SELECT event_type,enabled,message_template,updated_at FROM whatsapp_event_templates WHERE tenant_id=? ORDER BY id');$q->execute([$tenantId]);
        return$q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @param array<string,array{enabled?:bool|int|string,message?:string}> $templates */
    public function saveConfiguration(PDO $pdo,int $tenantId,bool $automationEnabled,array $templates):void
    {
        $current=$this->ensureConnection($pdo,$tenantId);$normalized=[];
        foreach(self::EVENTS as$event=>$default){
            $input=$templates[$event]??[];$message=trim((string)($input['message']??$default));
            if($message==='')throw new RuntimeException('A mensagem de '.$event.' não pode ficar vazia.');
            if(mb_strlen($message)>1500)throw new RuntimeException('Cada mensagem do WhatsApp pode ter no máximo 1500 caracteres.');
            $this->assertTemplateVariables($message);$normalized[$event]=['enabled'=>!empty($input['enabled'])?1:0,'message'=>$message];
        }
        $wasEnabled=(int)($current['automation_enabled']??0)===1;
        if($automationEnabled&&!$wasEnabled)$pdo->prepare('UPDATE whatsapp_connections SET automation_enabled=1,automation_enabled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$tenantId]);
        elseif(!$automationEnabled)$pdo->prepare('UPDATE whatsapp_connections SET automation_enabled=0,automation_enabled_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$tenantId]);
        else$pdo->prepare('UPDATE whatsapp_connections SET automation_enabled=1,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$tenantId]);
        $update=$pdo->prepare('UPDATE whatsapp_event_templates SET enabled=?,message_template=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND event_type=?');
        foreach($normalized as$event=>$input)$update->execute([$input['enabled'],$input['message'],$tenantId,$event]);
    }

    /** @return array<string,mixed> */
    public function status(PDO $pdo,int $tenantId,bool $refreshBridge=true):array
    {
        $row=$this->ensureConnection($pdo,$tenantId);$snapshot=$this->publicConnection($row);
        $snapshot['provider']=(string)($row['provider']??'eventmenu_connect');
        $snapshot['bridge_enabled']=false;$snapshot['qr']=null;
        $a=$pdo->prepare('SELECT device_label,status,phone_number,last_seen_at,last_error,engine FROM whatsapp_desktop_agents WHERE tenant_id=? ORDER BY last_seen_at DESC,id DESC LIMIT 1');
        try{$a->execute([$tenantId]);$agent=$a->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable){$agent=null;}
        $snapshot['agent']=$agent;
        $p=$pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND status IN ("queued","failed","desktop_queued","desktop_failed") AND attempt_count<max_attempts');
        try{$p->execute([$tenantId]);$snapshot['pending']=(int)$p->fetchColumn();}catch(Throwable){$snapshot['pending']=0;}
        return$snapshot;
    }

    /** @return array<string,mixed> */
    public function connect(PDO $pdo,int $tenantId,bool $replace=false):array
    {
        $this->ensureConnection($pdo,$tenantId);
        throw new RuntimeException('A conexão e o pareamento do WhatsApp são feitos exclusivamente no aplicativo EventMenu Connect.');
    }

    /** @return array<string,mixed> */
    public function disconnect(PDO $pdo,int $tenantId):array
    {
        $this->ensureConnection($pdo,$tenantId);
        throw new RuntimeException('A sessão do WhatsApp pertence ao EventMenu Connect e deve ser encerrada no próprio aplicativo.');
    }

    public function queueOrderEvent(PDO $pdo,int $tenantId,int $orderId,string $eventType,string $dedupeSuffix=''):?int
    {
        $q=$pdo->prepare('SELECT o.id,o.status,o.total_cents,c.name customer_name,c.phone customer_phone,t.name restaurant_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');
        $q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)return null;
        $link='';
        if($eventType==='out_for_delivery'){
            try{$l=$pdo->prepare('SELECT token_encrypted,expires_at,revoked_at FROM delivery_tracking_links WHERE tenant_id=? AND order_id=? LIMIT 1');$l->execute([$tenantId,$orderId]);$row=$l->fetch(PDO::FETCH_ASSOC);if($row&&empty($row['revoked_at'])&&strtotime((string)$row['expires_at'])>time()){$token=Crypto::decrypt((string)$row['token_encrypted']);if($token!=='')$link=\app_absolute_url('delivery-track.php?token='.rawurlencode($token));}}catch(Throwable){}
        }
        $customer=(string)($order['customer_name']?:'cliente');$value='R$ '.number_format(((int)$order['total_cents'])/100,2,',','.');$forecast=$this->readyForecast($pdo,$tenantId,$orderId);
        return$this->queueEvent($pdo,$tenantId,$eventType,(string)($order['customer_phone']??''),[
            'pedido'=>(string)$orderId,'cliente'=>$customer,'nome'=>$customer,
            'status'=>$this->friendlyStatusForEvent($eventType,(string)$order['status']),
            'total'=>$value,'valor'=>$value,'previsao'=>$forecast,'link'=>$link,'restaurante'=>(string)$order['restaurant_name']
        ],$orderId,$dedupeSuffix);
    }

    public function queueEvent(PDO $pdo,int $tenantId,string $eventType,string $recipient,array $context=[],?int$orderId=null,string$dedupeSuffix=''):?int
    {
        if(!array_key_exists($eventType,self::EVENTS))throw new RuntimeException('Evento de WhatsApp inválido.');
        $connection=$this->ensureConnection($pdo,$tenantId);if((int)($connection['automation_enabled']??0)!==1)return null;
        $q=$pdo->prepare('SELECT enabled,message_template FROM whatsapp_event_templates WHERE tenant_id=? AND event_type=? LIMIT 1');$q->execute([$tenantId,$eventType]);$template=$q->fetch(PDO::FETCH_ASSOC);
        if(!$template||(int)$template['enabled']!==1)return null;
        $phone=$this->normalizePhone($recipient);if($phone==='')return null;
        $message=$this->render((string)$template['message_template'],$context);
        $stableSuffix=($orderId!==null&&in_array($eventType,self::ORDER_LIFECYCLE_EVENTS,true))?'':$dedupeSuffix;
        $key=hash('sha256',implode('|',[$tenantId,$orderId??0,$eventType,$phone,$stableSuffix]));
        try{
            $pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,idempotency_key) VALUES (?,?,?,?,?,"desktop_queued",?)')->execute([$tenantId,$orderId,$eventType,$phone,$message,$key]);
            return(int)$pdo->lastInsertId();
        }catch(Throwable$e){
            $q=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');$q->execute([$key]);$existing=$q->fetchColumn();if($existing!==false)return(int)$existing;throw$e;
        }
    }

    public function syncPaymentEvents(PDO $pdo,int $limit=100):int
    {
        $limit=max(1,min(500,$limit));$sourceFilter=$this->hasColumn($pdo,'orders','order_source')?' AND COALESCE(o.order_source,"")<>"WHATSAPP"':'';
        $sql='SELECT o.tenant_id,o.id order_id,MAX(p.id) payment_id FROM orders o JOIN payments p ON p.tenant_id=o.tenant_id AND p.order_id=o.id JOIN whatsapp_connections wc ON wc.tenant_id=o.tenant_id WHERE wc.automation_enabled=1 AND wc.automation_enabled_at IS NOT NULL AND o.payment_status="paid" AND p.status="paid"'.$sourceFilter.' AND COALESCE(p.verified_at,p.created_at)>=wc.automation_enabled_at GROUP BY o.tenant_id,o.id ORDER BY payment_id DESC LIMIT '.$limit;
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];$queued=0;
        foreach($rows as$row){try{if($this->queueOrderEvent($pdo,(int)$row['tenant_id'],(int)$row['order_id'],'payment_confirmed','payment:'.(int)$row['payment_id'])!==null)$queued++;}catch(Throwable){}}
        return$queued;
    }

    /** @return array{processed:int,sent:int,failed:int,disabled:bool,reason:string} */
    public function processOutbox(PDO $pdo,int $limit=20):array
    {
        return['processed'=>0,'sent'=>0,'failed'=>0,'disabled'=>true,'reason'=>'server_sending_disabled_use_eventmenu_connect'];
    }

    public function render(string$template,array$context):string
    {
        $values=[];foreach(self::VARIABLES as$name)$values['{'.$name.'}']=trim((string)($context[$name]??''));
        return trim(preg_replace('/[ \t]+\n/',"\n",strtr($template,$values))??strtr($template,$values));
    }

    public function normalizePhone(string$value):string
    {
        $digits=preg_replace('/\D+/','',$value)??'';$digits=ltrim($digits,'0');
        if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
        if(!preg_match('/^\d{12,15}$/',$digits))return'';
        return$digits;
    }

    /** Legacy compatibility: server-side WhatsApp bridge is intentionally disabled. */
    public function bridgeConfigured():bool{return false;}

    private function readyForecast(PDO $pdo,int $tenantId,int $orderId):string
    {
        if(!$this->hasColumn($pdo,'orders','estimated_ready_at'))return'';
        try{
            $p=$pdo->prepare('SELECT estimated_ready_at FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$p->execute([$orderId,$tenantId]);$raw=$p->fetchColumn();
            if(!is_string($raw)||trim($raw)==='')return'';$time=strtotime($raw);return$time===false?'':'Previsão: '.date('H:i',$time).'.';
        }catch(Throwable){return'';}
    }

    private function friendlyStatus(string$value):string{return match(strtolower(trim($value))){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','completed'=>'Entregue','cancelled'=>'Cancelado',default=>'Em andamento'};}
    private function friendlyStatusForEvent(string$eventType,string$currentStatus):string{return match($eventType){'order_received'=>'Pedido recebido','order_confirmed'=>'Confirmado','payment_confirmed'=>'Pagamento confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','delivered'=>'Entregue','cancelled'=>'Cancelado',default=>$this->friendlyStatus($currentStatus)};}

    private function hasColumn(PDO$pdo,string$table,string$column):bool
    {
        try{
            $driver=strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if($driver==='sqlite'){$q=$pdo->query('PRAGMA table_info('.$table.')');foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as$row)if(strtolower((string)($row['name']??''))===strtolower($column))return true;return false;}
            $q=$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE ".$pdo->quote($column));return(bool)$q->fetch(PDO::FETCH_ASSOC);
        }catch(Throwable){return false;}
    }

    private function assertTemplateVariables(string$template):void
    {
        preg_match_all('/\{([a-z_]+)\}/i',$template,$matches);
        foreach(($matches[1]??[])as$name)if(!in_array(strtolower((string)$name),self::VARIABLES,true))throw new RuntimeException('Variável não permitida no modelo: {'.$name.'}.');
    }

    /** @return array<string,mixed> */
    private function publicConnection(array$row):array
    {
        return['status'=>(string)($row['status']??'disconnected'),'phone_number'=>(string)($row['phone_number']??''),'automation_enabled'=>(int)($row['automation_enabled']??0)===1,'last_connected_at'=>$row['last_connected_at']??null,'last_seen_at'=>$row['last_seen_at']??null,'last_error'=>$row['last_error']??null];
    }
}
