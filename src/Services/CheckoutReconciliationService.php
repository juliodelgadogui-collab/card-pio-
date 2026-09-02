<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CheckoutReconciliationService
{
    public function reconcileExpired(int $limit=50):array
    {
        $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT id FROM payments WHERE status IN ("created","pending","authorized") AND checkout_expires_at IS NOT NULL AND checkout_expires_at<=NOW() ORDER BY checkout_expires_at,id LIMIT ?');
        $stmt->bindValue(1,max(1,min(200,$limit)),PDO::PARAM_INT);
        $stmt->execute();

        $result=['checked'=>0,'paid'=>0,'cancelled'=>0,'pending'=>0,'review'=>0,'errors'=>0];
        foreach($stmt->fetchAll() as $row){
            $result['checked']++;
            try{
                $state=$this->reconcilePayment((int)$row['id']);
                if(isset($result[$state]))$result[$state]++;
                else $result['review']++;
            }catch(\Throwable $e){
                $result['errors']++;
                $this->recordError((int)$row['id'],$e->getMessage());
            }
        }
        return $result;
    }

    public function reconcilePayment(int $paymentId):string
    {
        $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT p.*,o.tenant_id order_tenant_id,o.id order_id,t.slug tenant_slug FROM payments p JOIN orders o ON o.id=p.order_id JOIN tenants t ON t.id=p.tenant_id WHERE p.id=? LIMIT 1');
        $stmt->execute([$paymentId]);
        $payment=$stmt->fetch();
        if(!$payment)throw new RuntimeException('Cobrança não encontrada para conciliação.');
        if($payment['status']==='paid')return 'paid';
        if(in_array($payment['status'],['cancelled','failed','refunded'],true))return 'cancelled';
        if(!in_array($payment['status'],['created','pending','authorized'],true))return 'review';
        if(empty($payment['provider_payment_id']))return 'review';

        $gatewayStmt=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? LIMIT 1');
        $gatewayStmt->execute([$payment['tenant_id'],$payment['provider']]);
        $gateway=$gatewayStmt->fetch();
        if(!$gateway)throw new RuntimeException('Gateway não encontrado para conciliação.');
        $config=Crypto::decryptJson($gateway['config_encrypted']);

        return match((string)$payment['provider']){
            'stripe'=>$this->stripe($payment,$gateway,$config),
            'mercadopago'=>$this->mercadoPago($payment,$gateway,$config),
            'pagbank'=>$this->pagBank($payment,$gateway,$config),
            default=>'review',
        };
    }

    private function stripe(array $payment,array $gateway,array $config):string
    {
        if(!class_exists('Stripe\\StripeClient'))throw new RuntimeException('SDK Stripe não instalado.');
        $secret=(string)($config['secret_key']??'');
        if($secret==='')throw new RuntimeException('Chave Stripe ausente para conciliação.');

        $client=new \Stripe\StripeClient($secret);
        $account=$client->accounts->retrieve();
        if((string)$account->id!==(string)$gateway['account_reference'])throw new RuntimeException('Conta Stripe divergente durante conciliação.');

        $session=$client->checkout->sessions->retrieve((string)$payment['provider_payment_id'],[]);
        if((string)$session->payment_status==='paid'&&!empty($session->payment_intent)){
            $pi=$client->paymentIntents->retrieve((string)$session->payment_intent,[]);
            $tenantId=(int)($pi->metadata->tenant_id??$session->metadata->tenant_id??0);
            $orderId=(int)($pi->metadata->order_id??$session->metadata->order_id??$session->client_reference_id??0);
            if($tenantId!==(int)$payment['tenant_id']||$orderId!==(int)$payment['order_id'])throw new RuntimeException('Metadados Stripe divergentes na conciliação.');
            if((string)$pi->status!=='succeeded')return 'pending';
            (new PaymentService())->confirmVerified([
                'tenant_id'=>$tenantId,
                'order_id'=>$orderId,
                'provider'=>'stripe',
                'provider_payment_id'=>(string)$pi->id,
                'amount_cents'=>(int)($pi->amount_received?:$pi->amount),
                'currency'=>strtoupper((string)$pi->currency),
                'account_reference'=>(string)$account->id,
                'reconciled'=>true,
            ]);
            return 'paid';
        }

        if((string)$session->status==='expired'){
            $this->cancelLocalAttempt((int)$payment['id'],['stripe_session_status'=>(string)$session->status]);
            return 'cancelled';
        }
        return 'pending';
    }

    private function mercadoPago(array $payment,array $gateway,array $config):string
    {
        $token=(string)($config['access_token']??'');
        if($token==='')throw new RuntimeException('Access token Mercado Pago ausente para conciliação.');
        $reference='eventmenu:'.$payment['tenant_id'].':'.$payment['order_id'];
        $url='https://api.mercadopago.com/v1/payments/search?'.http_build_query([
            'sort'=>'date_created',
            'criteria'=>'desc',
            'external_reference'=>$reference,
            'limit'=>20,
        ]);
        $search=$this->httpJson('GET',$url,['Authorization: Bearer '.$token]);
        $hasPending=false;
        foreach(($search['results']??[]) as $candidate){
            if((string)($candidate['external_reference']??'')!==$reference)continue;
            $status=(string)($candidate['status']??'');
            if($status==='approved'){
                $collector=(string)($candidate['collector_id']??'');
                if($collector!==''&&$collector!==(string)$gateway['account_reference'])throw new RuntimeException('Conta Mercado Pago divergente na conciliação.');
                $id=(string)($candidate['id']??'');
                if($id==='')throw new RuntimeException('Pagamento Mercado Pago sem ID na conciliação.');
                $detail=$this->httpJson('GET','https://api.mercadopago.com/v1/payments/'.rawurlencode($id),['Authorization: Bearer '.$token]);
                $collector=(string)($detail['collector_id']??'');
                if($collector===''||$collector!==(string)$gateway['account_reference'])throw new RuntimeException('Conta Mercado Pago divergente na consulta final.');
                (new PaymentService())->confirmVerified([
                    'tenant_id'=>(int)$payment['tenant_id'],
                    'order_id'=>(int)$payment['order_id'],
                    'provider'=>'mercadopago',
                    'provider_payment_id'=>(string)$detail['id'],
                    'amount_cents'=>(int)round(((float)($detail['transaction_amount']??0))*100),
                    'currency'=>(string)($detail['currency_id']??'BRL'),
                    'account_reference'=>$collector,
                    'reconciled'=>true,
                ]);
                return 'paid';
            }
            if(in_array($status,['pending','in_process','in_mediation','authorized'],true))$hasPending=true;
        }
        if($hasPending)return 'pending';

        $preference=$this->httpJson('GET','https://api.mercadopago.com/checkout/preferences/'.rawurlencode((string)$payment['provider_payment_id']),['Authorization: Bearer '.$token]);
        $expires=!empty($preference['expires']);
        $expiresAt=(string)($preference['expiration_date_to']??'');
        if($expires&&$expiresAt!==''){
            try{$expired=new \DateTimeImmutable($expiresAt)<=new \DateTimeImmutable();}catch(\Throwable){$expired=false;}
            if($expired){
                $this->cancelLocalAttempt((int)$payment['id'],['mercadopago_preference_expired'=>$expiresAt]);
                return 'cancelled';
            }
        }
        return 'pending';
    }

    private function pagBank(array $payment,array $gateway,array $config):string
    {
        $token=(string)($config['token']??'');
        if($token==='')throw new RuntimeException('Token PagBank ausente para conciliação.');
        $base=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');
        $checkout=$this->httpJson('GET',$base.'/checkouts/'.rawurlencode((string)$payment['provider_payment_id']).'?limit=100',['Authorization: Bearer '.$token]);
        $expectedReference='eventmenu:'.$payment['tenant_id'].':'.$payment['order_id'];
        if((string)($checkout['reference_id']??'')!==$expectedReference)throw new RuntimeException('Referência PagBank divergente na conciliação.');

        $transactions=[];
        foreach(['payments','charges'] as $key){if(!empty($checkout[$key])&&is_array($checkout[$key]))$transactions=array_merge($transactions,$checkout[$key]);}
        $hasPending=false;
        foreach($transactions as $transaction){
            $status=(string)($transaction['status']??'');
            if(in_array($status,['WAITING','IN_ANALYSIS','AUTHORIZED'],true))$hasPending=true;
            if(!in_array($status,['PAID','APPROVED'],true))continue;
            $transactionId=(string)($transaction['id']??'');
            $detail=$transaction;
            if($transactionId!==''&&str_starts_with($transactionId,'CHAR_')){
                $detail=$this->httpJson('GET',$base.'/charges/'.rawurlencode($transactionId),['Authorization: Bearer '.$token]);
            }
            $amount=(int)($detail['amount']['value']??$detail['summary']['paid']??0);
            $currency=(string)($detail['amount']['currency']??'BRL');
            $providerPaymentId=(string)($detail['id']??$transactionId);
            if($providerPaymentId===''||$amount<=0)throw new RuntimeException('Pagamento PagBank incompleto na conciliação.');
            (new PaymentService())->confirmVerified([
                'tenant_id'=>(int)$payment['tenant_id'],
                'order_id'=>(int)$payment['order_id'],
                'provider'=>'pagbank',
                'provider_payment_id'=>$providerPaymentId,
                'amount_cents'=>$amount,
                'currency'=>$currency,
                'account_reference'=>(string)$gateway['account_reference'],
                'reconciled'=>true,
            ]);
            return 'paid';
        }
        if($hasPending)return 'pending';

        if((string)($checkout['status']??'')==='EXPIRED'){
            $this->cancelLocalAttempt((int)$payment['id'],['pagbank_checkout_status'=>'EXPIRED']);
            return 'cancelled';
        }
        return 'pending';
    }

    private function cancelLocalAttempt(int $paymentId,array $metadata=[]):void
    {
        Database::transaction(function(PDO $pdo)use($paymentId,$metadata):void{
            $stmt=$pdo->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');
            $stmt->execute([$paymentId]);
            $payment=$stmt->fetch();
            if(!$payment||!in_array($payment['status'],['created','pending','authorized'],true))return;
            $pdo->prepare('UPDATE payments SET status="cancelled" WHERE id=?')->execute([$paymentId]);
            $pdo->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND tenant_id=? AND payment_status="pending"')->execute([$payment['order_id'],$payment['tenant_id']]);
            $this->audit($pdo,(int)$payment['tenant_id'],'checkout.expired','payment',(string)$paymentId,$metadata);
        });
    }

    private function recordError(int $paymentId,string $message):void
    {
        try{
            $pdo=Database::connection();
            $stmt=$pdo->prepare('SELECT tenant_id,raw_payload FROM payments WHERE id=?');
            $stmt->execute([$paymentId]);
            $row=$stmt->fetch();
            if(!$row)return;
            $raw=[];
            if(is_string($row['raw_payload']??null)){$decoded=json_decode($row['raw_payload'],true);if(is_array($decoded))$raw=$decoded;}
            $raw['_eventmenu_reconciliation_error']=substr($message,0,500);
            $raw['_eventmenu_reconciliation_at']=date(DATE_ATOM);
            $pdo->prepare('UPDATE payments SET raw_payload=? WHERE id=?')->execute([json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$paymentId]);
            $this->audit($pdo,(int)$row['tenant_id'],'checkout.reconciliation_failed','payment',(string)$paymentId,['error'=>substr($message,0,500)]);
        }catch(\Throwable){}
    }

    private function audit(PDO $pdo,int $tenantId,string $action,string $entityType,string $entityId,array $metadata=[]):void
    {
        $stmt=$pdo->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,NULL,?,?,?,NULL,NULL,?)');
        $stmt->execute([$tenantId,$action,$entityType,$entityId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
    }

    private function httpJson(string $method,string $url,array $headers=[]):array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Falha ao iniciar consulta de conciliação.');
        $headers[]='Accept: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($response===false||$status<200||$status>=300)throw new RuntimeException('Falha na conciliação com o provedor (HTTP '.$status.').'.($error?' '.$error:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Resposta inválida do provedor na conciliação.');
        return $data;
    }
}
