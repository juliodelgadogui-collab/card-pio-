<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;
use Throwable;

final class ClusterControlPlaneSchedulerService
{
    /** @return array<string,mixed> */
    public function syncIfDue(int $intervalSeconds = 300): array
    {
        $intervalSeconds = max(60, min(3600, $intervalSeconds));
        $failover = new PlatformFailoverService();
        if (!$failover->isPrimaryNode()) return ['status' => 'skipped', 'reason' => 'not_primary'];

        $settings = $failover->get();
        if (empty($settings['enabled']) || trim((string)$settings['contingency_url']) === '') {
            return ['status' => 'skipped', 'reason' => 'disabled'];
        }
        if ((string)$settings['last_health_status'] !== 'healthy' || empty($settings['verified_at'])) {
            return ['status' => 'skipped', 'reason' => 'not_verified'];
        }

        $dir = dirname(__DIR__, 2) . '/storage/cluster';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar storage/cluster.');
        $marker = $dir . '/last-control-plane-sync.json';
        $lastAt = 0;
        if (is_file($marker)) {
            try {
                $data = json_decode((string)file_get_contents($marker), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data)) $lastAt = (int)($data['unix_time'] ?? 0);
            } catch (Throwable) {
            }
        }
        if ($lastAt > 0 && time() - $lastAt < $intervalSeconds) {
            return ['status' => 'skipped', 'reason' => 'not_due', 'last_sync_at' => gmdate('c', $lastAt)];
        }

        $result = (new ClusterSyncService())->pushControlPlane();
        $snapshot = [
            'unix_time' => time(),
            'synced_at' => gmdate('c'),
            'bundle_hash' => (string)($result['bundle_hash'] ?? ''),
        ];
        $tmp = $marker . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
        @chmod($tmp, 0640);
        if (!@rename($tmp, $marker)) @unlink($tmp);
        return ['status' => 'synced'] + $result;
    }
}
