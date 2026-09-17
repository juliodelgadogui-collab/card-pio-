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

/** @param array<string,mixed> $envelope */
function policy_envelope_fresh(array $envelope): bool
{
    $payloadRaw = base64_decode((string)($envelope['payload_b64'] ?? ''), true);
    if ($payloadRaw === false) return false;
    try { $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable) { return false; }
    if (!is_array($payload)) return false;
    $issuedAt = strtotime(trim((string)($payload['issued_at'] ?? '')));
    $expiresAt = strtotime(trim((string)($payload['expires_at'] ?? '')));
    if ($issuedAt === false || $expiresAt === false || $expiresAt <= $issuedAt) return false;
    $now = time();
    if ($issuedAt > $now + 300) return false;
    if ($expiresAt < $now - 300) return false;
    return ($expiresAt - $issuedAt) <= 605100;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') policy_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $action = (string)($_GET['action'] ?? 'manifest');
    if ($action !== 'manifest') policy_out(['ok' => false, 'error' => 'Endpoint não encontrado.'], 404);
    $platform = strtolower(trim((string)($_GET['platform'] ?? 'android')));

    $service = new ClientPolicyService();
    $failover = new PlatformFailoverService();
    if ($failover->nodeRole() === 'contingency') {
        $envelope = $service->cachedEnvelope($platform);
        if (!$envelope || !policy_envelope_fresh($envelope)) {
            policy_out([
                'ok' => false,
                'error' => 'Política assinada não está disponível ou expirou neste servidor de contingência.',
                'served_by' => 'contingency',
            ], 503);
        }
    } else {
        $envelope = $service->signedEnvelope($platform);
    }

    $envelope['served_by'] = $failover->nodeRole();
    $envelope['server_time'] = gmdate('c');
    policy_out($envelope);
} catch (RuntimeException $e) {
    policy_out(['ok' => false, 'error' => $e->getMessage()], 503);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) policy_out(['ok' => false, 'error' => $e->getMessage()], 500);
    policy_out(['ok' => false, 'error' => 'Política temporariamente indisponível.'], 500);
}
