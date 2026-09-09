<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Services\DeliveryTrackingService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cross-Origin-Resource-Policy: same-origin');

$token = strtolower(trim((string)($_GET['t'] ?? '')));
$tracking = (new DeliveryTrackingService())->latestPublic($token);
if (!$tracking) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Rastreamento indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'tracking' => $tracking], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
