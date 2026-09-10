<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\PlatformFailoverService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function cluster_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $service = new PlatformFailoverService();
    $action = (string)($_GET['action'] ?? 'health');

    if ($action === 'health') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') cluster_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
        cluster_out($service->publicHealth());
    }

    if ($action === 'verify') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') cluster_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) throw new RuntimeException('Desafio inválido.');
        $signature = trim((string)($_SERVER['HTTP_X_EVENTMENU_CLUSTER_SIGNATURE'] ?? ''));
        cluster_out($service->verifyIncoming($body, $signature));
    }

    cluster_out(['ok' => false, 'error' => 'Endpoint do cluster não encontrado.'], 404);
} catch (JsonException) {
    cluster_out(['ok' => false, 'error' => 'JSON inválido.'], 400);
} catch (RuntimeException $e) {
    cluster_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) cluster_out(['ok' => false, 'error' => $e->getMessage()], 500);
    cluster_out(['ok' => false, 'error' => 'Erro interno.'], 500);
}
