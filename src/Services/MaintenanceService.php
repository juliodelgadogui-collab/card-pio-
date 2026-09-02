<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class MaintenanceService
{
    public function releaseExpiredReservations():array
    {
        return Database::transaction(function(PDO $pdo){
            $releasedTickets=0;$releasedCoupons=0;$cancelledOrders=0;

            $groups=$pdo->query('SELECT t.batch_id,COUNT(*) qty FROM tickets t JOIN orders o ON o.id=t.order_id WHERE t.status="reserved" AND t.reserved_until IS NOT NULL AND t.reserved_until<NOW() AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ("created","pending","authorized")) GROUP BY t.batch_id FOR UPDATE')->fetchAll();
            foreach($groups as $g){
                $qty=(int)$g['qty'];
                $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?) WHERE id=?')->execute([$qty,$g['batch_id']]);
                $releasedTickets+=$qty;
            }
            $pdo->exec('UPDATE tickets t JOIN orders o ON o.id=t.order_id SET t.status="cancelled" WHERE t.status="reserved" AND t.reserved_until IS NOT NULL AND t.reserved_until<NOW() AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ("created","pending","authorized"))');

            $couponRows=$pdo->query('SELECT cr.id,cr.coupon_id FROM coupon_reservations cr JOIN orders o ON o.id=cr.order_id WHERE cr.status="reserved" AND cr.expires_at<NOW() AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ("created","pending","authorized")) FOR UPDATE')->fetchAll();
            foreach($couponRows as $row){
                $pdo->prepare('UPDATE coupon_reservations SET status="released" WHERE id=?')->execute([$row['id']]);
                $pdo->prepare('UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1) WHERE id=?')->execute([$row['coupon_id']]);
                $releasedCoupons++;
            }

            $s=$pdo->prepare('UPDATE orders o SET o.status="cancelled",o.payment_status=IF(o.payment_status="unpaid","failed",o.payment_status) WHERE o.channel="event" AND o.payment_status<>"paid" AND o.status NOT IN ("cancelled","completed") AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.order_id=o.id AND t.status="reserved") AND EXISTS (SELECT 1 FROM tickets t2 WHERE t2.order_id=o.id) AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ("created","pending","authorized"))');
            $s->execute();$cancelledOrders+=$s->rowCount();

            $s=$pdo->prepare('UPDATE orders o SET o.status="cancelled",o.payment_status=IF(o.payment_status="unpaid","failed",o.payment_status) WHERE o.channel IN ("delivery","pickup") AND o.expires_at IS NOT NULL AND o.expires_at<NOW() AND o.payment_status<>"paid" AND o.status NOT IN ("cancelled","completed") AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.status IN ("created","pending","authorized"))');
            $s->execute();$cancelledOrders+=$s->rowCount();

            $cleanup=$pdo->prepare('DELETE FROM login_throttles WHERE updated_at<DATE_SUB(NOW(),INTERVAL 2 DAY) AND (locked_until IS NULL OR locked_until<NOW())');
            $cleanup->execute();

            return [
                'tickets_released'=>$releasedTickets,
                'coupons_released'=>$releasedCoupons,
                'orders_cancelled'=>$cancelledOrders,
                'login_throttles_cleaned'=>$cleanup->rowCount(),
            ];
        });
    }
}
