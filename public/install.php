<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$storagePath = $root . '/storage';
$lock = $storagePath . '/installed.lock';

/** @param array<string,string> $values */
function eventmenu_install_runtime_env(array $values): void
{
    foreach ($values as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
    }
}

function eventmenu_install_detect_url(): string
{
    $configured = trim((string)env('APP_URL', ''));
    if ($configured !== '') return rtrim($configured, '/');
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $forwardedProto === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    return $scheme . '://' . $host;
}

/** @return array<string,string> */
function eventmenu_install_build_env(string $appUrl): array
{
    $appUrl = rtrim(trim($appUrl), '/');
    if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
        throw new RuntimeException('Informe uma URL válida do sistema, começando com http:// ou https://.');
    }
    $secure = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) === 'https' ? 'true' : 'false';
    return [
        'APP_NAME' => 'EventMenu Premium','APP_ENV' => 'production','APP_DEBUG' => 'false','APP_URL' => $appUrl,'APP_BASE_PATH' => app_base_path(),'APP_KEY' => Security::randomKey(32),'CRON_SECRET' => Security::randomKey(32),'DB_CONNECTION' => 'sqlite','DB_SQLITE_PATH' => 'storage/eventmenu.sqlite','DB_HOST' => '127.0.0.1','DB_PORT' => '3306','DB_DATABASE' => 'eventmenu','DB_USERNAME' => 'root','DB_PASSWORD' => '','SESSION_NAME' => 'eventmenu_session','SESSION_SECURE' => $secure,'PAYMENT_CURRENCY' => 'BRL','STRIPE_WEBHOOK_SECRET' => '','PAGBANK_WEBHOOK_SECRET' => '','MERCADOPAGO_WEBHOOK_SECRET' => '',
    ];
}

/** @param array<string,string> $values */
function eventmenu_install_write_env(string $path, array $values): void
{
    $quote = static function (string $value): string {
        if ($value === '') return '';
        if (preg_match('/^[A-Za-z0-9_\.\-\/:]+$/', $value)) return $value;
        return '"' . addcslashes($value, "\\\"") . '"';
    };
    $lines = [];
    foreach ($values as $key => $value) $lines[] = $key . '=' . $quote($value);
    $contents = implode("\n", $lines) . "\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) throw new RuntimeException('Não foi possível criar o arquivo .env. Verifique a permissão de escrita da pasta do sistema.');
    @chmod($path, 0640);eventmenu_install_runtime_env($values);
}

if (!is_file($envPath)) {
    eventmenu_install_runtime_env(['APP_ENV'=>'production','APP_DEBUG'=>'false','DB_CONNECTION'=>'sqlite','DB_SQLITE_PATH'=>'storage/eventmenu.sqlite']);
}

$preflight = [
    'PHP 8.2 ou superior' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'Extensão PDO SQLite' => in_array('sqlite', PDO::getAvailableDrivers(), true),
    'Schema SQLite disponível' => is_file($root . '/database/sqlite/schema.sql') && is_readable($root . '/database/sqlite/schema.sql'),
    'Dependências PHP instaladas' => is_file($root . '/vendor/autoload.php'),
    'Pasta storage gravável' => (is_dir($storagePath) && is_writable($storagePath)) || (!is_dir($storagePath) && is_writable($root)),
    'Configuração .env gravável' => is_file($envPath) ? is_writable($envPath) : is_writable($root),
];
$preflightOk = !in_array(false, $preflight, true);

$alreadyInstalled = is_file($lock);
if (!$alreadyInstalled) {
    try {
        $probe = Database::connection();$count = $probe->query('SELECT COUNT(*) FROM users WHERE role="super_admin"')->fetchColumn();
        if ((int)$count > 0) {$alreadyInstalled = true;if (!is_dir(dirname($lock))) @mkdir(dirname($lock), 0775, true);@file_put_contents($lock, date(DATE_ATOM), LOCK_EX);}
    } catch (Throwable) {}
}

if ($alreadyInstalled) {http_response_code(403);exit('O EventMenu já está instalado. Entre no painel e use ' . Security::e(app_url('update.php')) . ' para aplicar atualizações de banco.');}

