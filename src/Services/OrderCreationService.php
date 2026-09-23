<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

final class OrderCreationService
{
    public function create(array $payload):array
    {
        Auth::requirePermission('orders.create');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');
        $channel=strtolower(trim((string)($payload['channel']??'counter')));if(!in_array($channel,['counter','pickup','table','delivery','bar'],true))throw new RuntimeException('Canal inválido.');
        $rawItems=$payload['items']??[];if(!is_array($rawItems)||!$rawItems)throw new RuntimeException('Adicione itens ao pedido.');
        $tableId=(int)($payload['table_id']??0);$eventId=(int)($payload['event_id']??0);$name=mb_substr(trim((string)($payload['customer_name']??'')),0,160);$phone=mb_substr(trim((string)($payload['customer_phone']??'')),0,30);$address=mb_substr(trim((string)($payload['delivery_address']??'')),0,1000);$notes=mb_substr(trim((string)($payload['notes']??'')),0,1000);
        $idempotencyKey=$this->idempotencyKey($payload['idempotency_key']??null);
        $shift=(new WorkShiftService())->current();$unitId=$shift&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;
        if($channel==='delivery'&&($name===''||$phone===''||$address===''))throw new RuntimeException('Delivery exige nome, telefone e endereço.');
        if($channel==='bar'){
            Auth::requirePermission('events.bar');
            if(!$shift||$shift['mode']!=='events')throw new RuntimeException('Venda de bar só pode ser lançada durante turno de Eventos.');
            if($eventId<1)throw new RuntimeException('Selecione o evento desta venda de bar.');
        }

        $quantities=[];foreach($rawItems as $item){if(!is_array($item))continue;$id=(int)($item['product_id']??0);$qty=(float)str_replace(',','.',(string)($item['quantity']??$item['qty']??0));if($id<1||!is_finite($qty)||$qty<=0)continue;$quantities[$id]=($quantities[$id]??0)+$qty;}
        if(!$quantities)throw new RuntimeException('Nenhum item válido no pedido.');ksort($quantities,SORT_NUMERIC);
        $requestHash=$idempotencyKey!==null?$this->requestHash($userId,$unitId,$channel,$tableId,$eventId,$name,$phone,$address,$notes,$quantities):null;

        $createdNew=false;
        try{
            $order=Database::transaction(function(PDO $tx)use($tenantId,$userId,$unitId,$channel,$tableId,$eventId,$name,$phone,$address,$notes,$quantities,$idempotencyKey,$requestHash,&$createdNew):array{
                if($idempotencyKey!==null){
                    $existing=$this->existingByIdempotency($tx,$tenantId,$idempotencyKey,true);
                    if($existing!==null)return$this->validateReplay($existing,$userId,(string)$requestHash);
                }

                $validTableId=null;$tabId=null;$validEventId=null;$effectiveUnitId=$unitId;
                if($channel==='table'){
                    $t=$tx->prepare(Database::portableSql($tx,'SELECT id,status,unit_id FROM restaurant_tables WHERE id=? AND tenant_id=? FOR UPDATE'));$t->execute([$tableId,$tenantId]);$table=$t->fetch();if(!$table||$table['status']==='inactive')throw new RuntimeException('Mesa inválida.');
                    if($effectiveUnitId!==null&&$table['unit_id']!==null&&(int)$table['unit_id']!==$effectiveUnitId)throw new RuntimeException('Esta mesa pertence a outra unidade.');
                    if($effectiveUnitId===null&&$table['unit_id']!==null)$effectiveUnitId=(int)$table['unit_id'];
                    $tab=$tx->prepare(Database::portableSql($tx,'SELECT id FROM tabs WHERE tenant_id=? AND table_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$tab->execute([$tenantId,$tableId]);$tabId=$tab->fetchColumn();if($tabId===false)throw new RuntimeException('Abra uma comanda para esta mesa antes de lançar o pedido.');$validTableId=$tableId;
                }
                if($channel==='bar'){
                    $e=$tx->prepare(Database::portableSql($tx,'SELECT id,status,name FROM events WHERE id=? AND tenant_id=? FOR UPDATE'));$e->execute([$eventId,$tenantId]);$event=$e->fetch();if(!$event||$event['status']!=='published')throw new RuntimeException('O evento precisa estar publicado para receber vendas de bar.');$validEventId=$eventId;
                }
                $customerId=null;if($name!==''){$existing=false;if($phone!==''){$c=$tx->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? ORDER BY id DESC LIMIT 1');$c->execute([$tenantId,$phone]);$existing=$c->fetchColumn();}if($existing!==false&&$existing!==null)$customerId=(int)$existing;else{$c=$tx->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)');$c->execute([$tenantId,$name,$phone?:null]);$customerId=(int)$tx->lastInsertId();}}
                $items=[];$total=0;foreach($quantities as $productId=>$qty){$p=$tx->prepare(Database::portableSql($tx,'SELECT id,name,price_cents,track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? AND active=1 FOR UPDATE'));$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Um produto não está mais disponível.');if((int)$product['track_stock']&&(float)$product['stock_qty']<$qty)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'.');$line=(int)round((int)$product['price_cents']*$qty);if($line<0)throw new RuntimeException('Valor de item inválido.');$total+=$line;$items[]=['product_id'=>(int)$product['id'],'name'=>(string)$product['name'],'price'=>(int)$product['price_cents'],'quantity'=>$qty,'total'=>$line];}
                if($total<=0)throw new RuntimeException('Pedido sem valor válido.');
                $initialStatus=$channel==='bar'?'ready':'confirmed';
                $token=bin2hex(random_bytes(20));$o=$tx->prepare('INSERT INTO orders (public_token,creation_idempotency_key,creation_request_hash,tenant_id,unit_id,customer_id,channel,status,payment_status,subtotal_cents,total_cents,table_id,tab_id,event_id,delivery_address,notes,created_by) VALUES (?,?,?,?,?,?,?, ?,"unpaid",?,?,?,?,?,?,?,?)');$o->execute([$token,$idempotencyKey,$requestHash,$tenantId,$effectiveUnitId,$customerId,$channel,$initialStatus,$total,$total,$validTableId,$tabId,$validEventId,$channel==='delivery'?$address:null,$notes?:null,$userId]);$orderId=(int)$tx->lastInsertId();
                $i=$tx->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');foreach($items as $item)$i->execute([$orderId,$item['product_id'],$item['name'],$item['price'],$item['quantity'],$item['total']]);
                (new StockReservationService())->reserve($tx,$tenantId,$orderId,$items,null);
                (new OrderHistoryService())->record($tx,$tenantId,$orderId,null,$initialStatus,$channel==='bar'?'event_bar':'staff','Pedido criado pelo EventMenu GO.',$userId);
                Auth::audit('order.created_staff','order',(string)$orderId,['channel'=>$channel,'event_id'=>$validEventId,'unit_id'=>$effectiveUnitId,'total_cents'=>$total,'items'=>count($items),'idempotent'=>$idempotencyKey!==null]);
                $createdNew=true;
                return ['id'=>$orderId,'public_token'=>$token,'channel'=>$channel,'status'=>$initialStatus,'payment_status'=>'unpaid','total_cents'=>$total,'customer_id'=>$customerId,'table_id'=>$validTableId,'tab_id'=>$tabId,'event_id'=>$validEventId,'unit_id'=>$effectiveUnitId,'idempotent_replay'=>false];
            });
        }catch(PDOException $e){
            if($idempotencyKey===null)throw$e;
            $existing=$this->existingByIdempotency(Database::connection(),$tenantId,$idempotencyKey,false);
            if($existing===null)throw$e;
            $order=$this->validateReplay($existing,$userId,(string)$requestHash);
            $createdNew=false;
        }

        if($createdNew&&$channel!=='bar'){
            try{
                (new NotificationService())->publishToPermission(
                    'orders.kitchen','operation','order.new','Novo pedido #'.$order['id'],
                    'Um novo pedido foi confirmado e entrou na fila da cozinha.','order',(string)$order['id'],
                    'order:'.$order['id'].':kitchen-created','info',gmdate('Y-m-d H:i:s',time()+86400)
                );
            }catch(\Throwable){}
        }
        return $order;
    }

    private function idempotencyKey(mixed$value):?string
    {
        $key=trim((string)($value??''));if($key==='')return null;
        if(strlen($key)<16||strlen($key)>190||!preg_match('/^[A-Za-z0-9._:-]+$/',$key))throw new RuntimeException('Chave de idempotência do pedido inválida.');
        return$key;
    }

    private function requestHash(int$userId,?int$unitId,string$channel,int$tableId,int$eventId,string$name,string$phone,string$address,string$notes,array$quantities):string
    {
        $payload=['user_id'=>$userId,'unit_id'=>$unitId,'channel'=>$channel,'table_id'=>$tableId,'event_id'=>$eventId,'customer_name'=>$name,'customer_phone'=>$phone,'delivery_address'=>$address,'notes'=>$notes,'items'=>$quantities];
        return hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
    }

    private function existingByIdempotency(PDO$pdo,int$tenantId,string$key,bool$lock):?array
    {
        $sql='SELECT * FROM orders WHERE tenant_id=? AND creation_idempotency_key=? LIMIT 1';if($lock)$sql=Database::portableSql($pdo,str_replace(' LIMIT 1',' LIMIT 1 FOR UPDATE',$sql));$s=$pdo->prepare($sql);$s->execute([$tenantId,$key]);$row=$s->fetch();return$row?:null;
    }

    private function validateReplay(array$order,int$userId,string$requestHash):array
    {
        if((int)($order['created_by']??0)!==$userId)throw new RuntimeException('Esta chave de idempotência já pertence a outra operação.');
        if(!hash_equals((string)($order['creation_request_hash']??''),$requestHash))throw new RuntimeException('A chave de idempotência já foi usada com dados diferentes.');
        return ['id'=>(int)$order['id'],'public_token'=>(string)$order['public_token'],'channel'=>(string)$order['channel'],'status'=>(string)$order['status'],'payment_status'=>(string)$order['payment_status'],'total_cents'=>(int)$order['total_cents'],'customer_id'=>$order['customer_id']!==null?(int)$order['customer_id']:null,'table_id'=>$order['table_id']!==null?(int)$order['table_id']:null,'tab_id'=>$order['tab_id']!==null?(int)$order['tab_id']:null,'event_id'=>$order['event_id']!==null?(int)$order['event_id']:null,'unit_id'=>$order['unit_id']!==null?(int)$order['unit_id']:null,'idempotent_replay'=>true];
    }
}
