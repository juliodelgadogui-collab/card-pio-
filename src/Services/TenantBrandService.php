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
        $primary = $this->color($settings['brand_primary_color'] ?? ($settings['menu_primary_color'] ?? ''), $defaults['primary_color']);
        $secondary = $this->color($settings['brand_secondary_color'] ?? '', $defaults['secondary_color']);
        $background = $this->color($settings['brand_background_color'] ?? ($settings['menu_background_color'] ?? ''), $defaults['background_color']);
        $surface = $this->color($settings['brand_surface_color'] ?? ($settings['menu_surface_color'] ?? ''), $defaults['surface_color']);
        $requestedText = $this->color($settings['brand_text_color'] ?? ($settings['menu_text_color'] ?? ''), $defaults['text_color']);
        $text = $this->readableText($requestedText, [$background,$surface], $defaults['text_color']);

        return [
            'display_name' => mb_substr($displayName, 0, 100),
            'tagline' => mb_substr($tagline, 0, 160),
            'logo_url' => $logo,
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'background_color' => $background,
            'surface_color' => $surface,
            'text_color' => $text,
            'contrast_adjusted' => $text !== $requestedText,
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
            'contrast_adjusted' => false,
            'apply_app' => true,
            'apply_web' => true,
            'show_eventmenu_brand' => true,
        ];
    }

    private function readableText(string $requested,array $surfaces,string $fallback):string
    {
        $minimum=INF;foreach($surfaces as$surface)$minimum=min($minimum,$this->contrast($requested,$surface));
        if($minimum>=4.5)return$requested;
        $candidates=[$fallback,'#17131f','#ffffff'];$best=$fallback;$bestScore=0.0;
        foreach($candidates as$candidate){$score=INF;foreach($surfaces as$surface)$score=min($score,$this->contrast($candidate,$surface));if($score>$bestScore){$bestScore=$score;$best=$candidate;}}
        return$best;
    }

    private function contrast(string $a,string $b):float
    {
        $la=$this->luminance($a);$lb=$this->luminance($b);$light=max($la,$lb);$dark=min($la,$lb);return($light+.05)/($dark+.05);
    }

    private function luminance(string $hex):float
    {
        $hex=ltrim($hex,'#');$rgb=[hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
        $out=[];foreach($rgb as$c){$v=$c/255;$out[]=$v<=.03928?$v/12.92:(($v+.055)/1.055)**2.4;}return .2126*$out[0]+.7152*$out[1]+.0722*$out[2];
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
