<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;
use PDO;
use RuntimeException;

final class TicketService
{
    public function reservePublic(int $eventId,int $batchId,int $quantity,array $buyer,?string $couponCode=null,?string $promoterCode=null): array
    {
        $quantity=max(1,min(10,$quantity));
        $rate=new ApiRateLimitService();
        $rate->assertAllowed('event.public.reserve.'.$eventId,$rate->requestSubject(),6,60,'Muitas reservas em pouco tempo. Aguarde um minuto e tente novamente.');
        return Database::transaction(function(PDO $pdo)use($eventId,$batchId,$quantity,$buyer,$couponCode,$promoterCode): array {
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT e.*,t.status tenant_status FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.id=? AND e.status="published" FOR UPDATE'));
            $stmt->execute([$eventId]);
            $event=$stmt->fetch();
            if(!$event||$event['tenant_status']!=='active'||!TenantFeatures::events((int)$event['tenant_id']))throw new RuntimeException('Evento indisponível.');
            if(isset($event['sales_enabled'])&&!(int)$event['sales_enabled'])throw new RuntimeException('Venda online temporariamente desativada para este evento.');

            $now=new \DateTimeImmutable('now');
            if(!empty($event['ends_at'])&&$now>new \DateTimeImmutable((string)$event['ends_at']))throw new RuntimeException('Este evento já foi encerrado.');

            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT b.*,tt.name ticket_type_name,tt.capacity_total ticket_type_capacity,tt.active ticket_type_active FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.id=? AND b.event_id=? AND b.active=1 FOR UPDATE'));
            $stmt->execute([$batchId,$eventId]);
            $batch=$stmt->fetch();
            if(!$batch)throw new RuntimeException('Lote indisponível.');
            if(!empty($batch['ticket_type_id'])&&!(int)$batch['ticket_type_active'])throw new RuntimeException('Tipo de ingresso indisponível.');
            if($batch['sales_start']&&$now<new \DateTimeImmutable($batch['sales_start']))throw new RuntimeException('Venda deste lote ainda não começou.');
            if($batch['sales_end']&&$now>new \DateTimeImmutable($batch['sales_end']))throw new RuntimeException('Venda deste lote foi encerrada.');
            $available=(int)$batch['quantity_total']-(int)$batch['quantity_sold']-(int)$batch['quantity_reserved'];
            if($available<$quantity)throw new RuntimeException('Quantidade de ingressos indisponível neste lote.');

            if(!empty($event['capacity_total'])){
                $cap=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');
                $cap->execute([$event['tenant_id'],$eventId]);
                $used=(int)$cap->fetchColumn();
                $guests=$pdo->prepare('SELECT COALESCE(SUM(1+COALESCE(plus_ones,0)),0) FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');
                $guests->execute([$event['tenant_id'],$eventId]);
                $used+=(int)$guests->fetchColumn();
                if($used+$quantity>(int)$event['capacity_total'])throw new RuntimeException('Capacidade total do evento atingida.');
            }
            if(!empty($batch['ticket_type_id'])&&!empty($batch['ticket_type_capacity'])){
                $cap=$pdo->prepare('SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id=? AND t.status IN ("reserved","paid","checked_in")');
                $cap->execute([$event['tenant_id'],$eventId,$batch['ticket_type_id']]);
                $used=(int)$cap->fetchColumn();
                if($used+$quantity>(int)$batch['ticket_type_capacity'])throw new RuntimeException('Capacidade do tipo de ingresso atingida.');
            }

            $name=trim((string)($buyer['name']??''));
            $email=mb_strtolower(trim((string)($buyer['email']??'')));
            $phone=trim((string)($buyer['phone']??''));
            if($name===''||(!filter_var($email,FILTER_VALIDATE_EMAIL)&&$phone===''))throw new RuntimeException('Informe nome e e-mail ou telefone válido.');
            if($phone!==''&&strlen(preg_replace('/\D+/','',$phone)??'')<10)throw new RuntimeException('Informe um telefone válido.');

            $tenantId=(int)$event['tenant_id'];
            $customerId=null;
            if($phone!==''){
                $identity=new CustomerIdentityService();
                $customer=$identity->findByPhone($pdo,$tenantId,$phone);
                if(!$customer&&$email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)){
                    $s=$pdo->prepare('SELECT id,name,phone,default_address FROM customers WHERE tenant_id=? AND email=? ORDER BY id ASC LIMIT 1');
                    $s->execute([$tenantId,$email]);
                    $emailCustomer=$s->fetch();
                    if($emailCustomer){
                        try{$customer=$identity->save($pdo,$tenantId,['name'=>$name,'phone'=>$phone,'email'=>$email,'address'=>(string)($emailCustomer['default_address']??'')],(int)$emailCustomer['id']);}
                        catch(RuntimeException $e){$customer=$identity->findByPhone($pdo,$tenantId,$phone);if(!$customer)throw $e;}
                    }
                }
                if(!$customer)$customer=$identity->findOrCreate($pdo,$tenantId,$name,$phone);
                $customerId=(int)$customer['id'];
                if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL))$pdo->prepare('UPDATE customers SET email=? WHERE id=? AND tenant_id=?')->execute([$email,$customerId,$tenantId]);
            }elseif($email!==''){
                $s=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND email=? ORDER BY id ASC LIMIT 1');
                $s->execute([$tenantId,$email]);
                $customerId=$s->fetchColumn()?:null;
                if(!$customerId){$s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,NULL,?)');$s->execute([$tenantId,$name,$email]);$customerId=(int)$pdo->lastInsertId();}
                else $pdo->prepare('UPDATE customers SET name=? WHERE id=? AND tenant_id=?')->execute([$name,$customerId,$tenantId]);
            }

