<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderCreationService
{
    public function create(array $payload):array
    {
        Auth::requirePermission('orders.create');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');
        $channel=strtolower(trim((string)($payload['channel']??'counter')));if(!in_array($channel,['counter','pickup','table','delivery'],true))throw new RuntimeException('Canal inválido.');
        $rawItems=$payload['items']??[];if(!is_array($rawItems)||!$rawItems)throw new RuntimeException('Adicione itens ao pedido.');
        $tableId=(int)($payload['table_id']??0);$name=mb_substr(trim((string)($payload['customer_name']??'')),0,160);$phone=mb_substr(trim((string)($payload['customer_phone']??'')),0,30);$address=mb_substr(trim((string)($payload['delivery_address']??'')),0,1000);$notes=mb_substr(trim((string)($payload['notes']??'')),0,1000);
        if($channel==='delivery'&&($name===''||$phone===''||$address===''))throw new RuntimeException('Delivery exige nome, telefone e endereço.');

        $quantities=[];foreach($rawItems as $item){if(!is_array($item))continue;$id=(int)($item['product_id']??0);$qty=(float)str_replace(',','.',(string)($item['quantity']??$item['qty']??0));if($id<1||!is_finite($qty)||$qty<=0)continue;$quantities[$id]=($quantities[$id]??0)+$qty;}
        if(!$quantities)throw new RuntimeException('Nenhum item válido no pedido.');

        $order=Database::transaction(function(PDO $tx)use($tenantId,$userId,$channel,$tableId,$name,$phone,$address,$notes,$quantities):array{
            $validTableId=null;$tabId=null;
            if($channel==='table'){
                $t=$tx->prepare(Database::portableSql($tx,'SELECT id,status FROM restaurant_tables WHERE id=? AND tenant_id=? FOR UPDATE'));$t->execute([$tableId,$tenantId]);$table=$t->fetch();if(!$table||$table['status']==='inactive')throw new RuntimeException('Mesa inválida.');
                $tab=$tx->prepare(Database::portableSql($tx,'SELECT id FROM tabs WHERE tenant_id=? AND table_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$tab->execute([$tenantId,$tableId]);$tabId=$tab->fetchColumn();if($tabId===false)throw new RuntimeException('Abra uma comanda para esta mesa antes de lançar o pedido.');$validTableId=$tableId;
            }
            $customerId=null;if($name!==''){$existing=false;if($phone!==''){$c=$tx->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? ORDER BY id DESC LIMIT 1');$c->execute([$tenantId,$phone]);$existing=$c->fetchColumn();}if($existing!==false&&$existing!==null)$customerId=(int)$existing;else{$c=$tx->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)');$c->execute([$tenantId,$name,$phone?:null]);$customerId=(int)$tx->lastInsertId();}}
            $items=[];$total=0;foreach($quantities as $productId=>$qty){$p=$tx->prepare(Database::portableSql($tx,'SELECT id,name,price_cents,track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? AND active=1 FOR UPDATE'));$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Um produto não está mais disponível.');if((int)$product['track_stock']&&(float)$product['stock_qty']<$qty)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'.');$line=(int)round((int)$product['price_cents']*$qty);if($line<0)throw new RuntimeException('Valor de item inválido.');$total+=$line;$items[]=['product_id'=>(int)$product['id'],'name'=>(string)$product['name'],'price'=>(int)$product['price_cents'],'quantity'=>$qty,'total'=>$line];}
            if($total<=0)throw new RuntimeException('Pedido sem valor válido.');
            $token=bin2hex(random_bytes(20));$o=$tx->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,channel,status,payment_status,subtotal_cents,total_cents,table_id,tab_id,delivery_address,notes,created_by) VALUES (?,?,?,?,"confirmed","unpaid",?,?,?,?,?,?,?)');$o->execute([$token,$tenantId,$customerId,$channel,$total,$total,$validTableId,$tabId,$channel==='delivery'?$address:null,$notes?:null,$userId]);$orderId=(int)$tx->lastInsertId();
            $i=$tx->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');foreach($items as $item)$i->execute([$orderId,$item['product_id'],$item['name'],$item['price'],$item['quantity'],$item['total']]);
            (new StockReservationService())->reserve($tx,$tenantId,$orderId,$items,null);
            Auth::audit('order.created_staff','order',(string)$orderId,['channel'=>$channel,'total_cents'=>$total,'items'=>count($items)]);
            return ['id'=>$orderId,'public_token'=>$token,'channel'=>$channel,'status'=>'confirmed','payment_status'=>'unpaid','total_cents'=>$total,'customer_id'=>$customerId,'table_id'=>$validTableId,'tab_id'=>$tabId];
        });

        try{
            (new NotificationService())->publishToPermission(
                'orders.kitchen','operation','order.new','Novo pedido #'.$order['id'],
                'Um novo pedido foi confirmado e entrou na fila da cozinha.','order',(string)$order['id'],
                'order:'.$order['id'].':kitchen-created','info',gmdate('Y-m-d H:i:s',time()+86400)
            );
        }catch(\Throwable){}
        return $order;
    }
}
