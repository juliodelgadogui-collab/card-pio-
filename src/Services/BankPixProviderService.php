<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class BankPixProviderService
{
    private static array $tokenCache=[];

    public function create(string $provider,array $ctx,array $payment,string $taxId,string $idempotencyKey):array
    {
        return match($provider){
            'efi'=>$this->createEfi($ctx,$payment,$taxId,$idempotencyKey),
            'inter'=>$this->createInter($ctx,$payment,$taxId,$idempotencyKey),
            default=>throw new RuntimeException('Provedor Pix bancário não suportado.'),
        };
    }

    public function reconcileForOrder(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT p.*,g.account_reference,g.config_encrypted FROM payments p JOIN payment_gateways g ON g.tenant_id=p.tenant_id AND g.provider=p.provider AND g.active=1 WHERE p.tenant_id=? AND p.order_id=? AND p.provider IN ("efi","inter") AND p.status IN ("created","pending","authorized") AND p.provider_payment_id IS NOT NULL AND p.provider_payment_id<>"" ORDER BY p.id DESC LIMIT 1');
        $q->execute([$tenantId,$orderId]);$payment=$q->fetch();if(!$payment)return ['checked'=>false];
        $config=\EventMenu\Core\Crypto::decryptJson((string)$payment['config_encrypted']);
        return $this->reconcilePayment($pdo,$payment,$config,(string)$payment['account_reference']);
    }

    public function reconcilePending(PDO $pdo,int $limit=40):array
    {
        $limit=max(1,min(200,$limit));$q=$pdo->query('SELECT p.*,g.account_reference,g.config_encrypted FROM payments p JOIN payment_gateways g ON g.tenant_id=p.tenant_id AND g.provider=p.provider AND g.active=1 WHERE p.provider IN ("efi","inter") AND p.status IN ("created","pending","authorized") AND p.provider_payment_id IS NOT NULL AND p.provider_payment_id<>"" ORDER BY p.id ASC LIMIT '.$limit);
        $checked=0;$paid=0;$terminal=0;$errors=0;
        foreach($q->fetchAll()as$payment){try{$checked++;$config=\EventMenu\Core\Crypto::decryptJson((string)$payment['config_encrypted']);$r=$this->reconcilePayment($pdo,$payment,$config,(string)$payment['account_reference']);if(($r['status']??'')==='paid')$paid++;elseif(!empty($r['terminal']))$terminal++;}catch(\Throwable$e){$errors++;error_log('[bank-pix-reconcile] '.$e::class.': '.$e->getMessage());}}
        return compact('checked','paid','terminal','errors');
    }

    private function reconcilePayment(PDO $pdo,array $payment,array $config,string $accountReference):array
    {
        $provider=(string)$payment['provider'];$txid=(string)$payment['provider_payment_id'];
        $remote=$provider==='efi'?$this->queryEfi($config,$txid):$this->queryInter($config,$txid);
        $configuredKey=trim((string)($config['pix_key']??''));$remoteKey=trim((string)($remote['pix_key']??''));
        if($remoteKey!==''&&$configuredKey!==''&&!hash_equals($configuredKey,$remoteKey))throw new RuntimeException('A cobrança Pix pertence a outra chave recebedora.');

        if(!empty($remote['paid'])){
            $remoteAmount=(int)($remote['amount_cents']??0);if($remoteAmount<1)throw new RuntimeException('O banco não retornou o valor efetivamente recebido no Pix.');
            if($remoteAmount!==(int)$payment['amount_cents'])throw new RuntimeException('Valor recebido no Pix diverge do valor da cobrança local.');
            $e2e=trim((string)($remote['provider_payment_id']??''));if($e2e===''||$e2e===$txid)throw new RuntimeException('O banco não retornou o EndToEndId do Pix confirmado.');
            (new PaymentService())->confirmVerified([
                'payment_id'=>(int)$payment['id'],'tenant_id'=>(int)$payment['tenant_id'],'order_id'=>(int)$payment['order_id'],'provider'=>$provider,
                'provider_payment_id'=>$e2e,'amount_cents'=>$remoteAmount,'currency'=>'BRL','account_reference'=>$accountReference,'source'=>'pix','payment_method_type'=>'pix','raw_status'=>(string)$remote['raw_status'],'bank_txid'=>$txid,'bank_pix_key'=>$remoteKey?:$configuredKey,
            ]);
            return ['checked'=>true,'status'=>'paid','terminal'=>true,'amount_cents'=>$remoteAmount,'end_to_end_id'=>$e2e];
        }
        if(!empty($remote['terminal'])){
            Database::transaction(function(PDO $tx)use($payment,$remote):void{
                $status=($remote['raw_status']??'')==='cancelled'?'cancelled':'failed';
                $tx->prepare('UPDATE payments SET status=?,raw_payload=? WHERE id=? AND status IN ("created","pending","authorized")')->execute([$status,json_encode(['provider_status'=>$remote['raw_status'],'pix_key'=>$remote['pix_key']??null,'checked_at'=>gmdate('c')],JSON_UNESCAPED_UNICODE),(int)$payment['id']]);
                $tx->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([(int)$payment['order_id'],(int)$payment['tenant_id']]);
                try{(new StockReservationService())->rearmAfterPaymentFailure((int)$payment['tenant_id'],(int)$payment['order_id'],30);}catch(\Throwable){}
            });
            return ['checked'=>true,'status'=>'failed','terminal'=>true];
        }
        return ['checked'=>true,'status'=>'pending','terminal'=>false];
    }

    private function createEfi(array $ctx,array $payment,string $taxId,string $key):array
    {
        $config=$ctx['config'];$base=rtrim((string)($config['api_base']??'https://pix.api.efipay.com.br'),'/');$pixKey=trim((string)($config['pix_key']??''));if($pixKey==='')throw new RuntimeException('Chave Pix Efí não configurada.');
        $token=$this->efiToken($config,$base);$txid=$this->txid($key);$doc=$this->document($taxId);$debtor=['nome'=>mb_substr((string)$ctx['account']['name'],0,200)];$debtor[strlen($doc)===11?'cpf':'cnpj']=$doc;
        $body=['calendario'=>['expiracao'=>900],'devedor'=>$debtor,'valor'=>['original'=>number_format(((int)$payment['amount_cents'])/100,2,'.','')],'chave'=>$pixKey,'solicitacaoPagador'=>'Pedido EventMenu #'.(int)$ctx['order']['id']];
        $data=$this->mtlsJson('PUT',$base.'/v2/cob/'.rawurlencode($txid),['Authorization: Bearer '.$token],$body,$config);
        $this->assertCreatedCharge($data,$txid,$pixKey,(int)$payment['amount_cents'],'Efí');
        $copy=trim((string)($data['pixCopiaECola']??''));if($copy==='')throw new RuntimeException('Efí não retornou o Pix Copia e Cola.');$image='';$locId=(int)($data['loc']['id']??0);if($locId>0){try{$qr=$this->mtlsJson('GET',$base.'/v2/loc/'.$locId.'/qrcode',['Authorization: Bearer '.$token],null,$config);$image=(string)($qr['imagemQrcode']??'');}catch(\Throwable){}}
        $data['_eventmenu_pix_text']=$copy;$data['_eventmenu_pix_image_url']=$image;$data['_eventmenu_pix_expires_at']=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);$data['_provider_id']=$txid;return$data;
    }

    private function createInter(array $ctx,array $payment,string $taxId,string $key):array
    {
        $config=$ctx['config'];$base=rtrim((string)($config['api_base']??'https://cdpj.partners.bancointer.com.br'),'/');$pixKey=trim((string)($config['pix_key']??''));if($pixKey==='')throw new RuntimeException('Chave Pix Banco Inter não configurada.');$token=$this->interToken($config,$base);$txid=$this->txid($key);$doc=$this->document($taxId);$debtor=['nome'=>mb_substr((string)$ctx['account']['name'],0,200)];$debtor[strlen($doc)===11?'cpf':'cnpj']=$doc;
        $body=['calendario'=>['expiracao'=>900],'devedor'=>$debtor,'valor'=>['original'=>number_format(((int)$payment['amount_cents'])/100,2,'.',''),'modalidadeAlteracao'=>0],'chave'=>$pixKey,'solicitacaoPagador'=>'Pedido EventMenu #'.(int)$ctx['order']['id']];
        $headers=['Authorization: Bearer '.$token];$account=trim((string)($config['account_number']??''));if($account!=='')$headers[]='x-conta-corrente: '.$account;
        $data=$this->mtlsJson('PUT',$base.'/pix/v2/cob/'.rawurlencode($txid),$headers,$body,$config);$this->assertCreatedCharge($data,$txid,$pixKey,(int)$payment['amount_cents'],'Banco Inter');$copy=trim((string)($data['pixCopiaECola']??''));if($copy==='')throw new RuntimeException('Banco Inter não retornou o Pix Copia e Cola.');
        $data['_eventmenu_pix_text']=$copy;$data['_eventmenu_pix_image_url']='';$data['_eventmenu_pix_expires_at']=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);$data['_provider_id']=$txid;return$data;
    }

    private function queryEfi(array $config,string $txid):array
    {
        $base=rtrim((string)($config['api_base']??'https://pix.api.efipay.com.br'),'/');$token=$this->efiToken($config,$base);$data=$this->mtlsJson('GET',$base.'/v2/cob/'.rawurlencode($txid),['Authorization: Bearer '.$token],null,$config);return$this->normalizeCobStatus($data,$txid);
    }

    private function queryInter(array $config,string $txid):array
    {
        $base=rtrim((string)($config['api_base']??'https://cdpj.partners.bancointer.com.br'),'/');$token=$this->interToken($config,$base);$headers=['Authorization: Bearer '.$token];$account=trim((string)($config['account_number']??''));if($account!=='')$headers[]='x-conta-corrente: '.$account;$data=$this->mtlsJson('GET',$base.'/pix/v2/cob/'.rawurlencode($txid),$headers,null,$config);return$this->normalizeCobStatus($data,$txid);
    }

    private function normalizeCobStatus(array $data,string $txid):array
    {
        $status=mb_strtoupper(trim((string)($data['status']??'')));$paid=$status==='CONCLUIDA';$terminal=in_array($status,['REMOVIDA_PELO_USUARIO_RECEBEDOR','REMOVIDA_PELO_PSP','REMOVIDA_PELO_USUARIO_PAGADOR'],true);$providerId=$txid;$amount=0;
        $pixRows=is_array($data['pix']??null)?$data['pix']:[];foreach($pixRows as$pix){if(!is_array($pix))continue;$amount+=$this->moneyToCents($pix['valor']??0);if($providerId===$txid&&!empty($pix['endToEndId']))$providerId=trim((string)$pix['endToEndId']);}
        return ['paid'=>$paid,'terminal'=>$terminal,'provider_payment_id'=>$providerId,'amount_cents'=>$amount,'pix_key'=>trim((string)($data['chave']??'')),'raw_status'=>$terminal?'cancelled':strtolower($status?:'pending')];
    }

    private function assertCreatedCharge(array $data,string $expectedTxid,string $expectedKey,int $amountCents,string $provider):void
    {
        $returnedTxid=trim((string)($data['txid']??$expectedTxid));if($returnedTxid!==''&&!hash_equals($expectedTxid,$returnedTxid))throw new RuntimeException($provider.' retornou txid divergente.');
        $returnedKey=trim((string)($data['chave']??''));if($returnedKey!==''&&!hash_equals($expectedKey,$returnedKey))throw new RuntimeException($provider.' retornou chave Pix recebedora divergente.');
        $original=$this->moneyToCents($data['valor']['original']??0);if($original>0&&$original!==$amountCents)throw new RuntimeException($provider.' retornou valor de cobrança divergente.');
    }

    private function efiToken(array $config,string $base):string
    {
        $client=trim((string)($config['client_id']??''));$secret=trim((string)($config['client_secret']??''));if($client===''||$secret==='')throw new RuntimeException('Credenciais Efí incompletas.');$cache='efi:'.hash('sha256',$base.'|'.$client);if(($hit=self::$tokenCache[$cache]??null)&&($hit['expires']??0)>time()+60)return(string)$hit['token'];$data=$this->mtlsJson('POST',$base.'/oauth/token',['Authorization: Basic '.base64_encode($client.':'.$secret)],['grant_type'=>'client_credentials'],$config);$token=(string)($data['access_token']??'');if($token==='')throw new RuntimeException('Efí não retornou token OAuth.');self::$tokenCache[$cache]=['token'=>$token,'expires'=>time()+max(120,(int)($data['expires_in']??3600))];return$token;
    }

    private function interToken(array $config,string $base):string
    {
        $client=trim((string)($config['client_id']??''));$secret=trim((string)($config['client_secret']??''));if($client===''||$secret==='')throw new RuntimeException('Credenciais Banco Inter incompletas.');$cache='inter:'.hash('sha256',$base.'|'.$client);if(($hit=self::$tokenCache[$cache]??null)&&($hit['expires']??0)>time()+60)return(string)$hit['token'];$form=http_build_query(['client_id'=>$client,'client_secret'=>$secret,'grant_type'=>'client_credentials','scope'=>'cob.write cob.read pix.read']);$data=$this->mtlsRequest('POST',$base.'/oauth/v2/token',['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],$form,$config);$token=(string)($data['access_token']??'');if($token==='')throw new RuntimeException('Banco Inter não retornou token OAuth.');self::$tokenCache[$cache]=['token'=>$token,'expires'=>time()+max(120,(int)($data['expires_in']??3600))];return$token;
    }

    private function mtlsJson(string $method,string $url,array $headers,?array $body,array $config):array
    {
        $headers[]='Accept: application/json';if($body!==null)$headers[]='Content-Type: application/json';$payload=$body===null?null:json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);return$this->mtlsRequest($method,$url,$headers,$payload,$config);
    }

    private function mtlsRequest(string $method,string $url,array $headers,?string $body,array $config):array
    {
        $certs=new PaymentCertificateService();$cert=$certs->resolve((string)($config['certificate_path']??''));$keyPath=trim((string)($config['private_key_path']??''));$password=(string)($config['certificate_password']??'');$ext=strtolower(pathinfo($cert,PATHINFO_EXTENSION));$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar comunicação bancária.');$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_SSLCERT=>$cert];if(in_array($ext,['p12','pfx'],true)){$opts[CURLOPT_SSLCERTTYPE]='P12';if($password!=='')$opts[CURLOPT_SSLCERTPASSWD]=$password;}else{if($keyPath!=='')$opts[CURLOPT_SSLKEY]=$certs->resolve($keyPath);if($password!=='')$opts[CURLOPT_KEYPASSWD]=$password;}if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($raw===false||$status<200||$status>=300)throw new RuntimeException('O banco recusou a operação Pix (HTTP '.$status.').'.($error?' '.$error:''));$data=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta inválida da API bancária.');return$data;
    }

    private function moneyToCents(mixed $value):int
    {
        $raw=trim((string)$value);if($raw==='')return 0;$raw=str_replace(['R$',' '],'',$raw);if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);if(!is_numeric($raw))return 0;return max(0,(int)round(((float)$raw)*100));
    }
    private function txid(string $key):string{return substr(hash('sha256',$key),0,32);}
    private function document(string $value):string{$doc=preg_replace('/\D+/','',$value)??'';if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Informe CPF ou CNPJ para gerar o Pix.');return$doc;}
}
