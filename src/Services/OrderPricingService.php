<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderPricingService
{
    public function snapshot(int $tenantId,int $orderId):array
    {
        return Database::transaction(fn(PDO $pdo):array=>$this->recalculate($pdo,$tenantId,$orderId));
    }

    public function recalculate(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
        $q->execute([$orderId,$tenantId]);
        $order=$q->fetch();
        if(!$order)throw new RuntimeException('Pedido não encontrado.');

        $items=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        $itemSubtotal=(int)$items->fetchColumn();
        $subtotal=$itemSubtotal>0?$itemSubtotal:(int)$order['subtotal_cents'];

        $adj=$pdo->prepare('SELECT direction,COALESCE(SUM(amount_cents),0) total FROM order_price_adjustments WHERE tenant_id=? AND order_id=? AND active=1 GROUP BY direction');
        $adj->execute([$tenantId,$orderId]);
        $discountAdjustments=0;$surcharge=0;
        foreach($adj->fetchAll() as$row){if($row['direction']==='discount')$discountAdjustments=(int)$row['total'];elseif($row['direction']==='surcharge')$surcharge=(int)$row['total'];}

        $storedManual=(int)($order['manual_discount_cents']??0);
        $legacyManual=max(0,$storedManual-$discountAdjustments);
        $manual=$legacyManual+$discountAdjustments;
        $coupon=(int)($order['coupon_discount_cents']??0);
        if($coupon===0&&!empty($order['coupon_id']))$coupon=max(0,(int)$order['discount_cents']-$storedManual);
        $discount=min($subtotal,max(0,$coupon+$manual));
        $delivery=max(0,(int)$order['delivery_fee_cents']);
        $total=max(0,$subtotal-$discount+$delivery+$surcharge);

        $pdo->prepare('UPDATE orders SET subtotal_cents=?,coupon_discount_cents=?,manual_discount_cents=?,discount_cents=?,surcharge_cents=?,total_cents=? WHERE id=? AND tenant_id=?')
            ->execute([$subtotal,$coupon,$manual,$discount,$surcharge,$total,$orderId,$tenantId]);

        return ['order_id'=>$orderId,'subtotal_cents'=>$subtotal,'coupon_discount_cents'=>$coupon,'manual_discount_cents'=>$manual,'discount_cents'=>$discount,'delivery_fee_cents'=>$delivery,'surcharge_cents'=>$surcharge,'total_cents'=>$total];
    }

    public function addAdjustment(PDO $pdo,int $tenantId,int $orderId,string $direction,string $type,string $label,int $amountCents,int $percentBps,string $idempotencyKey,?int $requestId=null,?int $authorizedBy=null,array $metadata=[]):array
    {
        if(!in_array($direction,['discount','surcharge'],true))throw new RuntimeException('Direção de ajuste inválida.');
        if($amountCents<=0)throw new RuntimeException('Valor do ajuste inválido.');
        $existing=$pdo->prepare('SELECT id FROM order_price_adjustments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotencyKey]);
        if(!$existing->fetchColumn()){
            $pdo->prepare('INSERT INTO order_price_adjustments (tenant_id,order_id,direction,adjustment_type,label,amount_cents,percent_bps,request_id,authorized_by,active,idempotency_key,metadata) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)')
                ->execute([$tenantId,$orderId,$direction,mb_substr($type,0,40),mb_substr($label,0,160),$amountCents,max(0,$percentBps),$requestId,$authorizedBy,$idempotencyKey,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
        }
        return $this->recalculate($pdo,$tenantId,$orderId);
    }

    public function adjustments(int $tenantId,int $orderId):array
    {
        $q=Database::connection()->prepare('SELECT a.*,u.name authorized_by_name FROM order_price_adjustments a LEFT JOIN users u ON u.id=a.authorized_by WHERE a.tenant_id=? AND a.order_id=? AND a.active=1 ORDER BY a.id');
        $q->execute([$tenantId,$orderId]);return $q->fetchAll();
    }
}
