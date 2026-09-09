<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function hardening_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SERVER HARDENING FAILED: {$message}\n");
        exit(1);
    }
}

function hardening_file(string $root, string $path): string
{
    $full = $root . '/' . ltrim($path, '/');
    hardening_assert(is_file($full), "arquivo ausente: {$path}");
    $content = file_get_contents($full);
    hardening_assert($content !== false, "não foi possível ler: {$path}");
    return (string)$content;
}

$update = hardening_file($root, 'public/update.php');
hardening_assert(str_contains($update, 'Auth::isSuperAdmin()'), 'update.php precisa exigir Super ADM.');
hardening_assert(!str_contains($update, "requirePermission('users.manage')"), 'update.php não pode depender de users.manage.');

$install = hardening_file($root, 'public/install.php');
hardening_assert(str_contains($install, 'strlen($password) < 10'), 'instalador precisa exigir senha mínima de 10 caracteres.');
hardening_assert(str_contains($install, 'minlength="10"'), 'formulário do instalador precisa exigir 10 caracteres.');

$users = hardening_file($root, 'app/routes/users.php');
hardening_assert(substr_count($users, 'strlen($password)<10') >= 2, 'criação/edição de usuários precisa exigir 10 caracteres.');
hardening_assert(str_contains($users, 'minlength="10"'), 'formulário de usuários precisa exigir 10 caracteres.');

$resetService = hardening_file($root, 'src/Services/PasswordResetService.php');
hardening_assert(str_contains($resetService, "app_absolute_url('reset-password.php?token='"), 'recuperação precisa gerar URL absoluta.');
hardening_assert(str_contains($resetService, 'strlen($password) < 10'), 'redefinição precisa exigir 10 caracteres.');

$bootstrap = hardening_file($root, 'app/bootstrap.php');
hardening_assert(str_contains($bootstrap, 'Content-Security-Policy:'), 'CSP precisa estar habilitado.');
hardening_assert(str_contains($bootstrap, "object-src 'none'"), 'CSP precisa bloquear object-src.');
hardening_assert(str_contains($bootstrap, "base-uri 'self'"), 'CSP precisa limitar base-uri.');
hardening_assert(str_contains($bootstrap, "ini_set('session.use_strict_mode', '1')"), 'sessão precisa usar strict mode.');
hardening_assert(str_contains($bootstrap, "ini_set('session.use_only_cookies', '1')"), 'sessão precisa usar apenas cookies.');

$package = hardening_file($root, 'scripts/build-package.php');
foreach (['BUILD-MANIFEST\\.json', 'VERSION\\.txt', '\\.(?:sqlite3?', 'Header always set X-Content-Type-Options'] as $needle) {
    hardening_assert(str_contains($package, $needle), 'pacote de produção perdeu regra de proteção: ' . $needle);
}

echo "Server hardening smoke OK\n";
