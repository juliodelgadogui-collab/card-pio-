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
        Auth::requirePermission('gateways.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $clientId=$this->clientId();$redirect=$this->redirectUri();
        $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=date('Y-m-d H:i:s',time()+600);
        Database::connection()->prepare('INSERT INTO payment_oauth_states (tenant_id,user_id,provider,state_hash,expires_at) VALUES (?, ?,"sumup",?,?)')->execute([$tenantId,$userId,$hash,$expires]);
        $query=http_build_query([
            'response_type'=>'code',
            'client_id'=>$clientId,
            'redirect_uri'=>$redirect,
            'scope'=>'transactions.history user.profile_readonly payments',
            'state'=>$raw,
        ],'','&',PHP_QUERY_RFC3986);
        return 'https://api.sumup.com/authorize?'.$query;
    }

    public function completeAuthorization(string $state,string $code): array
    {
        $state=trim($state);$code=trim($code);
        if(strlen($state)<32||$code==='')throw new RuntimeException('Retorno OAuth SumUp inválido.');
        $pdo=Database::connection();$hash=hash('sha256',$state);
        $q=$pdo->prepare('SELECT * FROM payment_oauth_states WHERE provider="sumup" AND state_hash=? LIMIT 1');$q->execute([$hash]);$oauth=$q->fetch();
        if(!$oauth||$oauth['used_at']!==null||strtotime((string)$oauth['expires_at'])<time())throw new RuntimeException('Autorização SumUp expirada ou já utilizada.');

        // Chamadas externas são feitas antes da transação de banco para não manter locks durante HTTP.
        $tokens=$this->exchangeToken([
            'grant_type'=>'authorization_code',
            'code'=>$code,
            'redirect_uri'=>$this->redirectUri(),
            'client_id'=>$this->clientId(),
            'client_secret'=>$this->clientSecret(),
        ]);
        $access=trim((string)($tokens['access_token']??''));$refresh=trim((string)($tokens['refresh_token']??''));
        if($access==='')throw new RuntimeException('SumUp não retornou token de acesso.');
        $merchantCode=$this->merchantCodeFromProfile($access);
        if($merchantCode==='')throw new RuntimeException('Não foi possível identificar a conta recebedora SumUp.');
        $expiresAt=date('Y-m-d H:i:s',time()+max(60,(int)($tokens['expires_in']??3600)-60));

        $result=Database::transaction(function(PDO $tx)use($hash,$oauth,$tokens,$access,$refresh,$expiresAt,$merchantCode):array{
            $lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM payment_oauth_states WHERE provider="sumup" AND state_hash=? FOR UPDATE'));$lock->execute([$hash]);$fresh=$lock->fetch();
            if(!$fresh||$fresh['used_at']!==null||strtotime((string)$fresh['expires_at'])<time())throw new RuntimeException('Autorização SumUp expirada ou já utilizada.');
            if((int)$fresh['tenant_id']!==(int)$oauth['tenant_id']||(int)$fresh['user_id']!==(int)$oauth['user_id'])throw new RuntimeException('Estado OAuth SumUp divergente.');

            $config=[
                'access_token'=>$access,
                'refresh_token'=>$refresh,
                'expires_at'=>$expiresAt,
                'scope'=>(string)($tokens['scope']??''),
                'token_type'=>(string)($tokens['token_type']??'Bearer'),
                'oauth_connected_at'=>date(DATE_ATOM),
            ];
            $existing=$tx->prepare('SELECT config_encrypted,webhook_secret_encrypted FROM payment_gateways WHERE tenant_id=? AND provider="sumup" LIMIT 1');$existing->execute([(int)$fresh['tenant_id']]);$old=$existing->fetch();
            if($old){$prior=Crypto::decryptJson($old['config_encrypted']);$config=array_merge($prior,$config);}
            if(Database::isSqlite($tx)){
                $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,"sumup",?,?,?,1) ON CONFLICT(tenant_id,provider) DO UPDATE SET account_reference=excluded.account_reference,config_encrypted=excluded.config_encrypted,active=1';
            }else{
                $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,"sumup",?,?,?,1) ON DUPLICATE KEY UPDATE account_reference=VALUES(account_reference),config_encrypted=VALUES(config_encrypted),active=1';
            }
            $tx->prepare($sql)->execute([(int)$fresh['tenant_id'],$merchantCode,Crypto::encrypt($config),$old['webhook_secret_encrypted']??null]);
            $tx->prepare('UPDATE payment_oauth_states SET used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$fresh['id']]);
            return['tenant_id'=>(int)$fresh['tenant_id'],'user_id'=>(int)$fresh['user_id'],'scope'=>(string)($tokens['scope']??''),'expires_at'=>$expiresAt,'merchant_code'=>$merchantCode];
        });
        Auth::audit('gateway.sumup_connected','gateway','sumup',['scope'=>$result['scope'],'merchant_code'=>$merchantCode]);
        return$result;
    }

    /**
     * Sessão curta entregue ao SDK. O servidor mantém refresh token/client secret;
     * o APK recebe apenas o access token necessário para o AuthTokenProvider do SDK.
     */
    public function sdkSession(int $tenantId): array
    {
        $gateway=$this->gatewayWithFreshToken($tenantId);$config=$gateway['config'];
        $affiliateKey=trim((string)\env('SUMUP_AFFILIATE_KEY',''));
        if($affiliateKey==='')throw new RuntimeException('Affiliate Key SumUp não configurada no servidor.');
        $merchant=$this->ensureMerchantCode($tenantId,$gateway);
        return[
            'provider'=>'sumup',
            'access_token'=>(string)$config['access_token'],
            'merchant_code'=>$merchant,
            'affiliate_key'=>$affiliateKey,
            'app_id'=>(string)\env('SUMUP_APP_ID',''),
            'expires_at'=>(string)$config['expires_at'],
        ];
    }

    /**
     * A resposta do SDK é somente uma pista. A verdade vem desta consulta REST à SumUp.
     * Se o SDK não tiver resultado (TransactionResultUnknown), usa client_transaction_id.
     */
    public function verifyCardPresent(int $tenantId,array $intent,array $sdkResult): array
    {
        $gateway=$this->gatewayWithFreshToken($tenantId);$config=$gateway['config'];
        $bound=$this->ensureMerchantCode($tenantId,$gateway);
        $sdkMerchant=trim((string)($sdkResult['merchant_code']??''));
        if($sdkMerchant!==''&&!hash_equals($bound,$sdkMerchant))throw new RuntimeException('A transação pertence a outra conta SumUp.');

        $txCode=trim((string)($sdkResult['transaction_code']??''));
        $serverId=trim((string)($sdkResult['server_transaction_id']??''));
        $clientId=trim((string)($intent['client_transaction_id']??''));
        if($serverId!=='')$query=['id'=>$serverId];
        elseif($txCode!=='')$query=['transaction_code'=>$txCode];
        elseif($clientId!=='')$query=['client_transaction_id'=>$clientId];
        else throw new RuntimeException('Não há identificador suficiente para reconciliar a transação SumUp.');

        $url='https://api.sumup.com/v2.1/merchants/'.rawurlencode($bound).'/transactions?'.http_build_query($query);
        $transaction=$this->jsonRequest('GET',$url,['Authorization: Bearer '.$config['access_token']]);
        $returnedMerchant=trim((string)($transaction['merchant_code']??''));
        if($returnedMerchant===''||!hash_equals($bound,$returnedMerchant))throw new RuntimeException('Conta SumUp divergente na validação.');
        $status=strtoupper((string)($transaction['status']??''));
        if($status!=='SUCCESSFUL')throw new RuntimeException('A SumUp ainda não confirmou o pagamento ('.$status.').');
        $amount=(int)round(((float)($transaction['amount']??0))*100);
        if($amount!==(int)$intent['amount_cents'])throw new RuntimeException('Valor confirmado pela SumUp diverge do pedido.');
        $currency=strtoupper((string)($transaction['currency']??''));
        if($currency!=='BRL')throw new RuntimeException('Moeda da transação SumUp inválida.');
        $returnedClient=trim((string)($transaction['client_transaction_id']??''));
        if($clientId!==''&&$returnedClient!==''&&!hash_equals($clientId,$returnedClient))throw new RuntimeException('Identificador da cobrança SumUp divergente.');
        $returnedInstallments=(int)($transaction['installments_count']??0);
        if($returnedInstallments>0&&$returnedInstallments!==(int)($intent['installments_count']??1))throw new RuntimeException('Número de parcelas SumUp divergente.');
        $providerId=trim((string)($transaction['id']??$transaction['transaction_id']??$serverId));
        if($providerId==='')$providerId=trim((string)($transaction['transaction_code']??$txCode));
        if($providerId==='')throw new RuntimeException('SumUp confirmou a venda sem identificador utilizável.');

        return[
            'paid'=>true,
            'tenant_id'=>$tenantId,
            'order_id'=>(int)$intent['order_id'],
            'provider'=>'sumup',
            'provider_payment_id'=>$providerId,
            'amount_cents'=>$amount,
            'currency'=>'BRL',
            'account_reference'=>$bound,
            'raw_status'=>$status,
            'source'=>'sumup_tap_to_pay',
            'transaction_code'=>(string)($transaction['transaction_code']??$txCode),
            'client_transaction_id'=>$returnedClient?:$clientId,
            'raw_transaction'=>$transaction,
        ];
    }

    public function refreshIfNeeded(int $tenantId): array{return$this->gatewayWithFreshToken($tenantId);}

    private function ensureMerchantCode(int $tenantId,array &$gateway): string
    {
        $bound=trim((string)($gateway['account_reference']??''));
        if($bound!=='')return$bound;
        $access=trim((string)($gateway['config']['access_token']??''));
        if($access==='')throw new RuntimeException('Sessão SumUp inválida.');
        $bound=$this->merchantCodeFromProfile($access);
        if($bound==='')throw new RuntimeException('Não foi possível identificar o estabelecimento SumUp. Reconecte a conta.');
        Database::connection()->prepare('UPDATE payment_gateways SET account_reference=? WHERE tenant_id=? AND provider="sumup" AND (account_reference="" OR account_reference IS NULL)')->execute([$bound,$tenantId]);
        $gateway['account_reference']=$bound;
        return$bound;
    }

    private function merchantCodeFromProfile(string $accessToken): string
    {
        $profile=$this->jsonRequest('GET','https://api.sumup.com/v0.1/me',['Authorization: Bearer '.$accessToken]);
        $candidates=[
            $profile['merchant_profile']['merchant_code']??null,
            $profile['merchant_code']??null,
            $profile['merchant']['merchant_code']??null,
        ];
        foreach($candidates as$value){$code=trim((string)$value;if($code!=='')return$code;}
        return'';
    }

    private function gatewayWithFreshToken(int $tenantId): array
    {
        $registry=new PaymentProviderRegistry();$gateway=$registry->gateway($tenantId,'sumup');$config=$gateway['config'];
        $access=trim((string)($config['access_token']??''));
        if($access===''||empty($config['refresh_token']))throw new RuntimeException('Conta SumUp precisa ser conectada novamente.');
        if(strtotime((string)($config['expires_at']??'1970-01-01'))>time()+90)return$gateway;
        $tokens=$this->exchangeToken([
            'grant_type'=>'refresh_token',
            'refresh_token'=>(string)$config['refresh_token'],
            'client_id'=>$this->clientId(),
            'client_secret'=>$this->clientSecret(),
        ]);
        $newAccess=trim((string)($tokens['access_token']??''));
        if($newAccess==='')throw new RuntimeException('Não foi possível renovar a sessão SumUp. Reconecte a conta.');
        $config['access_token']=$newAccess;
        if(!empty($tokens['refresh_token']))$config['refresh_token']=(string)$tokens['refresh_token'];
        $config['expires_at']=date('Y-m-d H:i:s',time()+max(60,(int)($tokens['expires_in']??3600)-60));
        if(isset($tokens['scope']))$config['scope']=(string)$tokens['scope'];
        Database::connection()->prepare('UPDATE payment_gateways SET config_encrypted=? WHERE tenant_id=? AND provider="sumup"')->execute([Crypto::encrypt($config),$tenantId]);
        $gateway['config']=$config;
        return$gateway;
    }

    private function exchangeToken(array $form): array
    {
        return$this->jsonRequest('POST','https://api.sumup.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query($form));
    }

    private function clientId(): string
    {
        $v=trim((string)\env('SUMUP_CLIENT_ID',''));if($v==='')throw new RuntimeException('SUMUP_CLIENT_ID não configurado.');return$v;
    }

    private function clientSecret(): string
    {
        $v=trim((string)\env('SUMUP_CLIENT_SECRET',''));if($v==='')throw new RuntimeException('SUMUP_CLIENT_SECRET não configurado.');return$v;
    }

    private function redirectUri(): string{return\app_absolute_url('sumup-oauth.php');}

    private function jsonRequest(string $method,string $url,array $headers=[],?string $rawBody=null): array
    {
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar comunicação com SumUp.');
        $headers[]='Accept: application/json';
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);
        if($rawBody!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$rawBody);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($body===false||$status<200||$status>=300)throw new RuntimeException('SumUp recusou a operação (HTTP '.$status.')'.($error?' '.$error:''));
        $data=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta SumUp inválida.');return$data;
    }
}
