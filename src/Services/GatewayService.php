<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class GatewayService
{
    private const PROVIDERS=['stripe','pagbank','mercadopago'];

    public function save(string $provider,string $accountReference,array $config,string $webhookSecret,bool $active):void
    {
        Auth::requirePermission('gateways.manage');
        $tenantId=Auth::tenantId();
        $provider=strtolower(trim($provider));
        if(!$tenantId||!in_array($provider,self::PROVIDERS,true)) throw new RuntimeException('Gateway inválido.');
        if($active&&trim($accountReference)==='') throw new RuntimeException('Informe a conta recebedora do gateway.');

        $pdo=Database::connection();
        if(Database::isSqlite($pdo)){
            $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,?,?) ON CONFLICT(tenant_id,provider) DO UPDATE SET account_reference=excluded.account_reference,config_encrypted=excluded.config_encrypted,webhook_secret_encrypted=COALESCE(excluded.webhook_secret_encrypted,payment_gateways.webhook_secret_encrypted),active=excluded.active';
        }else{
            $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_reference=VALUES(account_reference),config_encrypted=VALUES(config_encrypted),webhook_secret_encrypted=COALESCE(VALUES(webhook_secret_encrypted),webhook_secret_encrypted),active=VALUES(active)';
        }
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$tenantId,$provider,$accountReference,Crypto::encrypt($config),$webhookSecret!==''?Crypto::encrypt($webhookSecret):null,$active?1:0]);
        Auth::audit('gateway.saved','gateway',$provider,['active'=>$active]);
    }

    public function processWebhook(string $provider,string $tenantSlug,string $rawBody,array $headers,array $query):array
    {
        $provider=strtolower($provider);
        if(!in_array($provider,self::PROVIDERS,true)) throw new RuntimeException('Provedor não suportado.');
        $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT g.*,t.id tenant_id,t.slug tenant_slug FROM payment_gateways g JOIN tenants t ON t.id=g.tenant_id WHERE t.slug=? AND t.status="active" AND g.provider=? AND g.active=1 LIMIT 1');
        $stmt->execute([$tenantSlug,$provider]);
        $gateway=$stmt->fetch();
        if(!$gateway) throw new RuntimeException('Gateway não localizado.');
        $tenantId=(int)$gateway['tenant_id'];
        $config=Crypto::decryptJson($gateway['config_encrypted']);
        $secret=Crypto::decrypt($gateway['webhook_secret_encrypted']);
        if($secret==='') throw new RuntimeException('Segredo do webhook não configurado.');

        $verified=match($provider){
            'stripe'=>$this->verifyStripe($gateway,$config,$secret,$rawBody,$headers),
            'pagbank'=>$this->verifyPagBank($gateway,$config,$secret,$rawBody,$headers),
            'mercadopago'=>$this->verifyMercadoPago($gateway,$config,$secret,$rawBody,$headers,$query),
        };
        $eventId=(string)$verified['external_event_id'];
        $hash=hash('sha256',$rawBody);
        try{
            $ins=$pdo->prepare('INSERT INTO webhook_events (tenant_id,provider,external_event_id,signature_valid,payload_hash,status) VALUES (?,?,?,1,?,"received")');
            $ins->execute([$tenantId,$provider,$eventId,$hash]);
        }catch(\PDOException $e){
            if((string)$e->getCode()==='23000'||str_contains(strtolower($e->getMessage()),'unique')) return ['ok'=>true,'duplicate'=>true];
            throw $e;
        }
        try{
            if(!empty($verified['paid'])){
                (new PaymentService())->confirmVerified($verified);
                $pdo->prepare('UPDATE webhook_events SET status="processed",processed_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND provider=? AND external_event_id=?')->execute([$tenantId,$provider,$eventId]);
                return ['ok'=>true,'processed'=>true];
            }
            $terminal=$this->synchronizeTerminalUnpaid($verified);
            $pdo->prepare('UPDATE webhook_events SET status=?,processed_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND provider=? AND external_event_id=?')->execute([$terminal?'processed':'ignored',$tenantId,$provider,$eventId]);
            return ['ok'=>true,'processed'=>$terminal,'paid'=>false];
        }catch(\Throwable $e){
            $pdo->prepare('UPDATE webhook_events SET status="failed" WHERE tenant_id=? AND provider=? AND external_event_id=?')->execute([$tenantId,$provider,$eventId]);
            throw $e;
        }
    }

    private function verifyStripe(array $gateway,array $config,string $secret,string $raw,array $headers):array
    {
        if(!class_exists('Stripe\\Webhook')||!class_exists('Stripe\\StripeClient')) throw new RuntimeException('Execute composer install para habilitar Stripe.');
        $signature=$this->header($headers,'stripe-signature');
        if($signature==='') throw new RuntimeException('Stripe-Signature ausente.');
        $event=\Stripe\Webhook::constructEvent($raw,$signature,$secret,300);
        $eventId=(string)$event->id;
        $object=$event->data->object??null;
        $paymentIntentId='';
        if(($object->object??'')==='payment_intent') $paymentIntentId=(string)$object->id;
        elseif(($object->payment_intent??'')!=='') $paymentIntentId=(string)$object->payment_intent;
        if($paymentIntentId==='') return ['external_event_id'=>$eventId,'paid'=>false];
        $key=(string)($config['secret_key']??'');
        if($key==='') throw new RuntimeException('Chave Stripe ausente.');
        $client=new \Stripe\StripeClient($key);
        $account=$client->accounts->retrieve();
        if((string)$account->id!==(string)$gateway['account_reference']) throw new RuntimeException('Conta Stripe divergente.');
        $pi=$client->paymentIntents->retrieve($paymentIntentId,[]);
        $orderId=(int)($pi->metadata->order_id??0);
        $tenantId=(int)($pi->metadata->tenant_id??0);
        if($tenantId!==(int)$gateway['tenant_id']||$orderId<1) throw new RuntimeException('Metadados Stripe inválidos.');
        return ['external_event_id'=>$eventId,'paid'=>(string)$pi->status==='succeeded','tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'stripe','provider_payment_id'=>(string)$pi->id,'amount_cents'=>(int)($pi->amount_received?:$pi->amount),'currency'=>strtoupper((string)$pi->currency),'account_reference'=>(string)$account->id,'raw_status'=>(string)$pi->status];
    }

    private function verifyPagBank(array $gateway,array $config,string $secret,string $raw,array $headers):array
    {
        $received=$this->header($headers,'x-authenticity-token');
        $expected=hash('sha256',$secret.'-'.$raw);
        if($received===''||!hash_equals(strtolower($expected),strtolower(trim($received)))) throw new RuntimeException('Assinatura PagBank inválida.');
        $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $orderExternal=(string)($payload['id']??'');
        if($orderExternal==='') throw new RuntimeException('Pedido PagBank ausente.');
        $token=(string)($config['token']??'');
        if($token==='') throw new RuntimeException('Token PagBank ausente.');
        $base=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');
        $order=$this->httpJson('GET',$base.'/orders/'.rawurlencode($orderExternal),['Authorization: Bearer '.$token]);
        [$tenantId,$orderId]=$this->parseReference((string)($order['reference_id']??''));
        if($tenantId!==(int)$gateway['tenant_id']) throw new RuntimeException('Empresa PagBank divergente.');
        $charges=is_array($order['charges']??null)?$order['charges']:[];$paidCharge=null;$latestCharge=$charges[0]??null;
        foreach($charges as $charge){if(($charge['status']??'')==='PAID'){$paidCharge=$charge;break;}}
        $statusSource=$paidCharge??$latestCharge;
        return ['external_event_id'=>$orderExternal.':'.hash('sha256',$raw),'paid'=>$paidCharge!==null,'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'pagbank','provider_payment_id'=>(string)($paidCharge['id']??$orderExternal),'amount_cents'=>is_array($statusSource)?(int)($statusSource['amount']['value']??0):0,'currency'=>'BRL','account_reference'=>(string)$gateway['account_reference'],'raw_status'=>is_array($statusSource)?(string)($statusSource['status']??''):''];
    }

    private function verifyMercadoPago(array $gateway,array $config,string $secret,string $raw,array $headers,array $query):array
    {
        if(!class_exists('MercadoPago\\Webhook\\WebhookSignatureValidator')) throw new RuntimeException('Execute composer install para habilitar Mercado Pago.');
        $sig=$this->header($headers,'x-signature');
        $requestId=$this->header($headers,'x-request-id');
        $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $dataId=(string)($query['data.id']??$query['data_id']??($payload['data']['id']??''));
        if($sig===''||$requestId===''||$dataId==='') throw new RuntimeException('Cabeçalhos Mercado Pago incompletos.');
        \MercadoPago\Webhook\WebhookSignatureValidator::validate($sig,$requestId,$dataId,$secret);
        $token=(string)($config['access_token']??'');
        if($token==='') throw new RuntimeException('Access token Mercado Pago ausente.');
        $payment=$this->httpJson('GET','https://api.mercadopago.com/v1/payments/'.rawurlencode($dataId),['Authorization: Bearer '.$token]);
        [$tenantId,$orderId]=$this->parseReference((string)($payment['external_reference']??''));
        if($tenantId!==(int)$gateway['tenant_id']) throw new RuntimeException('Empresa Mercado Pago divergente.');
        $collector=(string)($payment['collector_id']??'');
        if($collector===''||$collector!==(string)$gateway['account_reference']) throw new RuntimeException('Conta Mercado Pago divergente.');
        return ['external_event_id'=>(string)($payload['id']??$requestId.':'.$dataId.':'.($payload['action']??'')),'paid'=>(($payment['status']??'')==='approved'),'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'mercadopago','provider_payment_id'=>(string)$payment['id'],'amount_cents'=>(int)round(((float)($payment['transaction_amount']??0))*100),'currency'=>(string)($payment['currency_id']??'BRL'),'account_reference'=>$collector,'raw_status'=>(string)($payment['status']??'')];
    }

    private function synchronizeTerminalUnpaid(array $verified):bool
    {
        $provider=strtolower((string)($verified['provider']??''));$rawStatus=strtolower(trim((string)($verified['raw_status']??'')));
        $target=match($provider){
            'mercadopago'=>match($rawStatus){'rejected','refunded','charged_back'=>'failed','cancelled'=>'cancelled',default=>null},
            'pagbank'=>match($rawStatus){'declined','expired'=>'failed','canceled','cancelled'=>'cancelled',default=>null},
            'stripe'=>match($rawStatus){'canceled'=>'cancelled','requires_payment_method'=>'failed',default=>null},
            default=>null,
        };
        if($target===null)return false;
        $tenantId=(int)($verified['tenant_id']??0);$orderId=(int)($verified['order_id']??0);if($tenantId<1||$orderId<1)return false;

        return Database::transaction(function(\PDO $tx)use($verified,$provider,$rawStatus,$target,$tenantId,$orderId):bool{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE'));
            $q->execute([$tenantId,$orderId,$provider]);$payment=$q->fetch();if(!$payment)return false;
            $providerId=trim((string)($verified['provider_payment_id']??''));$localProviderId=trim((string)($payment['provider_payment_id']??''));
            if($providerId!==''&&$localProviderId!==''&&$providerId!==$localProviderId)throw new RuntimeException('Transação terminal não corresponde à cobrança local.');
            $currency=strtoupper(trim((string)($verified['currency']??'BRL')));if($currency!==''&&$currency!=='BRL')throw new RuntimeException('Moeda terminal divergente.');
            $amount=(int)($verified['amount_cents']??0);if($amount>0&&$amount!==(int)$payment['amount_cents'])throw new RuntimeException('Valor terminal divergente.');
            $raw=json_decode((string)($payment['raw_payload']??''),true);if(!is_array($raw))$raw=[];$raw['_eventmenu_terminal_status']=$rawStatus;$raw['_eventmenu_terminal_at']=gmdate('c');
            $storedProviderId=$providerId!==''?$providerId:$localProviderId;
            $update=$tx->prepare('UPDATE payments SET status=?,provider_payment_id=?,raw_payload=? WHERE id=? AND tenant_id=? AND order_id=? AND provider=? AND status IN ("created","pending","authorized")');
            $update->execute([$target,$storedProviderId!==''?$storedProviderId:null,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id'],$tenantId,$orderId,$provider]);
            if($update->rowCount()!==1)throw new RuntimeException('Cobrança terminal mudou durante o processamento do webhook.');
            $tx->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([$orderId,$tenantId]);
            try{(new StockReservationService())->rearmAfterPaymentFailure($tenantId,$orderId,30);}catch(\Throwable){}
            return true;
        });
    }

    private function parseReference(string $reference):array
    {
        if(!preg_match('/^eventmenu:(\d+):(\d+)$/',$reference,$m)) throw new RuntimeException('Referência do pedido inválida.');
        return [(int)$m[1],(int)$m[2]];
    }

    private function header(array $headers,string $name):string
    {
        $name=strtolower($name);
        foreach($headers as $k=>$v){
            if(strtolower((string)$k)===$name) return is_array($v)?(string)reset($v):(string)$v;
        }
        return '';
    }

    private function httpJson(string $method,string $url,array $headers=[],?array $body=null):array
    {
        $ch=curl_init($url);
        if($ch===false) throw new RuntimeException('Falha ao iniciar HTTP.');
        $headers[]='Accept: application/json';
        if($body!==null) $headers[]='Content-Type: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);
        if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($response===false||$status<200||$status>=300) throw new RuntimeException('Falha na consulta ao provedor HTTP '.$status.($error?' - '.$error:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data)) throw new RuntimeException('Resposta inválida do provedor.');
        return $data;
    }
}
