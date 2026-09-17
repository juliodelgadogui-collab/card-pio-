<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class HubCommandIdempotencyGuardService
{
    public function assertReusable(string $key, int $targetBindingId, string $commandType, string $mobileDeviceId): void
    {
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId) throw new RuntimeException('Sessão inválida.');

        $key = trim($key);
        if (strlen($key) < 12) return;
        $mobileDeviceId = trim($mobileDeviceId);
        if (strlen($mobileDeviceId) < 8) throw new RuntimeException('Celular não identificado.');

        $s = Database::connection()->prepare(
            'SELECT requested_by_user_id,source_device_hash,target_binding_id,command_type
             FROM hub_commands
             WHERE tenant_id=? AND idempotency_key=? LIMIT 1'
        );
        $s->execute([$tenantId, $key]);
        $existing = $s->fetch();
        if (!$existing) return;

        $sameUser = (int)$existing['requested_by_user_id'] === $userId;
        $sameDevice = hash_equals((string)($existing['source_device_hash'] ?? ''), hash('sha256', $mobileDeviceId));
        $sameTarget = (int)$existing['target_binding_id'] === $targetBindingId;
        $sameType = hash_equals((string)$existing['command_type'], $commandType);

        if (!$sameUser || !$sameDevice || !$sameTarget || !$sameType) {
            throw new RuntimeException('Esta chave de idempotência já pertence a outra solicitação do Hub.');
        }
    }
}
