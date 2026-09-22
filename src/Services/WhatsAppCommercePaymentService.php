<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCommercePaymentService
{
    /** @return array<string,mixed> */
    public function prompt(PDO $pdo,int $tenantId,int $conversationId,int $orderId,string $prefix=''):array
    {
        $order=$this->order($pdo,$tenantId,$orderId);
        if((string)$order['payment_status']==='paid')return $this->paidResult($pdo,$tenantId,$conversationId,$order);
        if(in_array((string)$order['status'],['cancelled','completed'],true))throw new RuntimeException('Este pedido não aceita nova forma de pagamento.');

        $methods=(new DeliveryPaymentMethodService())->forTenant($pdo,$tenantId);
        $options=[];$labels=[];$n=1;
        $pix=(array)($methods['pix']??[]);
        if($pix){$options[(string)$n]=['method'=>'pix','provider'=>(string)($pix[0]['provider']??'')];$labels[]=$n.' - ⚡ PIX';$n++;}
        $cards=(array)($methods['card']??[]);
        if($cards){$types=(array)($cards[0]['payment_types']??[]);$caption=[];if(in_array('credit_card',$types,true))$caption[]='crédito';if(in_array('debit_card',$types,true))$caption[]='débito';$options[(string)$n]=['method'=>'card','provider'=>'mercadopago'];$labels[]=$n.' - 💳 Cartão'.($caption?' ('.implode(' / ',$caption).')':'');$n++;}
        if(!empty($methods['cash'])){$options[(string)$n]=['method'=>'cash','provider'=>null];$labels[]=$n.' - 💵 Dinheiro';}
        if(!$options)return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>$orderId,'payment_step'=>'unavailable'],"⚠️ Nenhuma forma de pagamento está habilitada para este estabelecimento.\n\nO pedido #{$orderId} foi recebido e o estoque está reservado temporariamente. Digite *ATENDENTE* para a equipe orientar o pagamento.",'payment_unavailable');

        $lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='💰 *FORMA DE PAGAMENTO*';$lines[]='Pedido #'.$orderId.' · '.$this->money((int)$order['total_cents']);$lines[]='';foreach($labels as$label)$lines[]=$label;$lines[]='';$lines[]='Digite o número da forma desejada.';$lines[]='Para acompanhar o pedido, digite *MEU PEDIDO*.';
        return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>$orderId,'payment_step'=>'menu','payment_options'=>$options],trim(implode("\n",$lines)),'payment_menu');
    }

    /** @return array<string,mixed> */
    public function handle(PDO $pdo,int $tenantId,array $conversation,string $text):array
    {
        $conversationId=(int)($conversation['id']??0);$orderId=(int)($conversation['active_order_id']??0);if($conversationId<1||$orderId<1)throw new RuntimeException('Pedido ativo não encontrado.');
        $order=$this->order($pdo,$tenantId,$orderId);$normalized=$this->normalize($text);$context=$this->context($conversation['context_json']??null);$step=(string)($context['payment_step']??'menu');
        if((string)$order['payment_status']==='paid')return $this->paidResult($pdo,$tenantId,$conversationId,$order);
        if($normalized==='cancelar')return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>$orderId,'payment_step'=>$step],"O pedido #{$orderId} já foi confirmado e o estoque está reservado.\n\nPara cancelar um pedido operacional, digite *ATENDENTE* e a equipe fará a análise.",'operational_cancel_boundary');

        if(in_array($normalized,['verificar','verificar pagamento','paguei','ja paguei','já paguei','status pagamento'],true))return $this->checkStatus($pdo,$tenantId,$conversationId,$order);
        if(in_array($normalized,['formas','forma de pagamento','pagamento','alterar pagamento'],true))return $this->prompt($pdo,$tenantId,$conversationId,$orderId);

        if($step==='profile_email')return $this->collectEmail($pdo,$tenantId,$conversationId,$order,$context,$text);
        if($step==='profile_document')return $this->collectDocumentAndCreatePix($pdo,$tenantId,$conversationId,$order,$context,$text);
        if($step==='cash_change')return $this->selectCash($pdo,$tenantId,$conversationId,$order,$text);
        if($step==='awaiting_payment'){
            if(in_array($normalized,['1','pix','reenviar pix','reenviar código','reenviar codigo'],true)&&($context['payment_method']??'')==='pix')return $this->createPix($pdo,$tenantId,$conversationId,$order);
            return $this->awaitingResult($pdo,$tenantId,$conversationId,$order,$context);
        }
        if($step==='cash_selected')return $this->cashSelectedResult($pdo,$tenantId,$conversationId,$order,$context);
        if($step==='unavailable')return $this->prompt($pdo,$tenantId,$conversationId,$orderId);

        $options=is_array($context['payment_options']??null)?$context['payment_options']:[];
        if(!$options)return $this->prompt($pdo,$tenantId,$conversationId,$orderId);
        $choice=$options[$normalized]??null;if(!is_array($choice))return $this->prompt($pdo,$tenantId,$conversationId,$orderId,'Escolha uma das opções disponíveis.');
        return match((string)($choice['method']??'')){
            'pix'=>$this->beginPix($pdo,$tenantId,$conversationId,$order),
            'card'=>$this->selectCard($pdo,$tenantId,$conversationId,$order),
            'cash'=>$this->askCashChange($pdo,$tenantId,$conversationId,$order),
            default=>$this->prompt($pdo,$tenantId,$conversationId,$orderId,'Forma de pagamento inválida.'),
        };
    }

    public function syncPaidConversations(?PDO $pdo=null,?int $tenantId=null,int $limit=50):int
    {
        $pdo??=Database::connection();$tenantId??=(int)(Auth::tenantId()??0);if($tenantId<1)return 0;$limit=max(1,min(200,$limit));
        $q=$pdo->prepare("SELECT c.id,c.phone,c.active_order_id,o.total_cents,o.status FROM whatsapp_conversations c JOIN orders o ON o.id=c.active_order_id AND o.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.mode='auto' AND c.state='CHOOSING_PAYMENT' AND o.order_source='WHATSAPP' AND o.payment_status='paid' ORDER BY c.id LIMIT ".$limit);$q->execute([$tenantId]);$queued=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $conversationId=(int)$row['id'];$orderId=(int)$row['active_order_id'];$phone=(string)$row['phone'];$message=$this->paidMessage($orderId,(int)$row['total_cents'],(string)$row['status']);$key=hash('sha256','whatsapp-commerce-payment-confirmed|'.$tenantId.'|'.$conversationId.'|'.$orderId);
            try{
                $pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?,'commerce_auto',?,?,'desktop_queued',0,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$orderId,$phone,$message,$key]);$outboxId=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,outbox_id,direction,message_type,message_text,status) VALUES (?,?,?,'outbound','text',?,'queued')")->execute([$tenantId,$conversationId,$outboxId,$message]);$queued++;
            }catch(\PDOException $e){$exists=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$exists->execute([$tenantId,$key]);if(!$exists->fetchColumn())throw $e;}
            $json=json_encode(['order_id'=>$orderId,'payment_status'=>'paid'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare("UPDATE whatsapp_conversations SET state='ORDER_ACTIVE',context_json=?,last_outbound_at=CURRENT_TIMESTAMP,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND state='CHOOSING_PAYMENT'")->execute([$json,$conversationId,$tenantId]);
            $this->audit('payment_confirmed',$orderId,['conversation_id'=>$conversationId,'source'=>'connect_sync']);
        }
        return $queued;
    }

    /** @return array<string,mixed> */
    private function beginPix(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $token=(string)$order['public_token'];$service=new PublicOrderPaymentService();$profile=$service->currentProfile($pdo,$token);$email=trim((string)($profile['email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>(int)$order['id'],'payment_step'=>'profile_email','payment_method'=>'pix'],"Para gerar o PIX, informe seu *e-mail*.\n\nSeu CPF já fica salvo no cadastro do EventMenu e não será solicitado novamente quando estiver válido.",'payment_profile_email');
        if(empty($profile['document_configured']))return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>(int)$order['id'],'payment_step'=>'profile_document','payment_method'=>'pix','payment_email'=>$email],"Não encontrei um CPF válido no cadastro.\n\nInforme o *CPF ou CNPJ* do pagador para continuar.",'payment_profile_document');
        return $this->createPix($pdo,$tenantId,$conversationId,$order);
    }

    /** @return array<string,mixed> */
    private function collectEmail(PDO $pdo,int $tenantId,int $conversationId,array $order,array $context,string $text):array
    {
        $email=mb_strtolower(trim($text));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return $this->stateResult($pdo,$tenantId,$conversationId,$context,"E-mail inválido. Envie um endereço como nome@exemplo.com.",'payment_profile_email_invalid');
        $service=new PublicOrderPaymentService();$token=(string)$order['public_token'];$profileOrder=$service->order($pdo,$token);$document=trim((string)($profileOrder['customer_document']??''));
        if($document!==''){
            try{$service->profile($pdo,$token,$email,$document);return $this->createPix($pdo,$tenantId,$conversationId,$order);}catch(RuntimeException){}
        }
        $context['payment_step']='profile_document';$context['payment_email']=$email;
        return $this->stateResult($pdo,$tenantId,$conversationId,$context,"Não encontrei um CPF válido no cadastro.\n\nInforme o *CPF ou CNPJ* do pagador para continuar.",'payment_profile_document');
    }

    /** @return array<string,mixed> */
    private function collectDocumentAndCreatePix(PDO $pdo,int $tenantId,int $conversationId,array $order,array $context,string $text):array
    {
        $email=trim((string)($context['payment_email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return $this->beginPix($pdo,$tenantId,$conversationId,$order);
        (new PublicOrderPaymentService())->profile($pdo,(string)$order['public_token'],$email,$text);
        return $this->createPix($pdo,$tenantId,$conversationId,$order);
    }

    /** @return array<string,mixed> */
    private function createPix(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $service=new PublicOrderPaymentService();$payment=$service->pix($pdo,(string)$order['public_token'],'');$provider=(string)($payment['provider']??'');if($provider!=='')(new OrderPaymentPreferenceService())->set($pdo,$tenantId,(int)$order['id'],'pix',$provider,null,'whatsapp');
        $copy=trim((string)($payment['copy_paste']??''));if($copy==='')throw new RuntimeException('O provedor não retornou o PIX Copia e Cola.');$expires=trim((string)($payment['expires_at']??''));$lines=['⚡ *PIX GERADO*','Pedido #'.(int)$order['id'].' · '.$this->money((int)$payment['amount_cents']),'','Copie o código abaixo e pague no seu banco:','',$copy,''];if($expires!=='')$lines[]='Validade da cobrança: '.$this->friendlyDate($expires);$lines[]='';$lines[]='Assim que o pagamento for confirmado, eu aviso por aqui automaticamente.';$lines[]='Se quiser conferir antes, digite *PAGUEI*.';
        $context=['order_id'=>(int)$order['id'],'payment_step'=>'awaiting_payment','payment_method'=>'pix','payment_provider'=>$provider,'payment_id'=>(int)($payment['payment_id']??0)];$this->audit('pix_created',(int)$order['id'],['payment_id'=>(int)($payment['payment_id']??0),'provider'=>$provider]);
        return $this->stateResult($pdo,$tenantId,$conversationId,$context,implode("\n",$lines),'pix_created');
    }

    /** @return array<string,mixed> */
    private function selectCard(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $methods=(new DeliveryPaymentMethodService())->forTenant($pdo,$tenantId);if(!(array)($methods['card']??[]))throw new RuntimeException('Pagamento por cartão não está disponível.');$url=\app_absolute_url('pedido.php?t='.rawurlencode((string)$order['public_token']));$context=['order_id'=>(int)$order['id'],'payment_step'=>'awaiting_payment','payment_method'=>'card'];$reply="💳 *PAGAMENTO COM CARTÃO*\n\nPor segurança, número do cartão e CVV não são enviados pelo WhatsApp.\n\nAbra o checkout seguro do EventMenu:\n{$url}\n\nO cartão é tokenizado pelo provedor de pagamento e os dados sensíveis não passam pela conversa.\n\nDepois do pagamento, a confirmação chegará automaticamente por aqui. Você também pode digitar *PAGUEI* para conferir.";$this->audit('card_checkout_selected',(int)$order['id'],['provider'=>'mercadopago']);
        return $this->stateResult($pdo,$tenantId,$conversationId,$context,$reply,'card_checkout');
    }

    /** @return array<string,mixed> */
    private function askCashChange(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $methods=(new DeliveryPaymentMethodService())->forTenant($pdo,$tenantId);if(empty($methods['cash']))throw new RuntimeException('Pagamento em dinheiro não está disponível.');
        return $this->stateResult($pdo,$tenantId,$conversationId,['order_id'=>(int)$order['id'],'payment_step'=>'cash_change','payment_method'=>'cash'],"💵 *DINHEIRO*\n\nTotal do pedido: *".$this->money((int)$order['total_cents'])."*\n\nPrecisa de troco?\n• Digite *0* para sem troco\n• Ou informe o valor que vai entregar, por exemplo: *100,00*",'cash_change');
    }

    /** @return array<string,mixed> */
    private function selectCash(PDO $pdo,int $tenantId,int $conversationId,array $order,string $text):array
    {
        $normalized=$this->normalize($text);$change=null;if(!in_array($normalized,['0','nao','não','sem','sem troco'],true)){$change=$this->moneyToCents($text);if($change<(int)$order['total_cents'])return $this->askCashChange($pdo,$tenantId,$conversationId,$order);}
        $result=(new PublicOrderPaymentService())->cash($pdo,(string)$order['public_token'],$change);(new StockReservationService())->holdForPayment($tenantId,(int)$order['id']);(new OrderPaymentPreferenceService())->set($pdo,$tenantId,(int)$order['id'],'cash',null,$change,'whatsapp');$context=['order_id'=>(int)$order['id'],'payment_step'=>'cash_selected','payment_method'=>'cash','change_for_cents'=>$change];$json=json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare("UPDATE whatsapp_conversations SET state='ORDER_ACTIVE',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$json,$conversationId,$tenantId]);$reply="💵 Pagamento em dinheiro selecionado para o pedido #".(int)$order['id'].".\nTotal: *".$this->money((int)$order['total_cents'])."*".($change!==null?"\nTroco para: *".$this->money($change)."*":"\nSem troco informado")."\n\nO estoque continua reservado e a cobrança será concluída pela operação no recebimento do dinheiro.\nDigite *MEU PEDIDO* para acompanhar.";$this->audit('cash_selected',(int)$order['id'],['change_for_cents'=>$change]);
        return ['handled'=>true,'state'=>'ORDER_ACTIVE','context'=>$context,'active_order_id'=>(int)$order['id'],'reply'=>$reply,'kind'=>'cash_selected','payment'=>$result];
    }

    /** @return array<string,mixed> */
    private function checkStatus(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $status=(new PublicOrderPaymentService())->status($pdo,(string)$order['public_token']);$fresh=$this->order($pdo,$tenantId,(int)$order['id']);if((string)$fresh['payment_status']==='paid'||(string)($status['payment_status']??'')==='paid')return $this->paidResult($pdo,$tenantId,$conversationId,$fresh);
        $preference=(new OrderPaymentPreferenceService())->get($pdo,$tenantId,(int)$order['id']);$context=['order_id'=>(int)$order['id'],'payment_step'=>'awaiting_payment','payment_method'=>(string)($preference['method']??'')];
        return $this->stateResult($pdo,$tenantId,$conversationId,$context,"⏳ O pagamento do pedido #".(int)$order['id']." ainda não foi confirmado.\n\nSe você acabou de pagar, aguarde alguns instantes. O EventMenu também confirma automaticamente pelo webhook/reconciliação do provedor.",'payment_pending');
    }

    /** @return array<string,mixed> */
    private function awaitingResult(PDO $pdo,int $tenantId,int $conversationId,array $order,array $context):array
    {
        return $this->stateResult($pdo,$tenantId,$conversationId,$context,"⏳ Pedido #".(int)$order['id']." aguardando confirmação do pagamento.\n\nDigite *PAGUEI* para verificar agora ou *FORMAS* para ver as formas de pagamento.",'payment_pending');
    }

    /** @return array<string,mixed> */
    private function cashSelectedResult(PDO $pdo,int $tenantId,int $conversationId,array $order,array $context):array
    {
        return ['handled'=>true,'state'=>'ORDER_ACTIVE','context'=>$context,'active_order_id'=>(int)$order['id'],'reply'=>"Pedido #".(int)$order['id']." está com pagamento em dinheiro selecionado.\nDigite *MEU PEDIDO* para acompanhar.",'kind'=>'cash_selected'];
    }

    /** @return array<string,mixed> */
    private function paidResult(PDO $pdo,int $tenantId,int $conversationId,array $order):array
    {
        $context=['order_id'=>(int)$order['id'],'payment_status'=>'paid'];$json=json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare("UPDATE whatsapp_conversations SET state='ORDER_ACTIVE',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$json,$conversationId,$tenantId]);$this->audit('payment_confirmed',(int)$order['id'],['conversation_id'=>$conversationId,'source'=>'inbound_check']);
        return ['handled'=>true,'state'=>'ORDER_ACTIVE','context'=>$context,'active_order_id'=>(int)$order['id'],'reply'=>$this->paidMessage((int)$order['id'],(int)$order['total_cents'],(string)$order['status']),'kind'=>'payment_confirmed'];
    }

    /** @return array<string,mixed> */
    private function order(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare("SELECT o.*,c.email customer_email,c.document customer_document FROM orders o LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id WHERE o.id=? AND o.tenant_id=? AND o.order_source='WHATSAPP' LIMIT 1");$q->execute([$orderId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Pedido do WhatsApp não encontrado.');if(trim((string)($row['public_token']??''))==='')throw new RuntimeException('Pedido sem link público de pagamento.');return$row;
    }

    /** @return array<string,mixed> */
    private function stateResult(PDO $pdo,int $tenantId,int $conversationId,array $context,string $reply,string $kind):array
    {
        $json=json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$pdo->prepare("UPDATE whatsapp_conversations SET state='CHOOSING_PAYMENT',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$json,$conversationId,$tenantId]);return ['handled'=>true,'state'=>'CHOOSING_PAYMENT','context'=>$context,'active_order_id'=>$context['order_id']??null,'reply'=>$reply,'kind'=>$kind];
    }

    private function paidMessage(int $orderId,int $totalCents,string $status):string
    {
        $statusLabel=match($status){'confirmed'=>'confirmado','preparing'=>'em preparação','ready'=>'pronto','out_for_delivery'=>'saiu para entrega','completed'=>'concluído',default=>'recebido'};return "✅ *PAGAMENTO CONFIRMADO*\n\nPedido #{$orderId}\nTotal: *".$this->money($totalCents)."*\nStatus: {$statusLabel}\n\nSeu pedido já está no fluxo normal do EventMenu. Digite *MEU PEDIDO* para acompanhar.";
    }

    private function moneyToCents(string $value):int
    {
        $raw=trim(mb_strtolower($value));$raw=str_replace(['r$',' '],'',$raw);if($raw==='')throw new RuntimeException('Informe um valor válido para troco.');if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);if(!is_numeric($raw))throw new RuntimeException('Informe o valor para troco, por exemplo 100,00.');return max(0,(int)round(((float)$raw)*100));
    }
    private function context(mixed $raw):array{if(is_array($raw))return$raw;if(!is_string($raw)||trim($raw)==='')return[];$d=json_decode($raw,true);return is_array($d)?$d:[];}
    private function normalize(string $value):string{$value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:");}
    private function money(int $cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
    private function friendlyDate(string $value):string{$ts=strtotime($value);return $ts===false?$value:date('d/m/Y H:i',$ts);}
    private function audit(string $event,int $orderId,array $metadata):void{try{Auth::audit('whatsapp.commerce.'.$event,'order',(string)$orderId,$metadata);}catch(Throwable){}}
}
