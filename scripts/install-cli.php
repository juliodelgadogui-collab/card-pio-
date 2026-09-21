<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$envPath = $root . '/.env';
$storagePath = $root . '/storage';
$lockPath = $storagePath . '/installed.lock';

function need(string $name): string {
    $value = trim((string)getenv($name));
    if ($value === '') throw new RuntimeException("Variável obrigatória ausente: {$name}");
    return $value;
}
function envQuote(string $value): string {
    if ($value === '') return '';
    if (preg_match('/^[A-Za-z0-9_.\-\/:]+$/', $value)) return $value;
    return '"' . addcslashes($value, "\\\"") . '"';
}
function writeEnv(string $template, string $target, array $replace): void {
    $contents = file_get_contents($template);
    if ($contents === false) throw new RuntimeException('Não foi possível ler .env.example.');
    foreach ($replace as $key => $value) {
        $line = $key . '=' . envQuote($value);
        if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $contents)) {
            $contents = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $line, $contents) ?? $contents;
        } else {
            $contents .= "\n{$line}\n";
        }
    }
    if (file_put_contents($target, $contents, LOCK_EX) === false) throw new RuntimeException('Não foi possível criar .env.');
    @chmod($target, 0640);
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('Este instalador deve ser executado via CLI.');
if (version_compare(PHP_VERSION, '8.2.0', '<')) throw new RuntimeException('PHP 8.2+ é obrigatório.');
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Extensão pdo_sqlite é obrigatória.');
if (is_file($lockPath)) throw new RuntimeException('EventMenu já instalado: storage/installed.lock encontrado.');

$appUrl = rtrim(need('EVENTMENU_INSTALL_URL'), '/');
$tenantName = need('EVENTMENU_INSTALL_TENANT');
$adminName = need('EVENTMENU_INSTALL_ADMIN_NAME');
$adminEmail = mb_strtolower(need('EVENTMENU_INSTALL_ADMIN_EMAIL'));
$adminPassword = need('EVENTMENU_INSTALL_ADMIN_PASSWORD');
if (!filter_var($appUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('EVENTMENU_INSTALL_URL inválida.');
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail do Super ADM inválido.');
if (strlen($adminPassword) < 8) throw new RuntimeException('A senha do Super ADM deve ter pelo menos 8 caracteres.');

if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) throw new RuntimeException('Falha ao criar storage.');
@mkdir($storagePath . '/backups', 0775, true);
@mkdir($storagePath . '/private/whatsapp-sessions', 0700, true);

if (!is_file($envPath)) {
    writeEnv($root . '/.env.example', $envPath, [
        'APP_NAME' => 'EventMenu Server',
        'EVENTMENU_RELEASE' => '1.0.1',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => $appUrl,
        'APP_BASE_PATH' => '/1',
        'APP_KEY' => bin2hex(random_bytes(32)),
        'CRON_SECRET' => bin2hex(random_bytes(32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_SQLITE_PATH' => 'storage/eventmenu.sqlite',
        'SESSION_SECURE' => strtolower((string)parse_url($appUrl, PHP_URL_SCHEME)) === 'https' ? 'true' : 'false',
    ]);
}

require $root . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;

$pdo = Database::connection();
try {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="super_admin"')->fetchColumn();
    if ($count > 0) throw new RuntimeException('Já existe Super ADM neste banco; instalação cancelada.');
} catch (PDOException) {
    // Banco novo ainda sem schema.
}

$schemaPath = Database::schemaPath($pdo);
$schema = file_get_contents($schemaPath);
if ($schema === false) throw new RuntimeException('Schema principal não encontrado.');
$pdo->exec($schema);
Migrator::run($pdo);

$slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantName) ?? 'empresa');
$slug = trim($slug, '-') ?: 'empresa';
Database::transaction(function (PDO $pdo) use ($tenantName, $slug, $adminName, $adminEmail, $adminPassword): void {
    $existing = $pdo->query('SELECT COUNT(*) FROM users WHERE role="super_admin"')->fetchColumn();
    if ((int)$existing > 0) throw new RuntimeException('Super ADM já existe.');
    $tenant = $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?, ?, "premium", "active")');
    $tenant->execute([$tenantName, $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 6)]);
    $user = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL, ?, ?, ?, "super_admin", "active")');
    $user->execute([$adminName, $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT)]);
});

if (file_put_contents($lockPath, date(DATE_ATOM), LOCK_EX) === false) throw new RuntimeException('Banco criado, mas falhou ao gravar installed.lock.');
@chmod($lockPath, 0640);

echo "EventMenu Server 1.0.1 instalado com SQLite em storage/eventmenu.sqlite\n";
echo "Super ADM: {$adminEmail}\n";