$error = null;$success = false;$detectedUrl = eventmenu_install_detect_url();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) $error = 'Sessão expirada. Atualize a página.';
    elseif (!$preflightOk) $error = 'O servidor ainda não atende todos os requisitos exibidos abaixo.';
    else {
        try {
            if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) throw new RuntimeException('Não foi possível criar a pasta storage.');
            if (!is_file($envPath)) { $initialEnv = eventmenu_install_build_env((string)($_POST['app_url'] ?? $detectedUrl));eventmenu_install_write_env($envPath, $initialEnv); }
            if (strtolower((string)env('DB_CONNECTION', 'sqlite')) !== 'sqlite') throw new RuntimeException('A primeira instalação deste pacote está preparada para SQLite. Ajuste DB_CONNECTION=sqlite no .env.');
            $pdo = Database::connection();
            try {$existing = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="super_admin"')->fetchColumn();if ($existing > 0) throw new RuntimeException('Já existe um Super ADM neste banco. A instalação foi bloqueada.');} catch (PDOException) {}
            $schemaPath = Database::schemaPath($pdo);$schema = file_get_contents($schemaPath);if ($schema === false) throw new RuntimeException('Schema principal não encontrado para ' . Database::driver($pdo) . '.');$pdo->exec($schema);Migrator::run($pdo);
            $tenantName = trim((string)($_POST['tenant_name'] ?? 'Minha Empresa'));$tenantSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantName) ?? 'empresa');$tenantSlug = trim($tenantSlug, '-') ?: 'empresa';$email = mb_strtolower(trim((string)($_POST['email'] ?? '')));$name = trim((string)($_POST['name'] ?? 'Super Administrador'));$password = (string)($_POST['password'] ?? '');
            if ($tenantName === '') throw new RuntimeException('Informe o nome da empresa inicial.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
            if (strlen($password) < 6) throw new RuntimeException('A senha precisa ter pelo menos 6 caracteres.');
            Database::transaction(function (PDO $pdo) use ($tenantName, $tenantSlug, $email, $name, $password): void {
                $existing = $pdo->prepare(Database::portableSql($pdo, 'SELECT id FROM users WHERE role="super_admin" LIMIT 1 FOR UPDATE'));$existing->execute();if ($existing->fetchColumn()) throw new RuntimeException('A plataforma já possui Super ADM.');
                $stmt = $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?, ?, "premium", "active")');$stmt->execute([$tenantName, $tenantSlug . '-' . substr(bin2hex(random_bytes(4)), 0, 6)]);
                $stmt = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL, ?, ?, ?, "super_admin", "active")');$stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            });
            if (file_put_contents($lock, date(DATE_ATOM), LOCK_EX) === false) throw new RuntimeException('Instalação criada no banco, mas não foi possível gravar o arquivo de lock. Não execute o instalador novamente.');
            @chmod($lock, 0640);$success = true;
        } catch (Throwable $e) {$error = $e->getMessage();}
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação inicial</h1>
<?php if ($success): ?><div class="alert ok">Instalado com sucesso usando SQLite. O banco foi criado em <strong>storage/eventmenu.sqlite</strong> e o usuário informado é o Super ADM da plataforma. <a href="<?= Security::e(app_url('')) ?>">Entrar no sistema</a>.</div><?php else: ?><?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?><h2>Verificação do servidor</h2><ul><?php foreach ($preflight as $label => $ok): ?><li><?= $ok ? '✅' : '❌' ?> <?= Security::e($label) ?></li><?php endforeach; ?></ul><?php if ($preflightOk): ?><p class="muted">O instalador criará automaticamente o arquivo <strong>.env</strong>, chaves privadas aleatórias, a pasta <strong>storage</strong> e o banco SQLite. Não é necessário criar banco manualmente.</p><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>URL do sistema<input name="app_url" type="url" value="<?= Security::e((string)($_POST['app_url'] ?? $detectedUrl)) ?>" required></label><label>Empresa inicial<input name="tenant_name" value="<?= Security::e((string)($_POST['tenant_name'] ?? '')) ?>" required></label><label>Nome do Super ADM<input name="name" value="<?= Security::e((string)($_POST['name'] ?? '')) ?>" required></label><label>E-mail do Super ADM<input name="email" type="email" value="<?= Security::e((string)($_POST['email'] ?? '')) ?>" required></label><label>Senha do Super ADM<input name="password" type="password" minlength="6" required><small>Mínimo de 6 caracteres.</small></label><button class="primary" type="submit">Instalar EventMenu com SQLite</button></form><?php else: ?><div class="alert error">Corrija os itens marcados com ❌ no servidor antes de continuar.</div><?php endif; ?><?php endif; ?></main></body></html>