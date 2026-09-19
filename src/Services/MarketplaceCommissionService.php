<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

final class MarketplaceCommissionService
{
    public const ORDER_SOURCE = 'EVENTMENU_DELIVERY';

    private const SPECIFICITY = [
        'default' => 10,
        'plan' => 20,
        'state' => 30,
        'city' => 40,
        'campaign' => 50,
        'tenant' => 60,
    ];

    public function assertTenantCanReceive(PDO $pdo, int $tenantId, ?int $unitId = null): array
    {
        $s = $pdo->prepare('SELECT t.id,t.name,t.status tenant_status,m.participates,m.status marketplace_status,m.joined_at,m.city,m.state FROM tenants t LEFT JOIN marketplace_tenant_settings m ON m.tenant_id=t.id WHERE t.id=? LIMIT 1');
        $s->execute([$tenantId]);
        $row = $s->fetch();
        if (!$row || (string)$row['tenant_status'] !== 'active') throw new RuntimeException('Esta empresa não está disponível agora.');
        if ((int)($row['participates'] ?? 0) !== 1 || (string)($row['marketplace_status'] ?? '') !== 'active') {
            throw new RuntimeException('Esta empresa não está participando do EventMenu Delivery.');
        }
        if ($unitId !== null && $unitId > 0) {
            $u = $pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');
            $u->execute([$unitId, $tenantId]);
            if (!$u->fetchColumn()) throw new RuntimeException('Esta unidade não está disponível para pedidos.');
        }
        return $row;
    }

    public function resolveRule(PDO $pdo, int $tenantId, ?int $unitId = null, ?string $campaignCode = null, ?string $at = null): array
    {
        $settings = $this->assertTenantCanReceive($pdo, $tenantId, $unitId);
        $campaignCode = trim((string)$campaignCode);
        $at = $at ?: gmdate('Y-m-d H:i:s');

        $plan = $pdo->prepare('SELECT COALESCE(p.code,t.plan) plan_code FROM tenants t LEFT JOIN tenant_subscriptions ts ON ts.tenant_id=t.id AND ts.status IN ("trial","active","past_due") LEFT JOIN saas_plans p ON p.id=ts.plan_id WHERE t.id=? LIMIT 1');
        $plan->execute([$tenantId]);
        $planCode = trim((string)($plan->fetchColumn() ?: ''));
        $city = mb_strtolower(trim((string)($settings['city'] ?? '')));
        $state = mb_strtoupper(trim((string)($settings['state'] ?? '')));

        $q = $pdo->prepare('SELECT * FROM marketplace_commission_rules WHERE active=1 AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>=?)');
        $q->execute([$at, $at]);
        $matches = [];
        foreach ($q->fetchAll() as $rule) {
            $scope = strtolower(trim((string)$rule['scope_type']));
            $match = match ($scope) {
                'default' => true,
                'tenant' => (int)($rule['tenant_id'] ?? 0) === $tenantId,
                'city' => $city !== '' && mb_strtolower(trim((string)($rule['city'] ?? ''))) === $city,
                'state' => $state !== '' && mb_strtoupper(trim((string)($rule['state'] ?? ''))) === $state,
                'plan' => $planCode !== '' && trim((string)($rule['plan_code'] ?? '')) === $planCode,
                'campaign' => $campaignCode !== '' && trim((string)($rule['campaign_code'] ?? '')) === $campaignCode,
                default => false,
            };
            if (!$match) continue;
            $rate = (int)$rule['rate_bps'];
            if ($rate < 0 || $rate > 10000) continue;
            $rule['_specificity'] = self::SPECIFICITY[$scope] ?? 0;
            $matches[] = $rule;
        }
        if (!$matches) throw new RuntimeException('A taxa do EventMenu Delivery ainda não foi configurada para esta empresa.');
        usort($matches, static function (array $a, array $b): int {
            $priority = (int)$b['priority'] <=> (int)$a['priority'];
            if ($priority !== 0) return $priority;
            $specificity = (int)$b['_specificity'] <=> (int)$a['_specificity'];
            if ($specificity !== 0) return $specificity;
            return (int)$b['id'] <=> (int)$a['id'];
        });
        $selected = $matches[0];
        unset($selected['_specificity']);
        $selected['matched_city'] = $settings['city'] ?? null;
        $selected['matched_state'] = $settings['state'] ?? null;
        $selected['matched_plan_code'] = $planCode ?: null;
        $selected['matched_campaign_code'] = $campaignCode ?: null;
        return $selected;
    }

