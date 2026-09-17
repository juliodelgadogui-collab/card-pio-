<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class CustomerCommunicationService
{
    public const ORDER_CONFIRMATION='order_confirmation';
    public const DELIVERY_TRACKING='delivery_tracking';

    /**
     * Varre pedidos recentes como rede de segurança. Assim pedidos criados pelo
     * PDV, app ou cardápio entram no mesmo fluxo sem depender da tela de origem.
     * @return array{orders:int,queued:int}
     */
    public function queueRecentOrderConfirmations(int $limit=120):array
    {
        $limit=max(1,min(500,$limit));$cutoff=gmdate('Y-m-d H:i:s',time()-6*3600);
        $sql='SELECT o.tenant_id,o.id FROM orders o JOIN customers c ON c.id=o.customer_id WHERE o.created_at>=? AND o.status<>"cancelled" AND (COALESCE(c.phone,"")<>"" OR COALESCE(c.email,"")<>"") ORDER BY o.id DESC LIMIT '.$limit;
        $s=Database::connection()->prepare($sql);$s->execute([$cutoff]);$rows=$s->fetchAll();$queued=0;
        foreach($rows as $row){try{$queued+=$this->queueOrderConfirmation((int)$row['tenant_id'],(int)$row['id']);}catch(Throwable){}}
        return['orders'=>count($rows),'queued'=>$queued];
    }

    public function queueOrderConfirmation(int $tenantId,int $orderId):int
    {
        $order=$this->orderContact($tenantId,$orderId);if(!$order)return 0;
        return $this->queueForAvailableChannels($order,self::ORDER_CONFIRMATION,null);
    }

    public function queueDeliveryTracking(int $tenantId,int $orderId,string $trackingUrl):int
    {
        if(!filter_var($trackingUrl,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($trackingUrl),'https://'))throw new RuntimeException('Link de rastreamento inválido.');
        $order=$this->orderContact($tenantId,$orderId);if(!$order)return 0;
        return $this->queueForAvailableChannels($order,self::DELIVERY_TRACKING,$trackingUrl);
    }

    /** @param array<string,mixed> $payload */
    public function handleJob(array $payload):void
    {
        $tenantId=(int)($payload['tenant_id']??0);$orderId=(int)($payload['order_id']??0);$event=(string)($payload['event_type']??'');$channel=(string)($payload['channel']??'');
        if($tenantId<1||$orderId<1||!in_array($event,[self::ORDER_CONFIRMATION,self::DELIVERY_TRACKING],true)||!in_array($channel,['email','whatsapp'],true))throw new RuntimeException('Tarefa de comunicação inválida.');
        $order=$this->orderContact($tenantId,$orderId);if(!$order){$this->mark($tenantId,$orderId,$event,$channel,'skipped',null,'Pedido ou cliente indisponível.');return;}
        if((string)$order['status']==='cancelled'){$this->mark($tenantId,$orderId,$event,$channel,'skipped',null,'Pedido cancelado.');return;}
        $url=$event===self::ORDER_CONFIRMATION
            ? app_absolute_url('pedido.php?t='.rawurlencode((string)$order['public_token']))
            : $this->trackingUrl($payload);
        if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('URL pública do pedido não está configurada corretamente.');

        try{
            if($channel==='email')$providerId=$this->sendEmail($order,$event,$url);
            else $providerId=$this->sendWhatsApp($order,$event,$url);
            $this->mark($tenantId,$orderId,$event,$channel,'sent',$providerId,null,true);
        }catch(Throwable $e){$this->mark($tenantId,$orderId,$event,$channel,'failed',null,mb_substr($e->getMessage(),0,480));throw $e;}
    }

    /** @param array<string,mixed> $order */
    private function queueForAvailableChannels(array $order,string $event,?string $trackingUrl):int
    {
        $tenantId=(int)$order['tenant_id'];$orderId=(int)$order['id'];$customerId=(int)$order['customer_id'];$count=0;
        $email=mb_strtolower(trim((string)($order['customer_email']??'')));
        if(filter_var($email,FILTER_VALIDATE_EMAIL)){
            $configured=false;try{$configured=(new MailSettingsService())->effective($tenantId)!==null;}catch(Throwable){}
            $count+=$this->queueChannel($tenantId,$orderId,$customerId,$event,'email',$email,$configured,$trackingUrl);
        }
        $phone=trim((string)($order['customer_phone']??''));
        if($phone!==''){
            try{$normalized=(new WhatsAppCloudService())->normalizePhone($phone);}catch(Throwable){$normalized='';}
            if($normalized!==''){
                $configured=false;try{$configured=(new WhatsAppSettingsService())->effective($tenantId)!==null;}catch(Throwable){}
                $count+=$this->queueChannel($tenantId,$orderId,$customerId,$event,'whatsapp',$normalized,$configured,$trackingUrl);
            }
        }
        return$count;
    }

    private function queueChannel(int $tenantId,int $orderId,int $customerId,string $event,string $channel,string $recipient,bool $configured,?string $trackingUrl):int
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT id,status FROM customer_communications WHERE tenant_id=? AND order_id=? AND event_type=? AND channel=? LIMIT 1');$s->execute([$tenantId,$orderId,$event,$channel]);$existing=$s->fetch(PDO::FETCH_ASSOC);
        if($existing&&in_array((string)$existing['status'],['pending','sent'],true))return 0;
        $hash=hash('sha256',strtolower($recipient));$hint=$this->maskRecipient($recipient,$channel);$status=$configured?'pending':'unconfigured';
        if($existing){$u=$pdo->prepare('UPDATE customer_communications SET customer_id=?,status=?,recipient_hash=?,recipient_hint=?,provider_message_id=NULL,last_error=?,sent_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$customerId,$status,$hash,$hint,$configured?null:'Canal ainda não configurado.',(int)$existing['id']]);}
        else{$i=$pdo->prepare('INSERT INTO customer_communications (tenant_id,order_id,customer_id,event_type,channel,status,recipient_hash,recipient_hint,last_error) VALUES (?,?,?,?,?,?,?,?,?)');$i->execute([$tenantId,$orderId,$customerId,$event,$channel,$status,$hash,$hint,$configured?null:'Canal ainda não configurado.']);}
        if(!$configured)return 0;
        $payload=['tenant_id'=>$tenantId,'order_id'=>$orderId,'event_type'=>$event,'channel'=>$channel];
        if($event===self::DELIVERY_TRACKING&&$trackingUrl!==null)$payload['tracking_url_encrypted']=Crypto::encrypt($trackingUrl);
        $dedupe='customer-comm:'.$tenantId.':'.$orderId.':'.$event.':'.$channel;
        (new BackgroundJobService())->enqueue('customer.communication',$payload,$tenantId,$dedupe,null,5);return 1;
    }

    /** @return array<string,mixed>|null */
    private function orderContact(int $tenantId,int $orderId):?array
    {
        $sql='SELECT o.id,o.tenant_id,o.customer_id,o.public_token,o.status,o.channel,o.total_cents,o.created_at,t.name tenant_name,c.name customer_name,c.phone customer_phone,c.email customer_email FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.id=? LIMIT 1';
        $s=Database::connection()->prepare($sql);$s->execute([$tenantId,$orderId]);$row=$s->fetch(PDO::FETCH_ASSOC);return$row?:null;
    }

    /** @param array<string,mixed> $order */
    private function sendEmail(array $order,string $event,string $url):?string
    {
        $to=(string)$order['customer_email'];$name=trim((string)$order['customer_name'])?:'Cliente';$tenant=trim((string)$order['tenant_name'])?:'EventMenu';$id=(int)$order['id'];$safeName=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$safeTenant=htmlspecialchars($tenant,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$safeUrl=htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        if($event===self::ORDER_CONFIRMATION){
            $subject='Pedido #'.$id.' recebido — '.$tenant;$total='R$ '.number_format(((int)$order['total_cents'])/100,2,',','.');
            $html='<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#172033"><h2>Pedido recebido ✓</h2><p>Olá, '.$safeName.'. A <strong>'.$safeTenant.'</strong> recebeu seu pedido <strong>#'.$id.'</strong>.</p><p><strong>Total:</strong> '.htmlspecialchars($total,ENT_QUOTES,'UTF-8').'</p><p><a href="'.$safeUrl.'" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px">Acompanhar pedido</a></p><p style="color:#667085;font-size:12px">Este link é privado. Não compartilhe com terceiros.</p></div>';
            $text='Olá, '.$name.'. Seu pedido #'.$id.' foi recebido pela '.$tenant.'. Acompanhe: '.$url;
        }else{
            $subject='Seu pedido #'.$id.' saiu para entrega 🛵';
            $html='<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#172033"><h2>Seu pedido está a caminho 🛵</h2><p>Olá, '.$safeName.'. O pedido <strong>#'.$id.'</strong> saiu para entrega.</p><p><a href="'.$safeUrl.'" style="display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px">Acompanhar entregador</a></p><p style="color:#667085;font-size:12px">O compartilhamento da localização termina quando a entrega for concluída.</p></div>';
            $text='Olá, '.$name.'. Seu pedido #'.$id.' saiu para entrega. Acompanhe em tempo real: '.$url;
        }
        (new SmtpMailerService())->send((int)$order['tenant_id'],$to,$name,$subject,$html,$text);return null;
    }

    /** @param array<string,mixed> $order */
    private function sendWhatsApp(array $order,string $event,string $url):?string
    {
        $settings=(new WhatsAppSettingsService())->effective((int)$order['tenant_id']);if(!$settings)throw new RuntimeException('WhatsApp Cloud API não configurado.');
        $template=$event===self::ORDER_CONFIRMATION?(string)$settings['confirmation_template']:(string)$settings['tracking_template'];$name=trim((string)$order['customer_name'])?:'Cliente';
        $result=(new WhatsAppCloudService())->sendTemplate((int)$order['tenant_id'],(string)$order['customer_phone'],$template,(string)$settings['language_code'],[$name,(string)$order['id'],$url]);
        return$result['message_id'];
    }

    /** @param array<string,mixed> $payload */
    private function trackingUrl(array $payload):string
    {
        $encrypted=(string)($payload['tracking_url_encrypted']??'');if($encrypted==='')throw new RuntimeException('Link de rastreamento ausente.');return Crypto::decrypt($encrypted);
    }

    private function mark(int $tenantId,int $orderId,string $event,string $channel,string $status,?string $providerId,?string $error,bool $sent=false):void
    {
        $sql='UPDATE customer_communications SET status=?,provider_message_id=?,last_error=?,sent_at='.($sent?'CURRENT_TIMESTAMP':'sent_at').',updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=? AND event_type=? AND channel=?';
        Database::connection()->prepare($sql)->execute([$status,$providerId,$error,$tenantId,$orderId,$event,$channel]);
    }

    private function maskRecipient(string $recipient,string $channel):string
    {
        if($channel==='email'){$parts=explode('@',$recipient,2);if(count($parts)!==2)return'***';$left=$parts[0];return(mb_substr($left,0,1)?:'*').'***@'.$parts[1];}
        $digits=preg_replace('/\D+/','',$recipient)?:'';return'******'.substr($digits,-4);
    }
}
