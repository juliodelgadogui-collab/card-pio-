<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\PlatformFailoverService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function routing_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') routing_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $token = ApiAuthService::bearerToken();
    $deviceId = ApiAuthService::deviceId();
    if ($token === '') routing_out(['ok' => false, 'error' => 'Token Bearer obrigatório.'], 401);
    (new ApiAuthService())->authenticate($token, $deviceId);

    $action = (string)($_GET['action'] ?? 'config');
    if ($action !== 'config') routing_out(['ok' => false, 'error' => 'Endpoint de roteamento não encontrado.'], 404);

    $service = new PlatformFailoverService();
    routing_out([
        'ok' => true,
        'routing' => $service->routingConfig(),
        'served_by' => $service->nodeRole(),
        'server_time' => gmdate('c'),
    ]);
} catch (RuntimeException $e) {
    routing_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) routing_out(['ok' => false, 'error' => $e->getMessage()], 500);
    routing_out(['ok' => false, 'error' => 'Erro interno.'], 500);
}
