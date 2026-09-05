<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;

$pdo = Database::connection();
$token = trim((string)($_GET['t'] ?? ''));
$stmt = $pdo->prepare('SELECT rt.*,t.name tenant_name,t.slug tenant_slug FROM restaurant_tables rt JOIN tenants t ON t.id=rt.tenant_id WHERE rt.qr_token=? AND rt.status<>"inactive" AND t.status="active" LIMIT 1');
$stmt->execute([$token]);
$table = $stmt->fetch();
if (!$table) {
    http_response_code(404);
    exit('Mesa não encontrada ou indisponível.');
}

$menuUrl = app_url('menu.php?empresa=' . urlencode((string)$table['tenant_slug']) . '&mesa=' . urlencode($token));
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0f14"><title><?= Security::e($table['tenant_name']) ?> — <?= Security::e($table['name']) ?></title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"><style>.table-shell{max-width:640px;margin:0 auto;padding:32px 18px;min-height:100vh;display:grid;place-items:center}.table-card{width:100%;padding:34px;text-align:center}.table-card h1{font-size:clamp(36px,10vw,64px);margin:16px 0}.table-card .primary{display:inline-flex;justify-content:center;width:100%;margin-top:18px}</style></head><body><main class="table-shell"><section class="card table-card"><div class="brand">EventMenu <span>Premium</span></div><p class="muted"><?= Security::e($table['tenant_name']) ?></p><h1><?= Security::e($table['name']) ?></h1><span class="badge"><?= Security::e($table['status']) ?></span><p>Você está no cardápio desta mesa. Os pedidos feitos por este QR ficam vinculados diretamente à mesa.</p><a class="button primary" href="<?= Security::e($menuUrl) ?>">Abrir cardápio</a></section></main></body></html>
