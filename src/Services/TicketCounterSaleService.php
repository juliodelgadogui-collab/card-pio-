<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;
use PDO;
use RuntimeException;

final class TicketCounterSaleService
{
    public function sell(int $eventId,int $batchId,int $quantity,string $method,array $buyer=[]):array
    {
        Auth::requirePermission('tickets.manage');
        $tenantId=Auth::tenantId();$operatorId=Auth::id();
        if(!$tenantId||!$operatorId)throw new RuntimeException('Operador ou empresa inválida.');
        $quantity=max(1,min(20,$quantity));$method=strtolower(trim($method));
        if(!in_array($method,['cash','pix','card_pos','nfc','courtesy'],true))throw new RuntimeException('Forma de pagamento inválida.');
        if($method==='courtesy'&&!Auth::can('events.manage'))throw new RuntimeException('Cortesia exige permissão de gerente de eventos.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$operatorId,$eventId,$batchId,$quantity,$method,$buyer):array{
            $e=$pdo->prepare(Database::portableSql($pdo,'SELECT e.*,t.status tenant_status FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.id=? AND e.tenant_id=? FOR UPDATE'));
            $e->execute([$eventId,$tenantId]);$event=$e->fetch();
            if(!$event||$event['tenant_status']!=='active'||!TenantFeatures::events($tenantId))throw new RuntimeException('Evento indisponível.');
            if(!in_array((string)$event['status'],['published','draft'],true))throw new RuntimeException('Evento não aceita venda presencial.');
            if(!empty($event['ends_at'])&&new \DateTimeImmutable('now')>new \DateTimeImmutable((string)$event['ends_at']))throw new RuntimeException('Evento encerrado.');

            $b=$pdo->prepare(Database::portableSql($pdo,'SELECT b.*,tt.name ticket_type_name,tt.capacity_total ticket_type_capacity,tt.active ticket_type_active FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.id=? AND b.event_id=? FOR UPDATE'));
            $b->execute([$batchId,$eventId]);$batch=$b->fetch();if(!$batch||!(int)$batch['active'])throw new RuntimeException('Lote indisponível.');
            if(!empty($batch['ticket_type_id'])&&!(int)$batch['ticket_type_active'])throw new RuntimeException('Tipo de ingresso indisponível.');
            $available=(int)$batch['quantity_total']-(int)$batch['quantity_sold']-(int)$batch['quantity_reserved'];
            if($available<$quantity)throw new RuntimeException('Quantidade indisponível neste lote.');

            if(!empty($event['capacity_total'])){
                $x=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');$x->execute([$tenantId,$eventId]);$used=(int)$x->fetchColumn();
                $g=$pdo->prepare('SELECT COALESCE(SUM(1+COALESCE(plus_ones,0)),0) FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');$g->execute([$tenantId,$eventId]);$used+=(int)$g->fetchColumn();
                if($used+$quantity>(int)$event['capacity_total'])throw new RuntimeException('Capacidade total do evento atingida.');
            }
            if(!empty($batch['ticket_type_id'])&&!empty($batch['ticket_type_capacity'])){
                $x=$pdo->prepare('SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id=? AND t.status IN ("reserved","paid","checked_in")');$x->execute([$tenantId,$eventId,$batch['ticket_type_id']]);
                if((int)$x->fetchColumn()+$quantity>(int)$batch['ticket_type_capacity'])throw new RuntimeException('Capacidade do tipo de ingresso atingida.');
            }

            $name=trim((string)($buyer['name']??''));$email=mb_strtolower(trim((string)($buyer['email']??'')));$phone=trim((string)($buyer['phone']??''));
            $identified=$name!==''||$email!==''||$phone!=='';$customerId=null;
            if($identified){
                if($name==='')throw new RuntimeException('Informe o nome quando a venda for identificada.');
                if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
                if($phone!==''&&strlen(preg_replace('/\D+/','',$phone)??'')<10)throw new RuntimeException('Telefone inválido.');
                if($phone!==''){$c=(new CustomerIdentityService())->findOrCreate($pdo,$tenantId,$name,$phone);$customerId=(int)$c['id'];if($email!=='')$pdo->prepare('UPDATE customers SET email=? WHERE id=? AND tenant_id=?')->execute([$email,$customerId,$tenantId]);}
                elseif($email!==''){$s=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND email=? LIMIT 1');$s->execute([$tenantId,$email]);$customerId=$s->fetchColumn()?:null;if(!$customerId){$s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,NULL,?)');$s->execute([$tenantId,$name,$email]);$customerId=(int)$pdo->lastInsertId();}}
            }

