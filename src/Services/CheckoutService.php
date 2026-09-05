<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class CheckoutService
{
    public function create(string $publicToken,string $provider): array
    {
        $provider=strtolower(trim($provider));if(!in_array($provider,['stripe','mercadopago','pagbank'],true))throw new RuntimeException('Provedor inválido.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT o.*,t.slug tenant_slug,t.status tenant_status,c.name customer_name,c.email customer_email,c.phone customer_phone FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.public_token=? LIMIT 1');$s->execute([$publicToken]);$order=$s->fetch();
        if(!$order||$order['tenant_status']!=='active')throw new RuntimeException('Pedido não encontrado.');
        if(in_array($order['status'],['cancelled','completed'],true)||$order['payment_status']==='paid')throw new RuntimeException('Pedido não aceita nova cobrança.');
        if((int)$order['total_cents']<=0)throw new RuntimeException('Pedido com valor inválido.');
        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1');$g->execute([$order['tenant_id'],$provider]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Forma de pagamento indisponível.');$config=Crypto::decryptJson($gateway['config_encrypted']);

        $open=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1');$open->execute([$order['tenant_id'],$order['id']]);$existing=$open->fetch();
        if($existing){$raw=json_decode((string)($existing['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_checkout_url']))return ['provider'=>$existing['provider'],'url'=>$raw['_eventmenu_checkout_url'],'payment_id'=>$existing['id'],'reused'=>true];throw new RuntimeException('Já existe uma cobrança em andamento para este pedido.');}

        $key='public:'.$provider.':'.$order['tenant_id'].':'.$order['id'];
        try{$ins=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")');$ins->execute([$order['tenant_id'],$order['id'],$provider,$key,$order['total_cents']]);$paymentId=(int)$pdo->lastInsertId();}catch(\PDOException $e){$x=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=?');$x->execute([$order['tenant_id'],$key]);$row=$x->fetch();if($row){$raw=json_decode((string)($row['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_checkout_url']))return ['provider'=>$row['provider'],'url'=>$raw['_eventmenu_checkout_url'],'payment_id'=>$row['id'],'reused'=>true];}throw $e;}
        $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$order['id']]);
        try{
            $result=match($provider){'stripe'=>$this->stripe($order,$gateway,$config,$key),'mercadopago'=>$this->mercadoPago($order,$gateway,$config,$key),'pagbank'=>$this->pagBank($order,$gateway,$config,$key)};
            $payload=$result['raw'];$payload['_eventmenu_checkout_url']=$result['url'];
            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=?')->execute([$result['external_id'],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
            return ['provider'=>$provider,'url'=>$result['url'],'payment_id'=>$paymentId,'reused'=>false];
        }catch(\Throwable $e){$pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=?')->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$paymentId]);$pdo->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND payment_status="pending"')->execute([$order['id']]);throw $e;}
    }

    private function stripe(array $order,array $gateway,array $config,string $key): array
    {
        if(!class_exists('Stripe\\StripeClient'))throw new RuntimeException('Execute composer install para habilitar Stripe.');$secret=(string)($config['secret_key']??'');if($secret==='')throw new RuntimeException('Stripe não configurado.');$client=new \Stripe\StripeClient($secret);$account=$client->accounts->retrieve();if((string)$account->id!==(string)$gateway['account_reference'])throw new RuntimeException('Conta Stripe divergente.');$metadata=['tenant_id'=>(string)$order['tenant_id'],'order_id'=>(string)$order['id']];
        $session=$client->checkout->sessions->create(['mode'=>'payment','line_items'=>[['price_data'=>['currency'=>'brl','unit_amount'=>(int)$order['total_cents'],'product_data'=>['name'=>'Pedido EventMenu #'.$order['id']]],'quantity'=>1]],'client_reference_id'=>(string)$order['id'],'customer_email'=>$order['customer_email']?:null,'metadata'=>$metadata,'payment_intent_data'=>['metadata'=>$metadata],'success_url'=>\app_absolute_url('pedido.php?t='.rawurlencode($order['public_token']).'&retorno=sucesso'),'cancel_url'=>\app_absolute_url('pedido.php?t='.rawurlencode($order['public_token']).'&retorno=cancelado')],['idempotency_key'=>$key]);
        return ['url'=>(string)$session->url,'external_id'=>(string)$session->id,'raw'=>$session->toArray()];
    }

    private function mercadoPago(array $order,array $gateway,array $config,string $key): array
    {
        $token=(string)($config['access_token']??'');if($token==='')throw new RuntimeException('Mercado Pago não configurado.');$notification=\app_absolute_url('webhook.php?provider=mercadopago&tenant='.rawurlencode($order['tenant_slug']));$return=\app_absolute_url('pedido.php?t='.rawurlencode($order['public_token']));
        $body=['items'=>[['id'=>'order-'.$order['id'],'title'=>'Pedido EventMenu #'.$order['id'],'quantity'=>1,'currency_id'=>'BRL','unit_price'=>((int)$order['total_cents'])/100]],'external_reference'=>'eventmenu:'.$order['tenant_id'].':'.$order['id'],'back_urls'=>['success'=>$return,'pending'=>$return,'failure'=>$return],'notification_url'=>$notification,'metadata'=>['tenant_id'=>$order['tenant_id'],'order_id'=>$order['id']]];
        $data=$this->httpJson('POST','https://api.mercadopago.com/checkout/preferences',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$key],$body);$url=(string)($data['init_point']??'');if($url==='')throw new RuntimeException('Mercado Pago não retornou URL de pagamento.');return ['url'=>$url,'external_id'=>(string)($data['id']??''),'raw'=>$data];
    }

    private function pagBank(array $order,array $gateway,array $config,string $key): array
    {
        $token=(string)($config['token']??'');if($token==='')throw new RuntimeException('PagBank não configurado.');$api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$notification=\app_absolute_url('webhook.php?provider=pagbank&tenant='.rawurlencode($order['tenant_slug']));$return=\app_absolute_url('pedido.php?t='.rawurlencode($order['public_token']));
        $body=['reference_id'=>'eventmenu:'.$order['tenant_id'].':'.$order['id'],'items'=>[['reference_id'=>'order-'.$order['id'],'name'=>'Pedido EventMenu #'.$order['id'],'quantity'=>1,'unit_amount'=>(int)$order['total_cents']]],'payment_methods'=>[['type'=>'CREDIT_CARD'],['type'=>'PIX']], 'redirect_url'=>$return,'return_url'=>$return,'redirect_waiting_time'=>5,'notification_urls'=>[$notification],'payment_notification_urls'=>[$notification]];
        $data=$this->httpJson('POST',$api.'/checkouts',['Authorization: Bearer '.$token,'x-idempotency-key: '.$key],$body);$url='';foreach(($data['links']??[]) as $link){if(($link['rel']??'')==='PAY'){$url=(string)$link['href'];break;}}if($url==='')throw new RuntimeException('PagBank não retornou link de pagamento.');return ['url'=>$url,'external_id'=>(string)($data['id']??''),'raw'=>$data];
    }

    private function httpJson(string $method,string $url,array $headers,array $body): array
    {
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha HTTP.');$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException('Gateway recusou a criação da cobrança (HTTP '.$status.')'.($err?' '.$err:''));$data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta inválida do gateway.');return $data;
    }
}
