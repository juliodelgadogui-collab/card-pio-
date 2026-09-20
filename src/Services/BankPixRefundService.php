<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;

final class BankPixRefundService
{
    private static array $tokenCache=[];

    public function request(string $provider,array $config,array $payment,string $idempotencyKey):array
    {
        $provider=$this->provider($provider);$e2e=trim((string)($payment['provider_payment_id']??''));$this->assertE2E($e2e);$amount=(int)($payment['amount_cents']??0);if($amount<1)throw new RuntimeException('Valor do estorno Pix inválido.');
        $received=$this->requestApi($provider,$config,'GET','/pix/'.rawurlencode($e2e));$remoteE2E=trim((string)($received['endToEndId']??$received['endToEnd']??$e2e));if($remoteE2E!==''&&!hash_equals($e2e,$remoteE2E))throw new RuntimeException('EndToEndId do Pix recebido diverge do pagamento local.');
        $receivedAmount=$this->moneyToCents($received['valor']??0);if($receivedAmount<1||$receivedAmount<$amount)throw new RuntimeException('Valor do Pix recebido é menor que o estorno solicitado.');
        $configuredKey=$this->normalizePixKey((string)($config['pix_key']??''));$receivedKey=$this->normalizePixKey((string)($received['chave']??''));if($configuredKey!==''&&$receivedKey!==''&&!hash_equals($configuredKey,$receivedKey))throw new RuntimeException('O Pix recebido pertence a outra chave recebedora.');

        $refundId=substr(hash('sha256',$idempotencyKey),0,32);$response=$this->requestApi($provider,$config,'PUT','/pix/'.rawurlencode($e2e).'/devolucao/'.rawurlencode($refundId),['valor'=>number_format($amount/100,2,'.','')]);
        $normalized=$this->normalizeRefund($response,$refundId,$amount);if(!empty($normalized['failed']))throw new RuntimeException((string)$normalized['error']);return$normalized;
    }

    public function check(string $provider,array $config,array $refund,array $payment):array
    {
        $provider=$this->provider($provider);$e2e=trim((string)($payment['provider_payment_id']??''));$this->assertE2E($e2e);$refundId=trim((string)($refund['provider_refund_id']??''));if(!preg_match('/^[A-Za-z0-9]{1,35}$/',$refundId))throw new RuntimeException('Identificador da devolução Pix inválido.');
        $response=$this->requestApi($provider,$config,'GET','/pix/'.rawurlencode($e2e).'/devolucao/'.rawurlencode($refundId));$normalized=$this->normalizeRefund($response,$refundId,(int)$refund['amount_cents']);
        if(!empty($normalized['failed'])){
            $message=mb_substr((string)$normalized['error'],0,1000);try{Database::connection()->prepare('UPDATE refunds SET status="failed",provider_payload=?,error_message=? WHERE id=? AND tenant_id=? AND status="provider_pending"')->execute([json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$message,(int)$refund['id'],(int)$payment['tenant_id']]);}catch(\Throwable){}
            throw new RuntimeException($message);
        }
        return$normalized;
    }

    private function normalizeRefund(array $response,string $refundId,int $expectedAmount):array
    {
        $returnedId=trim((string)($response['id']??$refundId));if($returnedId!==''&&!hash_equals($refundId,$returnedId))throw new RuntimeException('Identificador da devolução retornado pelo banco é divergente.');$returnedAmount=$this->moneyToCents($response['valor']??0);if($returnedAmount>0&&$returnedAmount!==$expectedAmount)throw new RuntimeException('Valor da devolução retornado pelo banco diverge do estorno local.');
        $status=mb_strtoupper(trim((string)($response['status']??'')));if($status==='NAO_REALIZADO')return ['completed'=>false,'failed'=>true,'provider_refund_id'=>$refundId,'payload'=>$response,'error'=>(string)($response['motivo']??'Devolução Pix não realizada pelo banco.')];
        return ['completed'=>$status==='DEVOLVIDO','failed'=>false,'provider_refund_id'=>$refundId,'payload'=>$response];
    }

    private function requestApi(string $provider,array $config,string $method,string $path,?array $body=null):array
    {
        if($provider==='efi'){$base=rtrim((string)($config['api_base']??'https://pix.api.efipay.com.br'),'/');$token=$this->efiToken($config,$base);return$this->mtlsJson($method,$base.'/v2'.$path,['Authorization: Bearer '.$token],$body,$config);}
        $base=rtrim((string)($config['api_base']??'https://cdpj.partners.bancointer.com.br'),'/');$token=$this->interToken($config,$base);$headers=['Authorization: Bearer '.$token];$account=trim((string)($config['account_number']??''));if($account!=='')$headers[]='x-conta-corrente: '.$account;return$this->mtlsJson($method,$base.'/pix/v2'.$path,$headers,$body,$config);
    }

