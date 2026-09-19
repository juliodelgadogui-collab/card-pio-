<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use PDO;
use RuntimeException;

final class DeliveryCustomerPaymentService
{
    public function pix(PDO $pdo,int $accountId,int $orderId,string $provider,string $taxId=''): array
    {
        $provider=strtolower(trim($provider));if(!in_array($provider,['mercadopago','pagbank'],true))throw new RuntimeException('Forma PIX indisponível.');
        $ctx=$this->context($pdo,$accountId,$orderId,$provider);$this->assertChargeable($ctx['order']);$amount=$this->remaining($pdo,(int)$ctx['order']['tenant_id'],$orderId);if($amount<=0)throw new RuntimeException('Pedido já está pago.');
        $key='delivery-app:pix:'.$provider.':'.(int)$ctx['order']['tenant_id'].':'.$orderId.':'.$amount;$payment=$this->localPayment($pdo,$ctx['order'],$provider,$key,$amount);
        $raw=json_decode((string)($payment['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_pix_text'])&&in_array((string)$payment['status'],['created','pending','authorized'],true))return $this->pixResponse($payment,$raw,true);
        (new StockReservationService())->holdForPayment((int)$ctx['order']['tenant_id'],$orderId);
        try{
            $data=$provider==='mercadopago'?$this->mercadoPagoPix($ctx,$payment,$taxId,$key):$this->pagBankPix($ctx,$payment,$taxId,$key);
            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=?')->execute([(string)($data['_provider_id']??''),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);$payment['status']='pending';return $this->pixResponse($payment,$data,false);
        }catch(\Throwable $e){$this->failPayment($pdo,$payment,$ctx['order'],$e->getMessage());throw $e;}
    }

