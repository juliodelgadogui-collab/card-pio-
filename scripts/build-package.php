<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string)@file_get_contents($root . '/VERSION')) ?: '0.0.0';
$destination = $argv[1] ?? ($root . '/dist/eventmenu-server-' . $version);
$destination = rtrim($destination, '/\\');

function remove_tree(string $path): void
{
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { unlink($path); return; }
    foreach (scandir($path) ?: [] as $item) {
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

foreach (['app', 'src', 'database', 'public', 'vendor', 'integrations', 'scripts'] as $directory) {
    copy_tree($root . '/' . $directory, $destination . '/' . $directory);
}
if (is_dir($root . '/docs')) copy_tree($root . '/docs', $destination . '/docs');

foreach (['composer.json', 'composer.lock', '.env.example', 'VERSION', 'CHANGELOG.md', 'README-INSTALL.md', 'install.sh', 'update.sh'] as $file) {
    if (is_file($root . '/' . $file) && !copy($root . '/' . $file, $destination . '/' . $file)) {
        throw new RuntimeException('Falha ao copiar: ' . $file);
    }
}
@chmod($destination . '/install.sh', 0755);
@chmod($destination . '/update.sh', 0755);

foreach (['storage', 'storage/backups', 'storage/private', 'downloads'] as $directory) {
    if (!is_dir($destination . '/' . $directory)) mkdir($destination . '/' . $directory, 0775, true);
}
write_file($destination . '/storage/.gitkeep', '');
write_file($destination . '/downloads/.gitkeep', '');

$publicPhp = glob($root . '/public/*.php') ?: [];
foreach ($publicPhp as $file) {
    $name = basename($file);
    write_file(
        $destination . '/' . $name,
        "<?php\ndeclare(strict_types=1);\nrequire __DIR__ . '/public/{$name}';\n"
    );
}

copy_tree($root . '/public/assets', $destination . '/assets');
foreach (['manifest.webmanifest', 'sw.js'] as $file) {
    if (is_file($root . '/public/' . $file)) copy($root . '/public/' . $file, $destination . '/' . $file);
}

$deny = <<<'HTACCESS'
Require all denied
HTACCESS;
foreach (['app', 'src', 'database', 'storage', 'vendor', 'public', 'integrations', 'scripts', 'docs'] as $directory) {
    if (is_dir($destination . '/' . $directory)) write_file($destination . '/' . $directory . '/.htaccess', $deny . "\n");
}

$rootHtaccess = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(?:app|src|database|storage|vendor|public|integrations|scripts|docs)(?:/|$) - [F,L,NC]
</IfModule>

<FilesMatch "^(?:\.env|composer\.(?:json|lock))$">
Require all denied
</FilesMatch>
HTACCESS;
write_file($destination . '/.htaccess', $rootHtaccess . "\n");

$deployReadme = <<<TXT
EVENTMENU SERVER {$version} — PRIMEIRA INSTALAÇÃO /1 — SQLITE

Este pacote foi gerado para instalação em servidor vazio e não contém banco, clientes, pedidos, senhas, tokens ou sessões de produção.

REQUISITOS
- PHP 8.2+ com PDO, pdo_sqlite, mbstring, curl e openssl.
- HTTPS em produção.
- Apache/LiteSpeed com .htaccess ou regras equivalentes no Nginx.
- Node.js 22+ somente para a WhatsApp Bridge Beta (Baileys; não usa Chromium).

INSTALAÇÃO RECOMENDADA
1. Extraia o pacote na pasta /1.
2. Execute: chmod +x install.sh update.sh
3. Execute: ./install.sh
4. Informe URL, empresa inicial e Super ADM.
5. O instalador cria .env com chaves aleatórias, SQLite, schema, migrations e installed.lock.
6. Configure o cron a cada minuto conforme exibido.

ALTERNATIVA SEM SHELL
Abra /1/install.php no navegador e use o instalador web.

ATUALIZAÇÃO
Use ./update.sh. Ele preserva .env/storage, cria backup do SQLite antes das migrations e nunca substitui o banco por um vazio.

WHATSAPP BRIDGE BETA
Leia integrations/whatsapp-worker/README.md. A engine é Baileys/WhatsApp Web. Mantenha segredo e sessões fora de public/.

Consulte README-INSTALL.md e CHANGELOG.md para detalhes.
TXT;
write_file($destination . '/LEIA-ME-INSTALACAO.txt', $deployReadme . "\n");

echo "Pacote EventMenu Server {$version} criado em: {$destination}\n";
