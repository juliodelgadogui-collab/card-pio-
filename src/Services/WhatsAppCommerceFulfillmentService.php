<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCommerceFulfillmentService
{
    private CustomerIdentityService $identity;
    private CustomerAddressService $addresses;
    private WhatsAppCommerceOrderService $orders;

    public function __construct()
    {
        $this->identity=new CustomerIdentityService();
        $this->addresses=new CustomerAddressService();
        $this->orders=new WhatsAppCommerceOrderService();
    }

    /** @return array<string,mixed> */
    public function handle(PDO $pdo,int $tenantId,array $conversation,string $text):array
    {
        $state=(string)($conversation['state']??'CHOOSING_FULFILLMENT');
        $context=$this->context($conversation['context_json']??null);
        $normalized=$this->normalize($text);
        $conversationId=(int)$conversation['id'];
        $draftId=$this->draftId($pdo,$tenantId,$conversation);

        if($state==='CHOOSING_PAYMENT')return $this->paymentBoundary($pdo,$tenantId,$conversationId,$conversation,$normalized);
        if($draftId<1)throw new RuntimeException('O carrinho não está mais disponível para finalizar.');

        if($normalized==='cancelar')return $this->cancelDraft($pdo,$tenantId,$conversationId,$draftId);
        if($normalized==='meu pedido')return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,$context);

        if($state==='CHOOSING_FULFILLMENT'){
            $settings=$this->settings($pdo,$tenantId);$this->assertAcceptingOrders($settings);
            if($normalized==='1')return $this->startFulfillment($pdo,$tenantId,$conversationId,$conversation,$draftId,'delivery');
            if($normalized==='2'){
                if(empty($settings['delivery_pickup_enabled']))return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$settings,'A retirada no local não está habilitada para esta empresa.');
                return $this->startFulfillment($pdo,$tenantId,$conversationId,$conversation,$draftId,'pickup');
            }
            return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$settings,'Escolha 1 para entrega ou 2 para retirada.');
        }

        if($state==='ASKING_CUSTOMER_NAME'){
            $name=mb_substr(trim($text),0,160);if(mb_strlen($name)<2)return $this->stateResult($pdo,$tenantId,$conversationId,'ASKING_CUSTOMER_NAME',$context,'Informe seu nome para continuar o pedido.','customer_name_invalid');
            $phone=(string)$conversation['phone'];$customer=$this->identity->findOrCreate($pdo,$tenantId,$name,$phone);$customerId=(int)$customer['id'];
            $pdo->prepare('UPDATE whatsapp_conversations SET customer_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$customerId,$conversationId,$tenantId]);
            $pdo->prepare('UPDATE orders SET customer_id=? WHERE id=? AND tenant_id=? AND status="draft" AND order_source="WHATSAPP"')->execute([$customerId,$draftId,$tenantId]);
            $this->audit('customer_identified',$draftId,['customer_id'=>$customerId]);
            $context['customer_id']=$customerId;
            if(($context['fulfillment']??'')==='delivery')return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,$context);
            return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,$context);
        }

        if($state==='SELECTING_ADDRESS'){
            $customerId=$this->customerId($pdo,$tenantId,$conversation,$draftId);if($customerId<1)return $this->askCustomerName($pdo,$tenantId,$conversationId,$draftId,'delivery');
            if(in_array($normalized,['9','novo','novo endereço','novo endereco'],true))return $this->stateResult($pdo,$tenantId,$conversationId,'ENTERING_ADDRESS',['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery'],"Digite o endereço completo para entrega.\n\nExemplo: Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ\n\nDigite 0 para voltar.",'address_new');
            if($normalized==='0')return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$this->settings($pdo,$tenantId));
            if(!ctype_digit($normalized))return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,$context,'Escolha um endereço pelo número ou digite 9 para cadastrar outro.');
            $addresses=$this->addresses->list($pdo,$tenantId,$customerId,8);$choice=(int)$normalized;
            if($choice<1||$choice>count($addresses))return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,$context,'Endereço inválido.');
            $address=$addresses[$choice-1];$context=['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery','address_id'=>(int)$address['id'],'address_text'=>(string)$address['address_text']];
            return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,$context);
        }

        if($state==='ENTERING_ADDRESS'){
            if($normalized==='0'){$customerId=$this->customerId($pdo,$tenantId,$conversation,$draftId);return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery']);}
            $customerId=$this->customerId($pdo,$tenantId,$conversation,$draftId);if($customerId<1)return $this->askCustomerName($pdo,$tenantId,$conversationId,$draftId,'delivery');
            $address=$this->addresses->saveText($pdo,$tenantId,$customerId,$text,'WhatsApp',true);
            $context=['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery','address_id'=>(int)$address['id'],'address_text'=>(string)$address['address_text']];
            $this->audit('address_saved',$draftId,['customer_id'=>$customerId,'address_id'=>(int)$address['id']]);
            return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,$context);
        }

        if($state==='CONFIRMING_FULFILLMENT'){
            if($normalized==='0')return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$this->settings($pdo,$tenantId));
            if($normalized!=='1')return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,$context,'Digite 1 para confirmar ou 0 para voltar.');
            return $this->operationalize($pdo,$tenantId,$conversationId,$conversation,$draftId,$context);
        }

        return ['handled'=>false];
    }

    /** @return array<string,mixed> */
    private function startFulfillment(PDO $pdo,int $tenantId,int $conversationId,array $conversation,int $draftId,string $fulfillment):array
    {
        $customerId=$this->customerId($pdo,$tenantId,$conversation,$draftId);
        if($customerId<1)return $this->askCustomerName($pdo,$tenantId,$conversationId,$draftId,$fulfillment);
        if($fulfillment==='delivery')return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery']);
        return $this->confirmationResult($pdo,$tenantId,$conversationId,$draftId,['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'pickup']);
    }

    /** @return array<string,mixed> */
    private function askCustomerName(PDO $pdo,int $tenantId,int $conversationId,int $draftId,string $fulfillment):array
    {
        return $this->stateResult($pdo,$tenantId,$conversationId,'ASKING_CUSTOMER_NAME',['draft_order_id'=>$draftId,'fulfillment'=>$fulfillment],"Antes de finalizar, qual é o seu nome?\n\nEsse nome será vinculado ao seu telefone para facilitar os próximos pedidos.",'customer_name');
    }

    /** @return array<string,mixed> */
    private function fulfillmentPrompt(PDO $pdo,int $tenantId,int $conversationId,int $draftId,array $settings,string $prefix=''):array
    {
        $this->assertAcceptingOrders($settings);$lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='Como deseja receber o pedido?';$lines[]='';$lines[]='1 - 🛵 Entrega';if(!empty($settings['delivery_pickup_enabled']))$lines[]='2 - 🛍️ Retirada no local';$lines[]='';$lines[]='Digite *CANCELAR* para cancelar o carrinho.';
        return $this->stateResult($pdo,$tenantId,$conversationId,'CHOOSING_FULFILLMENT',['draft_order_id'=>$draftId],trim(implode("\n",$lines)),'fulfillment_prompt');
    }

    /** @return array<string,mixed> */
    private function addressesResult(PDO $pdo,int $tenantId,int $conversationId,int $draftId,int $customerId,array $context,string $prefix=''):array
    {
        $addresses=$this->addresses->list($pdo,$tenantId,$customerId,8);if(!$addresses)return $this->stateResult($pdo,$tenantId,$conversationId,'ENTERING_ADDRESS',['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery'],"Não encontrei endereço salvo para você.\n\nDigite o endereço completo para entrega.\nExemplo: Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ\n\nDigite 0 para voltar.",'address_new');
        $lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='📍 *Escolha o endereço de entrega:*';$lines[]='';foreach($addresses as$i=>$address){$default=(int)$address['is_default']===1?' ⭐':'';$lines[]=($i+1).' - '.(string)$address['label'].$default;$lines[]='   '.(string)$address['address_text'];}$lines[]='';$lines[]='9 - Cadastrar novo endereço';$lines[]='0 - Voltar';$context=['draft_order_id'=>$draftId,'customer_id'=>$customerId,'fulfillment'=>'delivery'];
        return $this->stateResult($pdo,$tenantId,$conversationId,'SELECTING_ADDRESS',$context,trim(implode("\n",$lines)),'address_list');
    }

    /** @return array<string,mixed> */
    private function confirmationResult(PDO $pdo,int $tenantId,int $conversationId,int $draftId,array $context,string $prefix=''):array
    {
        $settings=$this->settings($pdo,$tenantId);$this->assertAcceptingOrders($settings);$fulfillment=(string)($context['fulfillment']??'');if(!in_array($fulfillment,['delivery','pickup'],true))return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$settings);
        if($fulfillment==='pickup'&&empty($settings['delivery_pickup_enabled']))return $this->fulfillmentPrompt($pdo,$tenantId,$conversationId,$draftId,$settings,'A retirada no local foi desativada.');
        $customerId=$this->customerId($pdo,$tenantId,['id'=>$conversationId,'customer_id'=>$context['customer_id']??null],$draftId);if($customerId<1)return $this->askCustomerName($pdo,$tenantId,$conversationId,$draftId,$fulfillment);$context['customer_id']=$customerId;
        $order=$this->orders->validateDraftForCheckout($pdo,$tenantId,$draftId);$subtotal=(int)$order['subtotal_cents'];$fee=$fulfillment==='delivery'?max(0,(int)($settings['delivery_fee_cents']??0)):0;$minimum=max(0,(int)($settings['min_delivery_order_cents']??0));if($fulfillment==='delivery'&&$subtotal<$minimum)throw new RuntimeException('Pedido mínimo para entrega: '.$this->money($minimum).'.');
        $address='';if($fulfillment==='delivery'){$address=trim((string)($context['address_text']??''));if($address===''&&!empty($context['address_id'])){$saved=$this->addresses->get($pdo,$tenantId,$customerId,(int)$context['address_id']);$address=(string)$saved['address_text'];}$address=mb_substr(trim($address),0,1000);if($address==='')return $this->addressesResult($pdo,$tenantId,$conversationId,$draftId,$customerId,$context);$context['address_text']=$address;}
        $total=$subtotal+$fee;$lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='✅ *CONFIRMAR PEDIDO*';$lines[]='';$lines[]='Recebimento: '.($fulfillment==='delivery'?'Entrega':'Retirada no local');if($address!=='')$lines[]='Endereço: '.$address;$lines[]='Subtotal: '.$this->money($subtotal);if($fulfillment==='delivery')$lines[]='Taxa de entrega: '.$this->money($fee);$lines[]='*Total: '.$this->money($total).'*';$lines[]='';$lines[]='1 - Confirmar pedido';$lines[]='0 - Voltar';$context['draft_order_id']=$draftId;$context['fulfillment']=$fulfillment;$context['quoted_subtotal_cents']=$subtotal;$context['quoted_delivery_fee_cents']=$fee;$context['quoted_total_cents']=$total;
        return $this->stateResult($pdo,$tenantId,$conversationId,'CONFIRMING_FULFILLMENT',$context,trim(implode("\n",$lines)),'fulfillment_confirmation');
    }

    /** @return array<string,mixed> */
    private function operationalize(PDO $pdo,int $tenantId,int $conversationId,array $conversation,int $draftId,array $context):array
    {
        $savepoint='wa_stage2';$pdo->exec('SAVEPOINT '.$savepoint);
        try{
            $lock=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? AND status="draft" AND order_source="WHATSAPP" LIMIT 1 FOR UPDATE'));$lock->execute([$draftId,$tenantId]);$draft=$lock->fetch(PDO::FETCH_ASSOC);if(!$draft)throw new RuntimeException('Este carrinho já foi finalizado ou cancelado.');
            $settings=$this->settings($pdo,$tenantId);$this->assertAcceptingOrders($settings);$fulfillment=(string)($context['fulfillment']??'');if(!in_array($fulfillment,['delivery','pickup'],true))throw new RuntimeException('Escolha entrega ou retirada.');if($fulfillment==='pickup'&&empty($settings['delivery_pickup_enabled']))throw new RuntimeException('A retirada no local não está disponível agora.');
            $customerId=$this->customerId($pdo,$tenantId,$conversation,$draftId);if($customerId<1)throw new RuntimeException('Informe seu nome antes de confirmar o pedido.');
            $order=$this->orders->validateDraftForCheckout($pdo,$tenantId,$draftId);$subtotal=(int)$order['subtotal_cents'];$minimum=max(0,(int)($settings['min_delivery_order_cents']??0));if($fulfillment==='delivery'&&$subtotal<$minimum)throw new RuntimeException('Pedido mínimo para entrega: '.$this->money($minimum).'.');
            $fee=$fulfillment==='delivery'?max(0,(int)($settings['delivery_fee_cents']??0)):0;$total=$subtotal+$fee;$address=null;
            if($fulfillment==='delivery'){$address=mb_substr(trim((string)($context['address_text']??'')),0,1000);if($address==='')throw new RuntimeException('Escolha um endereço de entrega.');}
            $pdo->prepare('UPDATE orders SET customer_id=?,channel=?,delivery_address=?,delivery_fee_cents=?,total_cents=?,status="pending",payment_status="unpaid",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="draft" AND order_source="WHATSAPP"')->execute([$customerId,$fulfillment,$address,$fee,$total,$draftId,$tenantId]);
            $expires=(new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');(new StockReservationService())->reserve($pdo,$tenantId,$draftId,[],$expires);
            (new OrderHistoryService())->record($pdo,$tenantId,$draftId,'draft','pending','whatsapp',$fulfillment==='delivery'?'Pedido do WhatsApp confirmado para entrega.':'Pedido do WhatsApp confirmado para retirada.');
            $nextContext=['order_id'=>$draftId,'fulfillment'=>$fulfillment,'total_cents'=>$total,'stock_reserved_until'=>$expires];$json=json_encode($nextContext,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $pdo->prepare('UPDATE whatsapp_conversations SET draft_order_id=NULL,active_order_id=?,state="CHOOSING_PAYMENT",context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$draftId,$json,$conversationId,$tenantId]);
            $this->audit('order_operational',$draftId,['customer_id'=>$customerId,'channel'=>$fulfillment,'delivery_fee_cents'=>$fee,'total_cents'=>$total]);
            $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            $reply="Pedido #{$draftId} recebido. ✅\n\n".($fulfillment==='delivery'?"Entrega\nTaxa: ".$this->money($fee)."\n":'Retirada no local'."\n")."Total: *".$this->money($total)."*\n\nOs itens foram reservados no estoque. A escolha da forma de pagamento será liberada na próxima etapa.";
            return ['handled'=>true,'state'=>'CHOOSING_PAYMENT','context'=>$nextContext,'draft_order_id'=>null,'active_order_id'=>$draftId,'reply'=>$reply,'kind'=>'order_operational'];
        }catch(Throwable $e){
            try{$pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$pdo->exec('RELEASE SAVEPOINT '.$savepoint);}catch(Throwable){}
            return $this->stateResult($pdo,$tenantId,$conversationId,'CONFIRMING_FULFILLMENT',$context,"⚠️ Não foi possível confirmar o pedido: ".$e->getMessage()."\n\nRevise o carrinho ou tente novamente.\n1 - Tentar confirmar novamente\n0 - Voltar",'fulfillment_failed');
        }
    }

    /** @return array<string,mixed> */
    private function paymentBoundary(PDO $pdo,int $tenantId,int $conversationId,array $conversation,string $normalized):array
    {
        $orderId=(int)($conversation['active_order_id']??0);if($orderId<1)throw new RuntimeException('Pedido ativo não encontrado.');
        if($normalized==='cancelar')return $this->stateResult($pdo,$tenantId,$conversationId,'CHOOSING_PAYMENT',['order_id'=>$orderId],"O pedido #{$orderId} já foi confirmado e o estoque está reservado.\n\nO cancelamento operacional será tratado junto das regras de pagamento. Digite *ATENDENTE* se precisar de ajuda agora.",'operational_cancel_boundary');
        $q=$pdo->prepare('SELECT channel,total_cents,payment_status FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order)throw new RuntimeException('Pedido ativo não encontrado.');
        return $this->stateResult($pdo,$tenantId,$conversationId,'CHOOSING_PAYMENT',['order_id'=>$orderId,'fulfillment'=>(string)$order['channel'],'total_cents'=>(int)$order['total_cents']],"Pedido #{$orderId}\nTotal: *".$this->money((int)$order['total_cents'])."*\n\nA escolha da forma de pagamento será habilitada na ETAPA 3.\nDigite *ATENDENTE* se precisar falar com a equipe.",'payment_boundary');
    }

    /** @return array<string,mixed> */
    private function cancelDraft(PDO $pdo,int $tenantId,int $conversationId,int $draftId):array
    {
        $pdo->prepare('UPDATE orders SET status="cancelled",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="draft" AND order_source="WHATSAPP"')->execute([$draftId,$tenantId]);$pdo->prepare('UPDATE whatsapp_conversations SET draft_order_id=NULL,state="WELCOME",context_json=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);$this->audit('draft_cancelled',$draftId,[]);
        return ['handled'=>true,'state'=>'WELCOME','context'=>[],'draft_order_id'=>null,'reply'=>"Pedido em montagem cancelado.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'cart_cancelled'];
    }

    private function customerId(PDO $pdo,int $tenantId,array $conversation,int $draftId):int
    {
        $id=(int)($conversation['customer_id']??0);if($id<1){$q=$pdo->prepare('SELECT customer_id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$draftId,$tenantId]);$id=(int)($q->fetchColumn()?:0);}return $id;
    }

    private function draftId(PDO $pdo,int $tenantId,array $conversation):int
    {
        $id=(int)($conversation['draft_order_id']??0);if($id<1)return 0;$q=$pdo->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND status="draft" AND order_source="WHATSAPP" LIMIT 1');$q->execute([$id,$tenantId]);return $q->fetchColumn()?$id:0;
    }

    /** @return array<string,mixed> */
    private function settings(PDO $pdo,int $tenantId):array
    {
        $q=$pdo->prepare('SELECT status,settings FROM tenants WHERE id=? LIMIT 1');$q->execute([$tenantId]);$tenant=$q->fetch(PDO::FETCH_ASSOC);if(!$tenant||(string)$tenant['status']!=='active')throw new RuntimeException('Empresa indisponível.');$settings=json_decode((string)($tenant['settings']??'{}'),true);return is_array($settings)?$settings:[];
    }

    private function assertAcceptingOrders(array $settings):void
    { if(!empty($settings['delivery_paused']))throw new RuntimeException('A empresa pausou novos pedidos no momento.'); }

    /** @return array<string,mixed> */
    private function stateResult(PDO $pdo,int $tenantId,int $conversationId,string $state,array $context,string $reply,string $kind):array
    {
        $json=$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;$pdo->prepare('UPDATE whatsapp_conversations SET state=?,context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$state,$json,$conversationId,$tenantId]);return ['handled'=>true,'state'=>$state,'context'=>$context,'reply'=>$reply,'kind'=>$kind,'draft_order_id'=>$context['draft_order_id']??null,'active_order_id'=>$context['order_id']??null];
    }

    private function context(mixed $raw):array
    {if(is_array($raw))return$raw;if(!is_string($raw)||trim($raw)==='')return[];$decoded=json_decode($raw,true);return is_array($decoded)?$decoded:[];}
    private function normalize(string $value):string
    {$value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:");}
    private function money(int $cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
    private function audit(string $event,int $orderId,array $metadata):void
    {try{Auth::audit('whatsapp.commerce.'.$event,'order',(string)$orderId,$metadata);}catch(Throwable){}}
}
