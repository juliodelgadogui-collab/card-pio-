<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class NfcService
{
    public function createIntent(int $orderId,string $deviceIdentifier,?int $amountCents=null):array
    {
        Auth::requirePermission('nfc.collect');$tenantId=Auth::tenantId();$userId=Auth::id();$deviceIdentifier=trim($deviceIdentifier);
        if(!$tenantId||!$userId||strlen($deviceIdentifier)<8)throw new RuntimeException('Operador, empresa ou aparelho inválidos.');
        $pdo=Database::connection();$hash=hash('sha256',$deviceIdentifier);$d=$pdo->prepare('SELECT * FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=? AND status="active" LIMIT 1');$d->execute([$tenantId,$hash]);$device=$d->fetch();if(!$device)throw new RuntimeException('Aparelho NFC não está autorizado.');if($device['user_id']!==null&&(int)$device['user_id']!==$userId)throw new RuntimeException('Aparelho NFC está vinculado a outro usuário.');
        $o=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if(in_array($order['status'],['cancelled','completed'],true)||$order['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança.');
        $shift=(new WorkShiftService())->current();if($shift&&$shift['mode']==='delivery'){if(!Auth::can('orders.delivery')||(int)($order['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('Este pedido não está atribuído a você.');}
        $balance=(new PaymentService())->remaining($orderId,$tenantId);$remaining=(int)$balance['remaining_cents'];$amount=$amountCents??$remaining;if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor NFC maior que o saldo restante.');if($amount<100||$amount>1000000)throw new RuntimeException('Valor fora do limite permitido pelo Tap On.');
        $open=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") LIMIT 1');$open->execute([$tenantId,$orderId]);if($open->fetchColumn())throw new RuntimeException('Já existe uma cobrança em andamento para este pedido.');
        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$g->execute([$tenantId]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$config=Crypto::decryptJson($gateway['config_encrypted']);$appKey=trim((string)($config['tap_on_app_key']??''));if($appKey==='')throw new RuntimeException('AppKey do Tap On não configurada.');
        $raw=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$raw);$expires=(new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');$pdo->prepare('INSERT INTO nfc_payment_intents (tenant_id,order_id,user_id,nfc_device_id,intent_token_hash,amount_cents,status,expires_at) VALUES (?,?,?,?,?, ?,"created",?)')->execute([$tenantId,$orderId,$userId,$device['id'],$tokenHash,$amount,$expires]);
        $intentId=(int)$pdo->lastInsertId();Auth::audit('nfc.intent_created','order',(string)$orderId,['intent_id'=>$intentId,'device_id'=>(int)$device['id'],'amount_cents'=>$amount,'remaining_before_cents'=>$remaining]);
        return ['intent_token'=>$raw,'order_id'=>$orderId,'amount_cents'=>$amount,'remaining_before_cents'=>$remaining,'sale_amount'=>$amount/100,'expires_at'=>$expires,'tap_on'=>['app_key'=>$appKey,'app_name'=>(string)($config['tap_on_app_name']??'EventMenu'),'app_version'=>(string)($config['tap_on_app_version']??'1.0.0'),'enable_tax_pass_through'=>(bool)($config['tap_on_tax_pass_through']??false)]];
    }

    public function verifyIntent(string $intentToken,string $transactionCode,string $deviceIdentifier):array
    {
        Auth::requirePermission('nfc.collect');$tenantId=Auth::tenantId();$userId=Auth::id();$intentToken=trim($intentToken);$transactionCode=trim($transactionCode);$deviceIdentifier=trim($deviceIdentifier);
        if(!$tenantId||!$userId||strlen($intentToken)<32||!preg_match('/^[A-Za-z0-9-]{20,80}$/',$transactionCode)||strlen($deviceIdentifier)<8)throw new RuntimeException('Dados da transação NFC inválidos.');
        $pdo=Database::connection();$hash=hash('sha256',$intentToken);$stmt=$pdo->prepare('SELECT i.*,d.device_identifier_hash,d.status device_status,d.user_id device_user_id,o.status order_status,o.payment_status order_payment_status,o.assigned_delivery_user_id FROM nfc_payment_intents i JOIN nfc_devices d ON d.id=i.nfc_device_id JOIN orders o ON o.id=i.order_id WHERE i.tenant_id=? AND i.intent_token_hash=? LIMIT 1');$stmt->execute([$tenantId,$hash]);$intent=$stmt->fetch();
        if(!$intent||(int)$intent['user_id']!==$userId)throw new RuntimeException('Intenção NFC inválida.');if($intent['device_status']!=='active'||!hash_equals((string)$intent['device_identifier_hash'],hash('sha256',$deviceIdentifier)))throw new RuntimeException('Aparelho NFC não autorizado.');if($intent['device_user_id']!==null&&(int)$intent['device_user_id']!==$userId)throw new RuntimeException('Aparelho NFC pertence a outro usuário.');
        $shift=(new WorkShiftService())->current();if($shift&&$shift['mode']==='delivery'&&(int)($intent['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('A entrega não está mais atribuída a você.');
        if($intent['status']==='verified')return ['ok'=>true,'already_verified'=>true,'order_id'=>(int)$intent['order_id'],'payment_id'=>$intent['payment_id']?(int)$intent['payment_id']:null];
        if($intent['status']!=='created'||strtotime((string)$intent['expires_at'])<time()){$pdo->prepare('UPDATE nfc_payment_intents SET status="expired" WHERE id=? AND status="created"')->execute([$intent['id']]);throw new RuntimeException('Intenção NFC expirada.');}
        if($intent['order_status']==='cancelled')throw new RuntimeException('Pedido cancelado não aceita confirmação NFC.');
        $provider=$this->queryTapOnTransaction($tenantId,$transactionCode);if(!$provider['paid'])throw new RuntimeException('PagBank ainda não confirmou esta transação.');if((int)$provider['amount_cents']!==(int)$intent['amount_cents'])throw new RuntimeException('Valor da transação PagBank divergente.');
        $paymentId=Database::transaction(function(PDO $tx)use($intent,$tenantId,$transactionCode):int{
            $locked=$tx->prepare(Database::portableSql($tx,'SELECT * FROM nfc_payment_intents WHERE id=? AND tenant_id=? FOR UPDATE'));$locked->execute([$intent['id'],$tenantId]);$row=$locked->fetch();if(!$row)throw new RuntimeException('Intenção NFC não encontrada.');if($row['status']==='verified')return (int)$row['payment_id'];
            $dupe=$tx->prepare('SELECT id FROM nfc_payment_intents WHERE tenant_id=? AND provider_transaction_code=? AND id<>? LIMIT 1');$dupe->execute([$tenantId,$transactionCode,$row['id']]);if($dupe->fetchColumn())throw new RuntimeException('Transação PagBank já utilizada em outra cobrança.');
            $key='nfc:'.$row['id'].':'.hash('sha256',$transactionCode);$existing=$tx->prepare('SELECT id FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$key]);$paymentId=$existing->fetchColumn();if(!$paymentId){$tx->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,"pagbank",?,?,"BRL","created")')->execute([$tenantId,$row['order_id'],$key,$row['amount_cents']]);$paymentId=(int)$tx->lastInsertId();}
            $tx->prepare('UPDATE nfc_payment_intents SET provider_transaction_code=? WHERE id=?')->execute([$transactionCode,$row['id']]);return (int)$paymentId;
        });
        try{(new PaymentCollectionService())->register($paymentId,'card','eventmenu_go_tap_on');}catch(\Throwable){}
        (new PaymentService())->confirmVerified(['payment_id'=>$paymentId,'tenant_id'=>$tenantId,'order_id'=>(int)$intent['order_id'],'provider'=>'pagbank','provider_payment_id'=>$transactionCode,'amount_cents'=>(int)$provider['amount_cents'],'currency'=>'BRL','account_reference'=>(string)$provider['account_reference'],'raw_status'=>(string)$provider['raw_status'],'source'=>'tap_on_nfc']);
        $pdo->prepare('UPDATE nfc_payment_intents SET status="verified",payment_id=?,verified_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$paymentId,$intent['id'],$tenantId]);Auth::audit('nfc.payment_verified','order',(string)$intent['order_id'],['intent_id'=>(int)$intent['id'],'payment_id'=>$paymentId,'transaction_code'=>$transactionCode,'amount_cents'=>(int)$intent['amount_cents']]);
        return ['ok'=>true,'order_id'=>(int)$intent['order_id'],'payment_id'=>$paymentId,'transaction_code'=>$transactionCode,'balance'=>(new PaymentService())->remaining((int)$intent['order_id'],$tenantId)];
    }

    private function queryTapOnTransaction(int $tenantId,string $transactionCode):array
    {
        $pdo=Database::connection();$g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$g->execute([$tenantId]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$config=Crypto::decryptJson($gateway['config_encrypted']);
        $email=trim((string)($config['legacy_email']??''));$token=trim((string)($config['legacy_token']??$config['token']??''));$base=rtrim((string)($config['tap_on_query_base']??'https://ws.pagseguro.uol.com.br/v3/transactions'),'/');if($email===''||$token==='')throw new RuntimeException('Credenciais de consulta Tap On não configuradas no PagBank.');
        $url=$base.'/'.rawurlencode($transactionCode).'?'.http_build_query(['email'=>$email,'token'=>$token]);$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao consultar PagBank.');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/xml'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($body===false||$status<200||$status>=300)throw new RuntimeException('PagBank não confirmou a consulta da transação (HTTP '.$status.')'.($error?' '.$error:''));
        if(!function_exists('simplexml_load_string'))throw new RuntimeException('Extensão SimpleXML é necessária para validar Tap On.');libxml_use_internal_errors(true);$xml=simplexml_load_string((string)$body);if($xml===false)throw new RuntimeException('Resposta Tap On inválida.');$returnedCode=trim((string)($xml->code??''));if($returnedCode!==''&&strcasecmp(str_replace('-','',$returnedCode),str_replace('-','',$transactionCode))!==0)throw new RuntimeException('Código da transação PagBank divergente.');$rawStatus=(int)($xml->status??0);$gross=(string)($xml->grossAmount??'0');$amount=(int)round(((float)str_replace(',','.',$gross))*100);
        return ['paid'=>in_array($rawStatus,[3,4],true),'raw_status'=>$rawStatus,'amount_cents'=>$amount,'account_reference'=>(string)$gateway['account_reference']];
    }
}