    public function card(PDO $pdo,int $accountId,int $orderId,array $payload): array
    {
        $provider=strtolower(trim((string)($payload['provider']??'mercadopago')));if($provider!=='mercadopago')throw new RuntimeException('Cartão dentro do app está disponível pelo Mercado Pago nesta versão.');
        $token=trim((string)($payload['card_token']??''));$method=mb_substr(trim((string)($payload['payment_method_id']??'')),0,60);$installments=max(1,min(12,(int)($payload['installments']??1)));if($token===''||$method==='')throw new RuntimeException('Dados tokenizados do cartão são obrigatórios.');
        $ctx=$this->context($pdo,$accountId,$orderId,$provider);$this->assertChargeable($ctx['order']);$amount=$this->remaining($pdo,(int)$ctx['order']['tenant_id'],$orderId);if($amount<=0)throw new RuntimeException('Pedido já está pago.');$key='delivery-app:card:mercadopago:'.(int)$ctx['order']['tenant_id'].':'.$orderId.':'.$amount;
        $payment=$this->localPayment($pdo,$ctx['order'],$provider,$key,$amount);(new StockReservationService())->holdForPayment((int)$ctx['order']['tenant_id'],$orderId);
        try{
            $config=$ctx['config'];$access=trim((string)($config['access_token']??''));if($access==='')throw new RuntimeException('Mercado Pago não configurado.');
            $payer=['email'=>(string)$ctx['account']['email']];$taxId=preg_replace('/\D+/','',(string)($payload['tax_id']??''))??'';if(in_array(strlen($taxId),[11,14],true))$payer['identification']=['type'=>strlen($taxId)===11?'CPF':'CNPJ','number'=>$taxId];
            $body=['transaction_amount'=>$amount/100,'token'=>$token,'description'=>'Pedido EventMenu #'.$orderId,'installments'=>$installments,'payment_method_id'=>$method,'external_reference'=>'eventmenu:'.(int)$ctx['order']['tenant_id'].':'.$orderId,'notification_url'=>\app_absolute_url('webhook.php?provider=mercadopago&tenant='.rawurlencode((string)$ctx['tenant_slug'])),'payer'=>$payer,'metadata'=>['tenant_id'=>(int)$ctx['order']['tenant_id'],'order_id'=>$orderId,'eventmenu_payment_id'=>(int)$payment['id'],'channel'=>'eventmenu_delivery_app']];
            $issuer=trim((string)($payload['issuer_id']??''));if($issuer!=='')$body['issuer_id']=$issuer;
            $data=$this->httpJson('POST','https://api.mercadopago.com/v1/payments',['Authorization: Bearer '.$access,'X-Idempotency-Key: '.$key],$body);$external=(string)($data['id']??'');$providerStatus=strtolower((string)($data['status']??''));
            $payloadForDb=$data;$payloadForDb['_eventmenu_payment_id']=(int)$payment['id'];$pdo->prepare('UPDATE payments SET provider_payment_id=?,status=?,raw_payload=? WHERE id=?')->execute([$external,in_array($providerStatus,['approved'],true)?'authorized':(in_array($providerStatus,['pending','in_process','in_mediation'],true)?'pending':'failed'),json_encode($payloadForDb,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id']]);
            if($providerStatus==='approved'){
                (new PaymentService())->confirmVerified(['tenant_id'=>(int)$ctx['order']['tenant_id'],'order_id'=>$orderId,'provider'=>'mercadopago','provider_payment_id'=>$external,'payment_id'=>(int)$payment['id'],'amount_cents'=>$amount,'currency'=>'BRL','account_reference'=>(string)$ctx['gateway']['account_reference'],'source'=>'card','payment_method_type'=>'card']);
                return ['payment_id'=>(int)$payment['id'],'order_id'=>$orderId,'provider'=>'mercadopago','status'=>'paid','status_detail'=>(string)($data['status_detail']??''),'amount_cents'=>$amount];
            }
            if(in_array($providerStatus,['pending','in_process','in_mediation'],true))return ['payment_id'=>(int)$payment['id'],'order_id'=>$orderId,'provider'=>'mercadopago','status'=>'pending','status_detail'=>(string)($data['status_detail']??''),'amount_cents'=>$amount];
            (new StockReservationService())->rearmAfterPaymentFailure((int)$ctx['order']['tenant_id'],$orderId,30);throw new RuntimeException('O cartão não foi aprovado. Verifique os dados ou tente outra forma de pagamento.');
        }catch(\Throwable $e){$fresh=$pdo->prepare('SELECT status FROM payments WHERE id=?');$fresh->execute([(int)$payment['id']]);if(!in_array((string)$fresh->fetchColumn(),['paid','pending','authorized'],true))$this->failPayment($pdo,$payment,$ctx['order'],$e->getMessage());throw $e;}
    }

    public function status(PDO $pdo,int $accountId,int $orderId): array
    {
        $ctx=(new DeliveryCustomerMarketplaceService())->ownedOrder($pdo,$accountId,$orderId);$q=$pdo->prepare('SELECT id,provider,amount_cents,currency,status,verified_at,created_at FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id DESC LIMIT 10');$q->execute([(int)$ctx['tenant_id'],$orderId]);$payments=[];foreach($q->fetchAll() as$p)$payments[]=['id'=>(int)$p['id'],'provider'=>(string)$p['provider'],'amount_cents'=>(int)$p['amount_cents'],'currency'=>(string)$p['currency'],'status'=>(string)$p['status'],'verified_at'=>$p['verified_at'],'created_at'=>$p['created_at']];return ['order_id'=>$orderId,'payment_status'=>(string)$ctx['payment_status'],'payments'=>$payments];
    }

    private function context(PDO $pdo,int $accountId,int $orderId,string $provider): array
    {
        $order=(new DeliveryCustomerMarketplaceService())->ownedOrder($pdo,$accountId,$orderId);$a=$pdo->prepare('SELECT id,name,email,phone FROM delivery_customer_accounts WHERE id=? AND status="active" AND email_verified_at IS NOT NULL');$a->execute([$accountId]);$account=$a->fetch();if(!$account)throw new RuntimeException('Conta inválida.');$t=$pdo->prepare('SELECT slug FROM tenants WHERE id=? AND status="active"');$t->execute([(int)$order['tenant_id']]);$slug=$t->fetchColumn();if($slug===false)throw new RuntimeException('Restaurante indisponível.');$g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 LIMIT 1');$g->execute([(int)$order['tenant_id'],$provider]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Forma de pagamento indisponível.');return ['order'=>$order,'account'=>$account,'tenant_slug'=>(string)$slug,'gateway'=>$gateway,'config'=>Crypto::decryptJson((string)$gateway['config_encrypted'])];
    }

    private function localPayment(PDO $pdo,array $order,string $provider,string $key,int $amount): array
    {
        $tenantId=(int)$order['tenant_id'];$orderId=(int)$order['id'];
        $existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$key]);if($row=$existing->fetch())return $row;
        $open=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1');$open->execute([$tenantId,$orderId]);
        if($row=$open->fetch())throw new RuntimeException('Já existe uma cobrança eletrônica em andamento para este pedido. Aguarde o resultado antes de trocar a forma de pagamento.');
        try{$pdo->prepare('DELETE FROM delivery_customer_payment_preferences WHERE order_id=?')->execute([$orderId]);}catch(\Throwable){}
        $pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")')->execute([$tenantId,$orderId,$provider,$key,$amount]);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND payment_status<>"paid"')->execute([$orderId]);try{(new PaymentCollectionService())->register($id,$provider==='mercadopago'?'online':'pix','eventmenu_delivery_app');}catch(\Throwable){}$q=$pdo->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$id]);return $q->fetch();
    }

