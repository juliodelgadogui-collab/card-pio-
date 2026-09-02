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
        Auth::requirePermission('orders.create');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa não selecionada.');$unitId=Auth::unitId();
        return Database::transaction(function(PDO $pdo)use($tenantId,$unitId,$cart,$buyer,$notes){$tenant=$pdo->prepare('SELECT status FROM tenants WHERE id=? FOR UPDATE');$tenant->execute([$tenantId]);if($tenant->fetchColumn()!=='active')throw new RuntimeException('Empresa indisponível.');$rows=$this->normalizeCart($cart);if(!$rows)throw new RuntimeException('Adicione ao menos um produto ao pedido.');$items=[];$subtotal=0;$product=$pdo->prepare('SELECT id,name,price_cents,promo_price_cents,unit_id FROM products WHERE id=? AND tenant_id=? AND active=1 LIMIT 1 FOR UPDATE');$catalog=new CatalogOptionsService();
            foreach($rows as$row){$id=(int)$row['product_id'];$quantity=(int)$row['qty'];$product->execute([$id,$tenantId]);$p=$product->fetch();if(!$p||($unitId&&$p['unit_id']!==null&&(int)$p['unit_id']!==$unitId))throw new RuntimeException('Produto indisponível no PDV desta unidade.');$selection=$catalog->validateSelection($pdo,$tenantId,$id,(array)$row['option_ids']);$base=$p['promo_price_cents']!==null?(int)$p['promo_price_cents']:(int)$p['price_cents'];$unitPrice=max(0,$base+(int)$selection['delta_cents']);$line=$unitPrice*$quantity;$subtotal+=$line;$items[]=['product_id'=>$id,'name'=>$p['name'],'unit_price_cents'=>$unitPrice,'quantity'=>$quantity,'total_cents'=>$line,'options'=>$selection['options']];}
            $customerId=$this->customer($pdo,$tenantId,$buyer);$token=bin2hex(random_bytes(20));$fulfillmentToken=bin2hex(random_bytes(20));$notes=mb_substr(trim($notes),0,1000);$order=$pdo->prepare('INSERT INTO orders (public_token,fulfillment_token,tenant_id,unit_id,customer_id,channel,status,payment_status,fulfillment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,notes,created_by) VALUES (?,?,?,?,?,"counter","confirmed","unpaid","pending",?,0,0,?,?,?)');$order->execute([$token,$fulfillmentToken,$tenantId,$unitId,$customerId,$subtotal,$subtotal,$notes!==''?$notes:null,Auth::id()]);$orderId=(int)$pdo->lastInsertId();$insert=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');$opt=$pdo->prepare('INSERT INTO order_item_options (order_item_id,option_id,name_snapshot,price_delta_cents) VALUES (?,?,?,?)');foreach($items as$item){$insert->execute([$orderId,$item['product_id'],$item['name'],$item['unit_price_cents'],$item['quantity'],$item['total_cents']]);$itemId=(int)$pdo->lastInsertId();foreach($item['options']as$o)$opt->execute([$itemId,$o['id'],$o['name'],$o['price_delta_cents']]);}(new StockService())->commitForOrder($pdo,$tenantId,$orderId);(new NotificationService())->push($tenantId,'order','Nova venda no balcão','#'.$orderId.' · '.number_format($subtotal/100,2,',','.'),'order',$orderId);Auth::audit('order.counter_created','order',(string)$orderId,['total_cents'=>$subtotal,'items'=>count($items),'unit_id'=>$unitId]);return['order_id'=>$orderId,'public_token'=>$token,'fulfillment_token'=>$fulfillmentToken,'total_cents'=>$subtotal,'status'=>'confirmed','payment_status'=>'unpaid','fulfillment_status'=>'pending'];});
    }

    private function normalizeCart(array $cart):array
    {
        $out=[];$isRows=array_is_list($cart)&&isset($cart[0])&&is_array($cart[0]);if($isRows){foreach($cart as$r){$id=(int)($r['product_id']??0);$qty=max(0,min(50,(int)($r['qty']??0)));if($id<1||$qty<1)continue;$opts=array_values(array_unique(array_filter(array_map('intval',(array)($r['option_ids']??[])),fn($v)=>$v>0)));sort($opts);$out[]=['product_id'=>$id,'qty'=>$qty,'option_ids'=>$opts];}}else{foreach($cart as$id=>$qty){$id=(int)$id;$qty=max(0,min(50,(int)$qty));if($id>0&&$qty>0)$out[]=['product_id'=>$id,'qty'=>$qty,'option_ids'=>[]];}}return$out;
    }

    private function customer(PDO $pdo,int $tenantId,array $buyer):?int
    {
        $name=trim((string)($buyer['name']??''));$phone=trim((string)($buyer['phone']??''));if($name===''&&$phone==='')return null;if($phone!==''){$s=$pdo->prepare('SELECT id,status FROM customers WHERE tenant_id=? AND phone=? LIMIT 1');$s->execute([$tenantId,$phone]);$row=$s->fetch();if($row){if(($row['status']??'active')==='blocked')throw new RuntimeException('Cliente bloqueado para novas vendas.');if($name!=='')$pdo->prepare('UPDATE customers SET name=? WHERE id=? AND tenant_id=?')->execute([$name,$row['id'],$tenantId]);return(int)$row['id'];}}if($name==='')$name='Cliente balcão';$pdo->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)')->execute([$tenantId,$name,$phone!==''?$phone:null]);return(int)$pdo->lastInsertId();
    }
}
