<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CounterOrderService
{
    public function create(array $cart,array $buyer=[],string $notes=''):array
    {
        Auth::requirePermission('orders.create');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa não selecionada.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$cart,$buyer,$notes){
            $tenant=$pdo->prepare('SELECT id,status FROM tenants WHERE id=? LIMIT 1 FOR UPDATE');
            $tenant->execute([$tenantId]);
            $row=$tenant->fetch();
            if(!$row||$row['status']!=='active')throw new RuntimeException('Empresa indisponível.');

            $normalized=[];
            foreach($cart as $productId=>$qty){
                $id=(int)$productId;$quantity=max(0,min(50,(int)$qty));
                if($id>0&&$quantity>0)$normalized[$id]=($normalized[$id]??0)+$quantity;
            }
            if(!$normalized)throw new RuntimeException('Adicione ao menos um produto ao pedido.');

            $items=[];$subtotal=0;
            $product=$pdo->prepare('SELECT id,name,price_cents,track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? AND active=1 LIMIT 1 FOR UPDATE');
            foreach($normalized as$id=>$quantity){
                $product->execute([$id,$tenantId]);$p=$product->fetch();
                if(!$p)throw new RuntimeException('Produto indisponível no PDV.');
                if((int)$p['track_stock']&&$p['stock_qty']!==null&&(float)$p['stock_qty']<$quantity)throw new RuntimeException('Estoque insuficiente para '.$p['name'].'.');
                $line=(int)$p['price_cents']*$quantity;$subtotal+=$line;
                $items[]=['product_id'=>$id,'name'=>$p['name'],'unit_price_cents'=>(int)$p['price_cents'],'quantity'=>$quantity,'total_cents'=>$line];
            }

            $customerId=$this->customer($pdo,$tenantId,$buyer);
            $token=bin2hex(random_bytes(20));
            $fulfillmentToken=bin2hex(random_bytes(20));
            $notes=mb_substr(trim($notes),0,1000);
            $order=$pdo->prepare('INSERT INTO orders (public_token,fulfillment_token,tenant_id,customer_id,channel,status,payment_status,fulfillment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,notes,created_by) VALUES (?,?,?, ?,"counter","confirmed","unpaid","pending",?,0,0,?,?,?)');
            $order->execute([$token,$fulfillmentToken,$tenantId,$customerId,$subtotal,$subtotal,$notes!==''?$notes:null,Auth::id()]);
            $orderId=(int)$pdo->lastInsertId();

            $insert=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');
            foreach($items as$item)$insert->execute([$orderId,$item['product_id'],$item['name'],$item['unit_price_cents'],$item['quantity'],$item['total_cents']]);

            (new StockService())->commitForOrder($pdo,$tenantId,$orderId);
            Auth::audit('order.counter_created','order',(string)$orderId,['total_cents'=>$subtotal,'items'=>count($items)]);
            return ['order_id'=>$orderId,'public_token'=>$token,'fulfillment_token'=>$fulfillmentToken,'total_cents'=>$subtotal,'status'=>'confirmed','payment_status'=>'unpaid','fulfillment_status'=>'pending'];
        });
    }

    private function customer(PDO $pdo,int $tenantId,array $buyer):?int
    {
        $name=trim((string)($buyer['name']??''));$phone=trim((string)($buyer['phone']??''));
        if($name===''&&$phone==='')return null;
        if($phone!==''){
            $s=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? LIMIT 1');$s->execute([$tenantId,$phone]);$id=$s->fetchColumn();
            if($id){if($name!=='')$pdo->prepare('UPDATE customers SET name=? WHERE id=? AND tenant_id=?')->execute([$name,$id,$tenantId]);return(int)$id;}
        }
        if($name==='')$name='Cliente balcão';
        $pdo->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)')->execute([$tenantId,$name,$phone!==''?$phone:null]);
        return(int)$pdo->lastInsertId();
    }
}
