<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class StockReservationService
{
    /**
     * Reserva estoque reduzindo o saldo disponível imediatamente.
     * A saída definitiva só entra em stock_movements quando o pagamento é confirmado.
     */
    public function reserve(PDO $pdo,int $tenantId,int $orderId,array $items,?string $expiresAt=null):void
    {
        foreach($items as $item){
            $productId=(int)($item['product_id']??$item['id']??0);
            $qty=(float)($item['quantity']??$item['qty']??0);
            if($productId<1||!is_finite($qty)||$qty<=0)continue;

            $p=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));
            $p->execute([$productId,$tenantId]);$product=$p->fetch();
            if(!$product)throw new RuntimeException('Produto não encontrado durante reserva de estoque.');
            if(!(int)$product['track_stock'])continue;

            $existing=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM stock_reservations WHERE tenant_id=? AND order_id=? AND product_id=? FOR UPDATE'));
            $existing->execute([$tenantId,$orderId,$productId]);$row=$existing->fetch();
            if($row){
                if($row['status']==='reserved'||$row['status']==='consumed')continue;
                throw new RuntimeException('A reserva de estoque deste pedido já foi liberada.');
            }

            $update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?');
            $update->execute([$qty,$productId,$tenantId,$qty]);
            if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para '.$product['name'].'.');

            $pdo->prepare('INSERT INTO stock_reservations (tenant_id,order_id,product_id,quantity,status,expires_at) VALUES (?,?,?, ?,"reserved",?)')->execute([$tenantId,$orderId,$productId,$qty,$expiresAt]);
        }
    }

    public function holdForPayment(int $tenantId,int $orderId):void
    {
        Database::connection()->prepare('UPDATE stock_reservations SET expires_at=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);
    }

    public function rearmAfterPaymentFailure(int $tenantId,int $orderId,int $minutes=30):void
    {
        $expires=(new \DateTimeImmutable('+'.max(5,$minutes).' minutes'))->format('Y-m-d H:i:s');
        Database::connection()->prepare('UPDATE stock_reservations SET expires_at=? WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$expires,$tenantId,$orderId]);
    }

    public function consumeForPayment(PDO $pdo,int $tenantId,int $orderId,int $paymentId):void
    {
        $items=$pdo->prepare('SELECT oi.product_id,oi.quantity,p.track_stock,p.name FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
        $items->execute([$orderId]);
        foreach($items->fetchAll() as $item){
            if(!$item['product_id']||!(int)$item['track_stock'])continue;
            $productId=(int)$item['product_id'];$qty=(float)$item['quantity'];$key='payment:'.$paymentId.':product:'.$productId;
            $check=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=?');$check->execute([$tenantId,$key]);if($check->fetchColumn())continue;

            $r=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM stock_reservations WHERE tenant_id=? AND order_id=? AND product_id=? FOR UPDATE'));
            $r->execute([$tenantId,$orderId,$productId]);$reservation=$r->fetch();
            if($reservation&&in_array($reservation['status'],['reserved','consumed'],true)){
                if($reservation['status']==='reserved')$pdo->prepare('UPDATE stock_reservations SET status="consumed",expires_at=NULL WHERE id=?')->execute([$reservation['id']]);
                $movementQty=(float)$reservation['quantity'];
            }else{
                $update=$pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?');
                $update->execute([$qty,$productId,$tenantId,$qty]);
                if($update->rowCount()!==1)throw new RuntimeException('Estoque insuficiente para confirmar o pagamento de '.$item['name'].'.');
                $movementQty=$qty;
            }
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"out",?,?)')->execute([$tenantId,$productId,$orderId,$movementQty,$key]);
        }
    }

    public function release(PDO $pdo,int $tenantId,int $orderId):int
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM stock_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE'));
        $s->execute([$tenantId,$orderId]);$rows=$s->fetchAll();$released=0;
        foreach($rows as $row){
            $pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=?')->execute([$row['quantity'],$row['product_id'],$tenantId]);
            $pdo->prepare('UPDATE stock_reservations SET status="released",expires_at=NULL WHERE id=? AND status="reserved"')->execute([$row['id']]);
            $released++;
        }
        return $released;
    }

    /**
     * Expira somente pedidos públicos ainda não aceitos pela operação.
     * Ao mudar de pending/draft para confirmed a reserva passa a ser mantida até pagamento/cancelamento.
     */
    public function releaseExpired():int
    {
        return Database::transaction(function(PDO $pdo):int{
            $sql='SELECT sr.tenant_id,sr.order_id FROM stock_reservations sr JOIN orders o ON o.id=sr.order_id WHERE sr.status="reserved" AND sr.expires_at IS NOT NULL AND sr.expires_at<CURRENT_TIMESTAMP AND o.payment_status IN ("unpaid","failed") AND o.status IN ("draft","pending") GROUP BY sr.tenant_id,sr.order_id';
            $s=$pdo->query(Database::portableSql($pdo,$sql));$groups=$s->fetchAll();$total=0;
            foreach($groups as $g){
                $tenantId=(int)$g['tenant_id'];$orderId=(int)$g['order_id'];$total+=$this->release($pdo,$tenantId,$orderId);
                $pdo->prepare('UPDATE orders SET status="cancelled",payment_status=CASE WHEN payment_status="unpaid" THEN "failed" ELSE payment_status END WHERE id=? AND tenant_id=? AND payment_status IN ("unpaid","failed") AND status IN ("draft","pending")')->execute([$orderId,$tenantId]);
            }
            return $total;
        });
    }
}
