<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\DeliveryTrackingService;

function gps_fail(string $message): never { fwrite(STDERR, "GPS CI FAIL: {$message}\n"); exit(1); }
function gps_assert(bool $condition, string $message): void { if (!$condition) gps_fail($message); }

$pdo = Database::connection();
$tenantId = (int)$pdo->query('SELECT id FROM tenants WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn();
if ($tenantId < 1) gps_fail('Tenant ativo não encontrado.');

$suffix = bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"delivery","active")')
    ->execute([$tenantId, 'GPS Delivery', "gps-{$suffix}@example.test", 'x']);
$deliveryId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"delivery","active")')
    ->execute([$tenantId, 'GPS Other', "gps-other-{$suffix}@example.test", 'x']);
$otherId = (int)$pdo->lastInsertId();

$publicToken = bin2hex(random_bytes(20));
$pdo->prepare('INSERT INTO orders (public_token,tenant_id,assigned_delivery_user_id,channel,status,payment_status,subtotal_cents,total_cents,created_by,delivery_address) VALUES (?,?,?,"delivery","out_for_delivery","paid",2500,2500,?,"Rua Teste, 123")')
    ->execute([$publicToken, $tenantId, $deliveryId, $deliveryId]);
$orderId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO delivery_progress (tenant_id,order_id,delivery_user_id,picked_up_at,route_started_at) VALUES (?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')
    ->execute([$tenantId, $orderId, $deliveryId]);

$_SESSION['user_id'] = $deliveryId;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['role'] = 'delivery';
$_SESSION['name'] = 'GPS Delivery';
unset($_SESSION['acting_tenant_id']);

$tracking = new DeliveryTrackingService();
$now = time();
$result = $tracking->updateBatch([
    [
        'latitude' => -21.13370,
        'longitude' => -41.67910,
        'accuracy_m' => 12,
        'speed_mps' => 8.5,
        'bearing_deg' => 90,
        'battery_pct' => 73,
        'provider' => 'fused',
        'is_mock' => false,
        'captured_at' => gmdate('c', $now - 20),
    ],
    [
        'latitude' => -21.13400,
        'longitude' => -41.67850,
        'accuracy_m' => 9,
        'speed_mps' => 9.2,
        'bearing_deg' => 92,
        'battery_pct' => 72,
        'provider' => 'fused',
        'is_mock' => true,
        'captured_at' => gmdate('c', $now),
    ],
]);

gps_assert((int)$result['active_orders'] === 1, 'Lote não encontrou a entrega ativa atribuída.');
$live = $pdo->query('SELECT * FROM delivery_live_locations WHERE order_id=' . $orderId)->fetch();
gps_assert((bool)$live, 'Posição ao vivo não foi gravada.');
gps_assert((int)$live['delivery_user_id'] === $deliveryId, 'Posição ficou vinculada ao entregador incorreto.');
gps_assert((int)$live['battery_pct'] === 72, 'Telemetria de bateria não foi atualizada.');
gps_assert((int)$live['is_mock'] === 1, 'Flag de localização simulada não foi preservada.');

$historyCount = (int)$pdo->query('SELECT COUNT(*) FROM delivery_location_history WHERE order_id=' . $orderId)->fetchColumn();
gps_assert($historyCount === 1, 'GPS simulado entrou no histórico confiável.');
$trusted = $pdo->query('SELECT latitude,longitude,is_mock FROM delivery_location_history WHERE order_id=' . $orderId . ' ORDER BY id DESC LIMIT 1')->fetch();
gps_assert((int)$trusted['is_mock'] === 0, 'Histórico confiável contém ponto simulado.');

$_SESSION['user_id'] = $otherId;
$_SESSION['role'] = 'delivery';
$_SESSION['name'] = 'GPS Other';
$foreign = $tracking->updateBatch([[
    'latitude' => -22.0,
    'longitude' => -42.0,
    'accuracy_m' => 10,
    'captured_at' => gmdate('c'),
]]);
gps_assert((int)$foreign['active_orders'] === 0, 'Entregador não atribuído recebeu pedido ativo no GPS.');
$afterForeign = $pdo->query('SELECT latitude,longitude FROM delivery_live_locations WHERE order_id=' . $orderId)->fetch();
gps_assert(abs((float)$afterForeign['latitude'] - (float)$live['latitude']) < 0.000001, 'Entregador não atribuído alterou a posição da entrega.');

$_SESSION['user_id'] = $deliveryId;
$_SESSION['role'] = 'delivery';
$_SESSION['name'] = 'GPS Delivery';
$token = $tracking->publicToken($tenantId, $orderId);
gps_assert((bool)preg_match('/^[a-f0-9]{64}$/', $token), 'Token público não possui entropia/formato esperado.');
$public = $tracking->latestPublic($token);
gps_assert($public !== null, 'Link público não resolveu a entrega.');
gps_assert((int)$public['order_id'] === $orderId, 'Link público resolveu pedido incorreto.');
gps_assert(count($public['history']) === 1, 'Histórico público deveria conter somente ponto confiável.');
// Como o último ponto ao vivo foi marcado como simulado, o cliente deve receber
// como posição atual o último ponto confiável e não a coordenada simulada.
gps_assert(abs((float)$public['latitude'] - (float)$trusted['latitude']) < 0.000001, 'Cliente recebeu localização simulada como posição atual.');

echo "CI delivery tracking smoke OK\n";
