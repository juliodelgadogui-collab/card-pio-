<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\HubCommandIdempotencyGuardService;
use EventMenu\Services\HubDesktopPresenceService;
use EventMenu\Services\HubService;
use EventMenu\Services\HubTerminalCatalogService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

function hub_out(array $data, int $status=200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function hub_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $_POST ?: [];
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (Throwable) {
        hub_out(['ok'=>false,'error'=>'JSON inválido.'], 400);
    }
}

function hub_method(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expected) hub_out(['ok'=>false,'error'=>'Método não permitido.'], 405);
}

function hub_device(array $body, string $sessionDevice): string
{
    $reported = trim((string)($body['device_id'] ?? $sessionDevice));
    if ($reported === '') throw new RuntimeException('Dispositivo não identificado.');
    if ($sessionDevice !== '' && !hash_equals($sessionDevice, $reported)) throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
    return $reported;
}

try {
    $auth = new ApiAuthService();
    $token = ApiAuthService::bearerToken();
    $sessionDevice = ApiAuthService::deviceId();
    if ($token === '') hub_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'], 401);
    $auth->authenticate($token, $sessionDevice);

    $hub = new HubService();
    $action = (string)($_GET['action'] ?? 'links');

    if ($action === 'links') {
        hub_method('GET');
        $device = trim((string)($_GET['device_id'] ?? $sessionDevice));
        if ($device === '') throw new RuntimeException('Dispositivo não identificado.');
        if ($sessionDevice !== '' && !hash_equals($sessionDevice, $device)) throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        hub_out(['ok'=>true,'links'=>$hub->mobileLinks($device)]);
    }
    if ($action === 'terminals') {
        hub_method('GET');
        hub_out(['ok'=>true,'terminals'=>(new HubTerminalCatalogService())->listForUnit((int)($_GET['unit_id'] ?? 0))]);
    }
    if ($action === 'desktop-heartbeat') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        $hardware = is_array($body['hardware'] ?? null) ? $body['hardware'] : [];
        hub_out([
            'ok'=>true,
            'desktop'=>(new HubDesktopPresenceService())->heartbeat(
                (int)($body['unit_id'] ?? 0),
                $device,
                (string)($body['label'] ?? 'EventMenu Desktop'),
                $hardware,
            ),
        ]);
    }
    if ($action === 'pairing-create') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        hub_out(['ok'=>true,'pairing'=>$hub->createPairingCode((int)($body['unit_id'] ?? 0), $device)], 201);
    }
    if ($action === 'pairing-claim') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        hub_out(['ok'=>true,'link'=>$hub->claimPairing((string)($body['token'] ?? $body['qr'] ?? ''), $device, (string)($body['label'] ?? ''))], 201);
    }
    if ($action === 'command-create') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        $targetBindingId = (int)($body['target_binding_id'] ?? 0);
        $commandType = (string)($body['command_type'] ?? '');
        $idempotencyKey = (string)($body['idempotency_key'] ?? '');
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $guard = new HubCommandIdempotencyGuardService();
        $guard->assertReusable($idempotencyKey, $targetBindingId, $commandType, $device);
        $command = $hub->queueCommand($targetBindingId, $commandType, $payload, $idempotencyKey, $device);
        // Revalida depois da inserção para cobrir duas requisições simultâneas com a mesma chave.
        $guard->assertReusable($idempotencyKey, $targetBindingId, $commandType, $device);
        hub_out(['ok'=>true,'command'=>$command], 201);
    }
    if ($action === 'command-status') {
        hub_method('GET');
        $device = trim((string)($_GET['device_id'] ?? $sessionDevice));
        if ($device === '') throw new RuntimeException('Dispositivo não identificado.');
        if ($sessionDevice !== '' && !hash_equals($sessionDevice, $device)) throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        hub_out(['ok'=>true,'command'=>$hub->commandStatus((int)($_GET['id'] ?? 0), $device)]);
    }
    if ($action === 'desktop-poll') {
        hub_method('GET');
        $device = trim((string)($_GET['device_id'] ?? $sessionDevice));
        if ($device === '') throw new RuntimeException('Dispositivo não identificado.');
        if ($sessionDevice !== '' && !hash_equals($sessionDevice, $device)) throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        hub_out(['ok'=>true,'commands'=>$hub->pollDesktop($device, (int)($_GET['limit'] ?? 20))]);
    }
    if ($action === 'desktop-claim') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        hub_out(['ok'=>true,'command'=>$hub->claimCommand((int)($body['id'] ?? 0), $device)]);
    }
    if ($action === 'desktop-complete') {
        hub_method('POST');
        $body = hub_body();
        $device = hub_device($body, $sessionDevice);
        $result = is_array($body['result'] ?? null) ? $body['result'] : [];
        hub_out(['ok'=>true,'command'=>$hub->completeCommand((int)($body['id'] ?? 0), $device, !empty($body['success']), $result, (string)($body['error'] ?? ''))]);
    }
    if ($action === 'link-revoke') {
        hub_method('POST');
        $body = hub_body();
        hub_device($body, $sessionDevice);
        $hub->revokeLink((int)($body['id'] ?? 0));
        hub_out(['ok'=>true]);
    }

    hub_out(['ok'=>false,'error'=>'Endpoint do Hub não encontrado.'], 404);
} catch (RuntimeException $e) {
    hub_out(['ok'=>false,'error'=>$e->getMessage()], 422);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG','false'), FILTER_VALIDATE_BOOL)) hub_out(['ok'=>false,'error'=>$e->getMessage()], 500);
    hub_out(['ok'=>false,'error'=>'Erro interno.'], 500);
}
