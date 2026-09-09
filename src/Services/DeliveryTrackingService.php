<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryTrackingService
{
    private const MAX_BATCH_POINTS = 30;
    private const HISTORY_INTERVAL_SECONDS = 15;
    private const TRUSTED_ACCURACY_METERS = 150.0;

    /**
     * Endpoint novo do APK: um único lote atende todas as entregas em rota
     * atribuídas ao entregador autenticado.
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<string,mixed>
     */
    public function updateBatch(array $points): array
    {
        [$tenantId, $userId] = $this->deliveryContext();
        if ($points === []) {
            throw new RuntimeException('Nenhuma localização recebida.');
        }

        $normalized = [];
        foreach (array_slice($points, -self::MAX_BATCH_POINTS) as $point) {
            if (!is_array($point)) continue;
            $normalized[] = $this->normalizePoint($point);
        }
        if ($normalized === []) throw new RuntimeException('Localização inválida.');
        usort($normalized, static fn(array $a, array $b): int => strcmp($a['captured_at'], $b['captured_at']));

        $pdo = Database::connection();
        $orders = $this->activeAssignedOrderIds($pdo, $tenantId, $userId);
        if ($orders === []) {
            return ['active_orders' => 0, 'stored' => 0, 'order_ids' => []];
        }

        $stored = Database::transaction(function (PDO $tx) use ($tenantId, $userId, $orders, $normalized): int {
            $lastHistory = [];
            foreach ($orders as $orderId) {
                $s = $tx->prepare('SELECT captured_at FROM delivery_location_history WHERE tenant_id=? AND order_id=? ORDER BY id DESC LIMIT 1');
                $s->execute([$tenantId, $orderId]);
                $lastHistory[$orderId] = $s->fetchColumn() ?: null;
            }

            $writes = 0;
            foreach ($normalized as $point) {
                foreach ($orders as $orderId) {
                    $this->upsertLive($tx, $tenantId, $orderId, $userId, $point);
                    $writes++;

                    $trusted = !$point['is_mock'] && ($point['accuracy_m'] === null || $point['accuracy_m'] <= self::TRUSTED_ACCURACY_METERS);
                    if (!$trusted) continue;
                    $previous = $lastHistory[$orderId] ?? null;
                    if ($previous !== null && strtotime($point['captured_at']) - strtotime((string)$previous) < self::HISTORY_INTERVAL_SECONDS) continue;
                    $this->insertHistory($tx, $tenantId, $orderId, $userId, $point);
                    $lastHistory[$orderId] = $point['captured_at'];
                }
            }
            return $writes;
        });

        return [
            'active_orders' => count($orders),
            'stored' => $stored,
            'order_ids' => $orders,
            'last_captured_at' => end($normalized)['captured_at'] ?? null,
        ];
    }

    /**
     * Compatibilidade com APKs anteriores.
     *
     * @return array<string,mixed>
     */
    public function update(
        int $orderId,
        float $lat,
        float $lng,
        ?float $accuracy,
        ?float $speed,
        ?float $bearing,
        ?string $capturedAt = null,
        ?int $batteryPct = null,
        ?string $provider = null,
        bool $isMock = false,
    ): array {
        [$tenantId, $userId] = $this->deliveryContext();
        if ($orderId <= 0) throw new RuntimeException('Pedido inválido.');
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT id FROM orders WHERE tenant_id=? AND id=? AND channel="delivery" AND assigned_delivery_user_id=? AND status="out_for_delivery" LIMIT 1');
        $s->execute([$tenantId, $orderId, $userId]);
        if (!$s->fetchColumn()) throw new RuntimeException('Pedido não está em rota com este entregador.');

        $point = $this->normalizePoint([
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $accuracy,
            'speed_mps' => $speed,
            'bearing_deg' => $bearing,
            'captured_at' => $capturedAt,
            'battery_pct' => $batteryPct,
            'provider' => $provider,
            'is_mock' => $isMock,
        ]);

        Database::transaction(function (PDO $tx) use ($tenantId, $orderId, $userId, $point): void {
            $this->upsertLive($tx, $tenantId, $orderId, $userId, $point);
            if ($point['is_mock'] || ($point['accuracy_m'] !== null && $point['accuracy_m'] > self::TRUSTED_ACCURACY_METERS)) return;
            $s = $tx->prepare('SELECT captured_at FROM delivery_location_history WHERE tenant_id=? AND order_id=? ORDER BY id DESC LIMIT 1');
            $s->execute([$tenantId, $orderId]);
            $last = $s->fetchColumn();
            if (!$last || strtotime($point['captured_at']) - strtotime((string)$last) >= self::HISTORY_INTERVAL_SECONDS) {
                $this->insertHistory($tx, $tenantId, $orderId, $userId, $point);
            }
        });

        return ['order_id' => $orderId] + $point;
    }

    /** @return array<int,array<string,mixed>> */
    public function activeForManager(): array
    {
        $tenantId = Auth::tenantId();
        if (!$tenantId || !Auth::can('orders.delivery')) throw new RuntimeException('Acesso negado.');

        $sql = 'SELECT o.id order_id,o.unit_id,o.delivery_address,o.created_at,
                       o.assigned_delivery_user_id delivery_user_id,
                       u.name delivery_name,c.name customer_name,c.phone customer_phone,
                       dp.route_started_at,dp.arrived_at,
                       l.latitude,l.longitude,l.accuracy_m,l.speed_mps,l.bearing_deg,
                       l.battery_pct,l.provider,l.is_mock,l.captured_at,l.updated_at
                FROM orders o
                LEFT JOIN users u ON u.id=o.assigned_delivery_user_id
                LEFT JOIN customers c ON c.id=o.customer_id
                LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id
                LEFT JOIN delivery_live_locations l ON l.tenant_id=o.tenant_id AND l.order_id=o.id
                WHERE o.tenant_id=? AND o.channel="delivery" AND o.status="out_for_delivery"
                ORDER BY o.id DESC LIMIT 200';
        $s = Database::connection()->prepare($sql);
        $s->execute([$tenantId]);
        $rows = $s->fetchAll();

        foreach ($rows as &$row) {
            $signal = $this->signalMeta($row['captured_at'] ?? null, $row['accuracy_m'] ?? null, !empty($row['is_mock']));
            $row['latitude'] = $row['latitude'] !== null ? (float)$row['latitude'] : null;
            $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
            $row['accuracy_m'] = $row['accuracy_m'] !== null ? (float)$row['accuracy_m'] : null;
            $row['speed_kmh'] = $row['speed_mps'] !== null ? round((float)$row['speed_mps'] * 3.6, 1) : null;
            $row['bearing_deg'] = $row['bearing_deg'] !== null ? (float)$row['bearing_deg'] : null;
            $row['battery_pct'] = $row['battery_pct'] !== null ? (int)$row['battery_pct'] : null;
            $row['is_mock'] = !empty($row['is_mock']);
            $row['signal'] = $signal;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function latestPublic(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $pdo = Database::connection();
        $sql = 'SELECT t.tenant_id,o.id order_id,o.status,o.delivery_address,tn.name tenant_name,
                       dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at,
                       l.latitude,l.longitude,l.accuracy_m,l.speed_mps,l.bearing_deg,
                       l.battery_pct,l.provider,l.is_mock,l.captured_at
                FROM delivery_tracking_tokens t
                JOIN orders o ON o.tenant_id=t.tenant_id AND o.id=t.order_id
                LEFT JOIN tenants tn ON tn.id=t.tenant_id
                LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id
                LEFT JOIN delivery_live_locations l ON l.tenant_id=o.tenant_id AND l.order_id=o.id
                WHERE t.token_hash=? AND t.expires_at>CURRENT_TIMESTAMP LIMIT 1';
        $s = $pdo->prepare($sql);
        $s->execute([hash('sha256', $token)]);
        $row = $s->fetch();
        if (!$row) return null;

        $historyStmt = $pdo->prepare('SELECT latitude,longitude,accuracy_m,speed_mps,bearing_deg,captured_at FROM delivery_location_history WHERE tenant_id=? AND order_id=? AND is_mock=0 AND (accuracy_m IS NULL OR accuracy_m<=?) ORDER BY id DESC LIMIT 80');
        $historyStmt->execute([(int)$row['tenant_id'], (int)$row['order_id'], self::TRUSTED_ACCURACY_METERS]);
        $history = array_reverse($historyStmt->fetchAll());
        $history = array_map(static fn(array $p): array => [
            'latitude' => (float)$p['latitude'],
            'longitude' => (float)$p['longitude'],
            'accuracy_m' => $p['accuracy_m'] !== null ? (float)$p['accuracy_m'] : null,
            'speed_kmh' => $p['speed_mps'] !== null ? round((float)$p['speed_mps'] * 3.6, 1) : null,
            'bearing_deg' => $p['bearing_deg'] !== null ? (float)$p['bearing_deg'] : null,
            'captured_at' => $p['captured_at'],
        ], $history);

        $publicLat = $row['latitude'] !== null ? (float)$row['latitude'] : null;
        $publicLng = $row['longitude'] !== null ? (float)$row['longitude'] : null;
        $mock = !empty($row['is_mock']);
        if ($mock && $history !== []) {
            $lastTrusted = end($history);
            $publicLat = $lastTrusted['latitude'];
            $publicLng = $lastTrusted['longitude'];
        }

        $signal = $this->signalMeta($row['captured_at'] ?? null, $row['accuracy_m'] ?? null, $mock);
        if ($row['status'] === 'completed') $signal = ['status' => 'completed', 'label' => 'Entregue', 'age_seconds' => 0];

        return [
            'order_id' => (int)$row['order_id'],
            'order_status' => (string)$row['status'],
            'tenant_name' => (string)($row['tenant_name'] ?: 'EventMenu'),
            'latitude' => $publicLat,
            'longitude' => $publicLng,
            'accuracy_m' => $row['accuracy_m'] !== null ? (float)$row['accuracy_m'] : null,
            'speed_kmh' => $row['speed_mps'] !== null ? round((float)$row['speed_mps'] * 3.6, 1) : null,
            'bearing_deg' => $row['bearing_deg'] !== null ? (float)$row['bearing_deg'] : null,
            'captured_at' => $row['captured_at'],
            'picked_up_at' => $row['picked_up_at'],
            'route_started_at' => $row['route_started_at'],
            'arrived_at' => $row['arrived_at'],
            'completed_at' => $row['completed_at'],
            'signal' => $signal,
            'route_distance_m' => $this->routeDistanceMeters($history),
            'history' => $history,
        ];
    }

    public function publicToken(int $tenantId, int $orderId): string
    {
        if (Auth::tenantId() !== $tenantId || !Auth::can('orders.delivery')) throw new RuntimeException('Acesso negado ao link de rastreamento.');
        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT status FROM orders WHERE tenant_id=? AND id=? AND channel="delivery" LIMIT 1');
        $s->execute([$tenantId, $orderId]);
        $status = $s->fetchColumn();
        if (!in_array($status, ['ready', 'out_for_delivery'], true)) throw new RuntimeException('Esta entrega não está disponível para rastreamento.');

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = gmdate('Y-m-d H:i:s', time() + 8 * 3600);
        $pdo->prepare('DELETE FROM delivery_tracking_tokens WHERE tenant_id=? AND order_id=?')->execute([$tenantId, $orderId]);
        $pdo->prepare('INSERT INTO delivery_tracking_tokens (tenant_id,order_id,token_hash,expires_at) VALUES (?,?,?,?)')->execute([$tenantId, $orderId, $hash, $expires]);
        return $token;
    }

    /** @return array{0:int,1:int} */
    private function deliveryContext(): array
    {
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId || !Auth::can('orders.delivery')) throw new RuntimeException('Acesso negado ao rastreamento.');
        return [$tenantId, $userId];
    }

    /** @return array<int,int> */
    private function activeAssignedOrderIds(PDO $pdo, int $tenantId, int $userId): array
    {
        $sql = 'SELECT o.id FROM orders o
                JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id
                WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=?
                  AND o.status="out_for_delivery" AND dp.delivery_user_id=?
                  AND dp.route_started_at IS NOT NULL AND dp.completed_at IS NULL
                ORDER BY o.id';
        $s = $pdo->prepare($sql);
        $s->execute([$tenantId, $userId, $userId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalizePoint(array $raw): array
    {
        if (!isset($raw['latitude'], $raw['longitude']) || !is_numeric($raw['latitude']) || !is_numeric($raw['longitude'])) throw new RuntimeException('Latitude/longitude inválidas.');
        $lat = (float)$raw['latitude'];
        $lng = (float)$raw['longitude'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new RuntimeException('Localização fora do intervalo válido.');

        $accuracy = isset($raw['accuracy_m']) && is_numeric($raw['accuracy_m']) ? (float)$raw['accuracy_m'] : null;
        if ($accuracy !== null && ($accuracy < 0 || $accuracy > 5000)) $accuracy = null;
        $speed = isset($raw['speed_mps']) && is_numeric($raw['speed_mps']) ? max(0.0, min(100.0, (float)$raw['speed_mps'])) : null;
        $bearing = isset($raw['bearing_deg']) && is_numeric($raw['bearing_deg']) ? fmod(max(0.0, (float)$raw['bearing_deg']), 360.0) : null;
        $battery = isset($raw['battery_pct']) && is_numeric($raw['battery_pct']) ? max(0, min(100, (int)$raw['battery_pct'])) : null;
        $provider = trim((string)($raw['provider'] ?? ''));
        $provider = $provider === '' ? null : substr($provider, 0, 32);

        return [
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $accuracy,
            'speed_mps' => $speed,
            'bearing_deg' => $bearing,
            'battery_pct' => $battery,
            'provider' => $provider,
            'is_mock' => filter_var($raw['is_mock'] ?? false, FILTER_VALIDATE_BOOL),
            'captured_at' => $this->normalizeCapturedAt(isset($raw['captured_at']) ? (string)$raw['captured_at'] : null),
        ];
    }

    /** @param array<string,mixed> $point */
    private function upsertLive(PDO $pdo, int $tenantId, int $orderId, int $userId, array $point): void
    {
        $values = [$tenantId, $orderId, $userId, $point['latitude'], $point['longitude'], $point['accuracy_m'], $point['speed_mps'], $point['bearing_deg'], $point['battery_pct'], $point['provider'], $point['is_mock'] ? 1 : 0, $point['captured_at']];
        if (Database::isSqlite($pdo)) {
            $sql = 'INSERT INTO delivery_live_locations (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,battery_pct,provider,is_mock,captured_at,updated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
                    ON CONFLICT(tenant_id,order_id) DO UPDATE SET delivery_user_id=excluded.delivery_user_id,latitude=excluded.latitude,longitude=excluded.longitude,accuracy_m=excluded.accuracy_m,speed_mps=excluded.speed_mps,bearing_deg=excluded.bearing_deg,battery_pct=excluded.battery_pct,provider=excluded.provider,is_mock=excluded.is_mock,captured_at=excluded.captured_at,updated_at=CURRENT_TIMESTAMP';
        } else {
            $sql = 'INSERT INTO delivery_live_locations (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,battery_pct,provider,is_mock,captured_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE delivery_user_id=VALUES(delivery_user_id),latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_m=VALUES(accuracy_m),speed_mps=VALUES(speed_mps),bearing_deg=VALUES(bearing_deg),battery_pct=VALUES(battery_pct),provider=VALUES(provider),is_mock=VALUES(is_mock),captured_at=VALUES(captured_at),updated_at=CURRENT_TIMESTAMP';
        }
        $pdo->prepare($sql)->execute($values);
    }

    /** @param array<string,mixed> $point */
    private function insertHistory(PDO $pdo, int $tenantId, int $orderId, int $userId, array $point): void
    {
        $sql = 'INSERT INTO delivery_location_history (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,battery_pct,provider,is_mock,captured_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
        $pdo->prepare($sql)->execute([$tenantId, $orderId, $userId, $point['latitude'], $point['longitude'], $point['accuracy_m'], $point['speed_mps'], $point['bearing_deg'], $point['battery_pct'], $point['provider'], $point['is_mock'] ? 1 : 0, $point['captured_at']]);
    }

    /** @return array{status:string,label:string,age_seconds:int} */
    private function signalMeta(mixed $capturedAt, mixed $accuracy, bool $mock): array
    {
        if ($capturedAt === null || trim((string)$capturedAt) === '') return ['status' => 'offline', 'label' => 'Sem sinal', 'age_seconds' => 999999];
        $age = max(0, time() - (strtotime((string)$capturedAt) ?: time()));
        if ($mock) return ['status' => 'mock', 'label' => 'GPS simulado', 'age_seconds' => $age];
        $acc = $accuracy !== null ? (float)$accuracy : null;
        if ($age <= 30 && ($acc === null || $acc <= 80)) return ['status' => 'live', 'label' => 'Ao vivo', 'age_seconds' => $age];
        if ($age <= 90) return ['status' => 'weak', 'label' => 'Sinal fraco', 'age_seconds' => $age];
        return ['status' => 'offline', 'label' => 'Sem sinal', 'age_seconds' => $age];
    }

    /** @param array<int,array<string,mixed>> $history */
    private function routeDistanceMeters(array $history): int
    {
        $total = 0.0;
        $previous = null;
        foreach ($history as $point) {
            if ($previous !== null) $total += $this->haversine((float)$previous['latitude'], (float)$previous['longitude'], (float)$point['latitude'], (float)$point['longitude']);
            $previous = $point;
        }
        return (int)round($total);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function normalizeCapturedAt(?string $value): string
    {
        $ts = $value ? strtotime($value) : false;
        if ($ts === false || abs(time() - $ts) > 900) $ts = time();
        return gmdate('Y-m-d H:i:s', $ts);
    }
}
