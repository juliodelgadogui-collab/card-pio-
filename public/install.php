<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;

$lock = __DIR__ . '/../storage/installed.lock';
if (is_file($lock)) {
    http_response_code(403);
    exit('O EventMenu já está instalado. Use ' . app_url('update.php') . ' para aplicar atualizações de banco.');
}

$error = null;
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada. Atualize a página.';
    } else {
        try {
            $pdo = Database::connection();
            $schemaPath = Database::schemaPath($pdo);
            $schema = file_get_contents($schemaPath);
            if ($schema === false) throw new RuntimeException('Schema principal não encontrado para ' . Database::driver($pdo) . '.');
            $pdo->exec($schema);
            Migrator::run($pdo);

            $tenantName = trim((string)($_POST['tenant_name'] ?? 'Minha Empresa'));
            $tenantSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantName) ?? 'empresa');
            $tenantSlug = trim($tenantSlug, '-') ?: 'empresa';
            $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
            $name = trim((string)($_POST['name'] ?? 'Super Administrador'));
            $password = (string)($_POST['password'] ?? '');
            if ($tenantName === '') throw new RuntimeException('Informe o nome da empresa inicial.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
            if (strlen($password) < 10) throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');

            Database::transaction(function(\PDO $pdo) use ($tenantName, $tenantSlug, $email, $name, $password): void {
                $stmt = $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")');
                $stmt->execute([$tenantName, $tenantSlug . '-' . substr(bin2hex(random_bytes(4)), 0, 6)]);
                $stmt = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL,?,?,?,"super_admin","active")');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            });

            if (!is_dir(dirname($lock))) mkdir(dirname($lock), 0775, true);
            file_put_contents($lock, date(DATE_ATOM));
            $success = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação inicial</h1><?php if ($success): ?><div class="alert ok">Instalado com sucesso. O usuário criado é o Super ADM da plataforma. <a href="<?= Security::e(app_url('')) ?>">Entrar no sistema</a> e depois acesse a empresa inicial para cadastrar a equipe.</div><?php else: ?><?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>Empresa inicial<input name="tenant_name" required></label><label>Nome do Super ADM<input name="name" required></label><label>E-mail do Super ADM<input name="email" type="email" required></label><label>Senha do Super ADM<input name="password" type="password" minlength="10" required></label><button class="primary" type="submit">Instalar EventMenu</button></form><?php endif; ?></main></body></html>