    private function mercadoPagoPix(array $ctx,array $payment,string $taxId,string $key): array
    {
        $token=trim((string)($ctx['config']['access_token']??''));if($token==='')throw new RuntimeException('Mercado Pago não configurado.');$doc=preg_replace('/\D+/','',$taxId)??'';if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Informe CPF ou CNPJ para gerar o PIX.');$order=$ctx['order'];$body=['transaction_amount'=>(int)$payment['amount_cents']/100,'description'=>'Pedido EventMenu #'.(int)$order['id'],'payment_method_id'=>'pix','external_reference'=>'eventmenu:'.(int)$order['tenant_id'].':'.(int)$order['id'],'notification_url'=>\app_absolute_url('webhook.php?provider=mercadopago&tenant='.rawurlencode((string)$ctx['tenant_slug'])),'payer'=>['email'=>(string)$ctx['account']['email'],'identification'=>['type'=>strlen($doc)===11?'CPF':'CNPJ','number'=>$doc]],'metadata'=>['tenant_id'=>(int)$order['tenant_id'],'order_id'=>(int)$order['id'],'eventmenu_payment_id'=>(int)$payment['id'],'channel'=>'eventmenu_delivery_app']];$data=$this->httpJson('POST','https://api.mercadopago.com/v1/payments',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$key],$body);$tx=$data['point_of_interaction']['transaction_data']??[];$copy=trim((string)($tx['qr_code']??''));if($copy==='')throw new RuntimeException('Mercado Pago não retornou o PIX copia e cola.');$base64=trim((string)($tx['qr_code_base64']??''));$data['_eventmenu_pix_text']=$copy;$data['_eventmenu_pix_image_url']=$base64!==''?'data:image/png;base64,'.$base64:'';$data['_eventmenu_pix_expires_at']=(string)($data['date_of_expiration']??'');$data['_provider_id']=(string)($data['id']??'');return $data;
    }

