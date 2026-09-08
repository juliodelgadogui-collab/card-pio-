<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class OnboardingService
{
    public function shouldPrompt(int $tenantId): bool
    {
        $tenant = $this->tenant($tenantId);
        if (!$tenant) return false;
        $settings = json_decode((string)($tenant['settings'] ?? '{}'), true) ?: [];
        if (!empty($settings['onboarding_completed'])) return false;
        if (!empty($settings['onboarding_required'])) return true;

        // Compatibilidade com empresas já existentes: só apresenta automaticamente
        // quando a empresa está praticamente vazia, evitando interromper operação ativa.
        $pdo = Database::connection();
        $products = $this->count($pdo, 'SELECT COUNT(*) FROM products WHERE tenant_id=?', $tenantId);
        $orders = $this->count($pdo, 'SELECT COUNT(*) FROM orders WHERE tenant_id=?', $tenantId);
        $users = $this->count($pdo, 'SELECT COUNT(*) FROM users WHERE tenant_id=?', $tenantId);
        return $products === 0 && $orders === 0 && $users <= 2;
    }

    /** @return array<string,mixed> */
    public function checklist(int $tenantId): array
    {
        $pdo = Database::connection();
        $tenant = $this->tenant($tenantId) ?: [];
        $settings = json_decode((string)($tenant['settings'] ?? '{}'), true) ?: [];
        $units = $this->count($pdo, 'SELECT COUNT(*) FROM operating_units WHERE tenant_id=?', $tenantId);
        $products = $this->count($pdo, 'SELECT COUNT(*) FROM products WHERE tenant_id=?', $tenantId);
        $users = $this->count($pdo, 'SELECT COUNT(*) FROM users WHERE tenant_id=?', $tenantId);
        $gateways = $this->count($pdo, 'SELECT COUNT(*) FROM payment_gateways WHERE tenant_id=? AND active=1', $tenantId);
        $mail = (new MailSettingsService())->get($tenantId);
        $hasBrand = trim((string)($settings['brand_display_name'] ?? '')) !== '' || trim((string)($settings['brand_logo_url'] ?? '')) !== '';
        return [
            'company' => ['done' => trim((string)($tenant['name'] ?? '')) !== '', 'route'=>'settings-general'],
            'brand' => ['done' => $hasBrand, 'route'=>'media-settings'],
            'unit' => ['done' => $units > 0, 'route'=>'units'],
            'products' => ['done' => $products > 0, 'route'=>'products'],
            'payments' => ['done' => $gateways > 0, 'route'=>'gateways'],
            'team' => ['done' => $users > 1, 'route'=>'users'],
            'email' => ['done' => !empty($mail['enabled']), 'route'=>'email-settings'],
        ];
    }

    public function complete(int $tenantId): void
    {
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT settings FROM tenants WHERE id=?');$s->execute([$tenantId]);
        $settings = json_decode((string)($s->fetchColumn() ?: '{}'), true) ?: [];
        $settings['onboarding_completed'] = true;
        $settings['onboarding_required'] = false;
        $pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);
    }

    /** @return array<string,mixed>|null */
    private function tenant(int $tenantId): ?array
    {
        $s = Database::connection()->prepare('SELECT id,name,settings FROM tenants WHERE id=? LIMIT 1');$s->execute([$tenantId]);$row=$s->fetch(PDO::FETCH_ASSOC);return$row?:null;
    }

    private function count(PDO $pdo, string $sql, int $tenantId): int
    {
        try {$s=$pdo->prepare($sql);$s->execute([$tenantId]);return(int)$s->fetchColumn();} catch (\Throwable) {return 0;}
    }
}