    private function efiToken(array $config,string $base):string
    {
        $client=trim((string)($config['client_id']??''));$secret=trim((string)($config['client_secret']??''));if($client===''||$secret==='')throw new RuntimeException('Credenciais Efí incompletas.');$cache='efi-refund:'.hash('sha256',$base.'|'.$client);if(($hit=self::$tokenCache[$cache]??null)&&($hit['expires']??0)>time()+60)return(string)$hit['token'];$data=$this->mtlsJson('POST',$base.'/oauth/token',['Authorization: Basic '.base64_encode($client.':'.$secret)],['grant_type'=>'client_credentials'],$config);$token=(string)($data['access_token']??'');if($token==='')throw new RuntimeException('Efí não retornou token OAuth para devolução.');self::$tokenCache[$cache]=['token'=>$token,'expires'=>time()+max(120,(int)($data['expires_in']??3600))];return$token;
    }

    private function interToken(array $config,string $base):string
    {
        $client=trim((string)($config['client_id']??''));$secret=trim((string)($config['client_secret']??''));if($client===''||$secret==='')throw new RuntimeException('Credenciais Banco Inter incompletas.');$cache='inter-refund:'.hash('sha256',$base.'|'.$client);if(($hit=self::$tokenCache[$cache]??null)&&($hit['expires']??0)>time()+60)return(string)$hit['token'];$form=http_build_query(['client_id'=>$client,'client_secret'=>$secret,'grant_type'=>'client_credentials','scope'=>'pix.read pix.write']);$data=$this->mtlsRequest('POST',$base.'/oauth/v2/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$form,$config);$token=(string)($data['access_token']??'');if($token==='')throw new RuntimeException('Banco Inter não retornou token OAuth para devolução.');self::$tokenCache[$cache]=['token'=>$token,'expires'=>time()+max(120,(int)($data['expires_in']??3600))];return$token;
    }

    private function mtlsJson(string $method,string $url,array $headers,?array $body,array $config):array
    {
        $headers[]='Accept: application/json';if($body!==null)$headers[]='Content-Type: application/json';$payload=$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);return$this->mtlsRequest($method,$url,$headers,$payload,$config);
    }

    private function mtlsRequest(string $method,string $url,array $headers,?string $body,array $config):array
    {
        $files=new PaymentCertificateService();$cert=$files->resolve((string)($config['certificate_path']??''));$keyPath=trim((string)($config['private_key_path']??''));$password=(string)($config['certificate_password']??'');$ext=strtolower(pathinfo($cert,PATHINFO_EXTENSION));$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar comunicação bancária para devolução.');$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLCERT=>$cert];if(in_array($ext,['p12','pfx'],true)){$opts[CURLOPT_SSLCERTTYPE]='P12';if($password!=='')$opts[CURLOPT_SSLCERTPASSWD]=$password;}else{if($keyPath==='')throw new RuntimeException('Chave privada do certificado bancário ausente.');$opts[CURLOPT_SSLKEY]=$files->resolve($keyPath);if($password!=='')$opts[CURLOPT_KEYPASSWD]=$password;}if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false||$status<200||$status>=300)throw new RuntimeException('O banco recusou a devolução Pix (HTTP '.$status.').'.($error?' '.$error:''));$data=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta inválida da devolução Pix.');return$data;
    }

    private function provider(string $provider):string{$provider=strtolower(trim($provider));if(!in_array($provider,['efi','inter'],true))throw new RuntimeException('Provedor Pix bancário inválido.');return$provider;}
    private function assertE2E(string $value):void{if(!preg_match('/^E[A-Za-z0-9]{20,40}$/',$value))throw new RuntimeException('EndToEndId Pix inválido para devolução.');}
    private function normalizePixKey(string $value):string{$value=trim($value);if($value==='')return'';if(str_contains($value,'@'))return mb_strtolower($value);$digits=preg_replace('/\D+/','',$value)??'';if(in_array(strlen($digits),[11,14],true))return$digits;if(str_starts_with($value,'+'))return'+'.$digits;if(preg_match('/^[0-9a-fA-F-]{32,36}$/',$value))return strtolower($value);return$value;}
    private function moneyToCents(mixed $value):int{$raw=trim((string)$value);if($raw==='')return 0;$raw=str_replace(['R$',' '],'',$raw);if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);if(!is_numeric($raw))return 0;return max(0,(int)round(((float)$raw)*100));}
}