    private function pagBankPix(array $ctx,array $payment,string $taxId,string $key): array
    {
        $doc=preg_replace('/\D+/','',$taxId)??'';if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Informe CPF ou CNPJ para gerar o PIX.');$token=trim((string)($ctx['config']['token']??''));if($token==='')throw new RuntimeException('PagBank não configurado.');$order=$ctx['order'];$amount=(int)$payment['amount_cents'];$expires=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);$customer=['name'=>mb_substr((string)$ctx['account']['name'],0,120),'tax_id'=>$doc,'email'=>(string)$ctx['account']['email']];$phone=preg_replace('/\D+/','',(string)($ctx['account']['phone']??''))??'';if(strlen($phone)>=10){$local=substr($phone,-11);$customer['phones']=[['country'=>'55','area'=>substr($local,0,2),'number'=>substr($local,2),'type'=>'MOBILE']];}$body=['reference_id'=>'eventmenu:'.(int)$order['tenant_id'].':'.(int)$order['id'],'customer'=>$customer,'items'=>[['reference_id'=>'order-'.(int)$order['id'],'name'=>'Pedido EventMenu #'.(int)$order['id'],'quantity'=>1,'unit_amount'=>$amount]],'charges'=>[['reference_id'=>'pix-'.(int)$order['id'].'-'.(int)$payment['id'],'description'=>'Pedido EventMenu #'.(int)$order['id'],'amount'=>['value'=>$amount,'currency'=>'BRL'],'payment_method'=>['type'=>'PIX','pix'=>['expiration_date'=>$expires]]]],'notification_urls'=>[\app_absolute_url('webhook.php?provider=pagbank&tenant='.rawurlencode((string)$ctx['tenant_slug']))]];$api=rtrim((string)($ctx['config']['api_base']??'https://api.pagseguro.com'),'/');$data=$this->httpJson('POST',$api.'/orders',['Authorization: Bearer '.$token,'x-idempotency-key: '.$key],$body);$charge=$data['charges'][0]??null;$qr=is_array($charge)?($charge['qr_code']??null):null;if(!is_array($qr)||empty($qr['text']))throw new RuntimeException('PagBank não retornou o PIX copia e cola.');$image='';foreach(($charge['links']??[])as$link)if(($link['rel']??'')==='QRCODE.PNG'){$image=(string)($link['href']??'');break;}$data['_eventmenu_pix_text']=(string)$qr['text'];$data['_eventmenu_pix_image_url']=$image;$data['_eventmenu_pix_expires_at']=$expires;$data['_provider_id']=(string)($data['id']??'');return $data;
    }

    private function pixResponse(array $payment,array $raw,bool $reused): array{return ['payment_id'=>(int)$payment['id'],'order_id'=>(int)$payment['order_id'],'provider'=>(string)$payment['provider'],'status'=>(string)$payment['status'],'amount_cents'=>(int)$payment['amount_cents'],'copy_paste'=>(string)$raw['_eventmenu_pix_text'],'image_url'=>(string)($raw['_eventmenu_pix_image_url']??''),'expires_at'=>(string)($raw['_eventmenu_pix_expires_at']??''),'reused'=>$reused];}
    private function remaining(PDO $pdo,int $tenantId,int $orderId): int{$q=$pdo->prepare('SELECT o.total_cents-COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.tenant_id=o.tenant_id AND p.order_id=o.id AND p.status="paid"),0) FROM orders o WHERE o.id=? AND o.tenant_id=?');$q->execute([$orderId,$tenantId]);return max(0,(int)$q->fetchColumn());}
    private function assertChargeable(array $order): void{if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita nova cobrança.');}
    private function failPayment(PDO $pdo,array $payment,array $order,string $message): void{$pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=? AND status<>"paid"')->execute([json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE),(int)$payment['id']]);$pdo->prepare('UPDATE orders SET payment_status="failed" WHERE id=? AND payment_status<>"paid"')->execute([(int)$order['id']]);try{(new StockReservationService())->rearmAfterPaymentFailure((int)$order['tenant_id'],(int)$order['id'],30);}catch(\Throwable){}}
    private function httpJson(string $method,string $url,array $headers,array $body): array{$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar pagamento.');$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException('O provedor de pagamento recusou a operação (HTTP '.$status.').'.($err?' '.$err:''));$data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta inválida do provedor de pagamento.');return $data;}
}
