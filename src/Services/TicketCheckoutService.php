<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class TicketCheckoutService
{
    public function reserveMultiplePublic(int $eventId,array $quantities,array $buyer,?string $couponCode=null,?string $promoterCode=null):array
    {
        $clean=[];$totalQty=0;foreach($quantities as$batchId=>$qty){$batchId=(int)$batchId;$qty=max(0,min(10,(int)$qty));if($batchId>0&&$qty>0){$clean[$batchId]=$qty;$totalQty+=$qty;}}
        if(!$clean)throw new RuntimeException('Escolha pelo menos um ingresso.');if($totalQty>20)throw new RuntimeException('O limite por compra é de 20 ingressos.');
        return Database::transaction(function(PDO $pdo)use($eventId,$clean,$totalQty,$buyer,$couponCode,$promoterCode){
            $s=$pdo->prepare('SELECT e.*,t.status tenant_status FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.id=? AND e.status="published" FOR UPDATE');$s->execute([$eventId]);$event=$s->fetch();if(!$event||$event['tenant_status']!=='active')throw new RuntimeException('Evento indisponível.');TenantModuleService::requireModule((int)$event['tenant_id'],'events');
            $now=new \DateTimeImmutable('now');$batches=[];$subtotal=0;
            foreach($clean as$batchId=>$qty){$b=$pdo->prepare('SELECT * FROM ticket_batches WHERE id=? AND event_id=? AND active=1 FOR UPDATE');$b->execute([$batchId,$eventId]);$batch=$b->fetch();if(!$batch)throw new RuntimeException('Um tipo de ingresso não está mais disponível.');if($batch['sales_start']&&$now<new \DateTimeImmutable($batch['sales_start']))throw new RuntimeException($batch['name'].': venda ainda não iniciada.');if($batch['sales_end']&&$now>new \DateTimeImmutable($batch['sales_end']))throw new RuntimeException($batch['name'].': venda encerrada.');$available=(int)$batch['quantity_total']-(int)$batch['quantity_sold']-(int)$batch['quantity_reserved'];if($available<$qty)throw new RuntimeException($batch['name'].': quantidade indisponível.');$batches[]=['row'=>$batch,'qty'=>$qty];$subtotal+=(int)$batch['price_cents']*$qty;}
            $name=trim((string)($buyer['name']??''));$email=mb_strtolower(trim((string)($buyer['email']??'')));$phone=trim((string)($buyer['phone']??''));if($name===''||(!filter_var($email,FILTER_VALIDATE_EMAIL)&&$phone===''))throw new RuntimeException('Informe nome e e-mail ou telefone válido.');$customerId=$this->customer($pdo,(int)$event['tenant_id'],$name,$email,$phone);
            [$couponId,$discount]=$this->coupon($pdo,(int)$event['tenant_id'],$subtotal,$couponCode,$now);$promoterId=null;if($promoterCode){$p=$pdo->prepare('SELECT id FROM promoters WHERE tenant_id=? AND code=? AND active=1');$p->execute([$event['tenant_id'],strtoupper(trim($promoterCode))]);$promoterId=$p->fetchColumn()?:null;}
            $total=max(0,$subtotal-$discount);$publicToken=bin2hex(random_bytes(20));$expires=(new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');$order=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,coupon_id,promoter_id,channel,status,payment_status,expires_at,subtotal_cents,discount_cents,total_cents,notes) VALUES (?,?,?,?,?,? ,"event","pending","unpaid",?,?,?,?,?)');$order->execute([$publicToken,$event['tenant_id'],$event['unit_id']?:null,$customerId,$couponId,$promoterId,$expires,$subtotal,$discount,$total,'Evento #'.$eventId]);$orderId=(int)$pdo->lastInsertId();
            $item=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,NULL,?,?,?,?)');$ticket=$pdo->prepare('INSERT INTO tickets (tenant_id,event_id,batch_id,order_id,customer_id,code,status,reserved_until,qr_token) VALUES (?,?,?,?,?,?,"reserved",?,?)');$tickets=[];
            foreach($batches as$entry){$batch=$entry['row'];$qty=$entry['qty'];$line=(int)$batch['price_cents']*$qty;$item->execute([$orderId,'Ingresso '.$event['name'].' — '.$batch['name'],$batch['price_cents'],$qty,$line]);for($i=0;$i<$qty;$i++){$code=$this->newCode($eventId);$qr=bin2hex(random_bytes(32));$ticket->execute([$event['tenant_id'],$eventId,$batch['id'],$orderId,$customerId,$code,$expires,$qr]);$tickets[]=['id'=>(int)$pdo->lastInsertId(),'code'=>$code,'qr_token'=>$qr,'batch'=>$batch['name']];}$pdo->prepare('UPDATE ticket_batches SET quantity_reserved=quantity_reserved+? WHERE id=?')->execute([$qty,$batch['id']]);}
            if($couponId){$pdo->prepare('INSERT INTO coupon_reservations (tenant_id,coupon_id,order_id,discount_cents,status,expires_at) VALUES (?,?,?,?,"reserved",?)')->execute([$event['tenant_id'],$couponId,$orderId,$discount,$expires]);$pdo->prepare('UPDATE coupons SET reserved_count=reserved_count+1 WHERE id=?')->execute([$couponId]);}
            (new NotificationService())->push((int)$event['tenant_id'],'event','Nova reserva de ingressos',$totalQty.' ingresso(s) reservados para '.$event['name'],'order',$orderId);
            return['order_id'=>$orderId,'public_token'=>$publicToken,'subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'total_cents'=>$total,'expires_at'=>$expires,'tickets'=>$tickets];
        });
    }

    private function customer(PDO $pdo,int $tenantId,string $name,string $email,string $phone):int
    {
        $id=null;if($email!==''){$s=$pdo->prepare('SELECT id,status FROM customers WHERE tenant_id=? AND email=? LIMIT 1');$s->execute([$tenantId,$email]);$r=$s->fetch();if($r){if(($r['status']??'active')==='blocked')throw new RuntimeException('Titular bloqueado.');$id=$r['id'];}}
        if(!$id&&$phone!==''){$s=$pdo->prepare('SELECT id,status FROM customers WHERE tenant_id=? AND phone=? LIMIT 1');$s->execute([$tenantId,$phone]);$r=$s->fetch();if($r){if(($r['status']??'active')==='blocked')throw new RuntimeException('Titular bloqueado.');$id=$r['id'];}}
        if(!$id){$s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,?,?)');$s->execute([$tenantId,$name,$phone?:null,$email?:null]);return(int)$pdo->lastInsertId();}$pdo->prepare('UPDATE customers SET name=?,phone=COALESCE(NULLIF(?,""),phone),email=COALESCE(NULLIF(?,""),email) WHERE id=?')->execute([$name,$phone,$email,$id]);return(int)$id;
    }

    private function coupon(PDO $pdo,int $tenantId,int $subtotal,?string $couponCode,\DateTimeImmutable $now):array
    {
        $code=strtoupper(trim((string)$couponCode));if($code==='')return[null,0];$c=$pdo->prepare('SELECT * FROM coupons WHERE tenant_id=? AND code=? AND active=1 FOR UPDATE');$c->execute([$tenantId,$code]);$coupon=$c->fetch();if(!$coupon)throw new RuntimeException('Cupom inválido.');if($coupon['starts_at']&&$now<new \DateTimeImmutable($coupon['starts_at']))throw new RuntimeException('Cupom ainda não está válido.');if($coupon['ends_at']&&$now>new \DateTimeImmutable($coupon['ends_at']))throw new RuntimeException('Cupom expirado.');if($coupon['max_uses']!==null&&((int)$coupon['uses_count']+(int)$coupon['reserved_count'])>=(int)$coupon['max_uses'])throw new RuntimeException('Limite do cupom atingido.');if($subtotal<(int)$coupon['min_order_cents'])throw new RuntimeException('Valor mínimo do cupom não atingido.');$discount=$coupon['type']==='percent'?(int)round($subtotal*min(100,(int)$coupon['value'])/100):min($subtotal,(int)$coupon['value']);return[(int)$coupon['id'],$discount];
    }

    private function newCode(int $eventId):string{return sprintf('%s-%s-%s',str_pad((string)$eventId,4,'0',STR_PAD_LEFT),strtoupper(substr(bin2hex(random_bytes(5)),0,10)),strtoupper(substr(bin2hex(random_bytes(2)),0,4)));}
}
