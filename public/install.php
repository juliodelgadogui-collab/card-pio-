<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;
use PDO;
use PDOException;
use RuntimeException;

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

function eventmenu_install_driver(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === 'mariadb') $value = 'mysql';
    if (!in_array($value, ['sqlite','mysql'], true)) throw new RuntimeException('Escolha SQLite ou MySQL/MariaDB.');
    return $value;
}

/** @param array<string,mixed> $input @return array<string,string> */
function eventmenu_install_build_env(string $appUrl, string $driver, array $input): array
{
    $appUrl = rtrim(trim($appUrl), '/');
    if (!filter_var($appUrl, FILTER_VALIDATE_URL)) throw new RuntimeException('Informe uma URL válida do sistema, começando com https://.');
    $scheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
    $host = strtolower(trim((string)parse_url($appUrl, PHP_URL_HOST)));
    $localHosts = ['localhost','127.0.0.1','::1'];
    if ($scheme !== 'https' && !in_array($host, $localHosts, true)) throw new RuntimeException('HTTPS é obrigatório para instalar o EventMenu em produção.');

    $driver = eventmenu_install_driver($driver);
    $values = [
        'APP_NAME' => 'EventMenu Premium',
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_URL' => $appUrl,
        'APP_BASE_PATH' => app_base_path(),
        'APP_KEY' => Security::randomKey(32),
        'CRON_SECRET' => Security::randomKey(32),
        'DB_CONNECTION' => $driver,
        'DB_SQLITE_PATH' => 'storage/eventmenu.sqlite',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'eventmenu',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => '',
        'SESSION_NAME' => 'eventmenu_session',
        'SESSION_SECURE' => $scheme === 'https' ? 'true' : 'false',
        'PAYMENT_CURRENCY' => 'BRL',
        'STRIPE_WEBHOOK_SECRET' => '',
        'PAGBANK_WEBHOOK_SECRET' => '',
        'MERCADOPAGO_WEBHOOK_SECRET' => '',
    ];

    if ($driver === 'mysql') {
        $dbHost = trim((string)($input['db_host'] ?? '127.0.0.1'));
        $dbPort = (int)($input['db_port'] ?? 3306);
        $dbName = trim((string)($input['db_database'] ?? ''));
        $dbUser = trim((string)($input['db_username'] ?? ''));
        $dbPass = (string)($input['db_password'] ?? '');
        if ($dbHost === '' || mb_strlen($dbHost) > 190) throw new RuntimeException('Informe um host MySQL/MariaDB válido.');
        if ($dbPort < 1 || $dbPort > 65535) throw new RuntimeException('Porta MySQL/MariaDB inválida.');
        if ($dbName === '' || mb_strlen($dbName) > 128 || !preg_match('/^[A-Za-z0-9_$.-]+$/', $dbName)) throw new RuntimeException('Informe o nome do banco MySQL/MariaDB.');
        if ($dbUser === '' || mb_strlen($dbUser) > 190) throw new RuntimeException('Informe o usuário do banco MySQL/MariaDB.');
        $values['DB_HOST'] = $dbHost;
        $values['DB_PORT'] = (string)$dbPort;
        $values['DB_DATABASE'] = $dbName;
        $values['DB_USERNAME'] = $dbUser;
        $values['DB_PASSWORD'] = $dbPass;
    }
    return $values;
}

/** @param array<string,string> $values */
function eventmenu_install_probe_database(string $root, array $values): void
{
    $driver = (string)$values['DB_CONNECTION'];
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($driver === 'sqlite') {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('A extensão pdo_sqlite não está habilitada neste servidor.');
        $path = $root . '/storage/eventmenu.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta storage para o SQLite.');
        $probe = new PDO('sqlite:' . $path, null, null, $options);
        $probe->exec('PRAGMA foreign_keys = ON');
        return;
    }
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) throw new RuntimeException('A extensão pdo_mysql não está habilitada neste servidor.');
    $options[PDO::ATTR_EMULATE_PREPARES] = false;
    $dsn = 'mysql:host=' . $values['DB_HOST'] . ';port=' . $values['DB_PORT'] . ';dbname=' . $values['DB_DATABASE'] . ';charset=utf8mb4';
    try {
        $probe = new PDO($dsn, $values['DB_USERNAME'], $values['DB_PASSWORD'], $options);
        $probe->query('SELECT 1');
    } catch (PDOException) {
        throw new RuntimeException('Não foi possível conectar ao MySQL/MariaDB. Confira host, porta, banco, usuário e senha. O banco precisa existir antes da instalação.');
    }
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
    if (file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) === false) throw new RuntimeException('Não foi possível criar o arquivo .env. Verifique a permissão de escrita da pasta do sistema.');
    @chmod($path, 0640);
    eventmenu_install_runtime_env($values);
}

