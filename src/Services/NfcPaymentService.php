<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class NfcPaymentService
{
    private $fetchJson;

    public function __construct(?callable $fetchJson=null)
    {
        $this->fetchJson=$fetchJson;
    }

    public function confirm(int $orderId,string $deviceIdentifier,string $transactionCode):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida para pagamento NFC.');
        $transactionCode=strtoupper(trim($transactionCode));
        $transactionHash=PagBankSecurityService::transactionFingerprint($transactionCode);
        $pdo=Database::connection();

        $device=(new NfcDeviceService())->authorize($pdo,$tenantId,$userId,$deviceIdentifier);
        $orderStmt=$pdo->prepare('SELECT id,total_cents,status,payment_status FROM orders WHERE id=? AND tenant_id=? LIMIT 1');
        $orderStmt->execute([$orderId,$tenantId]);$order=$orderStmt->fetch();
        if(!$order)throw new RuntimeException('Pedido não encontrado para NFC.');
        if(in_array($order['status'],['cancelled','completed'],true))throw new RuntimeException('Pedido cancelado ou finalizado não aceita pagamento NFC.');
        if(in_array($order['payment_status'],['paid','partially_refunded','refunded'],true))throw new RuntimeException('Pedido já pago ou reembolsado.');
        $amount=(int)$order['total_cents'];if($amount<100||$amount>1000000)throw new RuntimeException('Valor fora dos limites suportados pelo Tap On.');

        $gatewayStmt=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');
        $gatewayStmt->execute([$tenantId]);$gateway=$gatewayStmt->fetch();
        if(!$gateway)throw new RuntimeException('Gateway PagBank não está ativo.');
        $config=Crypto::decryptJson($gateway['config_encrypted']);
        $apiBase=PagBankSecurityService::apiBase((string)($config['api_base']??''));
        $token=trim((string)($config['token']??''));
        if($token==='')throw new RuntimeException('Token PagBank ausente no servidor.');

        $claim=Database::transaction(function(PDO $db)use($tenantId,$userId,$orderId,$device,$transactionHash){
            $s=$db->prepare('SELECT * FROM nfc_payment_attempts WHERE tenant_id=? AND transaction_code_hash=? LIMIT 1 FOR UPDATE');
            $s->execute([$tenantId,$transactionHash]);$attempt=$s->fetch();
            if($attempt){
                if((int)$attempt['order_id']!==$orderId)throw new RuntimeException('Esta transação PagBank já foi vinculada a outro pedido.');
                if($attempt['status']==='verified')return ['verified'=>true,'attempt'=>$attempt];
                if($attempt['status']==='pending'&&!empty($attempt['updated_at'])&&new \DateTimeImmutable((string)$attempt['updated_at'],new \DateTimeZone('UTC'))>new \DateTimeImmutable('-2 minutes',new \DateTimeZone('UTC')))throw new RuntimeException('Esta transação já está sendo validada.');
                $db->prepare('UPDATE nfc_payment_attempts SET device_id=?,user_id=?,status="pending",failure_reason=NULL WHERE id=?')->execute([$device['id'],$userId,$attempt['id']]);
                return ['verified'=>false,'attempt_id'=>(int)$attempt['id']];
            }
            $db->prepare('INSERT INTO nfc_payment_attempts (tenant_id,device_id,user_id,order_id,transaction_code_hash,status) VALUES (?,?,?,?,?,"pending")')->execute([$tenantId,$device['id'],$userId,$orderId,$transactionHash]);
            return ['verified'=>false,'attempt_id'=>(int)$db->lastInsertId()];
        });

        if(!empty($claim['verified']))return ['ok'=>true,'verified'=>true,'reused'=>true,'order_id'=>$orderId,'transaction_code'=>$transactionCode];
        $attemptId=(int)$claim['attempt_id'];

        try{
            $verified=str_starts_with($transactionCode,'CHAR_')
                ?$this->verifyModernCharge($apiBase,$token,$transactionCode,$tenantId,$orderId,$amount)
                :$this->verifyLegacyTapOn($config,$transactionCode,$tenantId,$orderId,$amount);

            $payment=(new PaymentService())->create($orderId,'pagbank','nfc:pagbank:'.$tenantId.':'.$transactionHash);
            (new PaymentService())->confirmVerified([
                'tenant_id'=>$tenantId,
                'order_id'=>$orderId,
                'provider'=>'pagbank',
                'provider_payment_id'=>$transactionCode,
                'amount_cents'=>$verified['amount_cents'],
                'currency'=>$verified['currency'],
                'account_reference'=>(string)$gateway['account_reference'],
                'nfc_device_id'=>(int)$device['id'],
                'nfc_transaction_code'=>$transactionCode,
                'nfc_source'=>$verified['source'],
            ]);

            Database::transaction(function(PDO $db)use($tenantId,$attemptId,$device,$transactionCode,$verified){
                $db->prepare('UPDATE nfc_payment_attempts SET status="verified",amount_cents=?,currency=?,provider_payment_id=?,failure_reason=NULL,verified_at=NOW() WHERE id=? AND tenant_id=?')->execute([$verified['amount_cents'],$verified['currency'],$transactionCode,$attemptId,$tenantId]);
                $db->prepare('UPDATE nfc_devices SET last_payment_at=NOW(),last_seen_at=NOW() WHERE id=? AND tenant_id=?')->execute([$device['id'],$tenantId]);
            });
            Auth::audit('nfc.payment_verified','order',(string)$orderId,['device_id'=>(int)$device['id'],'payment_id'=>(int)$payment['id'],'source'=>$verified['source']]);
            return ['ok'=>true,'verified'=>true,'reused'=>false,'order_id'=>$orderId,'payment_id'=>(int)$payment['id'],'transaction_code'=>$transactionCode];
        }catch(Throwable $e){
            try{$pdo->prepare('UPDATE nfc_payment_attempts SET status="rejected",failure_reason=? WHERE id=? AND tenant_id=? AND status<>"verified"')->execute([mb_substr($e->getMessage(),0,255),$attemptId,$tenantId]);}catch(Throwable){}
            Auth::audit('nfc.payment_rejected','order',(string)$orderId,['device_id'=>(int)$device['id'],'reason'=>$e->getMessage()]);
            throw $e;
        }
    }

    private function verifyModernCharge(string $apiBase,string $token,string $transactionCode,int $tenantId,int $orderId,int $expectedAmount):array
    {
        $charge=$this->getJson($apiBase.'/charges/'.rawurlencode($transactionCode),['Authorization: Bearer '.$token]);
        if(strtoupper((string)($charge['id']??''))!==$transactionCode)throw new RuntimeException('PagBank retornou identificador de cobrança divergente.');
        if(strtoupper((string)($charge['status']??''))!=='PAID')throw new RuntimeException('A cobrança PagBank ainda não está paga.');
        $currency=strtoupper((string)($charge['amount']['currency']??'BRL'));
        $amount=(int)($charge['amount']['summary']['paid']??$charge['amount']['value']??0);
        if($currency!=='BRL')throw new RuntimeException('Moeda divergente na cobrança NFC PagBank.');
        if($amount!==$expectedAmount)throw new RuntimeException('Valor divergente na cobrança NFC PagBank.');
        $reference=trim((string)($charge['reference_id']??''));
        $expectedReference='eventmenu:'.$tenantId.':'.$orderId;
        if($reference!==''&&$reference!==$expectedReference)throw new RuntimeException('Referência divergente na cobrança NFC PagBank.');
        return ['amount_cents'=>$amount,'currency'=>$currency,'source'=>'charge-api'];
    }

    private function verifyLegacyTapOn(array $config,string $transactionCode,int $tenantId,int $orderId,int $expectedAmount):array
    {
        $email=trim((string)($config['tap_on_email']??''));
        $token=trim((string)($config['tap_on_token']??''));
        if($email===''||$token==='')throw new RuntimeException('Configure e-mail e token de consulta Tap On no servidor para validar este transactionCode.');
        $base=PagBankSecurityService::tapOnLegacyBase((string)($config['tap_on_environment']??'production'));
        $url=$base.'/'.rawurlencode($transactionCode).'?'.http_build_query(['email'=>$email,'token'=>$token]);
        $payload=$this->getJson($url,[]);
        $transaction=is_array($payload['transaction']??null)?$payload['transaction']:$payload;
        $returnedCode=strtoupper(str_replace('-','',(string)($transaction['code']??$transaction['transactionCode']??'')));
        $expectedCode=strtoupper(str_replace('-','',$transactionCode));
        if($returnedCode===''||!hash_equals($expectedCode,$returnedCode))throw new RuntimeException('PagBank retornou transactionCode divergente.');
        $status=(int)($transaction['status']??0);
        if(!in_array($status,[3,4],true))throw new RuntimeException('A transação Tap On não está paga/disponível no PagBank.');
        $gross=$transaction['grossAmount']??$transaction['gross_amount']??null;
        if($gross===null||!is_numeric((string)$gross))throw new RuntimeException('PagBank não retornou o valor bruto da transação Tap On.');
        $amount=(int)round(((float)$gross)*100);
        if($amount!==$expectedAmount)throw new RuntimeException('Valor divergente na transação Tap On.');
        $reference=trim((string)($transaction['reference']??''));
        $expectedReference='eventmenu:'.$tenantId.':'.$orderId;
        if($reference!==''&&$reference!==$expectedReference)throw new RuntimeException('Referência divergente na transação Tap On.');
        return ['amount_cents'=>$amount,'currency'=>'BRL','source'=>'tap-on-transaction-api'];
    }

    private function getJson(string $url,array $headers):array
    {
        if($this->fetchJson!==null){$data=($this->fetchJson)($url,$headers);if(!is_array($data))throw new RuntimeException('Resposta simulada PagBank inválida.');return $data;}
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar consulta PagBank.');
        $headers[]='Accept: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($response===false||$status<200||$status>=300)throw new RuntimeException('Falha ao consultar transação no PagBank (HTTP '.$status.')'.($error!==''?' '.$error:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta PagBank inválida.');return $data;
    }
}
