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
hardening_assert(str_contains($install, 'strlen($pass)<1'), 'instalador precisa rejeitar senha vazia.');
hardening_assert(str_contains($install, 'minlength="1"'), 'formulário do instalador precisa aceitar senha curta sem aceitar senha vazia.');
hardening_assert(str_contains($install, 'HTTPS é obrigatório em produção.'), 'instalador precisa bloquear domínio real em HTTP.');
hardening_assert(str_contains($install, "['localhost','127.0.0.1','::1']"), 'instalador deve permitir HTTP somente em ambiente local.');
hardening_assert(str_contains($install, "value=\"sqlite\""), 'instalador precisa oferecer SQLite.');
hardening_assert(str_contains($install, "value=\"mysql\""), 'instalador precisa oferecer MySQL/MariaDB.');
hardening_assert(str_contains($install, "PDO::getAvailableDrivers()"), 'instalador precisa validar os drivers PDO disponíveis.');
hardening_assert(str_contains($install, "Database::connection()"), 'instalador precisa abrir a conexão selecionada antes de concluir.');
hardening_assert(str_contains($install, "'DB_CONNECTION'=>\$driver"), 'instalador precisa persistir o banco escolhido no .env.');

$users = hardening_file($root, 'app/routes/users.php');
hardening_assert(str_contains($users, 'strlen($password)>200'), 'gestão de usuários precisa limitar o tamanho máximo da senha.');

$resetService = hardening_file($root, 'src/Services/PasswordResetService.php');
hardening_assert(str_contains($resetService, "app_absolute_url('reset-password.php?token='"), 'recuperação precisa gerar URL absoluta.');
hardening_assert(str_contains($resetService, 'strlen($password) > 200'), 'redefinição precisa limitar o tamanho máximo da senha.');

$bootstrap = hardening_file($root, 'app/bootstrap.php');
hardening_assert(str_contains($bootstrap, 'Content-Security-Policy:'), 'CSP precisa estar habilitado.');
hardening_assert(str_contains($bootstrap, "object-src 'none'"), 'CSP precisa bloquear object-src.');
hardening_assert(str_contains($bootstrap, "base-uri 'self'"), 'CSP precisa limitar base-uri.');
hardening_assert(str_contains($bootstrap, "frame-src 'self' https://maps.google.com https://www.google.com"), 'CSP precisa manter mapa público de eventos funcional.');
hardening_assert(str_contains($bootstrap, "ini_set('session.use_strict_mode', '1')"), 'sessão precisa usar strict mode.');
hardening_assert(str_contains($bootstrap, "ini_set('session.use_only_cookies', '1')"), 'sessão precisa usar apenas cookies.');

$package = hardening_file($root, 'scripts/build-package.php');
foreach (['BUILD-MANIFEST\\.json', 'VERSION\\.txt', '\\.(?:sqlite3?', 'Header always set X-Content-Type-Options'] as $needle) {
    hardening_assert(str_contains($package, $needle), 'pacote de produção perdeu regra de proteção: ' . $needle);
}

echo "Server hardening smoke OK\n";
