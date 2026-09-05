<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;

if (!Auth::check()) app_redirect('?route=login');
Auth::requirePermission('users.manage');
$error = null;
$applied = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'CSRF inválido.';
    } else {
        try {
            $applied = Migrator::run();
            Auth::audit('system.migrations', 'system', null, ['applied' => $applied]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Atualizar EventMenu</title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Atualização do banco</h1><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><?php if($_SERVER['REQUEST_METHOD']==='POST'&&!$error):?><div class="alert ok"><?= $applied ? 'Aplicadas: '.Security::e(implode(', ',$applied)) : 'Banco já está atualizado.' ?></div><?php endif;?><p class="muted">As migrações são registradas e executadas uma única vez.</p><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><button class="primary">Aplicar atualizações</button></form><p><a href="<?= Security::e(app_url('')) ?>">Voltar ao painel</a></p></main></body></html>
