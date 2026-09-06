<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;

$pdo = Database::connection();
$token = trim((string)($_GET['t'] ?? ''));
$stmt = $pdo->prepare('SELECT rt.*,t.name tenant_name,t.slug tenant_slug,t.settings tenant_settings FROM restaurant_tables rt JOIN tenants t ON t.id=rt.tenant_id WHERE rt.qr_token=? AND rt.status<>"inactive" AND t.status="active" LIMIT 1');
$stmt->execute([$token]);
$table = $stmt->fetch();
if (!$table || !TenantFeatures::menu((int)($table['tenant_id'] ?? 0))) {
    http_response_code(404);
    exit('Mesa não encontrada ou indisponível.');
}

$settings = json_decode((string)($table['tenant_settings'] ?? '{}'), true) ?: [];
$color = static function(mixed $v,string $fallback):string{$v=strtolower(trim((string)$v));return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$fallback;};
$primary = $color($settings['menu_primary_color'] ?? '', '#f4b942');
$background = $color($settings['menu_background_color'] ?? '', '#0b0d12');
$surface = $color($settings['menu_surface_color'] ?? '', '#141821');
$text = $color($settings['menu_text_color'] ?? '', '#f5f7fb');
$title = trim((string)($settings['menu_public_title'] ?? '')) ?: (string)$table['tenant_name'];
$logo = trim((string)($settings['menu_logo_url'] ?? ''));
$statusLabel = ['available'=>'Disponível','occupied'=>'Em atendimento','reserved'=>'Reservada'][(string)$table['status']] ?? 'Em atendimento';
$menuUrl = app_url('menu.php?empresa=' . urlencode((string)$table['tenant_slug']) . '&mesa=' . urlencode($token));
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="<?= Security::e($background) ?>"><title><?= Security::e($title) ?> — <?= Security::e($table['name']) ?></title><style>
:root{--bg:<?= Security::e($background) ?>;--surface:<?= Security::e($surface) ?>;--text:<?= Security::e($text) ?>;--primary:<?= Security::e($primary) ?>;--muted:color-mix(in srgb,var(--text) 62%,transparent);--line:color-mix(in srgb,var(--text) 14%,transparent)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,color-mix(in srgb,var(--primary) 12%,var(--bg)),var(--bg) 42%);color:var(--text);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}.shell{min-height:100vh;display:grid;place-items:center;padding:22px}.card{width:min(560px,100%);background:var(--surface);border:1px solid var(--line);border-radius:30px;padding:30px;box-shadow:0 30px 80px #0006}.brand{display:flex;align-items:center;gap:12px}.logo{width:50px;height:50px;border-radius:16px;object-fit:cover;background:var(--primary);display:grid;place-items:center;color:#111;font-weight:950;font-size:20px}.brand strong{display:block;font-size:16px}.brand small{color:var(--muted)}.content{padding:34px 0 10px}.eyebrow{font-size:11px;letter-spacing:.15em;color:var(--primary);font-weight:900}.content h1{font-size:clamp(42px,12vw,72px);line-height:.95;letter-spacing:-2px;margin:8px 0 14px}.status{display:inline-flex;padding:7px 10px;border-radius:999px;background:color-mix(in srgb,var(--primary) 14%,transparent);border:1px solid color-mix(in srgb,var(--primary) 30%,transparent);font-size:12px;font-weight:800}.content p{color:var(--muted);font-size:15px;line-height:1.55;max-width:460px;margin:20px 0}.primary{display:flex;align-items:center;justify-content:center;width:100%;border-radius:16px;padding:15px 18px;background:var(--primary);color:#111;text-decoration:none;font-weight:950;font-size:16px}.helper{display:flex;justify-content:center;color:var(--muted);font-size:12px;margin-top:14px}@media(max-width:520px){.shell{padding:14px}.card{padding:22px;border-radius:24px}.content{padding-top:28px}}
</style></head><body><main class="shell"><section class="card"><div class="brand"><?php if($logo!==''):?><img class="logo" src="<?= Security::e($logo) ?>" alt=""><?php else:?><div class="logo"><?= Security::e(mb_strtoupper(mb_substr($title,0,1))) ?></div><?php endif;?><div><strong><?= Security::e($title) ?></strong><small>Cardápio digital</small></div></div><div class="content"><span class="eyebrow">VOCÊ ESTÁ NA</span><h1><?= Security::e($table['name']) ?></h1><span class="status"><?= Security::e($statusLabel) ?></span><p>Faça seu pedido pelo celular. Tudo o que você adicionar por este QR fica ligado automaticamente a esta mesa.</p><a class="primary" href="<?= Security::e($menuUrl) ?>">Ver cardápio e pedir</a><span class="helper">Não é necessário informar o número da mesa novamente.</span></div></section></main></body></html>