    public function provision(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        // Lock the canonical order first. Every lifecycle operation uses the same
        // lock order (order -> commission), which avoids cross-path deadlocks and
        // prevents an order id from another tenant leaking an existing commission.
        $order = $this->lockOrder($pdo, $tenantId, $orderId);
        if ((string)($order['order_source'] ?? '') !== self::ORDER_SOURCE) return null;

        $existing = $this->lockedByOrder($pdo, $tenantId, $orderId);
        if ($existing) {
            $this->assertCommissionScope($existing, $order);
            return $existing;
        }

        $unitId = $order['unit_id'] !== null ? (int)$order['unit_id'] : null;
        $this->assertTenantCanReceive($pdo, $tenantId, $unitId);

        $amounts = $this->currentBase($pdo, $orderId, $order);
        $rule = $this->resolveRule(
            $pdo,
            $tenantId,
            $unitId,
            (string)($order['marketplace_campaign_code'] ?? '')
        );
        $rateBps = (int)$rule['rate_bps'];
        $commission = max(0, (int)round($amounts['base_cents'] * $rateBps / 10000));
        $snapshot = [
            'rule_id' => (int)$rule['id'],
            'name' => (string)$rule['name'],
            'scope_type' => (string)$rule['scope_type'],
            'rate_bps' => $rateBps,
            'priority' => (int)$rule['priority'],
            'starts_at' => $rule['starts_at'] ?? null,
            'ends_at' => $rule['ends_at'] ?? null,
            'tenant_id' => $rule['tenant_id'] ?? null,
            'city' => $rule['matched_city'] ?? null,
            'state' => $rule['matched_state'] ?? null,
            'plan_code' => $rule['matched_plan_code'] ?? null,
            'campaign_code' => $rule['matched_campaign_code'] ?? null,
            'resolved_at' => gmdate('Y-m-d H:i:s'),
        ];
        $insert = $pdo->prepare('INSERT INTO marketplace_order_commissions (tenant_id,unit_id,order_id,rule_id,order_source,products_gross_cents,product_discount_cents,excluded_adjustments_cents,calculation_base_cents,commission_bps,commission_cents,status,rule_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,"provisioned",?)');
        try {
            $insert->execute([
                $tenantId,
                $unitId,
                $orderId,
                (int)$rule['id'],
                self::ORDER_SOURCE,
                $amounts['products_gross_cents'],
                $amounts['product_discount_cents'],
                $amounts['excluded_adjustments_cents'],
                $amounts['base_cents'],
                $rateBps,
                $commission,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (PDOException $e) {
            // FOR UPDATE is removed by the SQLite portability layer and callers may
            // also invoke this service outside an explicit transaction. The unique
            // order constraint remains the final concurrency guard; if another
            // request won the race, return its tenant-scoped row idempotently.
            if ($this->isUniqueOrderConstraintViolation($e)) {
                $winner = $this->findByOrder($pdo, $orderId, $tenantId);
                if ($winner) {
                    $this->assertCommissionScope($winner, $order);
                    return $winner;
                }
            }
            throw $e;
        }

        return $this->findByOrder($pdo, $orderId, $tenantId)
            ?: throw new RuntimeException('Não foi possível registrar a comissão do marketplace.');
    }

    public function markDue(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        $order = $this->lockOrder($pdo, $tenantId, $orderId);
        if ((string)($order['order_source'] ?? '') !== self::ORDER_SOURCE) return null;

        $row = $this->lockedByOrder($pdo, $tenantId, $orderId);
        if (!$row) return null;
        $this->assertCommissionScope($row, $order);

        if ((string)$row['status'] === 'provisioned') {
            $amounts = $this->currentBase($pdo, $orderId, $order, (int)$row['excluded_adjustments_cents']);
            $rateBps = (int)$row['commission_bps'];
            $commission = max(0, (int)round($amounts['base_cents'] * $rateBps / 10000));
            $pdo->prepare('UPDATE marketplace_order_commissions SET products_gross_cents=?,product_discount_cents=?,excluded_adjustments_cents=?,calculation_base_cents=?,commission_cents=?,status="due",due_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="provisioned"')
                ->execute([$amounts['products_gross_cents'],$amounts['product_discount_cents'],$amounts['excluded_adjustments_cents'],$amounts['base_cents'],$commission,(int)$row['id'],$tenantId]);
        }
        return $this->findByOrder($pdo, $orderId, $tenantId);
    }

    public function reverse(PDO $pdo, int $tenantId, int $orderId, string $reason): ?array
    {
        $order = $this->lockOrder($pdo, $tenantId, $orderId);
        if ((string)($order['order_source'] ?? '') !== self::ORDER_SOURCE) return null;

        $row = $this->lockedByOrder($pdo, $tenantId, $orderId);
        if (!$row) return null;
        $this->assertCommissionScope($row, $order);

        $status = (string)$row['status'];
        if ($status === 'reversed') return $row;
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') $reason = 'Pedido cancelado ou estornado.';

        if (in_array($status, ['invoiced','paid'], true)) {
            (new PlatformBillingService())->createMarketplaceReversalCredit($pdo, $row, $reason);
        }
        $pdo->prepare('UPDATE marketplace_order_commissions SET status="reversed",reversed_at=CURRENT_TIMESTAMP,reversal_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status<>"reversed"')
            ->execute([$reason, (int)$row['id'], $tenantId]);
        return $this->findByOrder($pdo, $orderId, $tenantId);
    }

    /**
     * Backward-compatible lookup. Tenant-aware callers must pass $tenantId.
     */
    public function findByOrder(PDO $pdo, int $orderId, ?int $tenantId = null): ?array
    {
        if ($tenantId !== null) {
            $s = $pdo->prepare('SELECT * FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=? LIMIT 1');
            $s->execute([$tenantId, $orderId]);
        } else {
            $s = $pdo->prepare('SELECT * FROM marketplace_order_commissions WHERE order_id=? LIMIT 1');
            $s->execute([$orderId]);
        }
        $row = $s->fetch();
        return $row ?: null;
    }

    private function currentBase(PDO $pdo, int $orderId, array $order, int $excludedAdjustmentsCents = 0): array
    {
        $items = $pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        $gross = max(0, (int)$items->fetchColumn());
        if ($gross === 0) $gross = max(0, (int)($order['subtotal_cents'] ?? 0));
        $discount = min($gross, max(0, (int)($order['discount_cents'] ?? 0)));
        $excluded = max(0, min($gross - $discount, $excludedAdjustmentsCents));
        return [
            'products_gross_cents' => $gross,
            'product_discount_cents' => $discount,
            'excluded_adjustments_cents' => $excluded,
            'base_cents' => max(0, $gross - $discount - $excluded),
        ];
    }

    private function lockOrder(PDO $pdo, int $tenantId, int $orderId): array
    {
        $o = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,tenant_id,unit_id,order_source,marketplace_campaign_code,subtotal_cents,discount_cents,status,payment_status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
        $o->execute([$orderId, $tenantId]);
        return $o->fetch() ?: throw new RuntimeException('Pedido não encontrado para cobrança do marketplace.');
    }

    private function lockedByOrder(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=? FOR UPDATE'));
        $s->execute([$tenantId, $orderId]);
        $row = $s->fetch();
        return $row ?: null;
    }

    private function assertCommissionScope(array $commission, array $order): void
    {
        if ((int)($commission['tenant_id'] ?? 0) !== (int)($order['tenant_id'] ?? 0)
            || (int)($commission['order_id'] ?? 0) !== (int)($order['id'] ?? 0)
            || (string)($commission['order_source'] ?? '') !== self::ORDER_SOURCE) {
            throw new RuntimeException('Inconsistência de escopo na comissão do marketplace.');
        }

        $commissionUnit = $commission['unit_id'] !== null ? (int)$commission['unit_id'] : null;
        $orderUnit = $order['unit_id'] !== null ? (int)$order['unit_id'] : null;
        if ($commissionUnit !== $orderUnit) {
            throw new RuntimeException('A comissão do marketplace pertence a outra unidade.');
        }
    }

    private function isUniqueOrderConstraintViolation(PDOException $e): bool
    {
        $state = strtoupper((string)$e->getCode());
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        $message = strtolower($e->getMessage());
        if ($state !== '23000' && !in_array($driverCode, [19, 1062, 2067], true)) return false;
        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'uq_marketplace_commission_order')
            || str_contains($message, 'marketplace_order_commissions.order_id');
    }
}
