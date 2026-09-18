<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
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
        $existing = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM marketplace_order_commissions WHERE order_id=? FOR UPDATE'));
        $existing->execute([$orderId]);
        if ($row = $existing->fetch()) return $row;

        $o = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,tenant_id,unit_id,order_source,marketplace_campaign_code,subtotal_cents,discount_cents,status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
        $o->execute([$orderId, $tenantId]);
        $order = $o->fetch();
        if (!$order) throw new RuntimeException('Pedido não encontrado para cobrança do marketplace.');
        if ((string)($order['order_source'] ?? '') !== self::ORDER_SOURCE) return null;
        $this->assertTenantCanReceive($pdo, $tenantId, isset($order['unit_id']) && $order['unit_id'] !== null ? (int)$order['unit_id'] : null);

        $amounts = $this->currentBase($pdo, $orderId, $order);
        $rule = $this->resolveRule(
            $pdo,
            $tenantId,
            isset($order['unit_id']) && $order['unit_id'] !== null ? (int)$order['unit_id'] : null,
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
        $insert->execute([
            $tenantId,
            $order['unit_id'] !== null ? (int)$order['unit_id'] : null,
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
        return $this->findByOrder($pdo, $orderId) ?: throw new RuntimeException('Não foi possível registrar a comissão do marketplace.');
    }

    public function markDue(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        $row = $this->lockedByOrder($pdo, $tenantId, $orderId);
        if (!$row) return null;
        if ((string)$row['status'] === 'provisioned') {
            $o=$pdo->prepare(Database::portableSql($pdo,'SELECT id,subtotal_cents,discount_cents FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $o->execute([$orderId,$tenantId]);
            $order=$o->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado ao concluir cobrança do marketplace.');
            $amounts=$this->currentBase($pdo,$orderId,$order,(int)$row['excluded_adjustments_cents']);
            $rateBps=(int)$row['commission_bps'];
            $commission=max(0,(int)round($amounts['base_cents']*$rateBps/10000));
            $pdo->prepare('UPDATE marketplace_order_commissions SET products_gross_cents=?,product_discount_cents=?,excluded_adjustments_cents=?,calculation_base_cents=?,commission_cents=?,status="due",due_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status="provisioned"')->execute([$amounts['products_gross_cents'],$amounts['product_discount_cents'],$amounts['excluded_adjustments_cents'],$amounts['base_cents'],$commission,(int)$row['id']]);
        }
        return $this->findByOrder($pdo, $orderId);
    }

    public function reverse(PDO $pdo, int $tenantId, int $orderId, string $reason): ?array
    {
        $row = $this->lockedByOrder($pdo, $tenantId, $orderId);
        if (!$row) return null;
        $status = (string)$row['status'];
        if ($status === 'reversed') return $row;
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') $reason = 'Pedido cancelado ou estornado.';

        if (in_array($status, ['invoiced','paid'], true)) {
            (new PlatformBillingService())->createMarketplaceReversalCredit($pdo, $row, $reason);
        }
        $pdo->prepare('UPDATE marketplace_order_commissions SET status="reversed",reversed_at=CURRENT_TIMESTAMP,reversal_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$reason, (int)$row['id']]);
        return $this->findByOrder($pdo, $orderId);
    }

    public function findByOrder(PDO $pdo, int $orderId): ?array
    {
        $s = $pdo->prepare('SELECT * FROM marketplace_order_commissions WHERE order_id=? LIMIT 1');
        $s->execute([$orderId]);
        $row = $s->fetch();
        return $row ?: null;
    }

    private function currentBase(PDO $pdo,int $orderId,array $order,int $excludedAdjustmentsCents=0):array
    {
        $items=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id=?');
        $items->execute([$orderId]);
        $gross=max(0,(int)$items->fetchColumn());
        if($gross===0)$gross=max(0,(int)($order['subtotal_cents']??0));
        $discount=min($gross,max(0,(int)($order['discount_cents']??0)));
        $excluded=max(0,min($gross-$discount,$excludedAdjustmentsCents));
        return[
            'products_gross_cents'=>$gross,
            'product_discount_cents'=>$discount,
            'excluded_adjustments_cents'=>$excluded,
            'base_cents'=>max(0,$gross-$discount-$excluded),
        ];
    }

    private function lockedByOrder(PDO $pdo, int $tenantId, int $orderId): ?array
    {
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=? FOR UPDATE'));
        $s->execute([$tenantId, $orderId]);
        $row = $s->fetch();
        return $row ?: null;
    }
}
