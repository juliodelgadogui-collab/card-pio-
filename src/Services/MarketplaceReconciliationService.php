<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class MarketplaceReconciliationService
{
    public function reconcileOrder(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,order_source,status,payment_status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
        $s->execute([$orderId, $tenantId]);
        $order = $s->fetch();
        if (!$order || (string)($order['order_source'] ?? '') !== MarketplaceCommissionService::ORDER_SOURCE) return null;

        $service = new MarketplaceCommissionService();
        $commission = $service->findByOrder($pdo, $orderId, $tenantId);
        if (!$commission && (string)$order['status'] !== 'cancelled') {
            $commission = $service->provision($pdo, $tenantId, $orderId);
        }
        if (!$commission) return null;

        if ((string)$order['status'] === 'cancelled' || (string)$order['payment_status'] === 'refunded') {
            return $service->reverse($pdo, $tenantId, $orderId, 'Pedido cancelado ou pagamento estornado.');
        }
        if ((string)$order['status'] === 'completed') {
            return $service->markDue($pdo, $tenantId, $orderId);
        }
        return $commission;
    }

    public function reconcileTenant(PDO $pdo, int $tenantId, int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        $q = $pdo->prepare(
            'SELECT o.id FROM orders o '
            . 'LEFT JOIN marketplace_order_commissions mc ON mc.order_id=o.id AND mc.tenant_id=o.tenant_id '
            . 'WHERE o.tenant_id=? AND o.order_source=? '
            . 'AND ((o.status="completed" AND (mc.id IS NULL OR mc.status="provisioned")) '
            . 'OR ((o.status="cancelled" OR o.payment_status="refunded") AND mc.id IS NOT NULL AND mc.status<>"reversed") '
            . 'OR (mc.id IS NULL AND o.status<>"cancelled")) '
            . 'ORDER BY o.id LIMIT ' . $limit
        );
        $q->execute([$tenantId, MarketplaceCommissionService::ORDER_SOURCE]);

        $service = new MarketplaceCommissionService();
        $result = ['checked' => 0, 'changed' => 0];
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $orderId = (int)$orderId;
            $before = $service->findByOrder($pdo, $orderId, $tenantId);
            $beforeStatus = $before['status'] ?? null;
            $after = $this->reconcileOrder($pdo, $tenantId, $orderId);
            $result['checked']++;
            if (($after['status'] ?? null) !== $beforeStatus) $result['changed']++;
        }
        return $result;
    }
}
