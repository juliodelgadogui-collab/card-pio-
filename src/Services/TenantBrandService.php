<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;

final class TenantBrandService
{
    public function get(int $tenantId): array
    {
        if ($tenantId < 1) return $this->defaults();
        $stmt = Database::connection()->prepare('SELECT name,settings FROM tenants WHERE id=? LIMIT 1');
        $stmt->execute([$tenantId]);
        $tenant = $stmt->fetch();
        if (!$tenant) return $this->defaults();

        $settings = json_decode((string)($tenant['settings'] ?? '{}'), true) ?: [];
        $defaults = $this->defaults();
        $displayName = trim((string)($settings['brand_display_name'] ?? '')) ?: (string)$tenant['name'];
        $tagline = trim((string)($settings['brand_tagline'] ?? ''));
        $logo = $this->url($settings['brand_logo_url'] ?? ($settings['menu_logo_url'] ?? ''));

        return [
            'display_name' => mb_substr($displayName, 0, 100),
            'tagline' => mb_substr($tagline, 0, 160),
            'logo_url' => $logo,
            'primary_color' => $this->color($settings['brand_primary_color'] ?? ($settings['menu_primary_color'] ?? ''), $defaults['primary_color']),
            'secondary_color' => $this->color($settings['brand_secondary_color'] ?? '', $defaults['secondary_color']),
            'background_color' => $this->color($settings['brand_background_color'] ?? ($settings['menu_background_color'] ?? ''), $defaults['background_color']),
            'surface_color' => $this->color($settings['brand_surface_color'] ?? ($settings['menu_surface_color'] ?? ''), $defaults['surface_color']),
            'text_color' => $this->color($settings['brand_text_color'] ?? ($settings['menu_text_color'] ?? ''), $defaults['text_color']),
            'apply_app' => array_key_exists('brand_apply_app', $settings) ? (bool)$settings['brand_apply_app'] : true,
            'apply_web' => array_key_exists('brand_apply_web', $settings) ? (bool)$settings['brand_apply_web'] : true,
            'show_eventmenu_brand' => array_key_exists('brand_show_eventmenu', $settings) ? (bool)$settings['brand_show_eventmenu'] : true,
        ];
    }

    public function defaults(): array
    {
        return [
            'display_name' => 'EventMenu',
            'tagline' => '',
            'logo_url' => '',
            'primary_color' => '#5b34d6',
            'secondary_color' => '#159b63',
            'background_color' => '#f6f7fb',
            'surface_color' => '#ffffff',
            'text_color' => '#1e1b2b',
            'apply_app' => true,
            'apply_web' => true,
            'show_eventmenu_brand' => true,
        ];
    }

    private function color(mixed $value, string $fallback): string
    {
        $value = strtolower(trim((string)$value));
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
    }

    private function url(mixed $value): string
    {
        $url = trim((string)$value);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? mb_substr($url, 0, 600) : '';
    }
}
