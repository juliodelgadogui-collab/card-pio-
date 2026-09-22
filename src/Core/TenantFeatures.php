<?php

declare(strict_types=1);

namespace EventMenu\Core;

final class TenantFeatures
{
    public const FULL = 'full';
    public const MENU = 'menu';
    public const EVENT = 'event';

    private const MODULES = [
        'catalog' => [
            'label' => 'Cardápio e produtos',
            'routes' => ['products', 'product-config'],
        ],
        'pos' => [
            'label' => 'PDV, caixa e retirada',
            'routes' => ['pos', 'cash', 'pickup', 'receipt', 'receipt-settings'],
        ],
        'kitchen' => [
            'label' => 'Cozinha e produção',
            'routes' => ['kitchen', 'kds-stream', 'production'],
        ],
        'restaurant' => [
            'label' => 'Mesas e comandas',
            'routes' => ['restaurant'],
        ],
        'delivery' => [
            'label' => 'Delivery',
            'routes' => ['delivery'],
        ],
        'inventory' => [
            'label' => 'Estoque e compras',
            'routes' => ['inventory', 'purchases'],
        ],
        'customers' => [
            'label' => 'Clientes, comunicação e cupons',
            'routes' => ['customers', 'communications', 'coupons'],
        ],
        'payments' => [
            'label' => 'Pagamentos e gateways',
            'routes' => ['payments', 'gateways'],
        ],
        'events' => [
            'label' => 'Eventos e ingressos',
            'routes' => ['events', 'event-admin', 'tickets', 'guests', 'promoters'],
        ],
    ];

    public static function type(?int $tenantId = null): string
    {
        $tenantId ??= Auth::tenantId();
        if (!$tenantId) return self::FULL;

        $row = self::tenantRow($tenantId);
        if (!$row) return self::FULL;

        $settings = self::settingsFromRow($row);
        $type = (string)($settings['business_type'] ?? '');
        if (!in_array($type, [self::FULL, self::MENU, self::EVENT], true)) {
            $plan = (string)($row['plan'] ?? '');
            $type = in_array($plan, [self::MENU, self::EVENT], true) ? $plan : self::FULL;
        }
        return $type;
    }

    public static function label(?int $tenantId = null): string
    {
        return match (self::type($tenantId)) {
            self::MENU => 'Cardápio / Restaurante',
            self::EVENT => 'Eventos',
            default => 'Completo',
        };
    }

    public static function moduleCatalog(): array
    {
        $catalog = [];
        foreach (self::MODULES as $code => $config) {
            $catalog[$code] = (string)$config['label'];
        }
        return $catalog;
    }

    public static function defaultModulesForType(string $type): array
    {
        if (!in_array($type, [self::FULL, self::MENU, self::EVENT], true)) $type = self::FULL;

        $defaults = array_fill_keys(array_keys(self::MODULES), false);
        if ($type === self::FULL) {
            return array_fill_keys(array_keys(self::MODULES), true);
        }
        if ($type === self::MENU) {
            foreach (array_keys(self::MODULES) as $code) $defaults[$code] = $code !== 'events';
            return $defaults;
        }

        $defaults['customers'] = true;
        $defaults['payments'] = true;
        $defaults['events'] = true;
        return $defaults;
    }

    public static function normalizeModules(array $modules, string $type = self::FULL): array
    {
        $normalized = self::defaultModulesForType($type);
        foreach (array_keys(self::MODULES) as $code) {
            if (array_key_exists($code, $modules)) {
                $normalized[$code] = filter_var($modules[$code], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }
        return $normalized;
    }

    public static function modules(?int $tenantId = null): array
    {
        $tenantId ??= Auth::tenantId();
        if (!$tenantId) return self::defaultModulesForType(self::FULL);

        $row = self::tenantRow($tenantId);
        if (!$row) return self::defaultModulesForType(self::FULL);

        $settings = self::settingsFromRow($row);
        $type = self::typeFromRow($row, $settings);
        $configured = $settings['modules'] ?? null;
        if (!is_array($configured)) return self::defaultModulesForType($type);

        $explicit = [];
        foreach (array_keys(self::MODULES) as $code) {
            $explicit[$code] = !empty($configured[$code]);
        }
        return $explicit;
    }

    public static function moduleEnabled(string $module, ?int $tenantId = null): bool
    {
        if (!isset(self::MODULES[$module])) return true;
        $modules = self::modules($tenantId);
        return !empty($modules[$module]);
    }

    public static function menu(?int $tenantId = null): bool
    {
        return self::moduleEnabled('catalog', $tenantId);
    }

    public static function events(?int $tenantId = null): bool
    {
        return self::moduleEnabled('events', $tenantId);
    }

    public static function routeEnabled(string $route, ?int $tenantId = null): bool
    {
        foreach (self::MODULES as $code => $config) {
            if (in_array($route, $config['routes'], true)) {
                return self::moduleEnabled($code, $tenantId);
            }
        }
        return true;
    }

    private static function tenantRow(int $tenantId): ?array
    {
        static $cache = [];
        if (array_key_exists($tenantId, $cache)) return $cache[$tenantId];

        $s = Database::connection()->prepare('SELECT plan,settings FROM tenants WHERE id=? LIMIT 1');
        $s->execute([$tenantId]);
        $row = $s->fetch();
        return $cache[$tenantId] = ($row ?: null);
    }

    private static function settingsFromRow(array $row): array
    {
        $settings = json_decode((string)($row['settings'] ?? '{}'), true);
        return is_array($settings) ? $settings : [];
    }

    private static function typeFromRow(array $row, array $settings): string
    {
        $type = (string)($settings['business_type'] ?? '');
        if (in_array($type, [self::FULL, self::MENU, self::EVENT], true)) return $type;
        $plan = (string)($row['plan'] ?? '');
        return in_array($plan, [self::MENU, self::EVENT], true) ? $plan : self::FULL;
    }
}
