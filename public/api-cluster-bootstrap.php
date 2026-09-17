<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\ClusterNodeConfigService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function bootstrap_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') bootstrap_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $action = (string)($_GET['action'] ?? 'configure');
    if ($action !== 'configure') bootstrap_out(['ok' => false, 'error' => 'Endpoint não encontrado.'], 404);
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' || strlen($raw) > 100_000) bootstrap_out(['ok' => false, 'error' => 'Pacote de inicialização inválido.'], 400);
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) bootstrap_out(['ok' => false, 'error' => 'Pacote de inicialização inválido.'], 400);
    $signature = trim((string)($_SERVER['HTTP_X_EVENTMENU_BOOTSTRAP_SIGNATURE'] ?? ''));
    bootstrap_out((new ClusterNodeConfigService())->acceptBootstrap($payload, $raw, $signature));
} catch (JsonException) {
    bootstrap_out(['ok' => false, 'error' => 'JSON inválido.'], 400);
} catch (RuntimeException $e) {
    bootstrap_out(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL)) bootstrap_out(['ok' => false, 'error' => $e->getMessage()], 500);
    bootstrap_out(['ok' => false, 'error' => 'Erro interno.'], 500);
}
