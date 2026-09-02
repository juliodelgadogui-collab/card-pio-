<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CheckoutService
{
    private const CHECKOUT_MINUTES=40;

    public function create(string $publicToken,string $provider):array
    {
        $provider=strtolower(trim($provider));
        if(!in_array($provider,['stripe','mercadopago','pagbank'],true))throw new RuntimeException('Provedor inválido.');

        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.*,t.slug tenant_slug,t.status tenant_status,c.name customer_name,c.email customer_email,c.phone customer_phone FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.public_token=? LIMIT 1');
        $s->execute([$publicToken]);
        $order=$s->fetch();
        if(!$order||$order['tenant_status']!=='active')throw new RuntimeException('Pedido não encontrado.');
        if(in_array($order['status'],['cancelled','completed'],true)||in_array($order['payment_status'],['paid','refunded'],true))throw new RuntimeException('Pedido não aceita nova cobrança.');
        if((int)$order['total_cents']<=0)throw new RuntimeException('Pedido com valor inválido.');

        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1');
        $g->execute([$order['tenant_id'],$provider]);
        $gateway=$g->fetch();
        if(!$gateway)throw new RuntimeException('Forma de pagamento indisponível.');
        $config=Crypto::decryptJson($gateway['config_encrypted']);

        $open=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1');
        $open->execute([$order['tenant_id'],$order['id']]);
        $openPayment=$open->fetch();
        if($openPayment&&!empty($openPayment['checkout_expires_at'])&&new \DateTimeImmutable((string)$openPayment['checkout_expires_at'])<=new \DateTimeImmutable()){
            $state=(new CheckoutReconciliationService())->reconcilePayment((int)$openPayment['id']);
            if($state==='paid')throw new RuntimeException('Pagamento já foi confirmado. Atualize a página.');
            if($state!=='cancelled')throw new RuntimeException('O checkout expirou, mas a conciliação com o provedor ainda está pendente. Não será criada outra cobrança até essa situação ser resolvida.');
            $openPayment=null;
        }
        if($openPayment){
            $raw=$this->decodePayload($openPayment['raw_payload']??null);
            if(!empty($raw['_eventmenu_checkout_url']))return ['provider'=>$openPayment['provider'],'url'=>$raw['_eventmenu_checkout_url'],'payment_id'=>(int)$openPayment['id'],'reused'=>true];
            if($openPayment['provider']!==$provider)throw new RuntimeException('Já existe uma cobrança em andamento para este pedido.');
        }

        if(!$openPayment){
            if(!empty($order['expires_at'])&&new \DateTimeImmutable()>new \DateTimeImmutable((string)$order['expires_at']))throw new RuntimeException('O tempo para iniciar o pagamento expirou. Faça um novo pedido.');
            if($order['channel']==='event'){
                $r=$pdo->prepare('SELECT MIN(reserved_until) FROM tickets WHERE order_id=? AND status="reserved"');
                $r->execute([$order['id']]);
                $until=$r->fetchColumn();
                if(!$until||new \DateTimeImmutable()>new \DateTimeImmutable((string)$until))throw new RuntimeException('A reserva dos ingressos expirou. Faça uma nova reserva.');
            }
        }

        $attempt=$this->prepareAttempt($order,$provider);
        $paymentId=(int)$attempt['id'];
        $key=(string)$attempt['idempotency_key'];
        $providerKey=$this->providerIdempotencyKey($key);
        $expiresAt=new \DateTimeImmutable((string)$attempt['checkout_expires_at']);
        $attemptRaw=$this->decodePayload($attempt['raw_payload']??null);

        if($provider==='mercadopago'&&(bool)$attempt['_reused']){
            $recovered=$this->recoverMercadoPagoPreference($order,$config,$expiresAt);
            if($recovered!==null)return $this->persistCheckoutResult($paymentId,$provider,$recovered,$expiresAt,true,$providerKey);
            if(!empty($attemptRaw['_eventmenu_checkout_uncertain']))throw new RuntimeException('A criação anterior no Mercado Pago ficou sem resposta conclusiva. O EventMenu não criará outra preferência até a conciliação confirmar o estado da tentativa.');
        }

        try{
            $result=match($provider){
                'stripe'=>$this->stripe($order,$gateway,$config,$providerKey,$expiresAt),
                'mercadopago'=>$this->mercadoPago($order,$gateway,$config,$providerKey,$expiresAt),
                'pagbank'=>$this->pagBank($order,$gateway,$config,$providerKey,$expiresAt),
            };
            return $this->persistCheckoutResult($paymentId,$provider,$result,$expiresAt,(bool)$attempt['_reused'],$providerKey);
        }catch(\Throwable $e){
            if($provider==='mercadopago'&&!$this->isDefinitiveGatewayFailure($e)){
                try{
                    $recovered=$this->recoverMercadoPagoPreference($order,$config,$expiresAt);
                    if($recovered!==null)return $this->persistCheckoutResult($paymentId,$provider,$recovered,$expiresAt,true,$providerKey);
                }catch(\Throwable){}
                $payload=['error'=>$e->getMessage(),'idempotency_key'=>$key,'provider_idempotency_key'=>$providerKey,'_eventmenu_checkout_uncertain'=>true,'_eventmenu_checkout_expires_at'=>$expiresAt->format(DATE_ATOM)];
                $pdo->prepare('UPDATE payments SET status="created",raw_payload=? WHERE id=? AND status<>"paid"')->execute([json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
                $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND payment_status<>"paid"')->execute([$order['id']]);
                throw new RuntimeException('O Mercado Pago não retornou uma resposta conclusiva. A tentativa foi preservada para conciliação e nenhuma nova cobrança será criada automaticamente.',0,$e);
            }

            $pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=? AND status<>"paid"')->execute([json_encode(['error'=>$e->getMessage(),'idempotency_key'=>$key,'provider_idempotency_key'=>$providerKey,'_eventmenu_checkout_expires_at'=>$expiresAt->format(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
            $pdo->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND payment_status="pending"')->execute([$order['id']]);
            throw $e;
        }
    }

    private function prepareAttempt(array $order,string $provider):array
    {
        return Database::transaction(function(PDO $pdo)use($order,$provider){
            $lock=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $lock->execute([$order['id'],$order['tenant_id']]);
            $lockedOrder=$lock->fetch();
            if(!$lockedOrder||in_array($lockedOrder['status'],['cancelled','completed'],true)||in_array($lockedOrder['payment_status'],['paid','refunded'],true))throw new RuntimeException('Pedido não aceita nova cobrança.');

            $current=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $current->execute([$order['tenant_id'],$order['id']]);
            $row=$current->fetch();
            if($row){
                if($row['provider']!==$provider)throw new RuntimeException('Já existe uma cobrança em andamento para outro provedor.');
                if(!empty($row['checkout_expires_at'])&&new \DateTimeImmutable((string)$row['checkout_expires_at'])<=new \DateTimeImmutable())throw new RuntimeException('Cobrança expirada aguardando conciliação.');
                $row['_reused']=true;
                return $row;
            }

            $last=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $last->execute([$order['tenant_id'],$order['id'],$provider]);
            $previous=$last->fetch();
            if($previous&&$previous['status']==='failed'&&!empty($previous['checkout_expires_at'])&&new \DateTimeImmutable((string)$previous['checkout_expires_at'])>new \DateTimeImmutable()){
                $pdo->prepare('UPDATE payments SET status="created",raw_payload=NULL WHERE id=?')->execute([$previous['id']]);
                $previous['status']='created';
                $previous['raw_payload']=null;
                $previous['_reused']=true;
                $this->extendReservation($pdo,$lockedOrder,new \DateTimeImmutable((string)$previous['checkout_expires_at']));
                return $previous;
            }

            if($previous&&$lockedOrder['channel']!=='table'&&!empty($previous['checkout_expires_at'])&&new \DateTimeImmutable((string)$previous['checkout_expires_at'])<=new \DateTimeImmutable())throw new RuntimeException('A janela de pagamento deste pedido terminou. Faça um novo pedido ou uma nova reserva.');

            $count=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND provider=?');
            $count->execute([$order['tenant_id'],$order['id'],$provider]);
            $attempt=(int)$count->fetchColumn()+1;
            $key='public:'.$provider.':'.$order['tenant_id'].':'.$order['id'].':'.$attempt;
            $expiresAt=(new \DateTimeImmutable())->modify('+'.self::CHECKOUT_MINUTES.' minutes');
            $ins=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status,checkout_expires_at) VALUES (?,?,?,?,?,"BRL","created",?)');
            $ins->execute([$order['tenant_id'],$order['id'],$provider,$key,$order['total_cents'],$expiresAt->format('Y-m-d H:i:s')]);
            $id=(int)$pdo->lastInsertId();
            $this->extendReservation($pdo,$lockedOrder,$expiresAt);
            return ['id'=>$id,'tenant_id'=>$order['tenant_id'],'order_id'=>$order['id'],'provider'=>$provider,'idempotency_key'=>$key,'amount_cents'=>$order['total_cents'],'status'=>'created','checkout_expires_at'=>$expiresAt->format('Y-m-d H:i:s'),'raw_payload'=>null,'_reused'=>false];
        });
    }

    private function extendReservation(PDO $pdo,array $order,\DateTimeImmutable $expiresAt):void
    {
        $expires=$expiresAt->format('Y-m-d H:i:s');
        if(in_array($order['channel'],['event','delivery','pickup'],true))$pdo->prepare('UPDATE orders SET payment_status="pending",expires_at=? WHERE id=? AND tenant_id=?')->execute([$expires,$order['id'],$order['tenant_id']]);
        else $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND tenant_id=?')->execute([$order['id'],$order['tenant_id']]);
        if($order['channel']==='event')$pdo->prepare('UPDATE tickets SET reserved_until=? WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$expires,$order['tenant_id'],$order['id']]);
        $pdo->prepare('UPDATE coupon_reservations SET expires_at=? WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$expires,$order['tenant_id'],$order['id']]);
    }

    private function decodePayload(mixed $payload):array
    {
        if(!is_string($payload)||$payload==='')return [];
        $decoded=json_decode($payload,true);
        return is_array($decoded)?$decoded:[];
    }

    private function persistCheckoutResult(int $paymentId,string $provider,array $result,\DateTimeImmutable $expiresAt,bool $reused,string $providerKey):array
    {
        $url=(string)($result['url']??'');
        $externalId=(string)($result['external_id']??'');
        if($url===''||$externalId==='')throw new RuntimeException('Resposta incompleta do gateway.');
        $payload=is_array($result['raw']??null)?$result['raw']:[];
        $payload['_eventmenu_checkout_url']=$url;
        $payload['_eventmenu_checkout_expires_at']=$expiresAt->format(DATE_ATOM);
        $payload['_eventmenu_provider_idempotency_key']=$providerKey;
        Database::connection()->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=?')->execute([$externalId,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
        return ['provider'=>$provider,'url'=>$url,'payment_id'=>$paymentId,'reused'=>$reused,'expires_at'=>$expiresAt->format(DATE_ATOM)];
    }

    private function providerIdempotencyKey(string $localKey):string
    {
        return strtoupper(hash('sha256',$localKey));
    }

    private function isDefinitiveGatewayFailure(\Throwable $e):bool
    {
        return preg_match('/HTTP\s+4\d\d\b/',$e->getMessage())===1;
    }

    private function stripe(array $order,array $gateway,array $config,string $providerKey,\DateTimeImmutable $expiresAt):array
    {
        if(!class_exists('Stripe\\StripeClient'))throw new RuntimeException('Execute composer install para habilitar Stripe.');
        $secret=(string)($config['secret_key']??'');
        if($secret==='')throw new RuntimeException('Stripe não configurado.');
        $client=new \Stripe\StripeClient($secret);
        $account=$client->accounts->retrieve();
        if((string)$account->id!==(string)$gateway['account_reference'])throw new RuntimeException('Conta Stripe divergente.');
        $base=rtrim((string)env('APP_URL',''),'/');
        $metadata=['tenant_id'=>(string)$order['tenant_id'],'order_id'=>(string)$order['id']];
        $session=$client->checkout->sessions->create([
            'mode'=>'payment',
            'line_items'=>[['price_data'=>['currency'=>'brl','unit_amount'=>(int)$order['total_cents'],'product_data'=>['name'=>'Pedido EventMenu #'.$order['id']]],'quantity'=>1]],
            'client_reference_id'=>(string)$order['id'],
            'customer_email'=>$order['customer_email']?:null,
            'metadata'=>$metadata,
            'payment_intent_data'=>['metadata'=>$metadata],
            'expires_at'=>$expiresAt->getTimestamp(),
            'success_url'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']).'&retorno=sucesso',
            'cancel_url'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']).'&retorno=cancelado',
        ],['idempotency_key'=>$providerKey]);
        return ['url'=>(string)$session->url,'external_id'=>(string)$session->id,'raw'=>$session->toArray()];
    }

    private function mercadoPago(array $order,array $gateway,array $config,string $providerKey,\DateTimeImmutable $expiresAt):array
    {
        $token=(string)($config['access_token']??'');
        if($token==='')throw new RuntimeException('Mercado Pago não configurado.');
        $base=rtrim((string)env('APP_URL',''),'/');
        $notification=$base.'/webhook.php?provider=mercadopago&tenant='.rawurlencode($order['tenant_slug']);
        $startsAt=$expiresAt->modify('-'.self::CHECKOUT_MINUTES.' minutes');
        $body=[
            'items'=>[['id'=>'order-'.$order['id'],'title'=>'Pedido EventMenu #'.$order['id'],'quantity'=>1,'currency_id'=>'BRL','unit_price'=>((int)$order['total_cents'])/100]],
            'external_reference'=>'eventmenu:'.$order['tenant_id'].':'.$order['id'],
            'back_urls'=>['success'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']),'pending'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']),'failure'=>$base.'/pedido.php?t='.rawurlencode($order['public_token'])],
            'notification_url'=>$notification,
            'metadata'=>['tenant_id'=>$order['tenant_id'],'order_id'=>$order['id']],
            'expires'=>true,
            'expiration_date_from'=>$startsAt->format(DATE_ATOM),
            'expiration_date_to'=>$expiresAt->format(DATE_ATOM),
        ];
        $data=$this->httpJson('POST','https://api.mercadopago.com/checkout/preferences',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$providerKey],$body);
        $url=(string)($data['init_point']??'');
        if($url==='')throw new RuntimeException('Mercado Pago não retornou URL de pagamento.');
        return ['url'=>$url,'external_id'=>(string)($data['id']??''),'raw'=>$data];
    }

    private function recoverMercadoPagoPreference(array $order,array $config,\DateTimeImmutable $expiresAt):?array
    {
        $token=(string)($config['access_token']??'');
        if($token==='')return null;
        $reference='eventmenu:'.$order['tenant_id'].':'.$order['id'];
        $url='https://api.mercadopago.com/checkout/preferences/search?'.http_build_query(['external_reference'=>$reference,'limit'=>20]);
        $data=$this->httpGetJson($url,['Authorization: Bearer '.$token]);
        $best=null;
        foreach(($data['elements']??[]) as $candidate){
            if((string)($candidate['external_reference']??$reference)!==$reference)continue;
            $id=(string)($candidate['id']??'');
            if($id==='')continue;
            $detail=$this->httpGetJson('https://api.mercadopago.com/checkout/preferences/'.rawurlencode($id),['Authorization: Bearer '.$token]);
            if((string)($detail['external_reference']??'')!==$reference)continue;
            $to=(string)($detail['expiration_date_to']??'');
            if($to!==''){
                try{if(new \DateTimeImmutable($to)<new \DateTimeImmutable())continue;}catch(\Throwable){}
            }
            $initPoint=(string)($detail['init_point']??'');
            if($initPoint==='')continue;
            if($to!==''){
                try{if(abs((new \DateTimeImmutable($to))->getTimestamp()-$expiresAt->getTimestamp())>120)continue;}catch(\Throwable){}
            }
            $best=['url'=>$initPoint,'external_id'=>$id,'raw'=>$detail];
            break;
        }
        return $best;
    }

    private function pagBank(array $order,array $gateway,array $config,string $providerKey,\DateTimeImmutable $expiresAt):array
    {
        $token=(string)($config['token']??'');
        if($token==='')throw new RuntimeException('PagBank não configurado.');
        $api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');
        $base=rtrim((string)env('APP_URL',''),'/');
        $notification=$base.'/webhook.php?provider=pagbank&tenant='.rawurlencode($order['tenant_slug']);
        $body=[
            'reference_id'=>'eventmenu:'.$order['tenant_id'].':'.$order['id'],
            'items'=>[['reference_id'=>'order-'.$order['id'],'name'=>'Pedido EventMenu #'.$order['id'],'quantity'=>1,'unit_amount'=>(int)$order['total_cents']]],
            'payment_methods'=>[['type'=>'CREDIT_CARD'],['type'=>'PIX']],
            'expiration_date'=>$expiresAt->format(DATE_ATOM),
            'redirect_url'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']),
            'return_url'=>$base.'/pedido.php?t='.rawurlencode($order['public_token']),
            'redirect_waiting_time'=>5,
            'notification_urls'=>[$notification],
            'payment_notification_urls'=>[$notification],
        ];
        $data=$this->httpJson('POST',$api.'/checkouts',['Authorization: Bearer '.$token,'x-idempotency-key: '.$providerKey],$body);
        $url='';
        foreach(($data['links']??[]) as $link){if(($link['rel']??'')==='PAY'){$url=(string)$link['href'];break;}}
        if($url==='')throw new RuntimeException('PagBank não retornou link de pagamento.');
        return ['url'=>$url,'external_id'=>(string)($data['id']??''),'raw'=>$data];
    }

    private function httpJson(string $method,string $url,array $headers,array $body):array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Falha HTTP.');
        $headers[]='Accept: application/json';
        $headers[]='Content-Type: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $err=curl_error($ch);
        curl_close($ch);
        if($response===false||$status<200||$status>=300)throw new RuntimeException('Gateway recusou a criação da cobrança (HTTP '.$status.')'.($err?' '.$err:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Resposta inválida do gateway.');
        return $data;
    }

    private function httpGetJson(string $url,array $headers):array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Falha HTTP durante recuperação do checkout.');
        $headers[]='Accept: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $err=curl_error($ch);
        curl_close($ch);
        if($response===false||$status<200||$status>=300)throw new RuntimeException('Falha ao recuperar checkout no provedor (HTTP '.$status.')'.($err?' '.$err:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Resposta inválida ao recuperar checkout.');
        return $data;
    }
}
