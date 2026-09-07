<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class SumUpProvider
{
    public const CODE='sumup';

    public function authorizationUrl(): string
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $clientId=trim((string)\env('SUMUP_CLIENT_ID',''));$redirect=$this->redirectUri();if($clientId===''||$redirect==='')throw new RuntimeException('OAuth SumUp ainda não foi configurado no servidor.');
        $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=date('Y-m-d H:i:s',time()+600);Database::connection()->prepare('INSERT INTO payment_oauth_states (tenant_id,user_id,provider,state_hash,expires_at) VALUES (?, ?,"sumup",?,?)')->execute([$tenantId,$userId,$hash,$expires]);
        $query=http_build_query(['response_type'=>'code','client_id'=>$clientId,'redirect_uri'=>$redirect,'scope'=>'transactions.history user.profile_readonly payments','state'=>$raw],'','&',PHP_QUERY_RFC3986);return'https://api.sumup.com/authorize?'.$query;
    }

    public function completeAuthorization(string $state,string $code): array
    {
        $state=trim($state);$code=trim($code);if(strlen($state)<32||$code==='')throw new RuntimeException('Retorno OAuth SumUp inválido.');$pdo=Database::connection();$hash=hash('sha256',$state);
        $result=Database::transaction(function(PDO$tx)use($hash,$code):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM payment_oauth_states WHERE provider="sumup" AND state_hash=? FOR UPDATE'));$q->execute([$hash]);$oauth=$q->fetch();if(!$oauth||$oauth['used_at']!==null||strtotime((string)$oauth['expires_at'])<time())throw new RuntimeException('Autorização SumUp expirada ou já utilizada.');
            $tokens=$this->exchangeToken(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$this->redirectUri(),'client_id'=>$this->clientId(),'client_secret'=>$this->clientSecret()]);$access=trim((string)($tokens['access_token']??''));$refresh=trim((string)($tokens['refresh_token']??''));if($access==='')throw new RuntimeException('SumUp não retornou token de acesso.');$expiresAt=date('Y-m-d H:i:s',time()+max(60,(int)($tokens['expires_in']??3600)-60));
            $config=['access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>$expiresAt,'scope'=>(string)($tokens['scope']??''),'token_type'=>(string)($tokens['token_type']??'Bearer'),'oauth_connected_at'=>date(DATE_ATOM)];
            $existing=$tx->prepare('SELECT account_reference,config_encrypted,webhook_secret_encrypted FROM payment_gateways WHERE tenant_id=? AND provider="sumup" LIMIT 1');$existing->execute([(int)$oauth['tenant_id']]);$old=$existing->fetch();if($old){$prior=Crypto::decryptJson($old['config_encrypted']);$config=array_merge($prior,$config);$sql=Database::isSqlite($tx)?'INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,"sumup",?,?,?,1) ON CONFLICT(tenant_id,provider) DO UPDATE SET config_encrypted=excluded.config_encrypted,active=1':'INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,"sumup",?,?,?,1) ON DUPLICATE KEY UPDATE config_encrypted=VALUES(config_encrypted),active=1';$tx->prepare($sql)->execute([(int)$oauth['tenant_id'],(string)($old['account_reference']??''),Crypto::encrypt($config),$old['webhook_secret_encrypted']??null]);}else{$tx->prepare('INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,active) VALUES (?,"sumup","",?,1)')->execute([(int)$oauth['tenant_id'],Crypto::encrypt($config)]);}
            $tx->prepare('UPDATE payment_oauth_states SET used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$oauth['id']]);return['tenant_id'=>(int)$oauth['tenant_id'],'user_id'=>(int)$oauth['user_id'],'scope'=>(string)($tokens['scope']??''),'expires_at'=>$expiresAt];
        });Auth::audit('gateway.sumup_connected','gateway','sumup',['scope'=>$result['scope']]);return$result;
    }

    public function sdkSession(int $tenantId): array
    {
        $gateway=$this->gatewayWithFreshToken($tenantId);$config=$gateway['config'];$affiliateKey=trim((string)\env('SUMUP_AFFILIATE_KEY',''));if($affiliateKey==='')throw new RuntimeException('Affiliate Key SumUp não configurada no servidor.');return['provider'=>'sumup','access_token'=>(string)$config['access_token'],'merchant_code'=>(string)($gateway['account_reference']??''),'affiliate_key'=>$affiliateKey,'app_id'=>(string)\env('SUMUP_APP_ID',''),'expires_at'=>(string)$config['expires_at']];
    }

    public function verifyCardPresent(int $tenantId,array $intent,array $sdkResult): array
    {
        $gateway=$this->gatewayWithFreshToken($tenantId);$config=$gateway['config'];$txCode=trim((string)($sdkResult['transaction_code']??''));$serverId=trim((string)($sdkResult['server_transaction_id']??''));$merchant=trim((string)($sdkResult['merchant_code']??''));if($txCode===''&&$serverId==='')throw new RuntimeException('SumUp não retornou identificador da transação.');if($merchant==='')throw new RuntimeException('SumUp não retornou o estabelecimento da transação.');
        $bound=trim((string)($gateway['account_reference']??''));if($bound!==''&&!hash_equals($bound,$merchant))throw new RuntimeException('A transação pertence a outra conta SumUp.');
        $query=$serverId!==''?['id'=>$serverId]:['transaction_code'=>$txCode];$url='https://api.sumup.com/v2.1/merchants/'.rawurlencode($merchant).'/transactions?'.http_build_query($query);$transaction=$this->jsonRequest('GET',$url,['Authorization: Bearer '.$config['access_token']]);
        $returnedMerchant=trim((string)($transaction['merchant_code']??''));if($returnedMerchant===''||!hash_equals($merchant,$returnedMerchant))throw new RuntimeException('Conta SumUp divergente na validação.');$status=strtoupper((string)($transaction['status']??''));if($status!=='SUCCESSFUL')throw new RuntimeException('A SumUp ainda não confirmou o pagamento ('.$status.').');$amount=(int)round(((float)($transaction['amount']??0))*100);if($amount!==(int)$intent['amount_cents'])throw new RuntimeException('Valor confirmado pela SumUp diverge do pedido.');$currency=strtoupper((string)($transaction['currency']??''));if($currency!=='BRL')throw new RuntimeException('Moeda da transação SumUp inválida.');
        $client=(string)($transaction['client_transaction_id']??'');if(!empty($intent['client_transaction_id'])&&$client!==''&&!hash_equals((string)$intent['client_transaction_id'],$client))throw new RuntimeException('Identificador da cobrança SumUp divergente.');
        if($bound==='')$pdo=Database::connection();if($bound===''){$pdo->prepare('UPDATE payment_gateways SET account_reference=? WHERE tenant_id=? AND provider="sumup" AND (account_reference="" OR account_reference IS NULL)')->execute([$merchant,$tenantId]);}
        return['paid'=>true,'tenant_id'=>$tenantId,'order_id'=>(int)$intent['order_id'],'provider'=>'sumup','provider_payment_id'=>(string)($transaction['id']??$serverId?:$txCode),'amount_cents'=>$amount,'currency'=>'BRL','account_reference'=>$merchant,'raw_status'=>$status,'source'=>'sumup_tap_to_pay','transaction_code'=>(string)($transaction['transaction_code']??$txCode),'client_transaction_id'=>$client,'raw_transaction'=>$transaction];
    }

    public function refreshIfNeeded(int $tenantId): array { return $this->gatewayWithFreshToken($tenantId); }

    private function gatewayWithFreshToken(int $tenantId): array
    {
        $registry=new PaymentProviderRegistry();$gateway=$registry->gateway($tenantId,'sumup');$config=$gateway['config'];$access=trim((string)($config['access_token']??''));if($access===''||empty($config['refresh_token']))throw new RuntimeException('Conta SumUp precisa ser conectada novamente.');if(strtotime((string)($config['expires_at']??'1970-01-01'))>time()+90)return$gateway;
        $tokens=$this->exchangeToken(['grant_type'=>'refresh_token','refresh_token'=>(string)$config['refresh_token'],'client_id'=>$this->clientId(),'client_secret'=>$this->clientSecret()]);$newAccess=trim((string)($tokens['access_token']??''));if($newAccess==='')throw new RuntimeException('Não foi possível renovar a sessão SumUp. Reconecte a conta.');$config['access_token']=$newAccess;if(!empty($tokens['refresh_token']))$config['refresh_token']=(string)$tokens['refresh_token'];$config['expires_at']=date('Y-m-d H:i:s',time()+max(60,(int)($tokens['expires_in']??3600)-60));if(isset($tokens['scope']))$config['scope']=(string)$tokens['scope'];Database::connection()->prepare('UPDATE payment_gateways SET config_encrypted=? WHERE tenant_id=? AND provider="sumup"')->execute([Crypto::encrypt($config),$tenantId]);$gateway['config']=$config;return$gateway;
    }

    private function exchangeToken(array $form): array { return $this->jsonRequest('POST','https://api.sumup.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query($form)); }
    private function clientId(): string {$v=trim((string)\env('SUMUP_CLIENT_ID',''));if($v==='')throw new RuntimeException('SUMUP_CLIENT_ID não configurado.');return$v;}
    private function clientSecret(): string {$v=trim((string)\env('SUMUP_CLIENT_SECRET',''));if($v==='')throw new RuntimeException('SUMUP_CLIENT_SECRET não configurado.');return$v;}
    private function redirectUri(): string { return \app_absolute_url('sumup-oauth.php'); }

    private function jsonRequest(string $method,string $url,array $headers=[],?string $rawBody=null): array
    {
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar comunicação com SumUp.');$headers[]='Accept: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);if($rawBody!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$rawBody);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($body===false||$status<200||$status>=300)throw new RuntimeException('SumUp recusou a operação (HTTP '.$status.')'.($error?' '.$error:''));$data=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta SumUp inválida.');return$data;
    }
}
