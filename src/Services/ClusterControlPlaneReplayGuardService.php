<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;
use Throwable;

/**
 * Impede que uma sincronização válida, porém mais antiga, substitua o estado
 * mais novo já armazenado no servidor de contingência.
 */
final class ClusterControlPlaneReplayGuardService
{
    private const MAX_BYTES = 2_000_000;

    public function assertNotOlder(string $raw): void
    {
        // O limite espelha o ClusterSyncService para não fazer parse preliminar
        // de um corpo que o serviço autenticado recusará logo depois.
        if ($raw === '' || strlen($raw) > self::MAX_BYTES) return;
        try {
            $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return; // A validação estrutural/autenticada continua no ClusterSyncService.
        }
        if (!is_array($request)) return;
        $bundle = $request['bundle'] ?? null;
        if (!is_array($bundle)) return;

        $this->assertRoutingNotOlder($bundle);

        $policies = $bundle['policies'] ?? null;
        if (!is_array($policies)) return;
        $policyService = new ClientPolicyService();
        foreach (['android', 'windows'] as $platform) {
            $incoming = $policies[$platform] ?? null;
            if (!is_array($incoming)) continue;
            $stored = $policyService->cachedEnvelope($platform);
            if (!is_array($stored)) continue;

            $incomingOrder = $this->envelopeOrder($incoming, $platform);
            $storedOrder = $this->envelopeOrder($stored, $platform);
            if ($incomingOrder === null || $storedOrder === null) continue;
            if ($this->compareOrder($incomingOrder, $storedOrder) < 0) {
                throw new RuntimeException('Sincronização rejeitada: política ' . $platform . ' é anterior ao estado já armazenado na contingência.');
            }
        }
    }

    /** @param array<string,mixed> $bundle */
    private function assertRoutingNotOlder(array $bundle): void
    {
        $incoming = $bundle['routing'] ?? null;
        if (!is_array($incoming)) return;
        $incomingVersion = (int)($incoming['config_version'] ?? 0);

        $path = dirname(__DIR__, 2) . '/storage/cluster/control-plane.json';
        if (!is_file($path)) return;
        try {
            $stored = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }
        if (!is_array($stored) || !is_array($stored['routing'] ?? null)) return;
        $storedVersion = (int)($stored['routing']['config_version'] ?? 0);
        if ($storedVersion > 0 && $incomingVersion < $storedVersion) {
            throw new RuntimeException('Sincronização rejeitada: configuração de failover é anterior ao estado já armazenado na contingência.');
        }
    }

    /**
     * @param array<string,mixed> $envelope
     * @return array{policy:int,release:int,issued:int}|null
     */
    private function envelopeOrder(array $envelope, string $platform): ?array
    {
        $payloadRaw = base64_decode((string)($envelope['payload_b64'] ?? ''), true);
        if ($payloadRaw === false || $payloadRaw === '') return null;
        try {
            $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($payload) || (string)($payload['platform'] ?? '') !== $platform) return null;
        $issued = strtotime(trim((string)($payload['issued_at'] ?? '')));
        return [
            'policy' => (int)($payload['config_version'] ?? 0),
            'release' => is_array($payload['release'] ?? null) ? (int)($payload['release']['config_version'] ?? 0) : 0,
            'issued' => $issued === false ? 0 : $issued,
        ];
    }

    /**
     * @param array{policy:int,release:int,issued:int} $left
     * @param array{policy:int,release:int,issued:int} $right
     */
    private function compareOrder(array $left, array $right): int
    {
        foreach (['policy', 'release', 'issued'] as $key) {
            if ($left[$key] < $right[$key]) return -1;
            if ($left[$key] > $right[$key]) return 1;
        }
        return 0;
    }
}