            $unit=(int)$batch['price_cents'];$total=$method==='courtesy'?0:$unit*$quantity;$publicToken=bin2hex(random_bytes(20));
            $notes='Venda presencial · '.($identified?'comprador identificado':'venda avulsa')." · {$method}";
            $paidNow=in_array($method,['cash','card_pos','nfc','courtesy'],true);
            $s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,channel,status,payment_status,subtotal_cents,discount_cents,total_cents,notes) VALUES (?,?,?,"event",?,?,?,?,?,?)');
            $s->execute([$publicToken,$tenantId,$customerId,$paidNow?'confirmed':'pending',$paidNow?'paid':'pending',$unit*$quantity,$method==='courtesy'?$unit*$quantity:0,$total,$notes]);$orderId=(int)$pdo->lastInsertId();
            $label=trim((string)($batch['ticket_type_name']??''));$item='Ingresso '.$event['name'].' — '.($label!==''?$label.' · ':'').$batch['name'];
            $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,NULL,?,?,?,?)')->execute([$orderId,$item,$unit,$quantity,$unit*$quantity]);

            $tickets=[];$ticketStatus=$paidNow?'paid':'reserved';$reservedUntil=$paidNow?null:(new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
            for($i=0;$i<$quantity;$i++){
                $code=sprintf('%s-%s-%s',str_pad((string)$eventId,4,'0',STR_PAD_LEFT),strtoupper(substr(bin2hex(random_bytes(5)),0,10)),strtoupper(substr(bin2hex(random_bytes(2)),0,4)));$qr=bin2hex(random_bytes(32));
                $q=$pdo->prepare('INSERT INTO tickets (tenant_id,event_id,batch_id,order_id,customer_id,code,status,reserved_until,qr_token,sale_channel,sold_by_user_id,payment_method) VALUES (?,?,?,?,?,?,?,?,?,"counter",?,?)');
                $q->execute([$tenantId,$eventId,$batchId,$orderId,$customerId,$code,$ticketStatus,$reservedUntil,$qr,$operatorId,$method]);$tickets[]=['id'=>(int)$pdo->lastInsertId(),'code'=>$code,'qr_token'=>$qr];
            }
            if($paidNow)$pdo->prepare('UPDATE ticket_batches SET quantity_sold=quantity_sold+? WHERE id=?')->execute([$quantity,$batchId]);else $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=quantity_reserved+? WHERE id=?')->execute([$quantity,$batchId]);

            if($paidNow&&$total>0){
                $p=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,provider_payment_id,idempotency_key,amount_cents,currency,status,verified_at,raw_payload) VALUES (?,?,"manual",?,?,?,"BRL","paid",CURRENT_TIMESTAMP,?)');
                $ref='counter:'.$method.':'.$tenantId.':'.$orderId;$p->execute([$tenantId,$orderId,$ref,$ref,$total,json_encode(['method'=>$method,'operator_id'=>$operatorId,'counter_sale'=>true],JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,$operatorId,'ticket.counter_sale','order',(string)$orderId,json_encode(['quantity'=>$quantity,'batch_id'=>$batchId,'payment_method'=>$method,'anonymous'=>!$identified],JSON_UNESCAPED_UNICODE)]);
            Auth::audit('ticket.counter_sale','order',(string)$orderId,['event_id'=>$eventId,'quantity'=>$quantity,'payment_method'=>$method,'anonymous'=>!$identified]);
            return ['order_id'=>$orderId,'public_token'=>$publicToken,'payment_status'=>$paidNow?'paid':'pending','payment_method'=>$method,'anonymous'=>!$identified,'total_cents'=>$total,'expires_at'=>$reservedUntil,'tickets'=>$tickets];
        });
    }
}
