<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;

final class TenantBrandingService
{
    public function forTenant(int $tenantId): array
    {
        $pdo = Database::connection();
        $q = $pdo->prepare('SELECT name,settings FROM tenants WHERE id=? LIMIT 1');
        $q->execute([$tenantId]);
        $tenant = $q->fetch() ?: ['name'=>'EventMenu','settings'=>'{}'];
        $settings = json_decode((string)($tenant['settings'] ?? '{}'), true);
        if (!is_array($settings)) $settings = [];

        $primary = $this->color($settings['brand_primary_color'] ?? $settings['menu_primary_color'] ?? null, '#5B34D6');
        $secondary = $this->color($settings['brand_secondary_color'] ?? null, '#159B63');
        $background = $this->color($settings['brand_background_color'] ?? $settings['menu_background_color'] ?? null, '#F6F7FB');
        $surface = $this->color($settings['brand_surface_color'] ?? $settings['menu_surface_color'] ?? null, '#FFFFFF');
        $text = $this->color($settings['brand_text_color'] ?? $settings['menu_text_color'] ?? null, '#1E1B2B');
        $logo = $this->url($settings['brand_logo_url'] ?? $settings['menu_logo_url'] ?? '');
        $display = trim((string)($settings['brand_display_name'] ?? '')) ?: (string)$tenant['name'];

        return [
            'display_name' => mb_substr($display, 0, 90),
            'logo_url' => $logo,
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'background_color' => $background,
            'surface_color' => $surface,
            'text_color' => $text,
            'apply_app' => array_key_exists('brand_apply_app',$settings) ? (bool)$settings['brand_apply_app'] : true,
            'apply_panel' => array_key_exists('brand_apply_panel',$settings) ? (bool)$settings['brand_apply_panel'] : true,
            'sync_public_menu' => !empty($settings['brand_sync_public_menu']),
        ];
    }

    public function cssVariables(int $tenantId): string
    {
        $b = $this->forTenant($tenantId);
        if (!$b['apply_panel']) return '';
        $primary2 = $this->mix($b['primary_color'], '#FFFFFF', 0.12);
        $primarySoft = $this->mix($b['primary_color'], '#FFFFFF', 0.88);
        $sidebar2 = $this->mix($b['primary_color'], '#000000', 0.46);
        $sidebar = $this->mix($b['primary_color'], '#000000', 0.58);
        return sprintf(
            '--em-primary:%s;--em-primary-2:%s;--em-primary-soft:%s;--em-sidebar:%s;--em-sidebar-2:%s;--em-bg:%s;--em-surface:%s;--em-text:%s;--accent:%s;--bg:%s;--panel:%s;--text:%s;',
            $b['primary_color'],$primary2,$primarySoft,$sidebar,$sidebar2,$b['background_color'],$b['surface_color'],$b['text_color'],$b['primary_color'],$b['background_color'],$b['surface_color'],$b['text_color']
        );
    }

    private function color(mixed $value,string $fallback):string
    {
        $v=strtoupper(trim((string)$value));
        return preg_match('/^#[0-9A-F]{6}$/',$v)?$v:$fallback;
    }

    private function url(mixed $value):string
    {
        $v=trim((string)$value);
        if($v===''||!filter_var($v,FILTER_VALIDATE_URL))return'';
        $scheme=strtolower((string)parse_url($v,PHP_URL_SCHEME));
        return in_array($scheme,['http','https'],true)?mb_substr($v,0,600):'';
    }

    private function mix(string $a,string $b,float $towardB):string
    {
        $towardB=max(0,min(1,$towardB));
        $ar=hexdec(substr($a,1,2));$ag=hexdec(substr($a,3,2));$ab=hexdec(substr($a,5,2));
        $br=hexdec(substr($b,1,2));$bg=hexdec(substr($b,3,2));$bb=hexdec(substr($b,5,2));
        $r=(int)round($ar+($br-$ar)*$towardB);$g=(int)round($ag+($bg-$ag)*$towardB);$bl=(int)round($ab+($bb-$ab)*$towardB);
        return sprintf('#%02X%02X%02X',$r,$g,$bl);
    }
}
