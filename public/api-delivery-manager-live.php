<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Services\DeliveryTrackingService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-origin');

if (!Auth::id() || !Auth::tenantId() || !Auth::can('orders.delivery') || Auth::role() === 'delivery') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $deliveries = (new DeliveryTrackingService())->activeForManager();
    $metrics = ['total' => count($deliveries), 'live' => 0, 'attention' => 0, 'offline' => 0, 'mock' => 0];
    foreach ($deliveries as $delivery) {
        $status = (string)($delivery['signal']['status'] ?? 'offline');
        if ($status === 'live') $metrics['live']++;
        elseif ($status === 'mock') { $metrics['mock']++; $metrics['attention']++; }
        elseif ($status === 'offline') { $metrics['offline']++; $metrics['attention']++; }
        else $metrics['attention']++;
    }
    echo json_encode(['ok' => true, 'server_time' => gmdate('c'), 'metrics' => $metrics, 'deliveries' => $deliveries], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Não foi possível carregar o GPS das entregas.'], JSON_UNESCAPED_UNICODE);
}
