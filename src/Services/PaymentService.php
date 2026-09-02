<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PaymentService
{
    private const PROVIDERS=['stripe','pagbank','mercadopago','manual'];

    public function create(int $orderId,string $provider,string $idempotencyKey):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();
        $provider=strtolower(trim($provider));
        if(!$tenantId||!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Empresa ou provedor inválido.');
        if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$provider,$idempotencyKey){
            $stmt=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $stmt->execute([$orderId,$tenantId]);
            $order=$stmt->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(in_array($order['status'],['cancelled','completed'],true))throw new RuntimeException('Pedido cancelado ou finalizado não pode receber nova cobrança.');
            if(in_array($order['payment_status'],['paid','partially_refunded','refunded'],true))throw new RuntimeException('Pedido já pago ou reembolsado não pode receber nova cobrança.');

            if($provider!=='manual'){
                $gw=$pdo->prepare('SELECT id FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1');
                $gw->execute([$tenantId,$provider]);
                if(!$gw->fetchColumn())throw new RuntimeException('Gateway não está ativo para esta empresa.');
            }

            $existing=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$tenantId,$idempotencyKey]);
            if($payment=$existing->fetch()){
                if($payment['provider']!==$provider||(int)$payment['order_id']!==$orderId)throw new RuntimeException('Chave de idempotência vinculada a outra cobrança.');
                return $payment;
            }

            $open=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $open->execute([$tenantId,$orderId]);
            if($payment=$open->fetch()){
                if($payment['provider']!==$provider)throw new RuntimeException('Já existe uma cobrança em andamento por outro provedor.');
                return $payment;
            }

            $stmt=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")');
            $stmt->execute([$tenantId,$orderId,$provider,$idempotencyKey,$order['total_cents']]);
            $id=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);
            Auth::audit('payment.created','payment',(string)$id,['order_id'=>$orderId,'provider'=>$provider]);
            return ['id'=>$id,'order_id'=>$orderId,'amount_cents'=>(int)$order['total_cents'],'status'=>'created'];
        });
    }

    public function confirmVerified(array $verified):void
    {
        foreach(['tenant_id','order_id','provider','provider_payment_id','amount_cents','currency','account_reference'] as $key){
            if(!array_key_exists($key,$verified))throw new RuntimeException("Campo ausente: {$key}");
        }
        $verified['provider']=strtolower((string)$verified['provider']);

        $confirmed=Database::transaction(function(PDO $pdo)use($verified):bool{
            $tenantId=(int)$verified['tenant_id'];
            $orderId=(int)$verified['order_id'];
            $provider=(string)$verified['provider'];
            if(!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Provedor inválido.');

            $stmt=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $stmt->execute([$orderId,$tenantId]);
            $order=$stmt->fetch();
            if(!$order)throw new RuntimeException('Pedido inválido.');
            if($order['status']==='cancelled')throw new RuntimeException('Pedido cancelado.');
            if(in_array($order['payment_status'],['partially_refunded','refunded'],true))throw new RuntimeException('Pedido já passou por reembolso e não pode ser reconfirmado como pago.');
            if((int)$order['total_cents']!==(int)$verified['amount_cents'])throw new RuntimeException('Valor divergente.');
            if(strtoupper((string)$verified['currency'])!=='BRL')throw new RuntimeException('Moeda divergente.');

            if($provider!=='manual'){
                $gw=$pdo->prepare('SELECT account_reference FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 FOR UPDATE');
                $gw->execute([$tenantId,$provider]);
                $account=$gw->fetchColumn();
                if($account===false)throw new RuntimeException('Gateway local não está ativo.');
                if((string)$account!==(string)$verified['account_reference'])throw new RuntimeException('Conta do recebedor divergente.');
            }

            $find=$pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND status IN ("created","pending","authorized","paid") ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $find->execute([$tenantId,$orderId,$provider]);
            $payment=$find->fetch();
            if(!$payment)throw new RuntimeException('Cobrança local não encontrada.');
            if($payment['status']==='paid')return false;
            if($order['payment_status']==='paid')throw new RuntimeException('Pedido já foi pago por outra cobrança.');
            if((int)$payment['amount_cents']!==(int)$verified['amount_cents']||strtoupper((string)$payment['currency'])!=='BRL')throw new RuntimeException('Cobrança divergente.');

            $dupe=$pdo->prepare('SELECT id FROM payments WHERE provider=? AND provider_payment_id=? AND id<>? LIMIT 1');
            $dupe->execute([$provider,(string)$verified['provider_payment_id'],$payment['id']]);
            if($dupe->fetchColumn())throw new RuntimeException('Transação do provedor já vinculada a outra cobrança.');

            if($provider==='manual'){
                $userId=Auth::id();
                if(!$userId)throw new RuntimeException('Operador não autenticado para pagamento manual.');
                $method=(string)($verified['manual_method']??'');
                (new CashRegisterService())->recordManualSale($pdo,$tenantId,$userId,$orderId,(int)$verified['amount_cents'],$method,(string)$payment['idempotency_key']);
            }

            (new StockService())->commitForOrder($pdo,$tenantId,$orderId);
            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="paid",verified_at=NOW(),raw_payload=? WHERE id=?')->execute([(string)$verified['provider_payment_id'],json_encode($verified,JSON_UNESCAPED_UNICODE),$payment['id']]);
            $pdo->prepare('UPDATE orders SET payment_status="paid",status=IF(status="pending","confirmed",status) WHERE id=?')->execute([$orderId]);

            $tickets=$pdo->prepare('SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND order_id=? AND status="reserved" GROUP BY batch_id FOR UPDATE');
            $tickets->execute([$tenantId,$orderId]);
            foreach($tickets->fetchAll() as $row){
                $qty=(int)$row['qty'];
                $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?),quantity_sold=quantity_sold+? WHERE id=?')->execute([$qty,$qty,$row['batch_id']]);
            }
            $pdo->prepare('UPDATE tickets SET status="paid",reserved_until=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);

            if(!empty($order['coupon_id'])){
                $r=$pdo->prepare('SELECT * FROM coupon_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE');
                $r->execute([$tenantId,$orderId]);
                $reservation=$r->fetch();
                if($reservation){
                    $pdo->prepare('UPDATE coupon_reservations SET status="redeemed" WHERE id=?')->execute([$reservation['id']]);
                    $pdo->prepare('UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1),uses_count=uses_count+1 WHERE id=? AND tenant_id=?')->execute([$order['coupon_id'],$tenantId]);
                    $pdo->prepare('INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)')->execute([$tenantId,$order['coupon_id'],$orderId,$order['customer_id']?:null,$order['discount_cents'],'payment:'.$payment['id'].':coupon']);
                }else{
                    $red=$pdo->prepare('INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)');
                    $red->execute([$tenantId,$order['coupon_id'],$orderId,$order['customer_id']?:null,$order['discount_cents'],'payment:'.$payment['id'].':coupon']);
                    if($red->rowCount()===1)$pdo->prepare('UPDATE coupons SET uses_count=uses_count+1 WHERE id=? AND tenant_id=?')->execute([$order['coupon_id'],$tenantId]);
                }
            }

            if(!empty($order['customer_id'])&&$this->pointsEnabled($pdo,$tenantId)){
                $points=intdiv((int)$order['total_cents'],100);
                if($points>0){
                    $key='payment:'.$payment['id'].':points';
                    $insert=$pdo->prepare('INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?,? ,"earn",?)');
                    $insert->execute([$tenantId,$order['customer_id'],$orderId,$points,$key]);
                    if($insert->rowCount()===1)$pdo->prepare('UPDATE customers SET points=points+? WHERE id=? AND tenant_id=?')->execute([$points,$order['customer_id'],$tenantId]);
                }
            }

            if(!empty($order['promoter_id'])){
                $p=$pdo->prepare('SELECT commission_percent FROM promoters WHERE id=? AND tenant_id=? AND active=1');
                $p->execute([$order['promoter_id'],$tenantId]);
                $percent=$p->fetchColumn();
                if($percent!==false){
                    $commission=(int)round((int)$order['total_cents']*((float)$percent/100));
                    $pdo->prepare('INSERT IGNORE INTO promoter_commissions (tenant_id,promoter_id,order_id,amount_cents,status,idempotency_key) VALUES (?,?,?,?,"approved",?)')->execute([$tenantId,$order['promoter_id'],$orderId,$commission,'payment:'.$payment['id'].':commission']);
                }
            }
            return true;
        });

        if($confirmed)$this->afterConfirmed($verified);
    }

    private function pointsEnabled(PDO $pdo,int $tenantId):bool
    {
        try{$s=$pdo->prepare('SELECT settings FROM tenants WHERE id=?');$s->execute([$tenantId]);$settings=json_decode((string)($s->fetchColumn()?:'{}'),true);return!is_array($settings)||!array_key_exists('points_enabled',$settings)||(bool)$settings['points_enabled'];}catch(\Throwable){return true;}
    }

    private function afterConfirmed(array $verified):void
    {
        $tenantId=(int)$verified['tenant_id'];$orderId=(int)$verified['order_id'];$notifications=new NotificationService();
        $notifications->push($tenantId,'payment','Pagamento confirmado','Pedido #'.$orderId.' pago com sucesso via '.strtoupper((string)$verified['provider']).'.','order',$orderId);
        try{
            $pdo=Database::connection();
            $low=$pdo->prepare('SELECT DISTINCT p.id,p.name,p.stock_qty,p.min_stock_qty FROM stock_movements sm JOIN products p ON p.id=sm.product_id AND p.tenant_id=sm.tenant_id WHERE sm.tenant_id=? AND sm.order_id=? AND sm.type="out" AND p.track_stock=1 AND p.min_stock_qty IS NOT NULL AND p.stock_qty<=p.min_stock_qty');
            $low->execute([$tenantId,$orderId]);
            foreach($low->fetchAll() as$p)$notifications->pushOnce($tenantId,'stock','Estoque baixo: '.$p['name'],'Saldo atual: '.$p['stock_qty'].' · mínimo configurado: '.$p['min_stock_qty'].'.','product',(int)$p['id']);

            $s=$pdo->prepare('SELECT o.id,o.channel,o.total_cents,c.name customer_name,c.email customer_email FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$s->execute([$orderId,$tenantId]);$order=$s->fetch();
            if(!$order||!filter_var((string)($order['customer_email']??''),FILTER_VALIDATE_EMAIL))return;
            $body='Olá, '.(($order['customer_name']??'')?:'cliente').".\n\nO pagamento do pedido #{$orderId} foi confirmado.\nValor: R$ ".number_format(((int)$order['total_cents'])/100,2,',','.').".\n";
            $tickets=$pdo->prepare('SELECT t.code,t.qr_token,e.name event_name FROM tickets t JOIN events e ON e.id=t.event_id WHERE t.tenant_id=? AND t.order_id=? AND t.status IN ("paid","checked_in") ORDER BY t.id');$tickets->execute([$tenantId,$orderId]);$rows=$tickets->fetchAll();
            if($rows){$body.="\nSeus ingressos:\n";$base=rtrim((string)env('APP_URL',''),'/');foreach($rows as$t){$body.='- '.($t['event_name']??'Evento').' · '.$t['code'];if($base!==''&&!empty($t['qr_token']))$body.=' · '.$base.'/ingresso.php?t='.rawurlencode((string)$t['qr_token']);$body.="\n";}}
            $body.="\nObrigado por usar o EventMenu Premium.";
            (new MailQueueService())->queue((string)$order['customer_email'],$rows?'Pagamento confirmado e ingressos · EventMenu':'Pagamento confirmado · EventMenu',$body,'payment-confirmed|'.$tenantId.'|'.$orderId);
        }catch(\Throwable $e){error_log('[eventmenu post-payment] '.$e->getMessage());}
    }
}
