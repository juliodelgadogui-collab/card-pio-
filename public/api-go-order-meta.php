<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, OPTIONS');
    http_response_code(204);
    exit;
}

function order_meta_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') order_meta_out(['ok'=>false,'error'=>'Método não permitido.'],405);

    $auth = new ApiAuthService();
    $token = ApiAuthService::bearerToken();
    $deviceId = ApiAuthService::deviceId();
    if ($token === '') order_meta_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user = $auth->authenticate($token,$deviceId);
    $tenantId = (int)$user['tenant_id'];

    if (!Auth::can('orders.view') && !Auth::can('orders.create') && !Auth::can('orders.kitchen') && !Auth::can('orders.dispatch') && !Auth::can('orders.delivery') && !Auth::can('payments.manage')) {
        order_meta_out(['ok'=>false,'error'=>'Acesso negado aos pedidos.'],403);
    }

    $raw = trim((string)($_GET['ids'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval',preg_split('/[^0-9]+/',$raw) ?: []),static fn(int $id):bool=>$id>0)));
    if (!$ids) order_meta_out(['ok'=>true,'orders'=>[]]);
    if (count($ids) > 200) order_meta_out(['ok'=>false,'error'=>'Limite de pedidos excedido.'],422);

    $currentShift = (new WorkShiftService())->current();
    if (!$currentShift) throw new RuntimeException('Inicie seu turno antes de consultar a operação.');
    $unitId = isset($currentShift['unit_id']) && $currentShift['unit_id'] !== null && $currentShift['unit_id'] !== '' ? (int)$currentShift['unit_id'] : null;

    $marks = implode(',',array_fill(0,count($ids),'?'));
    $sql = 'SELECT id,order_source FROM orders WHERE tenant_id=? AND id IN ('.$marks.')';
    $args = array_merge([$tenantId],$ids);
    if ($unitId !== null) {
        $sql .= ' AND unit_id=?';
        $args[] = $unitId;
    }
    if ((string)($currentShift['mode'] ?? '') === 'delivery') {
        $sql .= ' AND channel="delivery" AND assigned_delivery_user_id=?';
        $args[] = (int)$user['id'];
    }
    $sql .= ' ORDER BY id DESC';
    $stmt = Database::connection()->prepare($sql);
    $stmt->execute($args);

    $orders = [];
    foreach ($stmt->fetchAll() as $row) {
        $source = strtoupper(trim((string)($row['order_source'] ?? 'EVENTMENU_OWN')));
        $orders[] = [
            'order_id'=>(int)$row['id'],
            'order_source'=>$source === 'EVENTMENU_DELIVERY' ? 'EVENTMENU_DELIVERY' : 'EVENTMENU_OWN',
        ];
    }
    order_meta_out(['ok'=>true,'orders'=>$orders]);
} catch (Throwable $e) {
    error_log('[eventmenu-go-order-meta] '.$e::class.': '.$e->getMessage());
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível consultar os pedidos agora.';
    order_meta_out(['ok'=>false,'error'=>$message],422);
}