$genericPreflight = [
    'PHP 8.2 ou superior' => version_compare(PHP_VERSION, '8.2.0', '>='),
    'Dependências PHP instaladas' => is_file($root . '/vendor/autoload.php'),
    'Pasta storage gravável' => (is_dir($storagePath) && is_writable($storagePath)) || (!is_dir($storagePath) && is_writable($root)),
    'Configuração .env gravável' => is_file($envPath) ? is_writable($envPath) : is_writable($root),
];
$genericPreflightOk = !in_array(false, $genericPreflight, true);
$sqliteAvailable = in_array('sqlite', PDO::getAvailableDrivers(), true) && is_readable($root . '/database/sqlite/schema.sql');
$mysqlAvailable = in_array('mysql', PDO::getAvailableDrivers(), true) && is_readable($root . '/database/schema.sql');

if (is_file($lock)) {http_response_code(403);exit('O EventMenu já está instalado. Entre no painel e use ' . Security::e(app_url('update.php')) . ' para aplicar atualizações de banco.');}

$error = null;
$success = false;
$installedDriver = '';
$detectedUrl = eventmenu_install_detect_url();
$selectedDriver = strtolower((string)($_POST['db_driver'] ?? 'sqlite'));
if ($selectedDriver === 'mariadb') $selectedDriver = 'mysql';
if (!in_array($selectedDriver, ['sqlite','mysql'], true)) $selectedDriver = 'sqlite';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) $error = 'Sessão expirada. Atualize a página.';
    elseif (!$genericPreflightOk) $error = 'O servidor ainda não atende os requisitos básicos exibidos abaixo.';
    else {
        try {
            $driver = eventmenu_install_driver((string)($_POST['db_driver'] ?? 'sqlite'));
            if ($driver === 'sqlite' && !$sqliteAvailable) throw new RuntimeException('SQLite não está disponível neste servidor.');
            if ($driver === 'mysql' && !$mysqlAvailable) throw new RuntimeException('MySQL/MariaDB não está disponível neste servidor.');
            if (!is_dir($storagePath) && !mkdir($storagePath, 0775, true) && !is_dir($storagePath)) throw new RuntimeException('Não foi possível criar a pasta storage.');

            $envValues = eventmenu_install_build_env((string)($_POST['app_url'] ?? $detectedUrl), $driver, $_POST);
            eventmenu_install_probe_database($root, $envValues);
            eventmenu_install_write_env($envPath, $envValues);

            $pdo = Database::connection();
            try {
                $existing = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role="super_admin"')->fetchColumn();
                if ($existing > 0) throw new RuntimeException('Já existe um Super ADM neste banco. A instalação foi bloqueada.');
            } catch (PDOException) {
                // Banco vazio: as tabelas ainda serão criadas abaixo.
            }

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
            if (strlen($password) > 200) throw new RuntimeException('Senha inválida.');

            $pdo->beginTransaction();
            try {
                $existing = $pdo->prepare(Database::portableSql($pdo, 'SELECT id FROM users WHERE role="super_admin" LIMIT 1 FOR UPDATE'));
                $existing->execute();
                if ($existing->fetchColumn()) throw new RuntimeException('A plataforma já possui Super ADM.');
                $stmt = $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?, ?, "premium", "active")');
                $stmt->execute([$tenantName, $tenantSlug . '-' . substr(bin2hex(random_bytes(4)), 0, 6)]);
                $stmt = $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL, ?, ?, ?, "super_admin", "active")');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            if (file_put_contents($lock, date(DATE_ATOM), LOCK_EX) === false) throw new RuntimeException('Instalação criada no banco, mas não foi possível gravar o arquivo de lock. Não execute o instalador novamente.');
            @chmod($lock, 0640);
            $installedDriver = $driver;
            $success = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$cronScript = is_file($root . '/cron.php') ? $root . '/cron.php' : $root . '/public/cron.php';
$cronCommand = '* * * * * php ' . escapeshellarg($cronScript) . ' >/dev/null 2>&1';
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>"><style>.db-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:12px 0}.db-option{display:block;border:2px solid #ded8ef;border-radius:16px;padding:16px;cursor:pointer}.db-option:has(input:checked){border-color:#5b34d6;background:#f7f4ff}.db-option input{margin-right:8px}.db-option strong{display:block;font-size:18px;margin-bottom:4px}.db-option small{display:block;color:#687083}.db-fields{border:1px solid #e4e0ee;border-radius:14px;padding:14px;margin:12px 0}.availability{font-size:12px;font-weight:800}.availability.ok{color:#117a55}.availability.no{color:#b42318}@media(max-width:640px){.db-grid{grid-template-columns:1fr}}</style></head><body class="auth-page"><main class="auth-card" style="max-width:760px"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação inicial</h1>
<?php if ($success): ?>
<div class="alert ok">Instalado com sucesso usando <strong><?= Security::e($installedDriver === 'mysql' ? 'MySQL/MariaDB' : 'SQLite') ?></strong>. O usuário informado é o Super ADM da plataforma.</div>
<h2>Último passo: ativar manutenção automática</h2><p>Configure no servidor uma tarefa de cron <strong>a cada minuto</strong>:</p><pre style="white-space:pre-wrap;overflow:auto"><code><?= Security::e($cronCommand) ?></code></pre><p class="muted">Esse agendamento processa fila, notificações, expirações, limpezas e backup automático.</p><p>Depois, <a href="<?= Security::e(app_url('')) ?>">entre no sistema</a> e abra <strong>Super ADM → Saúde do sistema</strong>.</p>
<?php else: ?>
<?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?>
<h2>Verificação do servidor</h2><ul><?php foreach ($genericPreflight as $label => $ok): ?><li><?= $ok ? '✅' : '❌' ?> <?= Security::e($label) ?></li><?php endforeach; ?></ul>
<?php if ($genericPreflightOk): ?>
<form method="post" id="install-form"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
<label>URL do sistema<input name="app_url" type="url" value="<?= Security::e((string)($_POST['app_url'] ?? $detectedUrl)) ?>" required></label>
<h2>Escolha o banco de dados</h2><div class="db-grid">
<label class="db-option"><input type="radio" name="db_driver" value="sqlite"<?= $selectedDriver === 'sqlite' ? ' checked' : '' ?>><strong>SQLite</strong><small>Mais simples. Não precisa criar banco ou usuário.</small><span class="availability <?= $sqliteAvailable ? 'ok' : 'no' ?>"><?= $sqliteAvailable ? 'DISPONÍVEL' : 'INDISPONÍVEL' ?></span></label>
<label class="db-option"><input type="radio" name="db_driver" value="mysql"<?= $selectedDriver === 'mysql' ? ' checked' : '' ?>><strong>MySQL / MariaDB</strong><small>Recomendado para maior volume e vários operadores simultâneos.</small><span class="availability <?= $mysqlAvailable ? 'ok' : 'no' ?>"><?= $mysqlAvailable ? 'DISPONÍVEL' : 'INDISPONÍVEL' ?></span></label>
</div>
<div class="db-fields" id="mysql-fields"><h3>Dados do MySQL / MariaDB</h3><p class="muted">Crie o banco e o usuário no painel da hospedagem antes de continuar. O EventMenu testa a conexão antes da instalação.</p><label>Host<input name="db_host" value="<?= Security::e((string)($_POST['db_host'] ?? '127.0.0.1')) ?>" placeholder="127.0.0.1 ou mysql.seudominio"></label><label>Porta<input name="db_port" type="number" min="1" max="65535" value="<?= Security::e((string)($_POST['db_port'] ?? '3306')) ?>"></label><label>Nome do banco<input name="db_database" value="<?= Security::e((string)($_POST['db_database'] ?? '')) ?>" placeholder="eventmenu"></label><label>Usuário<input name="db_username" value="<?= Security::e((string)($_POST['db_username'] ?? '')) ?>"></label><label>Senha do banco<input name="db_password" type="password" autocomplete="new-password" value=""></label></div>
<h2>Administrador inicial</h2><label>Empresa inicial<input name="tenant_name" value="<?= Security::e((string)($_POST['tenant_name'] ?? '')) ?>" required></label><label>Nome do Super ADM<input name="name" value="<?= Security::e((string)($_POST['name'] ?? '')) ?>" required></label><label>E-mail do Super ADM<input name="email" type="email" value="<?= Security::e((string)($_POST['email'] ?? '')) ?>" required></label><label>Senha do Super ADM<input name="password" type="password" minlength="10" maxlength="200" required><small>Mínimo de 10 caracteres.</small></label><button class="primary" type="submit">Testar banco e instalar EventMenu</button></form>
<script>(()=>{const fields=document.getElementById('mysql-fields');const radios=[...document.querySelectorAll('input[name="db_driver"]')];function sync(){const mysql=radios.find(r=>r.checked)?.value==='mysql';fields.hidden=!mysql;fields.querySelectorAll('input').forEach(i=>{i.required=mysql&&['db_host','db_port','db_database','db_username'].includes(i.name)})}radios.forEach(r=>r.addEventListener('change',sync));sync()})();</script>
<?php else: ?><div class="alert error">Corrija os itens marcados com ❌ no servidor antes de continuar.</div><?php endif; ?>
<?php endif; ?></main></body></html>
