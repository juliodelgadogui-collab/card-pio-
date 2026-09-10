<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class HubDesktopPresenceService
{
    private const HARDWARE_KEYS = [
        'default_printer',
        'cash_drawer',
        'scale',
        'barcode_scanner',
        'customer_display',
        'tef_provider',
        'pinpad',
        'printers',
    ];

    public function heartbeat(int $unitId, string $deviceId, string $label, array $hardware): array
    {
        Auth::requirePermission('hardware.manage');
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa inválida.');
        if ($unitId < 1) throw new RuntimeException('Unidade inválida.');
        $this->assertUnitAccess($unitId);

        $deviceId = trim($deviceId);
        if (strlen($deviceId) < 8) throw new RuntimeException('Computador não identificado.');
        $deviceHash = hash('sha256', $deviceId);
        $label = mb_substr(trim($label), 0, 190);
        $safeHardware = $this->sanitizeHardware($hardware);
        $hardwareJson = json_encode($safeHardware, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Database::transaction(function (PDO $pdo) use ($tenantId, $unitId, $deviceHash, $label, $hardwareJson, $safeHardware): array {
            $q = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,unit_id,revoked_at FROM desktop_hardware_bindings WHERE tenant_id=? AND device_hash=? LIMIT 1 FOR UPDATE'));
            $q->execute([$tenantId, $deviceHash]);
            $existing = $q->fetch();

            if ($existing && !empty($existing['revoked_at'])) {
                throw new RuntimeException('Este computador foi revogado. Libere o dispositivo antes de reconectar.');
            }
            if ($existing && (int)$existing['unit_id'] !== $unitId) {
                throw new RuntimeException('Este computador já está vinculado a outra unidade. Revogue o vínculo antes de alterar a unidade.');
            }

            if ($existing) {
                $bindingId = (int)$existing['id'];
                $pdo->prepare('UPDATE desktop_hardware_bindings SET device_label=?,hardware_json=?,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND unit_id=?')
                    ->execute([$label ?: null, $hardwareJson, $bindingId, $tenantId, $unitId]);
            } else {
                $pdo->prepare('INSERT INTO desktop_hardware_bindings (tenant_id,unit_id,device_hash,device_label,hardware_json,last_seen_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)')
                    ->execute([$tenantId, $unitId, $deviceHash, $label ?: null, $hardwareJson]);
                $bindingId = (int)$pdo->lastInsertId();
            }

            return [
                'id' => $bindingId,
                'unit_id' => $unitId,
                'label' => $label ?: 'EventMenu Desktop',
                'online' => true,
                'hardware' => $safeHardware,
                'last_seen_at' => gmdate('Y-m-d H:i:s'),
            ];
        });
    }

    private function sanitizeHardware(array $hardware): array
    {
        $safe = [];
        foreach (self::HARDWARE_KEYS as $key) {
            if (!array_key_exists($key, $hardware)) continue;
            $value = $hardware[$key];
            if ($key === 'printers') {
                if (!is_array($value)) continue;
                $printers = [];
                foreach (array_slice($value, 0, 20) as $printer) {
                    if (!is_scalar($printer)) continue;
                    $clean = mb_substr(trim((string)$printer), 0, 190);
                    if ($clean !== '') $printers[] = $clean;
                }
                $safe[$key] = array_values(array_unique($printers));
                continue;
            }
            if (!is_scalar($value)) continue;
            $safe[$key] = mb_substr(trim((string)$value), 0, 190);
        }
        return $safe;
    }

    private function assertUnitAccess(int $unitId): void
    {
        foreach ((new OperatingUnitService())->availableForCurrentUser() as $unit) {
            if ((int)$unit['id'] === $unitId) return;
        }
        throw new RuntimeException('Você não possui acesso a esta unidade.');
    }
}
