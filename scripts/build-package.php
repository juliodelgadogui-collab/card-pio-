<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$destination = $argv[1] ?? ($root . '/dist/EventMenu-Premium-1');
$destination = rtrim($destination, '/\\');

function remove_tree(string $path): void
{
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        remove_tree($path . DIRECTORY_SEPARATOR . $item);
    }
    rmdir($path);
}

function copy_tree(string $source, string $destination): void
{
    if (!is_dir($source)) throw new RuntimeException('Diretório ausente: ' . $source);
    if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Não foi possível criar: ' . $destination);
    }
    foreach (scandir($source) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $source . DIRECTORY_SEPARATOR . $item;
        $dst = $destination . DIRECTORY_SEPARATOR . $item;
        if (is_dir($src)) copy_tree($src, $dst);
        elseif (!copy($src, $dst)) throw new RuntimeException('Falha ao copiar: ' . $src);
    }
}

function write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Falha ao criar: ' . $dir);
    if (file_put_contents($path, $contents) === false) throw new RuntimeException('Falha ao gravar: ' . $path);
}

remove_tree($destination);
mkdir($destination, 0775, true);

foreach (['app', 'src', 'database', 'public', 'vendor'] as $directory) {
    copy_tree($root . '/' . $directory, $destination . '/' . $directory);
}

foreach (['composer.json', 'composer.lock', '.env.example'] as $file) {
    if (is_file($root . '/' . $file)) copy($root . '/' . $file, $destination . '/' . $file);
}

mkdir($destination . '/storage', 0775, true);
write_file($destination . '/storage/.gitkeep', '');

$publicPhp = glob($root . '/public/*.php') ?: [];
foreach ($publicPhp as $file) {
    $name = basename($file);
    write_file($destination . '/' . $name, "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/public/{$name}';\n");
}

copy_tree($root . '/public/assets', $destination . '/assets');
foreach (['manifest.webmanifest', 'sw.js'] as $file) {
    if (is_file($root . '/public/' . $file)) copy($root . '/public/' . $file, $destination . '/' . $file);
}

$deny = <<<'HTACCESS'
Options -Indexes
Require all denied
HTACCESS;
foreach (['app', 'src', 'database', 'storage', 'vendor', 'public'] as $directory) {
    write_file($destination . '/' . $directory . '/.htaccess', $deny . "\n");
}

$rootHtaccess = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(?:app|src|database|storage|vendor|public)(?:/|$) - [F,L,NC]
RewriteRule (^|/)(?:\.git|\.github)(?:/|$) - [F,L,NC]
</IfModule>

<FilesMatch "^(?:\.env(?:\..*)?|composer\.(?:json|lock)|BUILD-MANIFEST\.json|VERSION\.txt|LEIA-ME-INSTALACAO\.txt|SHA256SUMS\.txt)$">
Require all denied
</FilesMatch>

<FilesMatch "\.(?:sqlite3?|db|sql|log|bak|old|ini|pem|key|crt|p12|pfx)$">
Require all denied
</FilesMatch>

<IfModule mod_headers.c>
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
Header always set X-Frame-Options "SAMEORIGIN"
</IfModule>
HTACCESS;
write_file($destination . '/.htaccess', $rootHtaccess . "\n");

