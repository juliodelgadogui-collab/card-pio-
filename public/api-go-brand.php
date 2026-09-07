<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, OPTIONS');
    http_response_code(204);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Método não permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $auth = new ApiAuthService();
    $token = ApiAuthService::bearerToken();
    if ($token === '') throw new RuntimeException('Sessão necessária.');
    $user = $auth->authenticate($token, ApiAuthService::deviceId());

    echo json_encode([
        'ok' => true,
        'brand' => $user['brand'] ?? [],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Não foi possível carregar a identidade da empresa.'], JSON_UNESCAPED_UNICODE);
}
