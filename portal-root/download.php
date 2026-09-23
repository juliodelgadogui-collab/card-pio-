<?php
declare(strict_types=1);
require __DIR__ . '/lib/apk_registry.php';

$slug = strtolower(trim((string) ($_GET['app'] ?? '')));
$manifest = em_read_manifest();
$app = $manifest['apps'][$slug] ?? null;
$current = is_array($app) ? ($app['current'] ?? null) : null;

if (!em_slug_is_valid($slug) || !is_array($current) || empty($current['file'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Aplicativo ainda não publicado.\n");
}

$file = em_version_file_path($slug, (string) $current['file']);
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Arquivo da versão atual não encontrado.\n");
}

$downloadName = $slug . '-' . ((string) ($current['version'] ?? 'latest')) . '.apk';
header('Content-Type: application/vnd.android.package-archive');
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
