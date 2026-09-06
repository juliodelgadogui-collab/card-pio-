<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class NativePixService
{
    public function create(int $orderId,string $taxId,?int $amountCents=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$taxId=$this->validTaxId($taxId);$pdo=Database::connection();
        $o=$pdo->prepare('SELECT o.*,c.name customer_name,c.email customer_email,c.phone customer_phone,t.slug tenant_slug FROM orders o LEFT JOIN customers c ON c.id=o.customer_id JOIN tenants t ON t.id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        if(in_array($order['status'],['cancelled','completed'],true)||$order['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança PIX.');

        $shift=(new WorkShiftService())->current();
        if($shift&&$shift['mode']==='delivery'){
            if(!Auth::can('orders.delivery')||(int)($order['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('Este pedido não está atribuído a você.');
            (new DeliveryProgressService())->assertArrived($orderId);
        }elseif(!Auth::can('payments.manage'))throw new RuntimeException('Você não possui permissão para cobrar PIX.');

        $balance=(new PaymentService())->remaining($orderId,$tenantId);$remaining=(int)$balance['remaining_cents'];$paidBefore=(int)$balance['paid_cents'];$amount=$amountCents??$remaining;if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor PIX inválido. Saldo restante: R$ '.number_format($remaining/100,2,',','.').'.');
        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$g->execute([$tenantId]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$config=Crypto::decryptJson($gateway['config_encrypted']);$token=trim((string)($config['token']??''));if($token==='')throw new RuntimeException('Token PagBank não configurado.');

        $key='go-pix:'.$tenantId.':'.$orderId.':'.$paidBefore.':'.$amount;$existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider="pagbank" AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$orderId,$key]);$payment=$existing->fetch();
        if($payment){
            try{(new PaymentCollectionService())->register((int)$payment['id'],'pix','eventmenu_go_pix');}catch(\Throwable){}
            $raw=json_decode((string)($payment['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_pix_text'])&&in_array($payment['status'],['created','pending','authorized'],true))return $this->responseFromPayload($payment,$raw,true);
        }
        $active=$pdo->prepare('SELECT id,provider,status FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") AND id<>? LIMIT 1');$active->execute([$tenantId,$orderId,$payment['id']??0]);if($active->fetch())throw new RuntimeException('Já existe outra cobrança em andamento para este pedido.');

        if(!$payment){Database::transaction(function(PDO $tx)use(&$payment,$tenantId,$orderId,$key,$amount):void{
            $lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$lock->execute([$orderId,$tenantId]);$fresh=$lock->fetch();if(!$fresh||in_array($fresh['status'],['cancelled','completed'],true)||$fresh['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança.');
            $check=(new PaymentService())->remaining($orderId,$tenantId);if($amount>(int)$check['remaining_cents'])throw new RuntimeException('Saldo do pedido mudou. Atualize o pagamento.');
            $tx->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,"pagbank",?,?,"BRL","created")')->execute([$tenantId,$orderId,$key,$amount]);$id=(int)$tx->lastInsertId();$tx->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);$q=$tx->prepare('SELECT * FROM payments WHERE id=?');$q->execute([$id]);$payment=$q->fetch();
        });}
        try{(new PaymentCollectionService())->register((int)$payment['id'],'pix','eventmenu_go_pix');}catch(\Throwable){}

        (new StockReservationService())->holdForPayment($tenantId,$orderId);$expires=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);
        $customer=['name'=>mb_substr(trim((string)($order['customer_name']?:'Cliente EventMenu')),0,120),'tax_id'=>$taxId];$email=trim((string)($order['customer_email']??''));if(filter_var($email,FILTER_VALIDATE_EMAIL))$customer['email']=$email;$phone=preg_replace('/\D+/','',(string)($order['customer_phone']??''))??'';if(strlen($phone)>=10){$local=substr($phone,-11);$customer['phones']=[['country'=>'55','area'=>substr($local,0,2),'number'=>substr($local,2),'type'=>'MOBILE']];}
        $webhook=\app_absolute_url('webhook.php?provider=pagbank&tenant='.rawurlencode((string)$order['tenant_slug']));
        $body=['reference_id'=>'eventmenu:'.$tenantId.':'.$orderId,'customer'=>$customer,'items'=>[['reference_id'=>'order-'.$orderId.'-part-'.$payment['id'],'name'=>'Pedido EventMenu #'.$orderId,'quantity'=>1,'unit_amount'=>$amount]],'charges'=>[['reference_id'=>'go-pix-'.$orderId.'-'.$payment['id'],'description'=>'Pedido EventMenu #'.$orderId,'amount'=>['value'=>$amount,'currency'=>'BRL'],'payment_method'=>['type'=>'PIX','pix'=>['expiration_date'=>$expires]]]],'notification_urls'=>[$webhook]];
        try{
            $api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$data=$this->httpJson('POST',$api.'/orders',['Authorization: Bearer '.$token,'x-idempotency-key: '.$key],$body);$charge=$data['charges'][0]??null;if(!is_array($charge))throw new RuntimeException('PagBank não retornou a cobrança PIX.');$qr=$charge['qr_code']??null;if(!is_array($qr)||empty($qr['text']))throw new RuntimeException('PagBank não retornou o PIX copia e cola.');
            $imageUrl='';foreach(($charge['links']??[]) as $link)if(($link['rel']??'')==='QRCODE.PNG'){$imageUrl=(string)($link['href']??'');break;}
            $data['_eventmenu_pix_text']=(string)$qr['text'];$data['_eventmenu_pix_image_url']=$imageUrl;$data['_eventmenu_pix_expires_at']=$expires;$data['_eventmenu_payment_id']=(int)$payment['id'];
            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=? AND tenant_id=?')->execute([(string)($data['id']??''),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$payment['id'],$tenantId]);Auth::audit('payment.pix_created','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>$amount,'source'=>'eventmenu_go']);$payment['status']='pending';$payment['provider_payment_id']=(string)($data['id']??'');return $this->responseFromPayload($payment,$data,false);
        }catch(\Throwable $e){
            $pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=? AND tenant_id=?')->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$payment['id'],$tenantId]);$sum=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$sum->execute([$tenantId,$orderId]);$hasPaid=(int)$sum->fetchColumn()>0;$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([$hasPaid?'pending':'failed',$orderId,$tenantId]);(new StockReservationService())->rearmAfterPaymentFailure($tenantId,$orderId,30);throw $e;
        }
    }

    private function responseFromPayload(array $payment,array $raw,bool $reused):array{return ['payment_id'=>(int)$payment['id'],'order_id'=>(int)$payment['order_id'],'amount_cents'=>(int)$payment['amount_cents'],'copy_paste'=>(string)$raw['_eventmenu_pix_text'],'image_url'=>(string)($raw['_eventmenu_pix_image_url']??''),'expires_at'=>(string)($raw['_eventmenu_pix_expires_at']??''),'reused'=>$reused];}
    private function validTaxId(string $value):string{$v=preg_replace('/\D+/','',$value)??'';if(!in_array(strlen($v),[11,14],true)||preg_match('/^(\d)\1+$/',$v))throw new RuntimeException('CPF/CNPJ inválido.');if(strlen($v)===11){for($t=9;$t<11;$t++){$sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$v[$i]*(($t+1)-$i);$d=(10*($sum%11))%11;if($d===10)$d=0;if((int)$v[$t]!==$d)throw new RuntimeException('CPF inválido.');}return $v;}$calc=function(string $base,array $weights):int{$sum=0;foreach($weights as $i=>$w)$sum+=(int)$base[$i]*$w;$r=$sum%11;return $r<2?0:11-$r;};$d1=$calc(substr($v,0,12),[5,4,3,2,9,8,7,6,5,4,3,2]);$d2=$calc(substr($v,0,12).$d1,[6,5,4,3,2,9,8,7,6,5,4,3,2]);if((int)$v[12]!==$d1||(int)$v[13]!==$d2)throw new RuntimeException('CNPJ inválido.');return $v;}
    private function httpJson(string $method,string $url,array $headers,array $body):array{$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha HTTP PagBank.');$headers[]='Accept: application/json';$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_POSTFIELDS=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException('PagBank recusou a cobrança PIX (HTTP '.$status.')'.($err?' '.$err:''));$data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta PIX inválida.');return $data;}
}
