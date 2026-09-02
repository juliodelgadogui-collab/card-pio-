<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class StockService
{
    public function commitForOrder(PDO $pdo,int $tenantId,int $orderId):void
    {
        $items=$pdo->prepare('SELECT oi.product_id,SUM(oi.quantity) quantity,p.track_stock FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id AND p.tenant_id=? WHERE oi.order_id=? AND oi.product_id IS NOT NULL GROUP BY oi.product_id,p.track_stock');
        $items->execute([$tenantId,$orderId]);
        foreach($items->fetchAll() as$item){
            if(!(int)$item['track_stock'])continue;$productId=(int)$item['product_id'];$qty=(float)$item['quantity'];$key=$this->commitKey($orderId,$productId);
            $check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn())continue;
            $update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND track_stock=1 AND stock_qty>=?');$update->execute([$qty,$productId,$tenantId,$qty]);if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para concluir o pedido.');
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"out",?,?)')->execute([$tenantId,$productId,$orderId,$qty,$key]);
        }
    }

    public function reverseForOrder(PDO $pdo,int $tenantId,int $orderId):void
    {
        $s=$pdo->prepare('SELECT product_id,quantity FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out" AND idempotency_key LIKE ?');
        $s->execute([$tenantId,$orderId,'order:'.$orderId.':product:%:commit']);
        $remaining=$pdo->prepare('SELECT COALESCE(SUM(GREATEST(quantity-fulfilled_quantity,0)),0) FROM order_items WHERE order_id=? AND product_id=?');
        foreach($s->fetchAll() as$row){
            $productId=(int)$row['product_id'];$committed=(float)$row['quantity'];$key=$this->reversalKey($orderId,$productId);
            $check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn())continue;
            $remaining->execute([$orderId,$productId]);$unfulfilled=max(0,(float)$remaining->fetchColumn());$qty=min($committed,$unfulfilled);
            if($qty<=0.000001)continue;
            $pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=?')->execute([$qty,$productId,$tenantId]);
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"reversal",?,?)')->execute([$tenantId,$productId,$orderId,$qty,$key]);
        }
    }

    private function commitKey(int $orderId,int $productId):string{return 'order:'.$orderId.':product:'.$productId.':commit';}
    private function reversalKey(int $orderId,int $productId):string{return 'order:'.$orderId.':product:'.$productId.':reversal';}
}