            $subtotal=(int)$batch['price_cents']*$quantity;
            $discount=0;
            $couponId=null;
            if($couponCode){
                $code=strtoupper(trim($couponCode));
                $sql=Database::portableSql($pdo,'SELECT c.* FROM coupons c WHERE c.tenant_id=? AND c.code=? AND c.active=1 AND EXISTS (SELECT 1 FROM event_coupons ec WHERE ec.tenant_id=c.tenant_id AND ec.event_id=? AND ec.coupon_id=c.id AND ec.active=1) FOR UPDATE');
                $c=$pdo->prepare($sql);$c->execute([$event['tenant_id'],$code,$eventId]);$coupon=$c->fetch();
                if(!$coupon)throw new RuntimeException('Cupom inválido para este evento.');
                if($coupon['starts_at']&&$now<new \DateTimeImmutable($coupon['starts_at']))throw new RuntimeException('Cupom ainda não está válido.');
                if($coupon['ends_at']&&$now>new \DateTimeImmutable($coupon['ends_at']))throw new RuntimeException('Cupom expirado.');
                if($coupon['max_uses']!==null&&((int)$coupon['uses_count']+(int)$coupon['reserved_count'])>=(int)$coupon['max_uses'])throw new RuntimeException('Limite do cupom atingido.');
                if($subtotal<(int)$coupon['min_order_cents'])throw new RuntimeException('Valor mínimo do cupom não atingido.');
                $couponId=(int)$coupon['id'];
                $discount=(new CouponPricingService())->discount($coupon,$subtotal);
            }

            $promoterId=null;
            if($promoterCode){
                $sql='SELECT p.id FROM promoters p WHERE p.tenant_id=? AND p.code=? AND p.active=1 AND EXISTS (SELECT 1 FROM event_promoters ep WHERE ep.tenant_id=p.tenant_id AND ep.event_id=? AND ep.promoter_id=p.id AND ep.active=1)';
                $p=$pdo->prepare($sql);$p->execute([$event['tenant_id'],strtoupper(trim($promoterCode)),$eventId]);$promoterId=$p->fetchColumn()?:null;
                if(!$promoterId)throw new RuntimeException('Código de promotor inválido para este evento.');
            }

