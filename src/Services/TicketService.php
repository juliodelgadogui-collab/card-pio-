<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class TicketService
{
    public function reservePublic(int $eventId, int $batchId, int $quantity, array $buyer, ?string $couponCode = null, ?string $promoterCode = null): array
    {
        $quantity = max(1, min(10, $quantity));
        return Database::transaction(function (PDO $pdo) use ($eventId, $batchId, $quantity, $buyer, $couponCode, $promoterCode): array {
            $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT e.*,t.status tenant_status FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.id=? AND e.status="published" FOR UPDATE'));
            $stmt->execute([$eventId]);
            $event = $stmt->fetch();
            if (!$event || $event['tenant_status'] !== 'active') throw new RuntimeException('Evento indisponível.');

            $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM ticket_batches WHERE id=? AND event_id=? AND active=1 FOR UPDATE'));
            $stmt->execute([$batchId, $eventId]);
            $batch = $stmt->fetch();
            if (!$batch) throw new RuntimeException('Lote indisponível.');

            $now = new \DateTimeImmutable('now');
            if ($batch['sales_start'] && $now < new \DateTimeImmutable($batch['sales_start'])) throw new RuntimeException('Venda deste lote ainda não começou.');
            if ($batch['sales_end'] && $now > new \DateTimeImmutable($batch['sales_end'])) throw new RuntimeException('Venda deste lote foi encerrada.');
            $available = (int)$batch['quantity_total'] - (int)$batch['quantity_sold'] - (int)$batch['quantity_reserved'];
            if ($available < $quantity) throw new RuntimeException('Quantidade de ingressos indisponível.');

            $name = trim((string)($buyer['name'] ?? ''));
            $email = mb_strtolower(trim((string)($buyer['email'] ?? '')));
            $phone = trim((string)($buyer['phone'] ?? ''));
            if ($name === '' || (!filter_var($email, FILTER_VALIDATE_EMAIL) && $phone === '')) throw new RuntimeException('Informe nome e e-mail ou telefone válido.');

            $customerId = null;
            if ($email !== '') {
                $s = $pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND email=? LIMIT 1');
                $s->execute([$event['tenant_id'], $email]);
                $customerId = $s->fetchColumn() ?: null;
            }
            if (!$customerId && $phone !== '') {
                $s = $pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? LIMIT 1');
                $s->execute([$event['tenant_id'], $phone]);
                $customerId = $s->fetchColumn() ?: null;
            }
            if (!$customerId) {
                $s = $pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,?,?)');
                $s->execute([$event['tenant_id'], $name, $phone ?: null, $email ?: null]);
                $customerId = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare('UPDATE customers SET name=?,phone=COALESCE(NULLIF(?,""),phone),email=COALESCE(NULLIF(?,""),email) WHERE id=?')->execute([$name, $phone, $email, $customerId]);
            }

            $subtotal = (int)$batch['price_cents'] * $quantity;
            $discount = 0;
            $couponId = null;
            if ($couponCode) {
                $code = strtoupper(trim($couponCode));
                $c = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM coupons WHERE tenant_id=? AND code=? AND active=1 FOR UPDATE'));
                $c->execute([$event['tenant_id'], $code]);
                $coupon = $c->fetch();
                if (!$coupon) throw new RuntimeException('Cupom inválido.');
                if ($coupon['starts_at'] && $now < new \DateTimeImmutable($coupon['starts_at'])) throw new RuntimeException('Cupom ainda não está válido.');
                if ($coupon['ends_at'] && $now > new \DateTimeImmutable($coupon['ends_at'])) throw new RuntimeException('Cupom expirado.');
                if ($coupon['max_uses'] !== null && ((int)$coupon['uses_count'] + (int)$coupon['reserved_count']) >= (int)$coupon['max_uses']) throw new RuntimeException('Limite do cupom atingido.');
                if ($subtotal < (int)$coupon['min_order_cents']) throw new RuntimeException('Valor mínimo do cupom não atingido.');
                $couponId = (int)$coupon['id'];
                $discount = $coupon['type'] === 'percent'
                    ? (int)round($subtotal * min(100, (int)$coupon['value']) / 100)
                    : min($subtotal, (int)$coupon['value']);
            }

            $promoterId = null;
            if ($promoterCode) {
                $p = $pdo->prepare('SELECT id FROM promoters WHERE tenant_id=? AND code=? AND active=1');
                $p->execute([$event['tenant_id'], strtoupper(trim($promoterCode))]);
                $promoterId = $p->fetchColumn() ?: null;
            }

            $total = max(0, $subtotal - $discount);
            $publicToken = bin2hex(random_bytes(20));
            $stmt = $pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,coupon_id,promoter_id,channel,status,payment_status,subtotal_cents,discount_cents,total_cents,notes) VALUES (?,?,?,?,?,"event","pending","unpaid",?,?,?,?)');
            $stmt->execute([$publicToken, $event['tenant_id'], $customerId, $couponId, $promoterId, $subtotal, $discount, $total, 'Evento #' . $eventId]);
            $orderId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,NULL,?,?,?,?)')->execute([$orderId, 'Ingresso ' . $event['name'] . ' — ' . $batch['name'], $batch['price_cents'], $quantity, $subtotal]);

            $expires = (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
            if ($couponId) {
                $pdo->prepare('INSERT INTO coupon_reservations (tenant_id,coupon_id,order_id,discount_cents,status,expires_at) VALUES (?,?,?,?,"reserved",?)')->execute([$event['tenant_id'], $couponId, $orderId, $discount, $expires]);
                $pdo->prepare('UPDATE coupons SET reserved_count=reserved_count+1 WHERE id=?')->execute([$couponId]);
            }

            $tickets = [];
            for ($i = 0; $i < $quantity; $i++) {
                $code = sprintf('%s-%s-%s', str_pad((string)$eventId, 4, '0', STR_PAD_LEFT), strtoupper(substr(bin2hex(random_bytes(5)), 0, 10)), strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)));
                $qr = bin2hex(random_bytes(32));
                $s = $pdo->prepare('INSERT INTO tickets (tenant_id,event_id,batch_id,order_id,customer_id,code,status,reserved_until,qr_token) VALUES (?,?,?,?,?,?,"reserved",?,?)');
                $s->execute([$event['tenant_id'], $eventId, $batchId, $orderId, $customerId, $code, $expires, $qr]);
                $tickets[] = ['id' => (int)$pdo->lastInsertId(), 'code' => $code, 'qr_token' => $qr];
            }
            $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=quantity_reserved+? WHERE id=?')->execute([$quantity, $batchId]);

            $paid = false;
            if ($total === 0) {
                $pdo->prepare('UPDATE orders SET status="confirmed",payment_status="paid" WHERE id=? AND tenant_id=?')->execute([$orderId, $event['tenant_id']]);
                $pdo->prepare('UPDATE tickets SET status="paid",reserved_until=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$event['tenant_id'], $orderId]);
                $pdo->prepare(Database::portableSql($pdo, 'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?),quantity_sold=quantity_sold+? WHERE id=?'))->execute([$quantity, $quantity, $batchId]);

                if ($couponId) {
                    $pdo->prepare('UPDATE coupon_reservations SET status="redeemed" WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$event['tenant_id'], $orderId]);
                    $pdo->prepare(Database::portableSql($pdo, 'UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1),uses_count=uses_count+1 WHERE id=? AND tenant_id=?'))->execute([$couponId, $event['tenant_id']]);
                    $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)');
                    $pdo->prepare($sql)->execute([$event['tenant_id'], $couponId, $orderId, $customerId ?: null, $discount, 'free-ticket:order:' . $orderId . ':coupon']);
                }
                $expires = null;
                $paid = true;
            }

            return ['order_id' => $orderId, 'public_token' => $publicToken, 'total_cents' => $total, 'expires_at' => $expires, 'paid' => $paid, 'tickets' => $tickets];
        });
    }

    public function checkIn(string $token): array
    {
        Auth::requirePermission('tickets.manage');
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa inválida.');
        $token = $this->normalizeScannedToken($token);

        return Database::transaction(function (PDO $pdo) use ($tenantId, $token): array {
            $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM tickets WHERE tenant_id=? AND (qr_token=? OR code=?) LIMIT 1 FOR UPDATE'));
            $s->execute([$tenantId, $token, $token]);
            $ticket = $s->fetch();
            if (!$ticket) throw new RuntimeException('Ingresso não encontrado.');
            if ($ticket['status'] === 'checked_in') {
                $this->logCheckin($pdo, $tenantId, (int)$ticket['id'], 'duplicate');
                return ['ok' => false, 'result' => 'duplicate', 'ticket' => $ticket];
            }
            if ($ticket['status'] !== 'paid') {
                $this->logCheckin($pdo, $tenantId, (int)$ticket['id'], 'blocked', ['status' => $ticket['status']]);
                return ['ok' => false, 'result' => 'blocked', 'ticket' => $ticket];
            }
            $pdo->prepare('UPDATE tickets SET status="checked_in",checked_in_at=CURRENT_TIMESTAMP,checked_in_by=? WHERE id=?')->execute([Auth::id(), $ticket['id']]);
            $this->logCheckin($pdo, $tenantId, (int)$ticket['id'], 'accepted');
            return ['ok' => true, 'result' => 'accepted', 'ticket' => $ticket];
        });
    }

    public function releaseExpired(): int
    {
        return Database::transaction(function (PDO $pdo): int {
            $s = $pdo->query(Database::portableSql($pdo, 'SELECT batch_id,COUNT(*) qty FROM tickets WHERE status="reserved" AND reserved_until IS NOT NULL AND reserved_until<CURRENT_TIMESTAMP GROUP BY batch_id FOR UPDATE'));
            $groups = $s->fetchAll();
            $total = 0;
            foreach ($groups as $g) {
                $pdo->prepare(Database::portableSql($pdo, 'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?) WHERE id=?'))->execute([(int)$g['qty'], $g['batch_id']]);
                $total += (int)$g['qty'];
            }

            $couponRows = $pdo->query(Database::portableSql($pdo, 'SELECT id,coupon_id FROM coupon_reservations WHERE status="reserved" AND expires_at<CURRENT_TIMESTAMP FOR UPDATE'))->fetchAll();
            foreach ($couponRows as $row) {
                $pdo->prepare('UPDATE coupon_reservations SET status="released" WHERE id=?')->execute([$row['id']]);
                $pdo->prepare(Database::portableSql($pdo, 'UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1) WHERE id=?'))->execute([$row['coupon_id']]);
            }

            $pdo->exec('UPDATE tickets SET status="cancelled" WHERE status="reserved" AND reserved_until IS NOT NULL AND reserved_until<CURRENT_TIMESTAMP');
            $pdo->exec('UPDATE orders SET status="cancelled",payment_status=CASE WHEN payment_status="unpaid" THEN "failed" ELSE payment_status END WHERE channel="event" AND payment_status<>"paid" AND NOT EXISTS (SELECT 1 FROM tickets t WHERE t.order_id=orders.id AND t.status="reserved") AND EXISTS (SELECT 1 FROM tickets t2 WHERE t2.order_id=orders.id)');
            return $total;
        });
    }

    private function normalizeScannedToken(string $value): string
    {
        $value = trim($value);
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            if (isset($parts['query'])) {
                parse_str($parts['query'], $query);
                if (!empty($query['t'])) return trim((string)$query['t']);
            }
        }
        return $value;
    }

    private function logCheckin(PDO $pdo, int $tenantId, int $ticketId, string $result, array $metadata = []): void
    {
        $pdo->prepare('INSERT INTO ticket_checkin_logs (tenant_id,ticket_id,user_id,result,metadata) VALUES (?,?,?,?,?)')->execute([$tenantId, $ticketId, Auth::id(), $result, $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null]);
    }
}
