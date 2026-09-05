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
EVENTMENU PREMIUM — PACOTE /1

Este diretório foi gerado do HEAD atual do GitHub. Não reutiliza ZIP antigo.

Instalação:
1. Envie TODO o conteúdo deste diretório para a pasta /1 do domínio.
2. Renomeie .env.example para .env.
3. Ajuste APP_URL para a origem do domínio (ex.: https://exemplo.com.br).
4. Mantenha APP_BASE_PATH=/1.
5. Gere uma APP_KEY longa e aleatória.
6. Configure DB_CONNECTION=mysql ou sqlite e as credenciais/caminho.
7. Garanta permissão de escrita na pasta storage.
8. Acesse https://SEU-DOMINIO/1/install.php.
9. Depois da instalação, acesse https://SEU-DOMINIO/1/.

Produção:
- HTTPS obrigatório para pagamentos e PWA.
- SESSION_SECURE=true em HTTPS.
- Não exponha .env, app, src, database, storage, vendor ou public diretamente.
- O .htaccess do pacote bloqueia essas áreas em Apache/LiteSpeed.
- Em Nginx, replique os bloqueios no virtual host.
TXT;
write_file($destination . '/LEIA-ME-INSTALACAO.txt', $deployReadme . "\n");

echo "Pacote criado em: {$destination}\n";
