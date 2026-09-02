<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class StockService
{
    public function reserveForOrder(PDO $pdo,int $tenantId,int $orderId,string $expiresAt):void
    {
        foreach($this->requirements($pdo,$tenantId,$orderId,false)as$productId=>$qty){
            $p=$pdo->prepare('SELECT track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? FOR UPDATE');$p->execute([$productId,$tenantId]);$row=$p->fetch();if(!$row||(int)$row['track_stock']!==1)continue;
            $r=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_reservations WHERE tenant_id=? AND product_id=? AND status="reserved" AND expires_at>UTC_TIMESTAMP() AND order_id<>?');$r->execute([$tenantId,$productId,$orderId]);$reserved=(float)$r->fetchColumn();
            if((float)$row['stock_qty']-$reserved+0.000001<$qty)throw new RuntimeException('Estoque insuficiente para reservar o pedido.');
            $pdo->prepare('INSERT INTO stock_reservations (tenant_id,order_id,product_id,quantity,status,expires_at) VALUES (?,?,?,? ,"reserved",?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity),status="reserved",expires_at=VALUES(expires_at),released_at=NULL,consumed_at=NULL')->execute([$tenantId,$orderId,$productId,$qty,$expiresAt]);
        }
    }

    public function releaseReservations(PDO $pdo,int $tenantId,int $orderId):void{$pdo->prepare('UPDATE stock_reservations SET status="released",released_at=UTC_TIMESTAMP() WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);}

    public function releaseExpired(PDO $pdo,?int $tenantId=null):int
    {
        $sql='UPDATE stock_reservations SET status="released",released_at=UTC_TIMESTAMP() WHERE status="reserved" AND expires_at<=UTC_TIMESTAMP()';$args=[];if($tenantId){$sql.=' AND tenant_id=?';$args[]=$tenantId;}$s=$pdo->prepare($sql);$s->execute($args);return$s->rowCount();
    }

    public function commitForOrder(PDO $pdo,int $tenantId,int $orderId):void
    {
        foreach($this->requirements($pdo,$tenantId,$orderId,false)as$productId=>$qty){
            $p=$pdo->prepare('SELECT track_stock FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);if((int)$p->fetchColumn()!==1)continue;$key=$this->commitKey($orderId,$productId);
            $check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn())continue;
            $update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND track_stock=1 AND stock_qty>=?');$update->execute([$qty,$productId,$tenantId,$qty]);if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para concluir o pedido.');
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"out",?,?)')->execute([$tenantId,$productId,$orderId,$qty,$key]);
            $pdo->prepare('UPDATE stock_reservations SET status="consumed",consumed_at=UTC_TIMESTAMP() WHERE tenant_id=? AND order_id=? AND product_id=? AND status="reserved"')->execute([$tenantId,$orderId,$productId]);
        }
    }

    public function reverseForOrder(PDO $pdo,int $tenantId,int $orderId):void
    {
        $remaining=$this->requirements($pdo,$tenantId,$orderId,true);$s=$pdo->prepare('SELECT product_id,quantity FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out" AND idempotency_key LIKE ?');$s->execute([$tenantId,$orderId,'order:'.$orderId.':product:%:commit']);
        foreach($s->fetchAll()as$row){$productId=(int)$row['product_id'];$committed=(float)$row['quantity'];$qty=min($committed,max(0,(float)($remaining[$productId]??0)));$key=$this->reversalKey($orderId,$productId);$check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn()||$qty<=0.000001)continue;$pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=?')->execute([$qty,$productId,$tenantId]);$pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"reversal",?,?)')->execute([$tenantId,$productId,$orderId,$qty,$key]);}
        $this->releaseReservations($pdo,$tenantId,$orderId);
    }

    private function requirements(PDO $pdo,int $tenantId,int $orderId,bool $remainingOnly):array
    {
        $s=$pdo->prepare('SELECT product_id,quantity,fulfilled_quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL');$s->execute([$orderId]);$out=[];$catalog=new CatalogOptionsService();
        foreach($s->fetchAll()as$item){$qty=(float)$item['quantity'];if($remainingOnly)$qty=max(0,$qty-(float)$item['fulfilled_quantity']);if($qty<=0)continue;foreach($catalog->stockRequirements($pdo,$tenantId,(int)$item['product_id'],$qty)as$id=>$required)$out[$id]=($out[$id]??0)+$required;}
        return$out;
    }

    private function commitKey(int $orderId,int $productId):string{return 'order:'.$orderId.':product:'.$productId.':commit';}
    private function reversalKey(int $orderId,int $productId):string{return 'order:'.$orderId.':product:'.$productId.':reversal';}
}
