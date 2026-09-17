<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class HubTerminalCatalogService
{
    public function listForUnit(int $unitId): array
    {
        if (!Auth::can('terminal.request') && !Auth::can('terminal.collect')) {
            throw new RuntimeException('Você não possui permissão para solicitar cobrança no PINPad.');
        }
        $tenantId = Auth::tenantId();
        if (!$tenantId || $unitId < 1) throw new RuntimeException('Unidade inválida.');

        $allowed = false;
        foreach ((new OperatingUnitService())->availableForCurrentUser() as $unit) {
            if ((int)$unit['id'] === $unitId) { $allowed = true; break; }
        }
        if (!$allowed) throw new RuntimeException('Você não possui acesso a esta unidade.');

        $s = Database::connection()->prepare('SELECT id,unit_id,provider,terminal_label,pinpad_identifier FROM payment_terminal_configs WHERE tenant_id=? AND unit_id=? AND enabled=1 ORDER BY id');
        $s->execute([$tenantId, $unitId]);
        $rows = $s->fetchAll();
        foreach ($rows as &$row) {
            $row['label'] = trim((string)($row['terminal_label'] ?? '')) ?: ('PINPad #' . (int)$row['id']);
            unset($row['terminal_label']);
        }
        unset($row);
        return $rows;
    }
}
