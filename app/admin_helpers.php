<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\TenantBrandService;

$pdo = Database::connection();
Auth::enforceCurrentUser();
$tenantId = Auth::tenantId();

function em_money(int|float|string $cents): string
{
    return 'R$ ' . number_format(((int)$cents) / 100, 2, ',', '.');
}

function em_csrf(): string
{
    return Security::e(Security::csrfToken());
}

function em_post_csrf(): void
{
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página.');
    }
}

function em_go(string $route, array $params = []): never
{
    $params = ['route' => $route] + $params;
    header('Location: ' . app_url('?' . http_build_query($params)));
    exit;
}

function em_slug(string $value): string
{
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?? '', '-'));
    return $value ?: bin2hex(random_bytes(3));
}

function em_selected(mixed $a, mixed $b): string
{
    return (string)$a === (string)$b ? ' selected' : '';
}

function em_checked(bool|int|string $value): string
{
    return (bool)$value ? ' checked' : '';
}

function em_flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null && $message !== null) {
        $_SESSION['_flash'] = [$type, $message];
        return null;
    }

    $flash = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $flash;
}

function em_asset_version(string $relative): string
{
    $path = dirname(__DIR__) . '/public/' . ltrim($relative, '/');
    $mtime = @filemtime($path);
    return $mtime !== false ? (string)$mtime : '1';
}

function em_can_nav(string $permission): bool
{
    if ($permission === 'platform.manage') return Auth::isSuperAdmin();
    if (Auth::isSuperAdmin() && !Auth::tenantId()) return false;
    if ($permission === 'reports.any') return Auth::can('reports.view') || Auth::can('reports.own');
    return Auth::can($permission);
}

function em_nav(): array
{
    return [
        ['super', 'Plataforma', 'platform.manage'],
        ['dashboard', 'Visão geral', 'dashboard'],
        ['pos', 'Caixa / PDV', 'orders.create'],
        ['pickup', 'Retirada / QR', 'orders.fulfill'],
        ['cash', 'Turno de caixa', 'cash.manage'],
        ['kitchen', 'Cozinha / KDS', 'orders.kitchen'],
        ['production', 'Produção / Estações', 'production.manage'],
        ['delivery', 'Entregas', 'orders.delivery'],
        ['products', 'Cardápio', 'catalog.manage'],
        ['inventory', 'Estoque', 'inventory.manage'],
        ['purchases', 'Compras / Fornecedores', 'inventory.manage'],
        ['orders', 'Pedidos', 'orders.view'],
        ['restaurant', 'Mesas e comandas', 'tables.manage'],
        ['customers', 'Clientes e pontos', 'customers.manage'],
        ['coupons', 'Cupons', 'coupons.manage'],
        ['events', 'Eventos', 'events.manage'],
        ['tickets', 'Ingressos / Check-in', 'tickets.manage'],
        ['guests', 'Convidados', 'guests.manage'],
        ['promoters', 'Promotores', 'promoters.manage'],
        ['payments', 'Pagamentos', 'payments.manage'],
        ['gateways', 'Gateways e NFC', 'gateways.manage'],
        ['users', 'Equipe', 'users.manage'],
        ['units', 'Unidades', 'settings.manage'],
        ['reports', 'Relatórios', 'reports.any'],
        ['audit', 'Auditoria', 'audit.view'],
        ['settings', 'Configurações', 'settings.manage'],
    ];
}

function em_context_tenant_name(): ?string
{
    if (!Auth::isSuperAdmin() || !Auth::tenantId()) return null;
    static $name = null;
    static $loaded = false;
    if ($loaded) return $name;
    $loaded = true;
    $stmt = Database::connection()->prepare('SELECT name FROM tenants WHERE id=?');
    $stmt->execute([Auth::tenantId()]);
    $value = $stmt->fetchColumn();
    $name = $value !== false ? (string)$value : null;
    return $name;
}

function em_tenant_brand(): ?array
{
    $tenantId = Auth::tenantId();
    if (!$tenantId) return null;
    static $cache = [];
    if (!isset($cache[$tenantId])) $cache[$tenantId] = (new TenantBrandService())->get($tenantId);
    return $cache[$tenantId];
}

function em_operational_unit_context(): array
{
    if (!Auth::tenantId()) return ['units' => [], 'current' => null];
    try {
        $service = new OperatingUnitService();
        return ['units' => $service->availableForCurrentUser(), 'current' => $service->current()];
    } catch (Throwable) {
        return ['units' => [], 'current' => null];
    }
}

