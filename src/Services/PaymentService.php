<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PaymentService
{
    private const PROVIDERS = ['stripe', 'pagbank', 'mercadopago', 'manual'];

    public function create(int $orderId, string $provider, string $idempotencyKey): array
    {
        Auth::requirePermission('payments.manage');
        $tenantId = Auth::tenantId();
        $provider = strtolower(trim($provider));
        if (!$tenantId || !in_array($provider, self::PROVIDERS, true)) throw new RuntimeException('Empresa ou provedor inválido.');
        if (strlen($idempotencyKey) < 12) throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function (PDO $pdo) use ($tenantId, $orderId, $provider, $idempotencyKey): array {
            $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$orderId, $tenantId]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Pedido não encontrado.');
            if (in_array($order['status'], ['cancelled', 'completed'], true)) throw new RuntimeException('Pedido cancelado ou finalizado não pode receber nova cobrança.');
            if ($order['payment_status'] === 'paid') throw new RuntimeException('Pedido já está pago.');

            if ($provider !== 'manual') {
                $gw = $pdo->prepare('SELECT id FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1');
                $gw->execute([$tenantId, $provider]);
                if (!$gw->fetchColumn()) throw new RuntimeException('Gateway não está ativo para esta empresa.');
            }

            $existing = $pdo->prepare('SELECT * FROM payments WHERE tenant_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$tenantId, $idempotencyKey]);
            if ($payment = $existing->fetch()) return $payment;

            $open = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE'));
            $open->execute([$tenantId, $orderId]);
            if ($payment = $open->fetch()) return $payment;

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
        foreach (['tenant_id', 'order_id', 'provider', 'provider_payment_id', 'amount_cents', 'currency', 'account_reference'] as $key) {
            if (!array_key_exists($key, $verified)) throw new RuntimeException("Campo ausente: {$key}");
        }
        $verified['provider'] = strtolower((string)$verified['provider']);

        Database::transaction(function (PDO $pdo) use ($verified): void {
            $tenantId = (int)$verified['tenant_id'];
            $orderId = (int)$verified['order_id'];
            $provider = (string)$verified['provider'];
            if (!in_array($provider, self::PROVIDERS, true)) throw new RuntimeException('Provedor inválido.');

            $stmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$orderId, $tenantId]);
            $order = $stmt->fetch();
            if (!$order) throw new RuntimeException('Pedido inválido.');
            if (in_array($order['status'], ['cancelled', 'completed'], true)) throw new RuntimeException('Pedido cancelado ou finalizado não pode ser confirmado.');
            if ((int)$order['total_cents'] !== (int)$verified['amount_cents']) throw new RuntimeException('Valor divergente.');
            if (strtoupper((string)$verified['currency']) !== 'BRL') throw new RuntimeException('Moeda divergente.');

            if ($provider !== 'manual') {
                $gw = $pdo->prepare(Database::portableSql($pdo, 'SELECT account_reference FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 FOR UPDATE'));
                $gw->execute([$tenantId, $provider]);
                $account = $gw->fetchColumn();
                if ($account === false) throw new RuntimeException('Gateway local não está ativo.');
                if ((string)$account !== (string)$verified['account_reference']) throw new RuntimeException('Conta do recebedor divergente.');
            }

            $find = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM payments WHERE tenant_id=? AND order_id=? AND provider=? AND status IN ("created","pending","authorized","paid") ORDER BY id DESC LIMIT 1 FOR UPDATE'));
            $find->execute([$tenantId, $orderId, $provider]);
            $payment = $find->fetch();
            if (!$payment) throw new RuntimeException('Cobrança local não encontrada.');
            if ($payment['status'] === 'paid') return;
            if ((int)$payment['amount_cents'] !== (int)$verified['amount_cents'] || strtoupper((string)$payment['currency']) !== 'BRL') throw new RuntimeException('Cobrança divergente.');

            $dupe = $pdo->prepare('SELECT id FROM payments WHERE provider=? AND provider_payment_id=? AND id<>? LIMIT 1');
            $dupe->execute([$provider, (string)$verified['provider_payment_id'], $payment['id']]);
            if ($dupe->fetchColumn()) throw new RuntimeException('Transação do provedor já vinculada a outra cobrança.');

            $pdo->prepare('UPDATE payments SET provider_payment_id=?,status="paid",verified_at=CURRENT_TIMESTAMP,raw_payload=? WHERE id=?')->execute([
                (string)$verified['provider_payment_id'],
                json_encode($verified, JSON_UNESCAPED_UNICODE),
                $payment['id'],
            ]);
            $pdo->prepare('UPDATE orders SET payment_status="paid",status=CASE WHEN status="pending" THEN "confirmed" ELSE status END WHERE id=?')->execute([$orderId]);

            $items = $pdo->prepare('SELECT oi.product_id,oi.quantity,p.track_stock FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?');
            $items->execute([$orderId]);
            foreach ($items->fetchAll() as $item) {
                if (!$item['product_id'] || !(int)$item['track_stock']) continue;
                $key = 'payment:' . $payment['id'] . ':product:' . $item['product_id'];
                $check = $pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=?');
                $check->execute([$tenantId, $key]);
                if ($check->fetchColumn()) continue;
                $qty = (float)$item['quantity'];
                $update = $pdo->prepare('UPDATE products SET stock_qty=stock_qty-? WHERE id=? AND tenant_id=? AND stock_qty>=?');
                $update->execute([$qty, $item['product_id'], $tenantId, $qty]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Estoque insuficiente.');
                $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"out",?,?)')->execute([$tenantId, $item['product_id'], $orderId, $qty, $key]);
            }

            $tickets = $pdo->prepare(Database::portableSql($pdo, 'SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND order_id=? AND status="reserved" GROUP BY batch_id FOR UPDATE'));
            $tickets->execute([$tenantId, $orderId]);
            foreach ($tickets->fetchAll() as $row) {
                $qty = (int)$row['qty'];
                $pdo->prepare('UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?),quantity_sold=quantity_sold+? WHERE id=?')->execute([$qty, $qty, $row['batch_id']]);
            }
            $pdo->prepare('UPDATE tickets SET status="paid",reserved_until=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId, $orderId]);

            if (!empty($order['coupon_id'])) {
                $r = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM coupon_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE'));
                $r->execute([$tenantId, $orderId]);
                $reservation = $r->fetch();
                $insertRedemption = Database::portableSql($pdo, 'INSERT IGNORE INTO coupon_redemptions (tenant_id,coupon_id,order_id,customer_id,discount_cents,idempotency_key) VALUES (?,?,?,?,?,?)');
                if ($reservation) {
                    $pdo->prepare('UPDATE coupon_reservations SET status="redeemed" WHERE id=?')->execute([$reservation['id']]);
                    $pdo->prepare('UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1),uses_count=uses_count+1 WHERE id=? AND tenant_id=?')->execute([$order['coupon_id'], $tenantId]);
                    $pdo->prepare($insertRedemption)->execute([$tenantId, $order['coupon_id'], $orderId, $order['customer_id'] ?: null, $order['discount_cents'], 'payment:' . $payment['id'] . ':coupon']);
                } else {
                    $red = $pdo->prepare($insertRedemption);
                    $red->execute([$tenantId, $order['coupon_id'], $orderId, $order['customer_id'] ?: null, $order['discount_cents'], 'payment:' . $payment['id'] . ':coupon']);
                    if ($red->rowCount() === 1) $pdo->prepare('UPDATE coupons SET uses_count=uses_count+1 WHERE id=? AND tenant_id=?')->execute([$order['coupon_id'], $tenantId]);
                }
            }

            if (!empty($order['customer_id'])) {
                $points = intdiv((int)$order['total_cents'], 100);
                if ($points > 0) {
                    $key = 'payment:' . $payment['id'] . ':points';
                    $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?,?,"earn",?)');
                    $insert = $pdo->prepare($sql);
                    $insert->execute([$tenantId, $order['customer_id'], $orderId, $points, $key]);
                    if ($insert->rowCount() === 1) $pdo->prepare('UPDATE customers SET points=points+? WHERE id=? AND tenant_id=?')->execute([$points, $order['customer_id'], $tenantId]);
                }
            }

            if (!empty($order['promoter_id'])) {
                $p = $pdo->prepare('SELECT commission_percent FROM promoters WHERE id=? AND tenant_id=? AND active=1');
                $p->execute([$order['promoter_id'], $tenantId]);
                $percent = $p->fetchColumn();
                if ($percent !== false) {
                    $commission = (int)round((int)$order['total_cents'] * ((float)$percent / 100));
                    $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO promoter_commissions (tenant_id,promoter_id,order_id,amount_cents,status,idempotency_key) VALUES (?,?,?,?,"approved",?)');
                    $pdo->prepare($sql)->execute([$tenantId, $order['promoter_id'], $orderId, $commission, 'payment:' . $payment['id'] . ':commission']);
                }
            }
        });
    }
}
