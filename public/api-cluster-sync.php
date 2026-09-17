<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\ClusterControlPlaneReplayGuardService;
use EventMenu\Services\ClusterSyncService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function cluster_sync_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cluster_sync_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $action = (string)($_GET['action'] ?? 'push');
    if ($action !== 'push') cluster_sync_out(['ok' => false, 'error' => 'Endpoint não encontrado.'], 404);

    $raw = (string)file_get_contents('php://input');
    $signature = trim((string)($_SERVER['HTTP_X_EVENTMENU_CLUSTER_SIGNATURE'] ?? ''));

    // A guarda só compara versões depois de confirmar o mesmo HMAC do cluster.
    // O ClusterSyncService continua sendo a autoridade final e repete toda a
    // autenticação/validação antes de gravar qualquer estado.
    (new ClusterControlPlaneReplayGuardService())->assertAuthenticatedNotOlder($raw, $signature);
    $result = (new ClusterSyncService())->acceptControlPlane($raw, $signature);
    cluster_sync_out($result);
} catch (JsonException $e) {
    cluster_sync_out(['ok' => false, 'error' => 'JSON inválido.'], 400);
} catch (RuntimeException $e) {
    cluster_sync_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) cluster_sync_out(['ok' => false, 'error' => $e->getMessage()], 500);
    cluster_sync_out(['ok' => false, 'error' => 'Erro interno.'], 500);
}
