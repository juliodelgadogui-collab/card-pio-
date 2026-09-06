<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class TabSplitNfcService
{
    public function createIntent(int $groupId,string $deviceIdentifier):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('nfc.collect');$tenantId=Auth::tenantId();$userId=Auth::id();$deviceIdentifier=trim($deviceIdentifier);if(!$tenantId||!$userId||strlen($deviceIdentifier)<8)throw new RuntimeException('Sessão/aparelho inválido.');
        $pdo=Database::connection();$g=$pdo->prepare('SELECT * FROM payment_groups WHERE id=? AND tenant_id=? LIMIT 1');$g->execute([$groupId,$tenantId]);$group=$g->fetch();if(!$group||$group['provider']!=='pagbank'||$group['method']!=='nfc'||$group['status']!=='created')throw new RuntimeException('Grupo NFC não está disponível.');
        $hash=hash('sha256',$deviceIdentifier);$d=$pdo->prepare('SELECT * FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=? AND status="active" LIMIT 1');$d->execute([$tenantId,$hash]);$device=$d->fetch();if(!$device)throw new RuntimeException('Aparelho NFC não está autorizado.');if($device['user_id']!==null&&(int)$device['user_id']!==$userId)throw new RuntimeException('Aparelho NFC está vinculado a outro usuário.');
        $gw=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$gw->execute([$tenantId]);$gateway=$gw->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$config=Crypto::decryptJson($gateway['config_encrypted']);$appKey=trim((string)($config['tap_on_app_key']??''));if($appKey==='')throw new RuntimeException('AppKey do Tap On não configurada.');
        $raw=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$raw);$expires=(new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
        Database::transaction(function(\PDO $tx)use($tenantId,$userId,$groupId,$device,$tokenHash,$expires,$group):void{$tx->prepare('UPDATE group_nfc_intents SET status="expired" WHERE tenant_id=? AND payment_group_id=? AND status="created"')->execute([$tenantId,$groupId]);$tx->prepare('INSERT INTO group_nfc_intents (tenant_id,payment_group_id,user_id,nfc_device_id,intent_token_hash,amount_cents,status,expires_at) VALUES (?,?,?,?,?, ?,"created",?)')->execute([$tenantId,$groupId,$userId,$device['id'],$tokenHash,$group['amount_cents'],$expires]);});
        Auth::audit('tab.payment_group_nfc_intent','payment_group',(string)$groupId,['device_id'=>(int)$device['id'],'amount_cents'=>(int)$group['amount_cents']]);
        return ['intent_token'=>$raw,'group_id'=>$groupId,'amount_cents'=>(int)$group['amount_cents'],'expires_at'=>$expires,'tap_on'=>['app_key'=>$appKey,'app_name'=>(string)($config['tap_on_app_name']??'EventMenu GO'),'app_version'=>(string)($config['tap_on_app_version']??'1.0.0'),'enable_tax_pass_through'=>(bool)($config['tap_on_tax_pass_through']??false)]];
    }

    public function verifyIntent(string $intentToken,string $transactionCode,string $deviceIdentifier):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('nfc.collect');$tenantId=Auth::tenantId();$userId=Auth::id();$intentToken=trim($intentToken);$transactionCode=trim($transactionCode);$deviceIdentifier=trim($deviceIdentifier);if(!$tenantId||!$userId||strlen($intentToken)<32||!preg_match('/^[A-Za-z0-9-]{20,80}$/',$transactionCode)||strlen($deviceIdentifier)<8)throw new RuntimeException('Dados NFC inválidos.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT i.*,d.device_identifier_hash,d.status device_status,d.user_id device_user_id,pg.status group_status,pg.amount_cents group_amount FROM group_nfc_intents i JOIN nfc_devices d ON d.id=i.nfc_device_id JOIN payment_groups pg ON pg.id=i.payment_group_id WHERE i.tenant_id=? AND i.intent_token_hash=? LIMIT 1');$s->execute([$tenantId,hash('sha256',$intentToken)]);$intent=$s->fetch();if(!$intent||(int)$intent['user_id']!==$userId)throw new RuntimeException('Intenção NFC inválida.');
        if($intent['device_status']!=='active'||!hash_equals((string)$intent['device_identifier_hash'],hash('sha256',$deviceIdentifier)))throw new RuntimeException('Aparelho NFC não autorizado.');if($intent['device_user_id']!==null&&(int)$intent['device_user_id']!==$userId)throw new RuntimeException('Aparelho NFC pertence a outro usuário.');
        if($intent['status']==='verified')return ['ok'=>true,'already_verified'=>true,'group'=>(new TabSplitPaymentService())->status((int)$intent['payment_group_id'])];if($intent['status']!=='created'||strtotime((string)$intent['expires_at'])<time()){$pdo->prepare('UPDATE group_nfc_intents SET status="expired" WHERE id=? AND status="created"')->execute([$intent['id']]);throw new RuntimeException('Intenção NFC expirada.');}if($intent['group_status']!=='created')throw new RuntimeException('Grupo de pagamento não aceita esta intenção NFC.');
        $provider=$this->queryTapOnTransaction($tenantId,$transactionCode);if(!$provider['paid'])throw new RuntimeException('PagBank ainda não confirmou esta transação.');if((int)$provider['amount_cents']!==(int)$intent['amount_cents'])throw new RuntimeException('Valor da transação PagBank divergente.');
        $dupe=$pdo->prepare('SELECT id FROM group_nfc_intents WHERE tenant_id=? AND provider_transaction_code=? AND id<>? LIMIT 1');$dupe->execute([$tenantId,$transactionCode,$intent['id']]);if($dupe->fetchColumn())throw new RuntimeException('Transação PagBank já utilizada em outra divisão.');
        $group=(new TabSplitPaymentService())->settlePagBank((int)$intent['payment_group_id'],$transactionCode,(int)$provider['amount_cents'],(string)$provider['account_reference'],(string)$provider['raw_status'],['tap_on_transaction_code'=>$transactionCode]);
        $pdo->prepare('UPDATE group_nfc_intents SET status="verified",provider_transaction_code=?,verified_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$transactionCode,$intent['id'],$tenantId]);Auth::audit('tab.payment_group_nfc_verified','payment_group',(string)$intent['payment_group_id'],['transaction_code'=>$transactionCode,'amount_cents'=>(int)$intent['amount_cents']]);return ['ok'=>true,'group'=>$group,'transaction_code'=>$transactionCode];
    }

    private function queryTapOnTransaction(int $tenantId,string $transactionCode):array
    {
        $pdo=Database::connection();$g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$g->execute([$tenantId]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$config=Crypto::decryptJson($gateway['config_encrypted']);$email=trim((string)($config['legacy_email']??''));$token=trim((string)($config['legacy_token']??$config['token']??''));$base=rtrim((string)($config['tap_on_query_base']??'https://ws.pagseguro.uol.com.br/v3/transactions'),'/');if($email===''||$token==='')throw new RuntimeException('Credenciais de consulta Tap On não configuradas.');
        $url=$base.'/'.rawurlencode($transactionCode).'?'.http_build_query(['email'=>$email,'token'=>$token]);$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao consultar PagBank.');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/xml'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($body===false||$status<200||$status>=300)throw new RuntimeException('PagBank não confirmou a consulta Tap On (HTTP '.$status.')'.($error?' '.$error:''));if(!function_exists('simplexml_load_string'))throw new RuntimeException('Extensão SimpleXML necessária para validar Tap On.');libxml_use_internal_errors(true);$xml=simplexml_load_string((string)$body);if($xml===false)throw new RuntimeException('Resposta Tap On inválida.');$returned=trim((string)($xml->code??''));if($returned!==''&&strcasecmp(str_replace('-','',$returned),str_replace('-','',$transactionCode))!==0)throw new RuntimeException('Código PagBank divergente.');$rawStatus=(int)($xml->status??0);$amount=(int)round(((float)str_replace(',','.',(string)($xml->grossAmount??'0')))*100);return ['paid'=>in_array($rawStatus,[3,4],true),'raw_status'=>$rawStatus,'amount_cents'=>$amount,'account_reference'=>(string)$gateway['account_reference']];
    }
}