function em_header(string $title, string $active): void
{
    $flash = em_flash();
    $manifest = Security::e(app_url('manifest.webmanifest?v=' . em_asset_version('manifest.webmanifest')));
    $css = Security::e(app_url('assets/app.css?v=' . em_asset_version('assets/app.css')));
    $premiumCss = Security::e(app_url('assets/premium-v4.css?v=' . em_asset_version('assets/premium-v4.css')));
    $currentTenantId = Auth::tenantId();
    $context = em_context_tenant_name();
    $type = $currentTenantId ? TenantFeatures::label($currentTenantId) : null;
    $brand = $currentTenantId ? em_tenant_brand() : null;
    $brandActive = $brand && !empty($brand['apply_web']);
    $brandName = $brandActive ? trim((string)$brand['display_name']) : '';
    $brandName = $brandName !== '' ? $brandName : 'EventMenu Premium';
    $themeColor = $brandActive ? (string)$brand['primary_color'] : '#21134f';
    $unitContext = em_operational_unit_context();
    $units = $unitContext['units'];
    $currentUnit = $unitContext['current'];
    $homeRoute = Auth::isSuperAdmin() && !$currentTenantId ? 'super' : 'dashboard';
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="<?= Security::e($themeColor) ?>">
    <meta name="color-scheme" content="light">
    <title><?= Security::e($title) ?> — <?= Security::e($brandName) ?></title>
    <link rel="manifest" href="<?= $manifest ?>">
    <link rel="stylesheet" href="<?= $css ?>">
    <link rel="stylesheet" href="<?= $premiumCss ?>">
    <?php if ($brandActive): ?>
    <style>
      :root{
        --em-primary:<?= Security::e($brand['primary_color']) ?>;
        --em-primary-2:<?= Security::e($brand['primary_color']) ?>;
        --em-bg:<?= Security::e($brand['background_color']) ?>;
        --em-bg-soft:<?= Security::e($brand['background_color']) ?>;
        --em-surface:<?= Security::e($brand['surface_color']) ?>;
        --em-surface-2:<?= Security::e($brand['surface_color']) ?>;
        --em-text:<?= Security::e($brand['text_color']) ?>;
        --em-success:<?= Security::e($brand['secondary_color']) ?>;
        --accent:<?= Security::e($brand['primary_color']) ?>;
        --accent2:<?= Security::e($brand['primary_color']) ?>;
        --bg:<?= Security::e($brand['background_color']) ?>;
        --panel:<?= Security::e($brand['surface_color']) ?>;
        --text:<?= Security::e($brand['text_color']) ?>;
        --ok:<?= Security::e($brand['secondary_color']) ?>;
      }
      .sidebar{background:linear-gradient(180deg,<?= Security::e($brand['primary_color']) ?>,color-mix(in srgb,<?= Security::e($brand['primary_color']) ?> 76%,#111),color-mix(in srgb,<?= Security::e($brand['primary_color']) ?> 62%,#080808))}
      .nav a.active,.primary{background:<?= Security::e($brand['primary_color']) ?>}
      .brand-logo{width:30px;height:30px;border-radius:8px;object-fit:contain;background:#fff;margin-right:8px;vertical-align:middle}
      .tenant-brand-signature{font-size:9px;opacity:.65;margin-left:6px;font-weight:600}
    </style>
    <?php endif; ?>
</head>
<body>
<div class="layout">
    <aside class="sidebar" aria-label="Navegação principal">
        <div class="sidebar-head">
            <a class="brand" href="<?= Security::e(app_url('?route=' . $homeRoute)) ?>">
                <?php if ($brandActive && !empty($brand['logo_url'])): ?><img class="brand-logo" src="<?= Security::e($brand['logo_url']) ?>" alt=""><?php endif; ?>
                <?= Security::e($brandName) ?>
                <?php if ($brandActive && !empty($brand['show_eventmenu_brand']) && strcasecmp($brandName,'EventMenu Premium')!==0): ?><span class="tenant-brand-signature">EventMenu</span><?php elseif (!$brandActive): ?><span>Premium</span><?php endif; ?>
            </a>
            <button type="button" class="nav-toggle" aria-label="Abrir menu" aria-expanded="false"><span></span><span></span><span></span></button>
        </div>

        <?php if ($context): ?>
            <div class="tenant-context">
                <small>Empresa em contexto</small>
                <strong><?= Security::e($context) ?></strong>
                <?php if ($type): ?><span><?= Security::e($type) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>

        <nav class="nav">
            <?php foreach (em_nav() as [$route, $label, $permission]): ?>
                <?php
                if (!em_can_nav($permission)) continue;
                if ($currentTenantId && !TenantFeatures::routeEnabled($route, $currentTenantId)) continue;
                ?>
                <a data-route="<?= Security::e($route) ?>" class="<?= $active === $route ? 'active' : '' ?>" href="<?= Security::e(app_url('?route=' . urlencode($route))) ?>"><?= Security::e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-foot">
            <div class="user-chip">
                <span class="user-avatar"><?= Security::e(mb_strtoupper(mb_substr(Auth::name(), 0, 1))) ?></span>
                <div><strong><?= Security::e(Auth::name()) ?></strong><small><?= Security::e((string)Auth::role()) ?></small></div>
            </div>
            <div class="foot-links">
                <?php if (Auth::isSuperAdmin() && $currentTenantId): ?><a href="<?= Security::e(app_url('?route=super&leave=1')) ?>">Sair da empresa</a><?php endif; ?>
                <a href="<?= Security::e(app_url('?route=logout')) ?>">Encerrar sessão</a>
            </div>
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <span class="top-eyebrow"><?= Auth::isSuperAdmin() && !$currentTenantId ? 'PLATAFORMA' : ($brandActive ? Security::e(mb_strtoupper($brandName)) : 'PAINEL') ?></span>
                <h1><?= Security::e($title) ?></h1>
                <div class="muted top-subtitle">
                    <?php if ($brandActive && !empty($brand['tagline'])): ?><?= Security::e($brand['tagline']) ?><?php else: ?><?= $context ? Security::e($context) . ' · ' : '' ?><?= $type ? Security::e($type) : 'Gestão multiempresa' ?><?php endif; ?>
                    <?php if ($currentUnit): ?> · <strong><?= Security::e($currentUnit['name']) ?></strong><?php endif; ?>
                </div>
            </div>
            <div class="topbar-actions">
                <?php if (count($units) > 1): ?>
                    <form method="post" action="<?= Security::e(app_url('?route=unit-context')) ?>" class="unit-switcher">
                        <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
                        <input type="hidden" name="back_route" value="<?= Security::e($active) ?>">
                        <label><span>Unidade</span>
                            <select name="unit_id" onchange="this.form.submit()">
                                <option value="0"<?= $currentUnit ? '' : ' selected' ?>>Escolher unidade</option>
                                <?php foreach ($units as $unit): ?>
                                    <option value="<?= (int)$unit['id'] ?>"<?= $currentUnit && (int)$currentUnit['id'] === (int)$unit['id'] ? ' selected' : '' ?>><?= Security::e($unit['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>
                <?php elseif ($currentUnit): ?>
                    <span class="context-pill"><?= Security::e($currentUnit['name']) ?></span>
                <?php elseif ($brandActive): ?>
                    <span class="context-pill"><?= Security::e($brandName) ?></span>
                <?php elseif ($context): ?>
                    <span class="context-pill"><?= Security::e($context) ?></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($flash): ?><div class="alert <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>
    <?php
}

function em_footer(): void
{
    $sw = json_encode(app_url('sw.js?v=' . em_asset_version('sw.js')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $receiptBase = json_encode(app_url('?route=receipt&id='), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $receiptSettings = json_encode(app_url('?route=receipt-settings'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?>
        <script>
        (() => {
            const sidebar = document.querySelector('.sidebar');
            const toggle = document.querySelector('.nav-toggle');
            const closeMenu = () => {
                sidebar?.classList.remove('nav-open');
                toggle?.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('menu-open');
            };
            toggle?.addEventListener('click', () => {
                const open = !sidebar?.classList.contains('nav-open');
                sidebar?.classList.toggle('nav-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.body.classList.toggle('menu-open', open);
            });
            document.querySelectorAll('.nav a').forEach(a => a.addEventListener('click', closeMenu));
            window.addEventListener('resize', () => { if (window.innerWidth > 950) closeMenu(); });

            const params = new URLSearchParams(location.search);
            const route = params.get('route') || 'dashboard';
            if (route === 'orders' && /^\d+$/.test(params.get('view') || '')) {
                const id = params.get('view');
                const host = document.querySelector('.page-hero .hero-actions');
                if (host && !host.querySelector('[data-receipt-action]')) {
                    const a = document.createElement('a');
                    a.className = 'button primary';
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.dataset.receiptAction = '1';
                    a.href = <?= $receiptBase ?> + encodeURIComponent(id);
                    a.textContent = 'Imprimir cupom #' + id;
                    host.prepend(a);
                }
            }
            if (route === 'settings') {
                const host = document.querySelector('.page-hero .hero-actions');
                if (host && !host.querySelector('[data-receipt-settings]')) {
                    const a = document.createElement('a');
                    a.className = 'button secondary';
                    a.dataset.receiptSettings = '1';
                    a.href = <?= $receiptSettings ?>;
                    a.textContent = 'Impressão térmica';
                    host.append(a);
                }
            }
            if ('serviceWorker' in navigator) navigator.serviceWorker.register(<?= $sw ?>).catch(() => {});
        })();
        </script>
    </main>
</div>
</body>
</html>
    <?php
}

function em_require_tenant(): int
{
    $id = Auth::tenantId();
    if (!$id) {
        if (Auth::isSuperAdmin()) em_go('super');
        http_response_code(403);
        exit('Selecione uma empresa.');
    }
    return $id;
}
