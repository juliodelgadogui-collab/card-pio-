<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class LoyaltyPointsService
{
    public const DEFAULT_EARN_AMOUNT_CENTS = 100;
    public const DEFAULT_REDEEM_POINTS = 100;
    public const DEFAULT_REDEEM_VALUE_CENTS = 500;
    public const DEFAULT_MIN_REDEEM_POINTS = 100;
    public const DEFAULT_MAX_REDEEM_PERCENT = 30;

    public function config(int $tenantId, ?PDO $pdo = null): array
    {
        if ($tenantId < 1) throw new RuntimeException('Empresa inválida.');
        $pdo ??= Database::connection();
        $stmt = $pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');
        $stmt->execute([$tenantId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) throw new RuntimeException('Empresa não encontrada.');
        $settings = json_decode((string)$raw, true);
        return $this->normalizeConfig(is_array($settings) ? $settings : []);
    }

    public function normalizeConfig(array $settings): array
    {
        $enabled = array_key_exists('points_enabled', $settings) ? (bool)$settings['points_enabled'] : true;
        $earnAmount = max(1, min(10000000, (int)($settings['points_earn_amount_cents'] ?? self::DEFAULT_EARN_AMOUNT_CENTS)));
        $redeemPoints = max(1, min(1000000, (int)($settings['points_redeem_points'] ?? self::DEFAULT_REDEEM_POINTS)));
        $redeemValue = max(1, min(10000000, (int)($settings['points_redeem_value_cents'] ?? self::DEFAULT_REDEEM_VALUE_CENTS)));
        $minimum = max($redeemPoints, min(10000000, (int)($settings['points_min_redeem_points'] ?? self::DEFAULT_MIN_REDEEM_POINTS)));
        if ($minimum % $redeemPoints !== 0) $minimum = (int)(ceil($minimum / $redeemPoints) * $redeemPoints);
        $maxPercent = max(1, min(90, (int)($settings['points_max_redeem_percent'] ?? self::DEFAULT_MAX_REDEEM_PERCENT)));
        return [
            'enabled' => $enabled,
            'earn_amount_cents' => $earnAmount,
            'redeem_points' => $redeemPoints,
            'redeem_value_cents' => $redeemValue,
            'min_redeem_points' => $minimum,
            'max_redeem_percent' => $maxPercent,
        ];
    }

    public function pointsForPaidAmount(int $tenantId, int $amountCents, ?PDO $pdo = null): int
    {
        if ($amountCents <= 0) return 0;
        $config = $this->config($tenantId, $pdo);
        if (!$config['enabled']) return 0;
        return intdiv($amountCents, (int)$config['earn_amount_cents']);
    }

    public function summary(int $tenantId, int $customerId, ?PDO $pdo = null): array
    {
        if ($tenantId < 1 || $customerId < 1) throw new RuntimeException('Cliente inválido.');
        $pdo ??= Database::connection();
        $stmt = $pdo->prepare('SELECT id,name,points FROM customers WHERE id=? AND tenant_id=? LIMIT 1');
        $stmt->execute([$customerId, $tenantId]);
        $customer = $stmt->fetch();
        if (!$customer) throw new RuntimeException('Cliente não encontrado.');
        $reserved = $this->reservedPoints($pdo, $tenantId, $customerId);
        $balance = (int)$customer['points'];
        return [
            'customer_id' => $customerId,
            'name' => (string)$customer['name'],
            'balance' => $balance,
            'reserved' => $reserved,
            'available' => max(0, $balance - $reserved),
        ];
    }

    public function applyToExistingOrder(int $tenantId, int $customerId, int $orderId, int $requestedPoints): array
    {
        if ($tenantId < 1 || $customerId < 1 || $orderId < 1) throw new RuntimeException('Cliente ou pedido inválido.');
        return Database::transaction(function (PDO $pdo) use ($tenantId, $customerId, $orderId, $requestedPoints): array {
            $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$orderId, $tenantId]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Pedido não encontrado.');
            if ((int)($order['customer_id'] ?? 0) !== $customerId) throw new RuntimeException('Este pedido não pertence ao cliente selecionado.');
            if (in_array((string)$order['status'], ['completed','cancelled'], true)) throw new RuntimeException('Pedido encerrado não aceita resgate de pontos.');
            if (!in_array((string)$order['payment_status'], ['unpaid','failed'], true)) throw new RuntimeException('Aguarde ou encerre a cobrança antes de alterar os pontos deste pedido.');

            $paid = $pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');
            $paid->execute([$tenantId, $orderId]);
            if ((int)$paid->fetchColumn() > 0) throw new RuntimeException('Pedido com valor recebido não pode alterar pontos.');
            $active = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');
            $active->execute([$tenantId, $orderId]);
            if ((int)$active->fetchColumn() > 0) throw new RuntimeException('Há uma cobrança em processamento. Aguarde ou encerre a cobrança antes de usar pontos.');

            $existing = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM customer_points_reservations WHERE tenant_id=? AND order_id=? FOR UPDATE'));
            $existing->execute([$tenantId, $orderId]);
            $reservation = $existing->fetch();
            if ($reservation && $reservation['status'] === 'reserved') {
                if ((int)$reservation['points'] === $requestedPoints) return $this->reservationResult($reservation, (int)$order['total_cents']);
                throw new RuntimeException('Este pedido já possui pontos reservados. Remova o resgate atual antes de aplicar outro valor.');
            }
            if ($reservation && $reservation['status'] === 'redeemed') throw new RuntimeException('Os pontos deste pedido já foram utilizados.');

            $quote = $this->quote($pdo, $tenantId, $customerId, (int)$order['total_cents'], $requestedPoints);
            $discount = (int)$quote['discount_cents'];
            $newTotal = (int)$order['total_cents'] - $discount;
            if ($newTotal < 1) throw new RuntimeException('O resgate não pode quitar integralmente o pedido.');

            if ($reservation) {
                $pdo->prepare('UPDATE customer_points_reservations SET customer_id=?,points=?,discount_cents=?,status="reserved",redeemed_at=NULL,released_at=NULL,restored_at=NULL WHERE id=?')
                    ->execute([$customerId, $requestedPoints, $discount, $reservation['id']]);
                $reservationId = (int)$reservation['id'];
            } else {
                $pdo->prepare('INSERT INTO customer_points_reservations (tenant_id,customer_id,order_id,points,discount_cents,status) VALUES (?,?,?,?,?,"reserved")')
                    ->execute([$tenantId, $customerId, $orderId, $requestedPoints, $discount]);
                $reservationId = (int)$pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE orders SET discount_cents=discount_cents+?,total_cents=total_cents-?,payment_status=CASE WHEN payment_status="failed" THEN "unpaid" ELSE payment_status END WHERE id=? AND tenant_id=?')
                ->execute([$discount, $discount, $orderId, $tenantId]);

            return [
                'reservation_id' => $reservationId,
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'points' => $requestedPoints,
                'discount_cents' => $discount,
                'total_cents' => $newTotal,
                'available_after_reservation' => max(0, (int)$quote['available_before'] - $requestedPoints),
                'status' => 'reserved',
            ];
        });
    }

    public function releaseForOrder(PDO $pdo, int $tenantId, int $orderId, bool $restoreOrderTotal = false): int
    {
        $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM customer_points_reservations WHERE tenant_id=? AND order_id=? FOR UPDATE'));
        $stmt->execute([$tenantId, $orderId]);
        $reservation = $stmt->fetch();
        if (!$reservation || $reservation['status'] !== 'reserved') return 0;
        if ($restoreOrderTotal) {
            $order = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,payment_status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $order->execute([$orderId, $tenantId]);
            $orderRow = $order->fetch();
            if (!$orderRow) throw new RuntimeException('Pedido não encontrado.');
            if (!in_array((string)$orderRow['payment_status'], ['unpaid','failed'], true)) throw new RuntimeException('Não é possível remover pontos durante uma cobrança ou após o pagamento.');
            $active = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized","paid")');
            $active->execute([$tenantId, $orderId]);
            if ((int)$active->fetchColumn() > 0) throw new RuntimeException('Encerre a cobrança antes de remover os pontos.');
            $discount = (int)$reservation['discount_cents'];
            $pdo->prepare(Database::portableSql($pdo, 'UPDATE orders SET discount_cents=GREATEST(0,discount_cents-?),total_cents=total_cents+?,payment_status=CASE WHEN payment_status="failed" THEN "unpaid" ELSE payment_status END WHERE id=? AND tenant_id=?'))
                ->execute([$discount, $discount, $orderId, $tenantId]);
        }
        $pdo->prepare('UPDATE customer_points_reservations SET status="released",released_at=CURRENT_TIMESTAMP WHERE id=? AND status="reserved"')->execute([$reservation['id']]);
        return 1;
    }

    public function settleForOrder(PDO $pdo, int $tenantId, int $orderId, string $settlementKey): int
    {
        $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM customer_points_reservations WHERE tenant_id=? AND order_id=? FOR UPDATE'));
        $stmt->execute([$tenantId, $orderId]);
        $reservation = $stmt->fetch();
        if (!$reservation || $reservation['status'] === 'released' || $reservation['status'] === 'restored') return 0;
        if ($reservation['status'] === 'redeemed') return (int)$reservation['points'];

        $points = (int)$reservation['points'];
        $key = trim($settlementKey).':loyalty-redeem';
        $insert = $pdo->prepare(Database::portableSql($pdo, 'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?, ?,"redeem",?)'));
        $insert->execute([$tenantId, $reservation['customer_id'], $orderId, -$points, $key]);
        if ($insert->rowCount() === 1) {
            $pdo->prepare('UPDATE customers SET points=points-? WHERE id=? AND tenant_id=?')->execute([$points, $reservation['customer_id'], $tenantId]);
        }
        $pdo->prepare('UPDATE customer_points_reservations SET status="redeemed",redeemed_at=CURRENT_TIMESTAMP WHERE id=? AND status="reserved"')->execute([$reservation['id']]);
        return $points;
    }

    public function earnForOrder(PDO $pdo, int $tenantId, array $order, int $orderId, string $settlementKey): int
    {
        $customerId = (int)($order['customer_id'] ?? 0);
        if ($customerId < 1) return 0;
        $points = $this->pointsForPaidAmount($tenantId, (int)($order['total_cents'] ?? 0), $pdo);
        if ($points < 1) return 0;
        $key = trim($settlementKey).':loyalty-earn';
        $insert = $pdo->prepare(Database::portableSql($pdo, 'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?,?,"earn",?)'));
        $insert->execute([$tenantId, $customerId, $orderId, $points, $key]);
        if ($insert->rowCount() === 1) $pdo->prepare('UPDATE customers SET points=points+? WHERE id=? AND tenant_id=?')->execute([$points, $customerId, $tenantId]);
        return $points;
    }

    public function restoreRedeemedForRefund(PDO $pdo, int $tenantId, int $orderId, int $refundId): int
    {
        $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM customer_points_reservations WHERE tenant_id=? AND order_id=? FOR UPDATE'));
        $stmt->execute([$tenantId, $orderId]);
        $reservation = $stmt->fetch();
        if (!$reservation || $reservation['status'] === 'released') return 0;
        if ($reservation['status'] === 'restored') return (int)$reservation['points'];
        if ($reservation['status'] !== 'redeemed') return 0;

        $points = (int)$reservation['points'];
        $key = 'refund:'.$refundId.':loyalty-redeem';
        $insert = $pdo->prepare(Database::portableSql($pdo, 'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?,?,"reversal",?)'));
        $insert->execute([$tenantId, $reservation['customer_id'], $orderId, $points, $key]);
        if ($insert->rowCount() === 1) $pdo->prepare('UPDATE customers SET points=points+? WHERE id=? AND tenant_id=?')->execute([$points, $reservation['customer_id'], $tenantId]);
        $pdo->prepare('UPDATE customer_points_reservations SET status="restored",restored_at=CURRENT_TIMESTAMP WHERE id=? AND status="redeemed"')->execute([$reservation['id']]);
        return $points;
    }

    public function discountForOrder(PDO $pdo, int $tenantId, int $orderId): int
    {
        $stmt = $pdo->prepare('SELECT discount_cents FROM customer_points_reservations WHERE tenant_id=? AND order_id=? AND status IN ("reserved","redeemed","restored") LIMIT 1');
        $stmt->execute([$tenantId, $orderId]);
        $value = $stmt->fetchColumn();
        return $value === false ? 0 : max(0, (int)$value);
    }

    private function quote(PDO $pdo, int $tenantId, int $customerId, int $orderTotalCents, int $requestedPoints): array
    {
        $config = $this->config($tenantId, $pdo);
        if (!$config['enabled']) throw new RuntimeException('O programa de pontos está desativado para esta empresa.');
        if ($requestedPoints < (int)$config['min_redeem_points']) throw new RuntimeException('Mínimo para resgate: '.$config['min_redeem_points'].' pontos.');
        if ($requestedPoints % (int)$config['redeem_points'] !== 0) throw new RuntimeException('Os pontos devem ser usados em blocos de '.$config['redeem_points'].'.');

        $customer = $pdo->prepare(Database::portableSql($pdo, 'SELECT points FROM customers WHERE id=? AND tenant_id=? FOR UPDATE'));
        $customer->execute([$customerId, $tenantId]);
        $balance = $customer->fetchColumn();
        if ($balance === false) throw new RuntimeException('Cliente não encontrado.');
        $available = max(0, (int)$balance - $this->reservedPoints($pdo, $tenantId, $customerId));
        if ($requestedPoints > $available) throw new RuntimeException('Saldo de pontos disponível insuficiente.');

        $blocks = intdiv($requestedPoints, (int)$config['redeem_points']);
        $discount = $blocks * (int)$config['redeem_value_cents'];
        $maxDiscount = (int)floor(max(0, $orderTotalCents) * ((int)$config['max_redeem_percent'] / 100));
        if ($discount > $maxDiscount) throw new RuntimeException('Neste pedido os pontos podem cobrir no máximo '.$config['max_redeem_percent'].'% do valor.');
        if ($discount < 1 || $discount >= $orderTotalCents) throw new RuntimeException('Quantidade de pontos inválida para o valor deste pedido.');
        return ['points' => $requestedPoints, 'discount_cents' => $discount, 'available_before' => $available, 'config' => $config];
    }

    private function reservedPoints(PDO $pdo, int $tenantId, int $customerId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM customer_points_reservations WHERE tenant_id=? AND customer_id=? AND status="reserved"');
        $stmt->execute([$tenantId, $customerId]);
        return max(0, (int)$stmt->fetchColumn());
    }

    private function reservationResult(array $reservation, int $totalCents): array
    {
        return [
            'reservation_id' => (int)$reservation['id'],
            'order_id' => (int)$reservation['order_id'],
            'customer_id' => (int)$reservation['customer_id'],
            'points' => (int)$reservation['points'],
            'discount_cents' => (int)$reservation['discount_cents'],
            'total_cents' => $totalCents,
            'status' => (string)$reservation['status'],
        ];
    }
}
