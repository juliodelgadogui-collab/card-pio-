<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\TenantBrandingService;

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
        ['finance', 'Financeiro', 'finance.view'],
        ['finance-advanced', 'Financeiro avançado', 'finance.view'],
        ['gateways', 'Gateways e NFC', 'gateways.manage'],
        ['payment-providers', 'Provedores integrados', 'gateways.manage'],
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
    $unitContext = em_operational_unit_context();
    $units = $unitContext['units'];
    $currentUnit = $unitContext['current'];
    $homeRoute = Auth::isSuperAdmin() && !$currentTenantId ? 'super' : 'dashboard';

    $branding = null;
    $brandCss = '';
    if ($currentTenantId) {
        try {
            $brandService = new TenantBrandingService();
            $branding = $brandService->forTenant($currentTenantId);
            $brandCss = $brandService->cssVariables($currentTenantId);
        } catch (Throwable) {}
    }
    $brandActive = $branding && !empty($branding['apply_panel']);
    $brandName = $brandActive ? (string)$branding['display_name'] : 'EventMenu Premium';
    $brandLogo = $brandActive ? (string)$branding['logo_url'] : '';
    $themeColor = $brandActive ? (string)$branding['primary_color'] : '#21134f';
    $subtitleCompany = $brandActive ? $brandName : ($context ?: null);
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
</head>
<body<?= $brandCss!=='' ? ' style="'.Security::e($brandCss).'"' : '' ?>>
<div class="layout">
    <aside class="sidebar" aria-label="Navegação principal">
        <div class="sidebar-head">
            <a class="brand tenant-brand" href="<?= Security::e(app_url('?route=' . $homeRoute)) ?>">
                <?php if ($brandLogo !== ''): ?><img class="tenant-brand-logo" src="<?= Security::e($brandLogo) ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="tenant-brand-copy"><b><?= Security::e($brandName) ?></b><?php if($brandActive):?><small>EventMenu</small><?php endif;?></span>
            </a>
            <button type="button" class="nav-toggle" aria-label="Abrir menu" aria-expanded="false"><span></span><span></span><span></span></button>
        </div>

        <?php if ($context && !$brandActive): ?>
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
                <span class="top-eyebrow"><?= Auth::isSuperAdmin() && !$currentTenantId ? 'PLATAFORMA' : 'PAINEL' ?></span>
                <h1><?= Security::e($title) ?></h1>
                <div class="muted top-subtitle">
                    <?= $subtitleCompany ? Security::e($subtitleCompany) . ' · ' : '' ?><?= $type ? Security::e($type) : 'Gestão multiempresa' ?>
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
                <?php elseif ($subtitleCompany): ?>
                    <span class="context-pill"><?= Security::e($subtitleCompany) ?></span>
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
    $paymentProviders = json_encode(app_url('?route=payment-providers'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            if (route === 'gateways') {
                const host = document.querySelector('.page-hero .hero-actions');
                if (host && !host.querySelector('[data-payment-providers]')) {
                    const a = document.createElement('a');
                    a.className = 'button primary';
                    a.dataset.paymentProviders = '1';
                    a.href = <?= $paymentProviders ?>;
                    a.textContent = 'SumUp + PIX integrado';
                    host.prepend(a);
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
