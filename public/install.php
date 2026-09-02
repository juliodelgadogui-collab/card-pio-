<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;

$lock = __DIR__ . '/../storage/installed.lock';
if (is_file($lock)) {
    http_response_code(403);
    exit('O EventMenu já está instalado. Remova storage/installed.lock apenas se souber o que está fazendo.');
}

$error = null;
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada. Atualize a página.';
    } else {
        try {
            $pdo = Database::connection();
            $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
            $pdo->exec($schema);

            $tenantName = trim((string)($_POST['tenant_name'] ?? 'Minha Empresa'));
            $tenantSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantName) ?? 'empresa');
            $tenantSlug = trim($tenantSlug, '-') ?: 'empresa';
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $name = trim((string)($_POST['name'] ?? 'Administrador'));
            $password = (string)($_POST['password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
            if (strlen($password) < 10) throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")');
            $stmt->execute([$tenantName, $tenantSlug . '-' . substr(bin2hex(random_bytes(4)), 0, 6)]);
            $tenantId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")');
            $stmt->execute([$tenantId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $pdo->commit();

            if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0775, true);
            file_put_contents($lock, date(DATE_ATOM));
            $success = true;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação inicial</h1><?php if ($success): ?><div class="alert ok">Instalado com sucesso. <a href="/">Entrar no sistema</a>.</div><?php else: ?><?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>Empresa<input name="tenant_name" required></label><label>Seu nome<input name="name" required></label><label>E-mail do administrador<input name="email" type="email" required></label><label>Senha<input name="password" type="password" minlength="10" required></label><button class="primary" type="submit">Instalar EventMenu</button></form><?php endif; ?></main></body></html>
