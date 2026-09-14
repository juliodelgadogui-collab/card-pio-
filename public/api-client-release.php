<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\ClientReleaseDownloadService;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rate = new ApiRateLimitService();
    $rate->assertAllowed('client.release.download', $rate->requestSubject(), 30, 60);
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'android')));
    $file = (new ClientReleaseDownloadService())->resolve($platform);

    header('Content-Type: ' . $file['mime']);
    header('Content-Length: ' . $file['size']);
    header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
    header('X-EventMenu-Release-Version: ' . preg_replace('/[^A-Za-z0-9._-]/', '', $file['version']));
    header('X-EventMenu-SHA256: ' . $file['sha256']);
    if ($method === 'HEAD') exit;

    $handle = fopen($file['path'], 'rb');
    if ($handle === false) throw new RuntimeException('Não foi possível abrir o arquivo da atualização.');
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) break;
        echo $chunk;
        if (function_exists('fastcgi_finish_request')) {
            // Não chamar finish_request aqui: ainda há conteúdo a transmitir.
        }
        flush();
    }
    fclose($handle);
    exit;
} catch (ApiRateLimitExceededException $e) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL) ? $e->getMessage() : 'Erro interno.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
