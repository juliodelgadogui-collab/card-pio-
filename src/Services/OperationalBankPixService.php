<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OperationalBankPixService
{
    public function create(int $orderId,string $taxId,?int $amountCents,string $provider):array
    {
        if(!in_array($provider,['efi','inter'],true))throw new RuntimeException('Provedor PIX bancário não suportado.');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$pdo=Database::connection();
        $q=$pdo->prepare('SELECT o.*,c.name customer_name,c.email customer_email,c.phone customer_phone,c.document customer_document,t.slug tenant_slug FROM orders o LEFT JOIN customers c ON c.id=o.customer_id JOIN tenants t ON t.id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança PIX.');
        $shift=(new WorkShiftService())->current();if($shift&&$shift['mode']==='delivery'){if(!Auth::can('orders.delivery')||(int)($order['assigned_delivery_user_id']??0)!==$userId)throw new RuntimeException('Este pedido não está atribuído a você.');(new DeliveryProgressService())->assertArrived($orderId);}elseif(!Auth::can('payments.manage'))throw new RuntimeException('Você não possui permissão para cobrar PIX.');
        $balance=(new PaymentService())->remaining($orderId,$tenantId);$remaining=(int)$balance['remaining_cents'];$paidBefore=(int)$balance['paid_cents'];$amount=$amountCents??$remaining;if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor PIX inválido. Saldo restante: R$ '.number_format($remaining/100,2,',','.').'.');
        $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 LIMIT 1');$g->execute([$tenantId,$provider]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Provedor PIX não está ativo.');$config=Crypto::decryptJson((string)$gateway['config_encrypted']);if(array_key_exists('pix_enabled',$config)&&!filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL))throw new RuntimeException('PIX está desativado neste provedor.');
        $doc=preg_replace('/\D+/','',$taxId)??'';if($doc==='')$doc=preg_replace('/\D+/','',(string)($order['customer_document']??''))??'';if(!in_array(strlen($doc),[11,14],true))throw new RuntimeException('Informe CPF/CNPJ válido do pagador para gerar o PIX.');
        $key='pix:'.$provider.':'.$tenantId.':'.$orderId.':'.$paidBefore.':'.$amount;$existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$orderId,$provider,$key]);$payment=$existing->fetch();
        if($payment){$raw=json_decode((string)($payment['raw_payload']??''),true);if(is_array($raw)&&!empty($raw['_eventmenu_pix_text'])&&in_array((string)$payment['status'],['created','pending','authorized'],true))return $this->response($payment,$raw,true);}
        $active=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") AND id<>? LIMIT 1');$active->execute([$tenantId,$orderId,$payment['id']??0]);if($active->fetchColumn())throw new RuntimeException('Já existe outra cobrança em andamento para este pedido.');
        if(!$payment){Database::transaction(function(PDO$tx)use(&$payment,$tenantId,$orderId,$provider,$key,$amount):void{$lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$lock->execute([$orderId,$tenantId]);$fresh=$lock->fetch();if(!$fresh||in_array((string)$fresh['status'],['cancelled','completed'],true)||(string)$fresh['payment_status']==='paid')throw new RuntimeException('Pedido não aceita cobrança.');$check=(new PaymentService())->remaining($orderId,$tenantId);if($amount>(int)$check['remaining_cents'])throw new RuntimeException('Saldo do pedido mudou. Atualize o pagamento.');$tx->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")')->execute([$tenantId,$orderId,$provider,$key,$amount]);$id=(int)$tx->lastInsertId();$tx->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);$s=$tx->prepare('SELECT * FROM payments WHERE id=?');$s->execute([$id]);$payment=$s->fetch();});}
        try{(new PaymentCollectionService())->register((int)$payment['id'],'pix','eventmenu_pix');}catch(\Throwable){}(new StockReservationService())->holdForPayment($tenantId,$orderId);
        $ctx=['config'=>$config,'gateway'=>$gateway,'account'=>['name'=>trim((string)($order['customer_name']??''))?:'Cliente EventMenu','email'=>(string)($order['customer_email']??''),'phone'=>(string)($order['customer_phone']??'')],'order'=>$order,'tenant_slug'=>(string)$order['tenant_slug']];
        try{$data=(new BankPixProviderService())->create($provider,$ctx,$payment,$doc,$key);$providerId=trim((string)($data['_provider_id']??''));if($providerId==='')throw new RuntimeException('O banco não retornou o identificador da cobrança PIX.');$pdo->prepare('UPDATE payments SET provider_payment_id=?,status="pending",raw_payload=? WHERE id=? AND tenant_id=?')->execute([$providerId,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$payment['id'],$tenantId]);Auth::audit('payment.pix_created','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>$amount,'provider'=>$provider]);$payment['provider_payment_id']=$providerId;$payment['status']='pending';return $this->response($payment,$data,false);}
        catch(\Throwable$e){$pdo->prepare('UPDATE payments SET status="failed",raw_payload=? WHERE id=? AND tenant_id=?')->execute([json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),(int)$payment['id'],$tenantId]);$sum=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$sum->execute([$tenantId,$orderId]);$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([(int)$sum->fetchColumn()>0?'pending':'failed',$orderId,$tenantId]);(new StockReservationService())->rearmAfterPaymentFailure($tenantId,$orderId,30);throw $e;}
    }
    private function response(array $payment,array $raw,bool $reused):array{return ['payment_id'=>(int)$payment['id'],'order_id'=>(int)$payment['order_id'],'amount_cents'=>(int)$payment['amount_cents'],'copy_paste'=>(string)$raw['_eventmenu_pix_text'],'image_url'=>(string)($raw['_eventmenu_pix_image_url']??''),'expires_at'=>(string)($raw['_eventmenu_pix_expires_at']??''),'reused'=>$reused];}
}
