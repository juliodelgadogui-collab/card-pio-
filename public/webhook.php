<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\GatewayService;

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false]); exit; }
$provider = strtolower(trim((string)($_GET['provider'] ?? '')));
$tenant = trim((string)($_GET['tenant'] ?? ''));
$raw = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
foreach ($_SERVER as $key=>$value) {
    if (str_starts_with($key,'HTTP_')) $headers[str_replace('_','-',substr($key,5))] = $value;
}
try {
    if ($provider === '' || $tenant === '' || $raw === '') throw new RuntimeException('Requisição incompleta.');
    $result = (new GatewayService())->processWebhook($provider,$tenant,$raw,$headers,$_GET);
    http_response_code(200);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(401);
    error_log('EventMenu webhook rejected: '.$provider.' '.$e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Webhook rejeitado.']);
}
