<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use PDO;
use RuntimeException;

final class PublicOrderPaymentService
{
    public function methods(PDO $pdo,string $publicToken):array
    {
        $order=$this->order($pdo,$publicToken);
        return (new DeliveryPaymentMethodService())->forTenant($pdo,(int)$order['tenant_id']);
    }

    public function profile(PDO $pdo,string $publicToken,string $email,string $document):array
    {
        $order=$this->order($pdo,$publicToken);$customerId=(int)($order['customer_id']??0);if($customerId<1)throw new RuntimeException('Cliente do pedido não encontrado.');
        $email=mb_strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');$document=$this->validTaxId($document);
        $pdo->prepare('UPDATE customers SET email=?,document=? WHERE id=? AND tenant_id=?')->execute([$email,$document,$customerId,(int)$order['tenant_id']]);
        return ['email'=>$email,'document_configured'=>true,'document_masked'=>$this->maskTaxId($document)];
    }

    public function currentProfile(PDO $pdo,string $publicToken):array
    {
        $order=$this->order($pdo,$publicToken);$document=preg_replace('/\D+/','',(string)($order['customer_document']??''))??'';
        return ['email'=>(string)($order['customer_email']??''),'document_configured'=>in_array(strlen($document),[11,14],true),'document_masked'=>$document!==''?$this->maskTaxId($document):''];
    }

    public function pix(PDO $pdo,string $publicToken,string $provider):array
    {
        $ctx=$this->context($pdo,$publicToken,$provider);$doc=$this->validTaxId((string)($ctx['order']['customer_document']??''));$email=trim((string)($ctx['account']['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe e salve seu e-mail antes de gerar o PIX.');
        return (new DeliveryCustomerPaymentService())->pixForContext($pdo,$ctx,(int)$ctx['order']['id'],$provider,$doc);
    }

    public function card(PDO $pdo,string $publicToken,array $payload):array
    {
        $ctx=$this->context($pdo,$publicToken,'mercadopago');$doc=$this->validTaxId((string)($ctx['order']['customer_document']??''));$email=trim((string)($ctx['account']['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe e salve seu e-mail antes de pagar com cartão.');$payload['provider']='mercadopago';$payload['tax_id']=$doc;
        return (new DeliveryCustomerPaymentService())->cardForContext($pdo,$ctx,(int)$ctx['order']['id'],$payload);
    }

    public function cash(PDO $pdo,string $publicToken,?int $changeForCents=null):array
    {
        $order=$this->order($pdo,$publicToken);$methods=(new DeliveryPaymentMethodService())->forTenant($pdo,(int)$order['tenant_id']);if(empty($methods['cash']))throw new RuntimeException('Pagamento em dinheiro não está disponível.');
        if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita alteração de pagamento.');
        $active=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") LIMIT 1');$active->execute([(int)$order['tenant_id'],(int)$order['id']]);if($active->fetchColumn())throw new RuntimeException('Já existe uma cobrança eletrônica em andamento. Aguarde o resultado antes de trocar para dinheiro.');
        $changeForCents=$changeForCents!==null?max(0,$changeForCents):null;if($changeForCents!==null&&$changeForCents<(int)$order['total_cents'])throw new RuntimeException('O valor para troco deve ser maior ou igual ao total do pedido.');
        if((string)$order['payment_status']==='failed')$pdo->prepare('UPDATE orders SET payment_status="unpaid" WHERE id=? AND tenant_id=?')->execute([(int)$order['id'],(int)$order['tenant_id']]);
        $message=$changeForCents===null?'Cliente selecionou pagamento em dinheiro.':'Cliente selecionou dinheiro e informou troco para R$ '.number_format($changeForCents/100,2,',','.').'.';
        try{(new OrderHistoryService())->record($pdo,(int)$order['tenant_id'],(int)$order['id'],(string)$order['status'],(string)$order['status'],'public',$message,null);}catch(\Throwable){}
        return ['method'=>'cash','status'=>'selected','change_for_cents'=>$changeForCents];
    }

    public function status(PDO $pdo,string $publicToken):array
    {
        $order=$this->order($pdo,$publicToken);
        return (new DeliveryCustomerPaymentService())->statusForOrder($pdo,(int)$order['tenant_id'],(int)$order['id']);
    }

    public function order(PDO $pdo,string $publicToken):array
    {
        $publicToken=trim($publicToken);if($publicToken===''||strlen($publicToken)>100)throw new RuntimeException('Pedido inválido.');
        $q=$pdo->prepare('SELECT o.*,c.name customer_name,c.email customer_email,c.phone customer_phone,c.document customer_document,t.slug tenant_slug FROM orders o LEFT JOIN customers c ON c.id=o.customer_id JOIN tenants t ON t.id=o.tenant_id AND t.status="active" WHERE o.public_token=? LIMIT 1');$q->execute([$publicToken]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');return$order;
    }

    private function context(PDO $pdo,string $publicToken,string $provider):array
    {
        $order=$this->order($pdo,$publicToken);$provider=strtolower(trim($provider));$g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 LIMIT 1');$g->execute([(int)$order['tenant_id'],$provider]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Forma de pagamento indisponível.');
        return ['order'=>$order,'account'=>['id'=>(int)($order['customer_id']??0),'name'=>(string)($order['customer_name']??'Cliente EventMenu'),'email'=>(string)($order['customer_email']??''),'phone'=>(string)($order['customer_phone']??'')],'tenant_slug'=>(string)$order['tenant_slug'],'gateway'=>$gateway,'config'=>Crypto::decryptJson((string)$gateway['config_encrypted']),'key_namespace'=>'web-native','channel'=>'eventmenu_web'];
    }

    private function validTaxId(string $value):string
    {
        $v=preg_replace('/\D+/','',$value)??'';if(!in_array(strlen($v),[11,14],true)||preg_match('/^(\d)\1+$/',$v))throw new RuntimeException('Informe um CPF ou CNPJ válido.');
        if(strlen($v)===11){for($t=9;$t<11;$t++){$sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$v[$i]*(($t+1)-$i);$d=(10*($sum%11))%11;if($d===10)$d=0;if((int)$v[$t]!==$d)throw new RuntimeException('CPF inválido.');}return$v;}
        $calc=static function(string $base,array $weights):int{$sum=0;foreach($weights as$i=>$w)$sum+=(int)$base[$i]*$w;$r=$sum%11;return$r<2?0:11-$r;};$d1=$calc(substr($v,0,12),[5,4,3,2,9,8,7,6,5,4,3,2]);$d2=$calc(substr($v,0,12).$d1,[6,5,4,3,2,9,8,7,6,5,4,3,2]);if((int)$v[12]!==$d1||(int)$v[13]!==$d2)throw new RuntimeException('CNPJ inválido.');return$v;
    }

    private function maskTaxId(string $document):string
    {
        $digits=preg_replace('/\D+/','',$document)??'';if(strlen($digits)===11)return'***.***.***-'.substr($digits,-2);if(strlen($digits)===14)return'**.***.***/****-'.substr($digits,-2);return'';
    }
}
