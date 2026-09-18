<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class MercadoPagoPixService
{
    public function create(int $orderId,string $taxId='',?int $amountCents=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$pdo=Database::connection();
        $q=$pdo->prepare('SELECT o.*,c.name customer_name,c.email customer_email,c.phone customer_phone,c.document customer_document,t.slug tenant_slug,t.settings tenant_settings FROM orders o LEFT JOIN customers c ON c.id=o.customer_id JOIN tenants t ON t.id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança PIX.');
        $shift=(new WorkShiftService())->current();if($shift&&$shift['mode']==='delivery'){if(!Auth::can('orders.delivery')||(int)($order['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('Este pedido não está atribuído a você.');(new DeliveryProgressService())->assertArrived($orderId);}elseif(!Auth::can('payments.manage'))throw new RuntimeException('Você não possui permissão para cobrar PIX.');
        $balance=(new PaymentService())->remaining($orderId,$tenantId);$remaining=(int)$balance['remaining_cents'];$paidBefore=(int)$balance['paid_cents'];$amount=$amountCents??$remaining;if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor PIX inválido. Saldo restante: R$ '.number_format($remaining/100,2,',','.').'.');
        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="mercadopago" AND active=1 LIMIT 1');$g->execute([$tenantId]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Mercado Pago não está ativo.');$config=Crypto::decryptJson((string)$gateway['config_encrypted']);$token=trim((string)($config['access_token']??''));if($token==='')throw new RuntimeException('Access token Mercado Pago não configurado.');
        $key='pix:mercadopago:'.$tenantId.':'.$orderId.':'.$paidBefore.':'.$amount;$existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider="mercadopago" AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$orderId,$key]);$payment=$existing->fetch();if($payment){$raw=json_decode((string)($payment['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_pix_text'])&&in_array((string)$payment['status'],['created','pending','authorized'],true))return $this->response($payment,$raw,true);}
        $active=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") AND id<>? LIMIT 1');$active->execute([$tenantId,$orderId,$payment['id']??0]);if($active->fetchColumn())throw new RuntimeException('Já existe outra cobrança em andamento para este pedido.');
        if(!$payment){Database::transaction(function(PDO $tx)use(&$payment,$tenantId,$orderId,$key,$amount):void{$lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$lock->execute([$orderId,$tenantId]);$fresh=$lock->fetch();if(!$fresh||in_array((string)$fresh['status'],['cancelled','completed'],true)||(string)$fresh['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança.');$check=(new PaymentService())->remaining($orderId,$tenantId);if($amount>(int)$check['remaining_cents'])throw new RuntimeException('Saldo do pedido mudou. Atualize o pagamento.');$tx->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,"mercadopago",?,?,"BRL","created")')->execute([$tenantId,$orderId,$key,$amount]);$id=(int)$tx->lastInsertId();$tx->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);$s=$tx->prepare('SELECT * FROM payments WHERE id=?');$s->execute([$id]);$payment=$s->fetch();});}
        try{(new PaymentCollectionService())->register((int)$payment['id'],'pix','eventmenu_pix');}catch(\Throwable){}(new StockReservationService())->holdForPayment($tenantId,$orderId);

        $settings=json_decode((string)($order['tenant_settings']??'{}'),true);if(!is_array($settings))$settings=[];
        $doc=preg_replace('/\D+/','',$taxId)??'';
        if($doc==='')$doc=preg_replace('/\D+/','',(string)($order['customer_document']??''))??'';
        if($doc==='')$doc=preg_replace('/\D+/','',(string)($settings['pix_default_document']??$settings['receipt_document']??''))??'';
        if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Configure CPF/CNPJ padrão da empresa para PIX Mercado Pago ou informe um documento válido para o cliente.');

        $email=trim((string)($order['customer_email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))$email=trim((string)($settings['pix_default_email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))$email=trim((string)($settings['receipt_email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))$email=trim((string)($config['fallback_payer_email']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Configure um e-mail padrão da empresa para PIX Mercado Pago ou informe um e-mail válido no cadastro do cliente.');

        $payer=['email'=>$email,'identification'=>['type'=>strlen($doc)===11?'CPF':'CNPJ','number'=>$doc]];
        $notification=\app_absolute_url('webhook.php?provider=mercadopago&tenant='.rawurlencode((string)$order['tenant_slug']));$body=['transaction_amount'=>$amount/100,'description'=>'Pedido EventMenu #'.$orderId,'payment_method_id'=>'pix','external_reference'=>'eventmenu:'.$tenantId.':'.$orderId,'notification_url'=>$notification,'payer'=>$payer,'metadata'=>['tenant_id'=>$tenantId,'order_id'=>$orderId,'eventmenu_payment_id'=>(int)$payment['id']]];
        try{$data=$this->httpJson('POST','https://api.mercadopago.com/v1/payments',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$key],$body);$tx=$data['point_of_interaction']['transaction_data']??[];$copy=trim((string)($tx['qr_code']??''));if($copy==='')throw new RuntimeException('Mercado Pago não retornou o PIX copia e cola.');$base64=trim((string)($tx['qr_code_base64']??''));$image=$base64!==''?'data:image/png;base64,'.$base64:'';$expires=(string)($data['date_of_expiration']??'');$data['_eventmenu_pix_text']=$copy;$data['_eventmenu_pix_image_url']=$image;$data['_eventmenu_pix_expires_at']=$expires;$data['_eventmenu_payment_id']=(int)$payment['id'];$pdo->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=? AND tenant_id=?')->execute([(string)($data['id']??''),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$payment['id'],$tenantId]);Auth::audit('payment.pix_created','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>$amount,'provider'=>'mercadopago']);$payment['status']='pending';$payment['provider_payment_id']=(string)($data['id']??'');return $this->response($payment,$data,false);
        }catch(\Throwable $e){$pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=? AND tenant_id=?')->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$payment['id'],$tenantId]);$sum=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$sum->execute([$tenantId,$orderId]);$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([(int)$sum->fetchColumn()>0?'pending':'failed',$orderId,$tenantId]);(new StockReservationService())->rearmAfterPaymentFailure($tenantId,$orderId,30);throw $e;}
    }
    private function response(array $payment,array $raw,bool $reused):array{return ['payment_id'=>(int)$payment['id'],'order_id'=>(int)$payment['order_id'],'amount_cents'=>(int)$payment['amount_cents'],'copy_paste'=>(string)$raw['_eventmenu_pix_text'],'image_url'=>(string)($raw['_eventmenu_pix_image_url']??''),'expires_at'=>(string)($raw['_eventmenu_pix_expires_at']??''),'reused'=>$reused];}
    private function httpJson(string $method,string $url,array $headers,array $body):array{$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha HTTP Mercado Pago.');$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException('Mercado Pago recusou a cobrança PIX (HTTP '.$status.')'.($err?' '.$err:''));$data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta PIX inválida.');return $data;}
}
