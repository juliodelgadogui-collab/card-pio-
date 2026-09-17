<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Support\JcLancheDemoSeeder;

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Acesso restrito ao Super ADM.');
}

$error = null;
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada. Atualize a página.';
    } else {
        try {
            JcLancheDemoSeeder::run(Database::connection());
            $done = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Preparar JC Lanche</title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"></head><body class="auth-page"><main class="auth-card" style="max-width:720px"><div class="brand">EventMenu <span>Premium</span></div><h1>Preparar JC Lanche</h1><?php if($error):?><div class="alert error"><?=Security::e($error)?></div><?php endif;?><?php if($done):?><div class="alert ok"><strong>JC Lanche pronta.</strong><br>Super ADM: juliodelgadogui@gmail.com / 1<br>Administrador: A@1.com / 1<br>Entregador Carlos: 1@1.com / 1<br>28 itens de cardápio e 20 mesas cadastradas.</div><p><a class="primary" href="<?=Security::e(app_url(''))?>">Abrir EventMenu</a></p><?php else:?><p>Esta etapa cria ou atualiza a empresa de demonstração sem duplicar os dados existentes.</p><ul><li>JC Lanche — Premium</li><li>Administrador e entregador Carlos</li><li>5 categorias e 28 itens</li><li>20 mesas livres</li><li>Senhas de demonstração: 1</li></ul><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrfToken())?>"><button class="primary" type="submit">Criar JC Lanche agora</button></form><?php endif;?></main></body></html>
