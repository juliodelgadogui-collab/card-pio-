<?php
declare(strict_types=1);

function em_portal_root(): string
{
    return dirname(__DIR__);
}

function em_manifest_path(): string
{
    return em_portal_root() . '/data/apps.json';
}

function em_default_manifest(): array
{
    return [
        'schema' => 1,
        'updated_at' => null,
        'apps' => [
            'eventmenu-go' => [
                'name' => 'EventMenu GO',
                'platform' => 'Android',
                'audience' => 'Restaurantes e equipe',
                'description' => 'Operação do restaurante, pedidos, produção e entregas no celular.',
                'current' => null,
                'previous' => null,
                'latest_url' => 'https://go.gestao2.store/apk/eventmenu-go/latest.apk',
            ],
            'delyvre' => [
                'name' => 'DELYVRE',
                'platform' => 'Android',
                'audience' => 'Clientes',
                'description' => 'Aplicativo para clientes descobrirem estabelecimentos e fazerem pedidos.',
                'current' => null,
                'previous' => null,
                'latest_url' => 'https://go.gestao2.store/apk/delyvre/latest.apk',
            ],
        ],
    ];
}

function em_read_manifest(): array
{
    $path = em_manifest_path();
    if (!is_file($path)) {
        return em_default_manifest();
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded) || !isset($decoded['apps']) || !is_array($decoded['apps'])) {
        return em_default_manifest();
    }

    return $decoded;
}

function em_write_manifest(array $manifest): void
{
    $path = em_manifest_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta de dados.');
    }

    $manifest['updated_at'] = gmdate('c');
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Não foi possível gerar o manifesto dos aplicativos.');
    }

    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível salvar o manifesto dos aplicativos.');
    }
}

function em_slug_is_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', $slug);
}

function em_version_is_valid(string $version): bool
{
    return (bool) preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/', $version);
}

function em_apk_storage_dir(string $slug): string
{
    return em_portal_root() . '/storage/apks/' . $slug;
}

function em_version_file_path(string $slug, string $file): string
{
    $safe = basename($file);
    return em_apk_storage_dir($slug) . '/' . $safe;
}

function em_format_bytes(?int $bytes): string
{
    if (!$bytes) {
        return '—';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }

    return number_format($value, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

function em_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function em_public_manifest(): array
{
    $manifest = em_read_manifest();
    foreach ($manifest['apps'] as $slug => &$app) {
        $app['slug'] = $slug;
        $app['available'] = !empty($app['current']['file']) && is_file(em_version_file_path($slug, (string) $app['current']['file']));
        if ($app['available']) {
            $app['download_url'] = $app['latest_url'] ?? ('https://go.gestao2.store/apk/' . $slug . '/latest.apk');
        } else {
            $app['download_url'] = null;
        }
    }
    unset($app);

    return $manifest;
}

function em_publish_apk(string $slug, string $version, int $versionCode, string $tmpFile, string $originalName): array
{
    $manifest = em_read_manifest();
    if (!isset($manifest['apps'][$slug])) {
        throw new RuntimeException('Aplicativo não cadastrado.');
    }
    if (!em_slug_is_valid($slug) || !em_version_is_valid($version)) {
        throw new RuntimeException('Aplicativo ou versão inválidos.');
    }
    if ($versionCode < 1) {
        throw new RuntimeException('O versionCode deve ser maior que zero.');
    }
    if (!is_uploaded_file($tmpFile)) {
        throw new RuntimeException('Upload inválido.');
    }
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'apk') {
        throw new RuntimeException('Envie somente arquivo .apk.');
    }

    $dir = em_apk_storage_dir($slug);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar a pasta do aplicativo.');
    }

    $file = $slug . '-' . preg_replace('/[^A-Za-z0-9._+-]/', '-', $version) . '.apk';
    $target = $dir . '/' . $file;
    if (!move_uploaded_file($tmpFile, $target)) {
        throw new RuntimeException('Não foi possível mover o APK para o armazenamento.');
    }

    $meta = [
        'version' => $version,
        'version_code' => $versionCode,
        'file' => $file,
        'size_bytes' => (int) filesize($target),
        'sha256' => hash_file('sha256', $target),
        'published_at' => gmdate('c'),
    ];

    $oldCurrent = $manifest['apps'][$slug]['current'] ?? null;
    $oldPrevious = $manifest['apps'][$slug]['previous'] ?? null;
    $manifest['apps'][$slug]['previous'] = $oldCurrent;
    $manifest['apps'][$slug]['current'] = $meta;
    em_write_manifest($manifest);

    // Mantém somente a versão atual e a imediatamente anterior para economizar os 500 MB.
    if (is_array($oldPrevious) && !empty($oldPrevious['file'])) {
        $oldFile = em_version_file_path($slug, (string) $oldPrevious['file']);
        if (is_file($oldFile) && basename($oldFile) !== $file) {
            @unlink($oldFile);
        }
    }

    return $meta;
}