            $total=max(0,$subtotal-$discount);
            $publicToken=bin2hex(random_bytes(20));
            $stmt=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,coupon_id,promoter_id,channel,status,payment_status,subtotal_cents,discount_cents,total_cents,notes) VALUES (?,?,?,?,?,"event","pending","unpaid",?,?,?,?)');
            $stmt->execute([$publicToken,$event['tenant_id'],$customerId,$couponId,$promoterId,$subtotal,$discount,$total,'Evento #'.$eventId]);
            $orderId=(int)$pdo->lastInsertId();
            $typeLabel=trim((string)($batch['ticket_type_name']??''));
            $itemName='Ingresso '.$event['name'].' — '.($typeLabel!==''?$typeLabel.' · ':'').$batch['name'];
            $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,NULL,?,?,?,?)')->execute([$orderId,$itemName,$batch['price_cents'],$quantity,$subtotal]);

            $expires=(new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
            if($couponId){$pdo->prepare('INSERT INTO coupon_reservations (tenant_id,coupon_id,order_id,discount_cents,status,expires_at) VALUES (?,?,?,?,"reserved",?)')->execute([$event['tenant_id'],$couponId,$orderId,$discount,$expires]);$pdo->prepare('UPDATE coupons SET reserved_count=reserved_count+1 WHERE id=?')->execute([$couponId]);}

            $tickets=[];
            for($i=0;$i<$quantity;$i++){
                $code=sprintf('%s-%s-%s',str_pad((string)$eventId,4,'0',STR_PAD_LEFT),strtoupper(substr(bin2hex(random_bytes(5)),0,10)),strtoupper(substr(bin2hex(random_bytes(2)),0,4)));
                $qr=bin2hex(random_bytes(32));
                $s=$pdo->prepare('INSERT INTO tickets (tenant_id,event_id,batch_id,order_id,customer_id,code,status,reserved_until,qr_token) VALUES (?,?,?,?,?,?,"reserved",?,?)');
                $s->execute([$event['tenant_id'],$eventId,$batchId,$orderId,$customerId,$code,$expires,$qr]);
                $tickets[]=['id'=>(int)$pdo->lastInsertId(),'code'=>$code,'qr_token'=>$qr];
            }
            $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=quantity_reserved+? WHERE id=?')->execute([$quantity,$batchId]);
            $this->eventAudit($pdo,(int)$event['tenant_id'],$eventId,null,'ticket.reserved','order',(string)$orderId,['quantity'=>$quantity,'batch_id'=>$batchId,'ticket_type_id'=>$batch['ticket_type_id']?:null,'subtotal_cents'=>$subtotal,'discount_cents'=>$discount]);

            $paid=false;
            if($total===0){
                $pdo->prepare('UPDATE orders SET status="confirmed",payment_status="paid" WHERE id=? AND tenant_id=?')->execute([$orderId,$event['tenant_id']]);
                $pdo->prepare('UPDATE tickets SET status="paid",reserved_until=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$event['tenant_id'],$orderId]);
                $pdo->prepare(Database::portableSql($pdo,'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?),quantity_sold=quantity_sold+? WHERE id=?'))->execute([$quantity,$quantity,$batchId]);
                if($couponId){$pdo->prepare('UPDATE coupon_reservations SET status="redeemed" WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$event['tenant_id'],$orderId]);$pdo->prepare(Database::portableSql($pdo,'UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1),uses_count=uses_count+1 WHERE id=? AND tenant_id=?'))->execute([$couponId,$event['tenant_id']]);$sql=Database::portableSql($pdo,'INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)');$pdo->prepare($sql)->execute([$event['tenant_id'],$couponId,$orderId,$customerId?:null,$discount,'free-ticket:order:'.$orderId.':coupon']);}
                $expires=null;$paid=true;$this->eventAudit($pdo,(int)$event['tenant_id'],$eventId,null,'ticket.free_issued','order',(string)$orderId,['quantity'=>$quantity]);
            }
            return ['order_id'=>$orderId,'public_token'=>$publicToken,'total_cents'=>$total,'expires_at'=>$expires,'paid'=>$paid,'tickets'=>$tickets,'ticket_type'=>$typeLabel?:null];
        });
    }

    public function checkIn(string $token,?int $expectedEventId=null): array
    {
        Auth::requirePermission('tickets.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!$expectedEventId||$expectedEventId<1)throw new RuntimeException('Selecione o evento antes de validar a entrada.');
        $token=$this->normalizeScannedToken($token);
        if($token==='')throw new RuntimeException('Código do ingresso é obrigatório.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$token,$expectedEventId): array {
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT t.*,e.status event_status,e.starts_at event_starts_at,e.ends_at event_ends_at,tn.status tenant_status FROM tickets t JOIN events e ON e.id=t.event_id JOIN tenants tn ON tn.id=t.tenant_id WHERE t.tenant_id=? AND (t.qr_token=? OR t.code=?) LIMIT 1 FOR UPDATE'));
            $s->execute([$tenantId,$token,$token]);
            $ticket=$s->fetch();
            if(!$ticket)throw new RuntimeException('Ingresso não encontrado.');
            if((int)$ticket['event_id']!==$expectedEventId){$this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'blocked',['reason'=>'wrong_event','expected_event_id'=>$expectedEventId,'ticket_event_id'=>(int)$ticket['event_id']]);return ['ok'=>false,'result'=>'wrong_event','ticket'=>$ticket];}
            if($ticket['tenant_status']!=='active'||!TenantFeatures::events($tenantId)){$this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'blocked',['reason'=>'tenant_unavailable']);return ['ok'=>false,'result'=>'blocked','ticket'=>$ticket];}
            try{(new EventCheckinPolicyService())->assertOpen($pdo,$tenantId,['id'=>(int)$ticket['event_id'],'status'=>(string)$ticket['event_status'],'starts_at'=>(string)$ticket['event_starts_at'],'ends_at'=>$ticket['event_ends_at']]);}
            catch(RuntimeException $e){$this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'blocked',['reason'=>'checkin_window','message'=>$e->getMessage()]);return ['ok'=>false,'result'=>'window_closed','message'=>$e->getMessage(),'ticket'=>$ticket];}
            if($ticket['status']==='checked_in'){$this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'duplicate');return ['ok'=>false,'result'=>'duplicate','ticket'=>$ticket];}
            if($ticket['status']!=='paid'){$this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'blocked',['status'=>$ticket['status']]);return ['ok'=>false,'result'=>'blocked','ticket'=>$ticket];}
            $pdo->prepare('UPDATE tickets SET status="checked_in",checked_in_at=CURRENT_TIMESTAMP,checked_in_by=? WHERE id=?')->execute([Auth::id(),$ticket['id']]);
            $this->logCheckin($pdo,$tenantId,(int)$ticket['id'],'accepted',['event_id'=>$expectedEventId]);
            $this->eventAudit($pdo,$tenantId,(int)$ticket['event_id'],Auth::id(),'ticket.checkin','ticket',(string)$ticket['id'],[]);
            $ticket['status']='checked_in';
            return ['ok'=>true,'result'=>'accepted','ticket'=>$ticket];
        });
    }

    public function releaseExpired(): int
    {
        return Database::transaction(function(PDO $pdo): int {
            $s=$pdo->query(Database::portableSql($pdo,'SELECT batch_id,COUNT(*) qty FROM tickets WHERE status="reserved" AND reserved_until IS NOT NULL AND reserved_until<CURRENT_TIMESTAMP GROUP BY batch_id FOR UPDATE'));
            $groups=$s->fetchAll();
            $total=0;
            foreach($groups as$g){$pdo->prepare(Database::portableSql($pdo,'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?) WHERE id=?'))->execute([(int)$g['qty'],$g['batch_id']]);$total+=(int)$g['qty'];}
            $couponRows=$pdo->query(Database::portableSql($pdo,'SELECT id,coupon_id FROM coupon_reservations WHERE status="reserved" AND expires_at<CURRENT_TIMESTAMP FOR UPDATE'))->fetchAll();
            foreach($couponRows as$row){$pdo->prepare('UPDATE coupon_reservations SET status="released" WHERE id=?')->execute([$row['id']]);$pdo->prepare(Database::portableSql($pdo,'UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1) WHERE id=?'))->execute([$row['coupon_id']]);}
            $pdo->exec('UPDATE tickets SET status="cancelled" WHERE status="reserved" AND reserved_until IS NOT NULL AND reserved_until<CURRENT_TIMESTAMP');
            $pdo->exec('UPDATE orders SET status="cancelled",payment_status=CASE WHEN payment_status="unpaid" THEN "failed" ELSE payment_status END WHERE channel="event" AND payment_status<>"paid" AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.order_id=orders.id AND t.status="reserved") AND EXISTS (SELECT 1 FROM tickets t2 WHERE t2.order_id=orders.id)');
            return $total;
        });
    }

    private function normalizeScannedToken(string $value): string
    {
        $value=trim($value);
        if(filter_var($value,FILTER_VALIDATE_URL)){$parts=parse_url($value);if(isset($parts['query'])){parse_str($parts['query'],$query);if(!empty($query['t']))return trim((string)$query['t']);}}
        return $value;
    }

    private function logCheckin(PDO $pdo,int $tenantId,int $ticketId,string $result,array $metadata=[]): void
    {
        $pdo->prepare('INSERT INTO ticket_checkin_logs (tenant_id,ticket_id,user_id,result,metadata) VALUES (?,?,?,?,?)')->execute([$tenantId,$ticketId,Auth::id(),$result,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
    }

    private function eventAudit(PDO $pdo,int $tenantId,int $eventId,?int $userId,string $action,?string $entityType,?string $entityId,array $metadata): void
    {
        try{$pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,$userId,$action,$entityType,$entityId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}
    }
}
