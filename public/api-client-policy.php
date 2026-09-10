<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\ClientPolicyService;
use EventMenu\Services\PlatformFailoverService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function policy_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') policy_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $action = (string)($_GET['action'] ?? 'manifest');
    if ($action !== 'manifest') policy_out(['ok' => false, 'error' => 'Endpoint não encontrado.'], 404);
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'android')));

    $service = new ClientPolicyService();
    $failover = new PlatformFailoverService();
    $envelope = $failover->nodeRole() === 'contingency'
        ? ($service->cachedEnvelope($platform) ?: $service->signedEnvelope($platform))
        : $service->signedEnvelope($platform);

    $envelope['served_by'] = $failover->nodeRole();
    $envelope['server_time'] = gmdate('c');
    policy_out($envelope);
} catch (RuntimeException $e) {
    policy_out(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) policy_out(['ok' => false, 'error' => $e->getMessage()], 500);
    policy_out(['ok' => false, 'error' => 'Política temporariamente indisponível.'], 500);
}
