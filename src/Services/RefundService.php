<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class RefundService
{
    public function request(int $paymentId,?int $amountCents,string $idempotencyKey,bool $restoreStock=false):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');

        $context=Database::transaction(function(PDO $pdo)use($tenantId,$paymentId,$amountCents,$idempotencyKey,$restoreStock){
            $existing=$pdo->prepare('SELECT * FROM refunds WHERE tenant_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
            $existing->execute([$tenantId,$idempotencyKey]);
            if($row=$existing->fetch()){
                if($row['status']==='failed')throw new RuntimeException('Esta solicitação de reembolso já falhou. Gere uma nova solicitação após corrigir o motivo.');
                return $this->loadContext($pdo,$row);
            }

            $s=$pdo->prepare('SELECT p.*,o.channel,o.status order_status,o.payment_status order_payment_status,o.customer_id,o.coupon_id,o.promoter_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.tenant_id=? FOR UPDATE');
            $s->execute([$paymentId,$tenantId]);
            $payment=$s->fetch();
            if(!$payment)throw new RuntimeException('Pagamento não encontrado.');
            if(!in_array($payment['status'],['paid','partially_refunded'],true))throw new RuntimeException('Pagamento não está elegível para reembolso.');

            $sum=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE tenant_id=? AND payment_id=? AND status IN ("processing","succeeded")');
            $sum->execute([$tenantId,$paymentId]);
            $reserved=(int)$sum->fetchColumn();
            $remaining=(int)$payment['amount_cents']-$reserved;
            if($remaining<=0)throw new RuntimeException('Não existe saldo disponível para reembolso.');

            $amount=$amountCents===null?$remaining:$amountCents;
            if($amount<=0||$amount>$remaining)throw new RuntimeException('Valor de reembolso excede o saldo disponível.');

            if($payment['channel']==='event'){
                if($amount!==$remaining||$reserved!==0)throw new RuntimeException('Ingressos aceitam apenas reembolso total nesta versão.');
                $used=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND order_id=? AND status="checked_in"');
                $used->execute([$tenantId,$payment['order_id']]);
                if((int)$used->fetchColumn()>0)throw new RuntimeException('Não é permitido reembolsar ingresso que já realizou check-in.');
            }

            $paidCommission=$pdo->prepare('SELECT COUNT(*) FROM promoter_commissions WHERE tenant_id=? AND order_id=? AND status="paid"');
            $paidCommission->execute([$tenantId,$payment['order_id']]);
            if((int)$paidCommission->fetchColumn()>0)throw new RuntimeException('A comissão deste pedido já foi paga. Regularize a comissão antes do reembolso.');

            if($restoreStock&&($reserved+$amount)!==(int)$payment['amount_cents'])throw new RuntimeException('A devolução automática de estoque só pode ocorrer quando o pagamento ficar totalmente reembolsado.');

            $ins=$pdo->prepare('INSERT INTO refunds (tenant_id,payment_id,order_id,provider,idempotency_key,amount_cents,restore_stock,status,requested_by) VALUES (?,?,?,?,?,?,?,"processing",?)');
            $ins->execute([$tenantId,$paymentId,$payment['order_id'],$payment['provider'],$idempotencyKey,$amount,$restoreStock?1:0,Auth::id()]);
            $row=[
                'id'=>(int)$pdo->lastInsertId(),
                'tenant_id'=>$tenantId,
                'payment_id'=>$paymentId,
                'order_id'=>$payment['order_id'],
                'provider'=>$payment['provider'],
                'idempotency_key'=>$idempotencyKey,
                'amount_cents'=>$amount,
                'restore_stock'=>$restoreStock?1:0,
                'status'=>'processing',
                'provider_refund_id'=>null,
                'requested_by'=>Auth::id(),
            ];
            Auth::audit('refund.requested','refund',(string)$row['id'],['payment_id'=>$paymentId,'amount_cents'=>$amount,'restore_stock'=>$restoreStock]);
            return $this->loadContext($pdo,$row);
        });

        if($context['refund']['status']==='succeeded')return $context['refund'];
        return $this->advance($context,true);
    }

    public function reconcileProcessing(int $limit=50):array
    {
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT id FROM refunds WHERE status="processing" ORDER BY id LIMIT ?');
        $s->bindValue(1,max(1,min(200,$limit)),PDO::PARAM_INT);
        $s->execute();
        $checked=0;$completed=0;$errors=0;$pending=0;

        foreach($s->fetchAll() as $row){
            $checked++;
            try{
                $context=Database::transaction(fn(PDO $db)=>$this->loadContext($db,$this->lockRefund($db,(int)$row['id'])));
                $result=$this->advance($context,false);
                if(($result['status']??'')==='succeeded')$completed++;else$pending++;
            }catch(\Throwable $e){
                $errors++;
                $this->saveProcessingResult((int)$row['id'],'',[],substr($e->getMessage(),0,500));
            }
        }
        return ['checked'=>$checked,'completed'=>$completed,'pending'=>$pending,'errors'=>$errors];
    }

    private function advance(array $context,bool $throwOnTransportError):array
    {
        $refund=$context['refund'];
        try{
            $result=!empty($refund['provider_refund_id'])?$this->checkProvider($context):$this->sendToProvider($context);
            if(!empty($result['succeeded']))return $this->finalize((int)$refund['id'],(string)($result['provider_refund_id']??''),(array)($result['raw']??[]));
            if(!empty($result['failed'])){
                $this->markFailed((int)$refund['id'],(string)($result['message']??'Reembolso recusado pelo provedor.'),(array)($result['raw']??[]));
                if($throwOnTransportError)throw new RuntimeException((string)($result['message']??'Reembolso recusado pelo provedor.'));
                return $this->getRefund((int)$refund['id']);
            }
            $this->saveProcessingResult((int)$refund['id'],(string)($result['provider_refund_id']??''),(array)($result['raw']??[]),(string)($result['message']??'Reembolso aguardando confirmação do provedor.'));
            return $this->getRefund((int)$refund['id']);
        }catch(\Throwable $e){
            $this->saveProcessingResult((int)$refund['id'],'',[],substr($e->getMessage(),0,500));
            if($throwOnTransportError)throw $e;
            return $this->getRefund((int)$refund['id']);
        }
    }

    private function loadContext(PDO $pdo,array $refund):array
    {
        $s=$pdo->prepare('SELECT p.*,o.channel,o.status order_status,o.payment_status order_payment_status,o.customer_id,o.coupon_id,o.promoter_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.tenant_id=?');
        $s->execute([$refund['payment_id'],$refund['tenant_id']]);
        $payment=$s->fetch();
        if(!$payment)throw new RuntimeException('Pagamento do reembolso não encontrado.');

        $gateway=null;$config=[];
        if($payment['provider']!=='manual'){
            $g=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? LIMIT 1');
            $g->execute([$refund['tenant_id'],$payment['provider']]);
            $gateway=$g->fetch();
            if(!$gateway)throw new RuntimeException('Configuração do gateway não encontrada para reembolso.');
            $config=Crypto::decryptJson($gateway['config_encrypted']);
        }

        $done=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE tenant_id=? AND payment_id=? AND status="succeeded"');
        $done->execute([$refund['tenant_id'],$refund['payment_id']]);
        return ['refund'=>$refund,'payment'=>$payment,'gateway'=>$gateway,'config'=>$config,'already_refunded_cents'=>(int)$done->fetchColumn()];
    }

    private function sendToProvider(array $c):array
    {
        $r=$c['refund'];$p=$c['payment'];$config=$c['config'];$amount=(int)$r['amount_cents'];$provider=$p['provider'];
        $providerKey=substr(hash('sha256',(string)$r['idempotency_key']),0,64);

        if($provider==='manual')return ['succeeded'=>true,'provider_refund_id'=>'MANUAL-REFUND-'.strtoupper(substr(hash('sha256',(string)$r['idempotency_key']),0,20)),'raw'=>['manual'=>true,'amount_cents'=>$amount]];

        if($provider==='stripe'){
            if(!class_exists('Stripe\\StripeClient'))throw new RuntimeException('SDK Stripe não instalado.');
            $secret=(string)($config['secret_key']??'');
            if($secret==='')throw new RuntimeException('Chave Stripe ausente.');
            $client=new \Stripe\StripeClient($secret);
            $refund=$client->refunds->create([
                'payment_intent'=>(string)$p['provider_payment_id'],
                'amount'=>$amount,
                'metadata'=>['eventmenu_refund_id'=>(string)$r['id'],'order_id'=>(string)$p['order_id']],
            ],['idempotency_key'=>$providerKey]);
            $status=(string)$refund->status;
            return ['succeeded'=>$status==='succeeded','failed'=>in_array($status,['failed','canceled'],true),'provider_refund_id'=>(string)$refund->id,'message'=>'Stripe: '.$status,'raw'=>$refund->toArray()];
        }

        if($provider==='mercadopago'){
            $token=(string)($config['access_token']??'');
            if($token==='')throw new RuntimeException('Access token Mercado Pago ausente.');
            $body=[];
            if($amount<(int)$p['amount_cents']||$c['already_refunded_cents']>0)$body=['amount'=>$amount/100];
            $data=$this->httpJson('POST','https://api.mercadopago.com/v1/payments/'.rawurlencode((string)$p['provider_payment_id']).'/refunds',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$providerKey],$body);
            $status=(string)($data['status']??'');
            return ['succeeded'=>$status==='approved','failed'=>in_array($status,['rejected','cancelled'],true),'provider_refund_id'=>(string)($data['id']??''),'message'=>'Mercado Pago: '.($status?:'desconhecido'),'raw'=>$data];
        }

        if($provider==='pagbank'){
            $token=(string)($config['token']??'');
            if($token==='')throw new RuntimeException('Token PagBank ausente.');
            $base=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');
            $chargeId=(string)$p['provider_payment_id'];
            $before=$this->httpJson('GET',$base.'/charges/'.rawurlencode($chargeId),['Authorization: Bearer '.$token]);
            $providerRefunded=(int)($before['summary']['refunded']??0);
            $target=$c['already_refunded_cents']+$amount;
            if($providerRefunded>=$target)return ['succeeded'=>true,'provider_refund_id'=>$chargeId.'#'.$target,'raw'=>$before];
            if($providerRefunded!==$c['already_refunded_cents'])throw new RuntimeException('Saldo reembolsado no PagBank diverge do EventMenu; faça conciliação antes de continuar.');
            $data=$this->httpJson('POST',$base.'/charges/'.rawurlencode($chargeId).'/cancel',['Authorization: Bearer '.$token,'x-idempotency-key: '.$providerKey],['amount'=>['value'=>$amount]]);
            $after=$this->httpJson('GET',$base.'/charges/'.rawurlencode($chargeId),['Authorization: Bearer '.$token]);
            $confirmed=(int)($after['summary']['refunded']??0)>=$target;
            return ['succeeded'=>$confirmed,'provider_refund_id'=>$chargeId.'#'.$target,'message'=>'PagBank: '.($after['status']??''),'raw'=>['cancel'=>$data,'charge'=>$after]];
        }

        throw new RuntimeException('Provedor não suportado para reembolso.');
    }

    private function checkProvider(array $c):array
    {
        $r=$c['refund'];$p=$c['payment'];$config=$c['config'];$provider=$p['provider'];$refundId=(string)($r['provider_refund_id']??'');

        if($provider==='manual')return ['succeeded'=>true,'provider_refund_id'=>$refundId?:'MANUAL-REFUND-'.$r['id'],'raw'=>['manual'=>true]];

        if($provider==='stripe'&&$refundId!==''){
            if(!class_exists('Stripe\\StripeClient'))throw new RuntimeException('SDK Stripe não instalado.');
            $client=new \Stripe\StripeClient((string)($config['secret_key']??''));
            $refund=$client->refunds->retrieve($refundId,[]);
            $status=(string)$refund->status;
            return ['succeeded'=>$status==='succeeded','failed'=>in_array($status,['failed','canceled'],true),'provider_refund_id'=>$refundId,'message'=>'Stripe: '.$status,'raw'=>$refund->toArray()];
        }

        if($provider==='mercadopago'&&$refundId!==''){
            $token=(string)($config['access_token']??'');
            $data=$this->httpJson('GET','https://api.mercadopago.com/v1/payments/'.rawurlencode((string)$p['provider_payment_id']).'/refunds/'.rawurlencode($refundId),['Authorization: Bearer '.$token]);
            $status=(string)($data['status']??'');
            return ['succeeded'=>$status==='approved','failed'=>in_array($status,['rejected','cancelled'],true),'provider_refund_id'=>$refundId,'message'=>'Mercado Pago: '.$status,'raw'=>$data];
        }

        if($provider==='pagbank'){
            $token=(string)($config['token']??'');
            $base=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');
            $charge=$this->httpJson('GET',$base.'/charges/'.rawurlencode((string)$p['provider_payment_id']),['Authorization: Bearer '.$token]);
            $target=$c['already_refunded_cents']+(int)$r['amount_cents'];
            $ok=(int)($charge['summary']['refunded']??0)>=$target;
            return ['succeeded'=>$ok,'failed'=>false,'provider_refund_id'=>(string)$p['provider_payment_id'].'#'.$target,'message'=>'PagBank: '.($charge['status']??''),'raw'=>$charge];
        }

        return ['succeeded'=>false,'failed'=>false,'provider_refund_id'=>$refundId,'raw'=>[]];
    }

    private function finalize(int $refundId,string $providerRefundId,array $raw):array
    {
        return Database::transaction(function(PDO $pdo)use($refundId,$providerRefundId,$raw){
            $refund=$this->lockRefund($pdo,$refundId);
            if($refund['status']==='succeeded')return $refund;
            if($refund['status']!=='processing')throw new RuntimeException('Reembolso não está mais em processamento.');

            $s=$pdo->prepare('SELECT p.*,o.channel,o.status order_status,o.customer_id,o.coupon_id,o.promoter_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.tenant_id=? FOR UPDATE');
            $s->execute([$refund['payment_id'],$refund['tenant_id']]);
            $payment=$s->fetch();
            if(!$payment)throw new RuntimeException('Pagamento não encontrado durante finalização do reembolso.');

            $pdo->prepare('UPDATE refunds SET provider_refund_id=?,status="succeeded",error_message=NULL,raw_response=? WHERE id=?')->execute([$providerRefundId?:null,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$refundId]);
            $sum=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE tenant_id=? AND payment_id=? AND status="succeeded"');
            $sum->execute([$refund['tenant_id'],$refund['payment_id']]);
            $total=(int)$sum->fetchColumn();
            $full=$total>=(int)$payment['amount_cents'];
            $paymentStatus=$full?'refunded':'partially_refunded';
            $pdo->prepare('UPDATE payments SET status=? WHERE id=?')->execute([$paymentStatus,$payment['id']]);
            $pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=?')->execute([$paymentStatus,$payment['order_id'],$refund['tenant_id']]);

            $this->reversePoints($pdo,$refund,$payment,$total);

            if(!empty($payment['promoter_id'])){
                $pc=$pdo->prepare('SELECT pc.id,pc.status,p.commission_percent FROM promoter_commissions pc JOIN promoters p ON p.id=pc.promoter_id WHERE pc.tenant_id=? AND pc.order_id=? FOR UPDATE');
                $pc->execute([$refund['tenant_id'],$payment['order_id']]);
                if($commission=$pc->fetch()){
                    if($commission['status']!=='paid'){
                        if($full)$pdo->prepare('UPDATE promoter_commissions SET status="cancelled" WHERE id=?')->execute([$commission['id']]);
                        else{
                            $remaining=max(0,(int)$payment['amount_cents']-$total);
                            $newAmount=(int)round($remaining*((float)$commission['commission_percent']/100));
                            $pdo->prepare('UPDATE promoter_commissions SET amount_cents=? WHERE id=?')->execute([$newAmount,$commission['id']]);
                        }
                    }
                }
            }

            if($full){
                if((int)$refund['restore_stock']){
                    (new StockService())->reverseForOrder($pdo,(int)$refund['tenant_id'],(int)$payment['order_id']);
                    if(!in_array($payment['order_status'],['completed','cancelled'],true))$pdo->prepare('UPDATE orders SET status="cancelled" WHERE id=?')->execute([$payment['order_id']]);
                }

                if($payment['channel']==='event'){
                    $used=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND order_id=? AND status="checked_in"');
                    $used->execute([$refund['tenant_id'],$payment['order_id']]);
                    if((int)$used->fetchColumn()>0)throw new RuntimeException('Ingresso utilizado não pode ser invalidado por reembolso.');
                    $groups=$pdo->prepare('SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND order_id=? AND status="paid" GROUP BY batch_id FOR UPDATE');
                    $groups->execute([$refund['tenant_id'],$payment['order_id']]);
                    foreach($groups->fetchAll() as $g)$pdo->prepare('UPDATE ticket_batches SET quantity_sold=GREATEST(0,quantity_sold-?) WHERE id=?')->execute([(int)$g['qty'],$g['batch_id']]);
                    $pdo->prepare('UPDATE tickets SET status="refunded" WHERE tenant_id=? AND order_id=? AND status="paid"')->execute([$refund['tenant_id'],$payment['order_id']]);
                    if(!in_array($payment['order_status'],['completed','cancelled'],true))$pdo->prepare('UPDATE orders SET status="cancelled" WHERE id=?')->execute([$payment['order_id']]);
                }

                if(!empty($payment['coupon_id'])){
                    $red=$pdo->prepare('SELECT id FROM coupon_redemptions WHERE tenant_id=? AND order_id=? LIMIT 1');
                    $red->execute([$refund['tenant_id'],$payment['order_id']]);
                    if($red->fetchColumn())$pdo->prepare('UPDATE coupons SET uses_count=GREATEST(0,uses_count-1) WHERE id=? AND tenant_id=?')->execute([$payment['coupon_id'],$refund['tenant_id']]);
                }
            }

            $this->writeAudit($pdo,$refund,'refund.succeeded',['payment_id'=>$payment['id'],'amount_cents'=>$refund['amount_cents'],'full'=>$full]);
            $refund['status']='succeeded';
            $refund['provider_refund_id']=$providerRefundId;
            return $refund;
        });
    }

    private function reversePoints(PDO $pdo,array $refund,array $payment,int $totalRefunded):void
    {
        if(empty($payment['customer_id']))return;
        $earned=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM customer_points_movements WHERE tenant_id=? AND order_id=? AND type="earn"');
        $earned->execute([$refund['tenant_id'],$payment['order_id']]);
        $earnedPoints=max(0,(int)$earned->fetchColumn());
        if($earnedPoints===0)return;

        $rev=$pdo->prepare('SELECT COALESCE(-SUM(points),0) FROM customer_points_movements WHERE tenant_id=? AND order_id=? AND type="reversal"');
        $rev->execute([$refund['tenant_id'],$payment['order_id']]);
        $already=max(0,(int)$rev->fetchColumn());
        $target=min($earnedPoints,intdiv($totalRefunded,100));
        $delta=max(0,$target-$already);
        if($delta===0)return;

        $key='refund:'.$refund['id'].':points';
        $ins=$pdo->prepare('INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?, ?,"reversal",?)');
        $ins->execute([$refund['tenant_id'],$payment['customer_id'],$payment['order_id'],-$delta,$key]);
        if($ins->rowCount()===1)$pdo->prepare('UPDATE customers SET points=GREATEST(0,points-?) WHERE id=? AND tenant_id=?')->execute([$delta,$payment['customer_id'],$refund['tenant_id']]);
    }

    private function writeAudit(PDO $pdo,array $refund,string $action,array $metadata=[]):void
    {
        $stmt=$pdo->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$refund['tenant_id'],$refund['requested_by']??null,$action,'refund',(string)$refund['id'],null,null,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
    }

    private function saveProcessingResult(int $id,string $providerRefundId,array $raw,string $message):void
    {
        Database::connection()->prepare('UPDATE refunds SET provider_refund_id=COALESCE(NULLIF(?,""),provider_refund_id),raw_response=?,error_message=? WHERE id=? AND status="processing"')->execute([$providerRefundId,json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),substr($message,0,500),$id]);
    }

    private function markFailed(int $id,string $message,array $raw):void
    {
        Database::connection()->prepare('UPDATE refunds SET status="failed",error_message=?,raw_response=? WHERE id=? AND status="processing"')->execute([substr($message,0,500),json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
    }

    private function lockRefund(PDO $pdo,int $id):array
    {
        $s=$pdo->prepare('SELECT * FROM refunds WHERE id=? FOR UPDATE');
        $s->execute([$id]);
        $r=$s->fetch();
        if(!$r)throw new RuntimeException('Reembolso não encontrado.');
        return $r;
    }

    private function getRefund(int $id):array
    {
        $s=Database::connection()->prepare('SELECT * FROM refunds WHERE id=?');
        $s->execute([$id]);
        return $s->fetch()?:[];
    }

    private function httpJson(string $method,string $url,array $headers=[],?array $body=null):array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Falha ao iniciar requisição de reembolso.');
        $headers[]='Accept: application/json';
        if($body!==null)$headers[]='Content-Type: application/json';
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30];
        if($body!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        curl_setopt_array($ch,$opts);
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if($response===false||$status<200||$status>=300)throw new RuntimeException('Falha no reembolso junto ao provedor (HTTP '.$status.').'.($error?' '.$error:''));
        $data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new RuntimeException('Resposta inválida do provedor no reembolso.');
        return $data;
    }
}
