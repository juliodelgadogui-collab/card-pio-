<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class StockReservationService
{
    public function reserve(PDO $pdo,int $tenantId,int $orderId,array $items,?string $expiresAt=null):void
    {
        $requirements=$this->requirements($pdo,$tenantId,$orderId,$items);
        foreach($requirements as $productId=>$qty){
            if($productId<1||!is_finite($qty)||$qty<=0)continue;
            $p=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Produto/ingrediente não encontrado durante reserva de estoque.');if(!(int)$product['track_stock'])continue;
            $existing=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM stock_reservations WHERE tenant_id=? AND order_id=? AND product_id=? FOR UPDATE'));$existing->execute([$tenantId,$orderId,$productId]);$row=$existing->fetch();if($row){if($row['status']==='reserved'||$row['status']==='consumed')continue;throw new RuntimeException('A reserva de estoque deste pedido já foi liberada.');}
            $update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?');$update->execute([$qty,$productId,$tenantId,$qty]);if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'. Necessário: '.$this->formatQty($qty).'.');
            $pdo->prepare('INSERT INTO stock_reservations (tenant_id,order_id,product_id,quantity,status,expires_at) VALUES (?,?,?, ?,"reserved",?)')->execute([$tenantId,$orderId,$productId,$qty,$expiresAt]);
        }
    }

    public function holdForPayment(int $tenantId,int $orderId):void{Database::connection()->prepare('UPDATE stock_reservations SET expires_at=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);}
    public function rearmAfterPaymentFailure(int $tenantId,int $orderId,int $minutes=30):void{$expires=(new \DateTimeImmutable('+'.max(5,$minutes).' minutes'))->format('Y-m-d H:i:s');Database::connection()->prepare('UPDATE stock_reservations SET expires_at=? WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$expires,$tenantId,$orderId]);}

    public function consumeForPayment(PDO $pdo,int $tenantId,int $orderId,int $paymentId):void
    {
        $this->consumeForSettlement($pdo,$tenantId,$orderId,'payment:'.$paymentId);
    }

    public function consumeForSettlement(PDO $pdo,int $tenantId,int $orderId,string $settlementKey):void
    {
        $settlementKey=trim($settlementKey);if($settlementKey==='')throw new RuntimeException('Chave de liquidação do estoque inválida.');
        $reservations=$pdo->prepare(Database::portableSql($pdo,'SELECT sr.*,p.name,p.average_cost_cents FROM stock_reservations sr JOIN products p ON p.id=sr.product_id WHERE sr.tenant_id=? AND sr.order_id=? AND sr.status IN ("reserved","consumed") FOR UPDATE'));$reservations->execute([$tenantId,$orderId]);$rows=$reservations->fetchAll();
        if(!$rows){
            $requirements=$this->requirements($pdo,$tenantId,$orderId,[]);
            foreach($requirements as$productId=>$qty){$p=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,track_stock,average_cost_cents FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product||!(int)$product['track_stock'])continue;$update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?');$update->execute([$qty,$productId,$tenantId,$qty]);if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para confirmar '.$product['name'].'.');$rows[]=['id'=>null,'product_id'=>$productId,'quantity'=>$qty,'status'=>'consumed','average_cost_cents'=>$product['average_cost_cents']];}
        }
        foreach($rows as$row){$productId=(int)$row['product_id'];$key=$settlementKey.':product:'.$productId;$check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=?');$check->execute([$tenantId,$key]);if($check->fetchColumn())continue;if($row['id']!==null&&$row['status']==='reserved')$pdo->prepare('UPDATE stock_reservations SET status="consumed",expires_at=NULL WHERE id=?')->execute([$row['id']]);$qty=(float)$row['quantity'];$cost=(int)($row['average_cost_cents']??0);$pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key,unit_cost_cents,total_cost_cents) VALUES (?,?,?,"out",?,?,?,?)')->execute([$tenantId,$productId,$orderId,$qty,$key,$cost,(int)round($cost*$qty)]);}
    }

    public function release(PDO $pdo,int $tenantId,int $orderId):int
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM stock_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE'));$s->execute([$tenantId,$orderId]);$rows=$s->fetchAll();$released=0;
        foreach($rows as $row){$pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=?')->execute([$row['quantity'],$row['product_id'],$tenantId]);$pdo->prepare('UPDATE stock_reservations SET status="released",expires_at=NULL WHERE id=? AND status="reserved"')->execute([$row['id']]);$released++;}
        return $released;
    }

    public function releaseExpired():int
    {
        return Database::transaction(function(PDO $pdo):int{
            $sql='SELECT sr.tenant_id,sr.order_id FROM stock_reservations sr JOIN orders o ON o.id=sr.order_id WHERE sr.status="reserved" AND sr.expires_at IS NOT NULL AND sr.expires_at<CURRENT_TIMESTAMP AND o.payment_status IN ("unpaid","failed") AND o.status IN ("draft","pending") GROUP BY sr.tenant_id,sr.order_id';
            $s=$pdo->query(Database::portableSql($pdo,$sql));$groups=$s->fetchAll();$total=0;
            foreach($groups as $g){
                $tenantId=(int)$g['tenant_id'];$orderId=(int)$g['order_id'];
                $o=$pdo->prepare(Database::portableSql($pdo,'SELECT id,table_id,tab_id FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)continue;
                $total+=$this->release($pdo,$tenantId,$orderId);
                $pdo->prepare('UPDATE orders SET status="cancelled",payment_status=CASE WHEN payment_status="unpaid" THEN "failed" ELSE payment_status END WHERE id=? AND tenant_id=? AND payment_status IN ("unpaid","failed") AND status IN ("draft","pending")')->execute([$orderId,$tenantId]);
                if(!empty($order['tab_id'])&&!empty($order['table_id'])){
                    $tab=$pdo->prepare(Database::portableSql($pdo,'SELECT id,opened_by,label,status FROM tabs WHERE id=? AND tenant_id=? FOR UPDATE'));$tab->execute([$order['tab_id'],$tenantId]);$tabRow=$tab->fetch();
                    if($tabRow&&$tabRow['status']==='open'&&$tabRow['opened_by']===null&&str_starts_with((string)($tabRow['label']??''),'QR ')){
                        $active=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND tab_id=? AND status<>"cancelled"');$active->execute([$tenantId,$tabRow['id']]);
                        if((int)$active->fetchColumn()===0){$pdo->prepare('UPDATE tabs SET status="closed",closed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$tabRow['id']]);$pdo->prepare('UPDATE restaurant_tables SET status="available" WHERE id=? AND tenant_id=?')->execute([$order['table_id'],$tenantId]);}
                    }
                }
            }
            return $total;
        });
    }

    private function requirements(PDO $pdo,int $tenantId,int $orderId,array $fallbackItems):array
    {
        $requirements=[];$lines=[];
        $s=$pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL');$s->execute([$orderId]);$lines=$s->fetchAll();
        if(!$lines){foreach($fallbackItems as$item){$pid=(int)($item['product_id']??$item['id']??0);$qty=(float)($item['quantity']??$item['qty']??0);if($pid>0&&$qty>0)$lines[]=['product_id'=>$pid,'quantity'=>$qty];}}
        foreach($lines as$line){$productId=(int)$line['product_id'];$ordered=(float)$line['quantity'];if($productId<1||$ordered<=0)continue;$r=$pdo->prepare('SELECT ingredient_product_id,quantity,waste_percent FROM product_recipes WHERE tenant_id=? AND product_id=?');$r->execute([$tenantId,$productId]);$recipe=$r->fetchAll();if($recipe){foreach($recipe as$row){$ingredient=(int)$row['ingredient_product_id'];$factor=(float)$row['quantity']*(1+max(0.0,(float)$row['waste_percent'])/100);$requirements[$ingredient]=($requirements[$ingredient]??0)+($ordered*$factor);}}else{$requirements[$productId]=($requirements[$productId]??0)+$ordered;}}
        try{$m=$pdo->prepare('SELECT inventory_product_id,inventory_quantity,quantity FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND inventory_product_id IS NOT NULL AND inventory_quantity>0');$m->execute([$tenantId,$orderId]);foreach($m->fetchAll() as$row){$pid=(int)$row['inventory_product_id'];$requirements[$pid]=($requirements[$pid]??0)+((float)$row['inventory_quantity']*(float)$row['quantity']);}}catch(\Throwable){}
        foreach($requirements as$id=>$qty)$requirements[$id]=round($qty,6);return $requirements;
    }

    private function formatQty(float $qty):string
    {
        if(abs($qty-round($qty))<0.0005)return (string)(int)round($qty);return rtrim(rtrim(number_format($qty,3,',','.'),'0'),',');
    }
}
