<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PaymentService
{
    public function create(int $orderId, string $provider, string $idempotencyKey): array
    {
        Auth::requirePermission('payments.manage');
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa inválida.');

        return Database::transaction(function (PDO $pdo) use ($tenantId, $orderId, $provider, $idempotencyKey) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $stmt->execute([$orderId, $tenantId]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Pedido não encontrado.');
            if (in_array($order['status'], ['cancelled','completed'], true)) {
                throw new RuntimeException('Pedido cancelado ou finalizado não pode receber nova cobrança.');
            }

            $existing = $pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$tenantId, $idempotencyKey]);
            if ($payment = $existing->fetch()) return $payment;

            $stmt = $pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")');
            $stmt->execute([$tenantId, $orderId, $provider, $idempotencyKey, $order['total_cents']]);
            $id = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);
            Auth::audit('payment.created', 'payment', (string)$id, ['order_id' => $orderId, 'provider' => $provider]);
            return ['id' => $id, 'order_id' => $orderId, 'amount_cents' => (int)$order['total_cents'], 'status' => 'created'];
        });
    }

    public function confirmVerified(array $verified): void
    {
        // This method must only receive data verified server-to-server with the gateway.
        $required = ['tenant_id','order_id','provider','provider_payment_id','amount_cents','currency'];
        foreach ($required as $key) if (!array_key_exists($key, $verified)) throw new RuntimeException("Campo ausente: {$key}");

        Database::transaction(function (PDO $pdo) use ($verified) {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $stmt->execute([(int)$verified['order_id'], (int)$verified['tenant_id']]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Pedido inválido.');
            if (in_array($order['status'], ['cancelled'], true)) throw new RuntimeException('Pedido cancelado.');
            if ((int)$order['total_cents'] !== (int)$verified['amount_cents']) throw new RuntimeException('Valor divergente.');
            if (strtoupper((string)$verified['currency']) !== 'BRL') throw new RuntimeException('Moeda divergente.');

            $find = $pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $find->execute([(int)$verified['tenant_id'], (int)$verified['order_id'], (string)$verified['provider']]);
            $payment = $find->fetch();
            if (!$payment) throw new RuntimeException('Cobrança local não encontrada.');
            if ($payment['status'] === 'paid') return;
            if ((int)$payment['amount_cents'] !== (int)$verified['amount_cents']) throw new RuntimeException('Cobrança divergente.');

            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="paid",verified_at=NOW(),raw_payload=? WHERE id=?')
                ->execute([(string)$verified['provider_payment_id'], json_encode($verified, JSON_UNESCAPED_UNICODE), $payment['id']]);
            $pdo->prepare('UPDATE orders SET payment_status="paid", status=IF(status="pending","confirmed",status) WHERE id=?')->execute([$order['id']]);

            // Stock is committed only once, inside the same transaction, protected by idempotency.
            $items = $pdo->prepare('SELECT oi.product_id, oi.quantity, p.track_stock FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
            $items->execute([$order['id']]);
            foreach ($items->fetchAll() as $item) {
                if (!$item['product_id'] || !(int)$item['track_stock']) continue;
                $key = 'payment:' . $payment['id'] . ':product:' . $item['product_id'];
                $check = $pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=?');
                $check->execute([$order['tenant_id'], $key]);
                if ($check->fetch()) continue;
                $qty = (float)$item['quantity'];
                $pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?')
                    ->execute([$qty, $item['product_id'], $order['tenant_id'], $qty]);
                if ($pdo->lastInsertId() === '0' && $pdo->query('SELECT ROW_COUNT()')->fetchColumn() == 0) throw new RuntimeException('Estoque insuficiente.');
                $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"out",?,?)')
                    ->execute([$order['tenant_id'], $item['product_id'], $order['id'], $qty, $key]);
            }
        });
    }
}
