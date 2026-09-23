<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\TenantFeatures;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tenantId = Auth::tenantId();
if (!$tenantId) {
    echo json_encode(['ok' => true, 'data' => ['modules' => [], 'disabled_routes' => []]], JSON_UNESCAPED_UNICODE);
    exit;
}

$modules = TenantFeatures::modules((int)$tenantId);
$routeMap = TenantFeatures::routeModuleMap();
$disabledRoutes = [];
foreach ($routeMap as $route => $module) {
    if (empty($modules[$module])) $disabledRoutes[] = $route;
}

echo json_encode([
    'ok' => true,
    'data' => [
        'modules' => $modules,
        'disabled_routes' => array_values(array_unique($disabledRoutes)),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