$releaseVersion = trim((string)(getenv('EVENTMENU_RELEASE_VERSION') ?: 'production-candidate'));
$buildCommit = trim((string)(getenv('EVENTMENU_BUILD_COMMIT') ?: getenv('GITHUB_SHA') ?: 'unknown'));
$buildRun = trim((string)(getenv('GITHUB_RUN_ID') ?: 'local'));
$manifest = [
    'product' => 'EventMenu Premium',
    'version' => $releaseVersion,
    'commit' => $buildCommit,
    'build_run' => $buildRun,
    'generated_at_utc' => gmdate('c'),
    'php_min' => '8.2',
    'default_database' => 'sqlite',
    'supported_databases' => ['sqlite','mysql','mariadb'],
    'mysql_supported' => true,
    'app_base_path' => '/1',
    'package_profile' => 'production',
];
write_file($destination . '/BUILD-MANIFEST.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
write_file($destination . '/VERSION.txt', $releaseVersion . "\n" . $buildCommit . "\n");

if (is_file($destination . '/.env')) throw new RuntimeException('Pacote de produção não pode conter .env real.');
if (!is_file($destination . '/vendor/autoload.php')) throw new RuntimeException('Pacote de produção sem vendor/autoload.php.');
if (!is_file($destination . '/.env.example')) throw new RuntimeException('Pacote de produção sem .env.example.');

$deployReadme = <<<'TXT'
EVENTMENU PREMIUM — PACOTE DE PRODUÇÃO /1 — MULTI-BANCO

Este pacote foi gerado pelo CI a partir de uma revisão validada do EventMenu.
O Composer NÃO precisa estar instalado no servidor: a pasta vendor já acompanha o pacote.
Consulte BUILD-MANIFEST.json e VERSION.txt localmente para identificar exatamente a versão/commit instalado.

BANCOS SUPORTADOS NA INSTALAÇÃO
- SQLite: mais simples, sem criação manual de banco ou usuário.
- MySQL / MariaDB: recomendado para maior volume e vários operadores simultâneos.
- PostgreSQL ainda não é oferecido porque o schema/migrações do EventMenu não foram validados para ele.

REQUISITOS DO SERVIDOR
- PHP 8.2 ou superior.
- Extensões comuns: PDO, mbstring, curl e openssl.
- Para SQLite: pdo_sqlite.
- Para MySQL/MariaDB: pdo_mysql.
- HTTPS obrigatório para operação real.
- Apache/LiteSpeed com .htaccess habilitado, ou regras equivalentes no Nginx.
- Permissão de escrita para a pasta do sistema durante a instalação e para storage depois.

INSTALAÇÃO DO ZERO
1. Crie/abra a pasta /1 no domínio.
2. Envie TODO o conteúdo deste pacote para essa pasta.
3. Acesse https://SEU-DOMINIO/1/install.php.
4. Escolha SQLite ou MySQL/MariaDB.
5. Para SQLite, o EventMenu cria storage/eventmenu.sqlite automaticamente.
6. Para MySQL/MariaDB, crie antes o banco e o usuário no painel da hospedagem e informe host, porta, banco, usuário e senha. O instalador testa a conexão antes de instalar.
7. Informe a URL, empresa inicial e os dados do Super ADM.
8. Clique em "Testar banco e instalar EventMenu".
9. O sistema criará automaticamente o .env com APP_KEY e CRON_SECRET aleatórios, schema, migrações, empresa inicial, Super ADM e storage/installed.lock.
10. Entre em https://SEU-DOMINIO/1/.
11. Configure o cron do servidor a cada minuto:

   * * * * * php /CAMINHO/DO/SITE/1/cron.php >/dev/null 2>&1

12. Entre como Super ADM, abra "Saúde do sistema" e confirme Cron, Worker, fila e backup.
13. Antes de liberar Pix/cartão reais, confirme também a seção "Pagamentos reais" como PRONTO.

ATUALIZAÇÕES
- Preserve .env e toda a pasta storage.
- Envie os novos arquivos e acesse /1/update.php usando o Super ADM da plataforma.
- Se estiver em SQLite, nunca substitua storage/eventmenu.sqlite por um arquivo vazio.
- Confira VERSION.txt/BUILD-MANIFEST.json localmente depois da atualização.

SEGURANÇA
- O pacote não contém .env real, chave privada ou credenciais de gateway.
- .htaccess bloqueia .env, metadados de build, app, src, database, storage, vendor e public em Apache/LiteSpeed.
- Extensões sensíveis como .sqlite, .db, .sql, .log, .pem e .key também são negadas.
- Em Nginx, replique os mesmos bloqueios no virtual host.
- SESSION_SECURE deve permanecer ativo em HTTPS.
- install.php é bloqueado após instalação por installed.lock.
- SHA256SUMS.txt acompanha o artefato do CI para conferência de integridade local.
TXT;
write_file($destination . '/LEIA-ME-INSTALACAO.txt', $deployReadme . "\n");

echo "Pacote de produção multi-banco criado em: {$destination}\n";
