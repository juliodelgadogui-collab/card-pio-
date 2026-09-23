<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;

final class MarketplaceCampaignService
{
    private const SPECIFICITY = [
        'default' => 10,
        'plan' => 20,
        'state' => 30,
        'city' => 40,
        'tenant' => 50,
    ];

    /**
     * Selects the active marketplace campaign for the company at checkout time.
     * The consumer never supplies a campaign code. Selection is based only on
     * server-controlled ADM Geral configuration and current tenant metadata.
     */
    public function resolve(PDO $pdo, int $tenantId, ?int $unitId = null, ?string $at = null): ?array
    {
        $settings = (new MarketplaceCommissionService())->assertTenantCanReceive($pdo, $tenantId, $unitId);
        $at = $at ?: gmdate('Y-m-d H:i:s');

        // Resolve the commercial plan in portable queries. Keeping this logic
        // separate avoids SQLite rejecting JOIN ON clauses that reference a
        // table introduced later while preserving subscription precedence.
        $legacyPlan = $pdo->prepare('SELECT plan FROM tenants WHERE id=? LIMIT 1');
        $legacyPlan->execute([$tenantId]);
        $planCode = trim((string)($legacyPlan->fetchColumn() ?: ''));

        $subscriptionPlan = $pdo->prepare("SELECT p.code
            FROM tenant_subscriptions ts
            INNER JOIN saas_plans p ON p.id=ts.plan_id
            WHERE ts.tenant_id=? AND ts.status IN ('trial','active','past_due')
            ORDER BY CASE ts.status WHEN 'active' THEN 0 WHEN 'trial' THEN 1 WHEN 'past_due' THEN 2 ELSE 9 END, ts.id DESC
            LIMIT 1");
        $subscriptionPlan->execute([$tenantId]);
        $resolvedPlan = trim((string)($subscriptionPlan->fetchColumn() ?: ''));
        if ($resolvedPlan !== '') $planCode = $resolvedPlan;

        $city = mb_strtolower(trim((string)($settings['city'] ?? '')));
        $state = mb_strtoupper(trim((string)($settings['state'] ?? '')));

        $query = $pdo->prepare('SELECT * FROM marketplace_campaign_assignments WHERE active=1 AND (starts_at IS NULL OR starts_at<=?) AND (ends_at IS NULL OR ends_at>=?)');
        $query->execute([$at, $at]);
        $matches = [];
        foreach ($query->fetchAll() as $campaign) {
            $scope = strtolower(trim((string)($campaign['scope_type'] ?? '')));
            $match = match ($scope) {
                'default' => true,
                'tenant' => (int)($campaign['tenant_id'] ?? 0) === $tenantId,
                'city' => $city !== '' && mb_strtolower(trim((string)($campaign['city'] ?? ''))) === $city,
                'state' => $state !== '' && mb_strtoupper(trim((string)($campaign['state'] ?? ''))) === $state,
                'plan' => $planCode !== '' && trim((string)($campaign['plan_code'] ?? '')) === $planCode,
                default => false,
            };
            if (!$match) continue;
            $code = trim((string)($campaign['campaign_code'] ?? ''));
            if ($code === '' || mb_strlen($code) > 80) continue;
            $campaign['_specificity'] = self::SPECIFICITY[$scope] ?? 0;
            $matches[] = $campaign;
        }
        if (!$matches) return null;

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
        return $selected;
    }

    public function codeForCheckout(PDO $pdo, int $tenantId, ?int $unitId = null, ?string $at = null): ?string
    {
        $campaign = $this->resolve($pdo, $tenantId, $unitId, $at);
        $code = trim((string)($campaign['campaign_code'] ?? ''));
        return $code !== '' ? $code : null;
    }
}
