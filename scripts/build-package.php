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
foreach (['app', 'src', 'database', 'storage', 'vendor', 'public'] as $directory) {
    write_file($destination . '/' . $directory . '/.htaccess', $deny . "\n");
}

$rootHtaccess = <<<'HTACCESS'
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^(?:app|src|database|storage|vendor|public)(?:/|$) - [F,L,NC]
</IfModule>

<FilesMatch "^(?:\.env|composer\.(?:json|lock))$">
Require all denied
</FilesMatch>
HTACCESS;
write_file($destination . '/.htaccess', $rootHtaccess . "\n");

$deployReadme = <<<'TXT'
EVENTMENU PREMIUM — PRIMEIRA INSTALAÇÃO /1 — SQLITE

Este pacote foi gerado do HEAD atual do GitHub e foi preparado para um servidor totalmente vazio.
O Composer NÃO precisa estar instalado no servidor: a pasta vendor já acompanha o pacote.

REQUISITOS DO SERVIDOR
- PHP 8.2 ou superior.
- Extensões: PDO, pdo_sqlite, mbstring, curl e openssl.
- HTTPS recomendado desde a primeira instalação.
- Apache/LiteSpeed com .htaccess habilitado, ou regras equivalentes no Nginx.
- Permissão de escrita para a pasta do sistema durante a instalação e para storage depois.

INSTALAÇÃO DO ZERO
1. Crie/abra a pasta /1 no domínio.
2. Envie TODO o conteúdo deste pacote para essa pasta.
3. NÃO crie banco de dados manualmente.
4. NÃO é obrigatório renomear .env.example: se .env não existir, o instalador cria automaticamente.
5. Acesse https://SEU-DOMINIO/1/install.php.
6. Confira se todos os requisitos aparecem com ✅.
7. Informe a URL, empresa inicial e os dados do Super ADM.
8. Clique em "Instalar EventMenu com SQLite".
9. O sistema criará automaticamente:
   - .env com APP_KEY e CRON_SECRET aleatórios;
   - storage/eventmenu.sqlite;
   - schema e migrações atuais;
   - empresa inicial;
   - usuário Super ADM;
   - storage/installed.lock para bloquear nova instalação.
10. Entre em https://SEU-DOMINIO/1/.

BANCO INICIAL
- Banco: SQLite.
- Arquivo: storage/eventmenu.sqlite.
- WAL e foreign keys são ativados automaticamente.
- Nunca disponibilize storage publicamente.
- Faça backup periódico do banco antes de atualizações importantes.

ATUALIZAÇÕES
- Envie os novos arquivos preservando .env e toda a pasta storage.
- Entre como administrador autorizado e acesse /1/update.php para aplicar migrações.
- NUNCA substitua storage/eventmenu.sqlite por um arquivo vazio durante atualização.

SEGURANÇA
- O .htaccess do pacote bloqueia .env, app, src, database, storage, vendor e public em Apache/LiteSpeed.
- Em Nginx, replique esses bloqueios no virtual host.
- SESSION_SECURE é ativado automaticamente quando a URL informada usa HTTPS.
- Depois de instalado, install.php é bloqueado por installed.lock e pela existência do Super ADM.

MIGRAÇÃO FUTURA PARA MYSQL/MARIADB
O sistema mantém suporte a MySQL/MariaDB, mas a primeira instalação deste pacote usa SQLite conforme definido para a fase inicial do projeto.
TXT;
write_file($destination . '/LEIA-ME-INSTALACAO.txt', $deployReadme . "\n");

echo "Pacote SQLite de primeira instalação criado em: {$destination}\n";
