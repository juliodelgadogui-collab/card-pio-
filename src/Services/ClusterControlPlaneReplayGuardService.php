<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

/**
 * Impede que uma sincronização válida, porém mais antiga, substitua o estado
 * mais novo já armazenado no servidor de contingência.
 *
 * A comparação monotônica só acontece depois de confirmar o HMAC do pacote.
 * Requisições não autenticadas seguem para o ClusterSyncService, que devolve
 * o erro criptográfico normal sem revelar a ordem/versão do estado armazenado.
 */
final class ClusterControlPlaneReplayGuardService
{
    private const MAX_BYTES = 2_000_000;

    public function assertAuthenticatedNotOlder(string $raw, string $signature): void
    {
        if ($raw === '' || strlen($raw) > self::MAX_BYTES || $signature === '') return;

        $credentials = $this->credentialsOrNull();
        if ($credentials === null) return;
        [$clusterId, $secret] = $credentials;
        if (!hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) return;

        try {
            $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }
        if (!is_array($request)) return;
        if (!hash_equals($clusterId, trim((string)($request['cluster_id'] ?? '')))) return;
        if ((string)($request['type'] ?? '') !== 'control_plane') return;
        $timestamp = (int)($request['timestamp'] ?? 0);
        if ($timestamp < 1 || abs(time() - $timestamp) > 300) return;
        if (strlen(trim((string)($request['nonce'] ?? ''))) < 16) return;

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

    /** @return array{0:string,1:string}|null */
    private function credentialsOrNull(): ?array
    {
        $clusterId = trim((string)env('EVENTMENU_CLUSTER_ID', ''));
        $secret = trim((string)env('EVENTMENU_CLUSTER_SECRET', ''));
        try {
            $stmt = Database::connection()->prepare('SELECT cluster_id,cluster_secret_encrypted FROM platform_failover_settings WHERE id=1 LIMIT 1');
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                if (trim((string)($row['cluster_id'] ?? '')) !== '') $clusterId = trim((string)$row['cluster_id']);
                if (!empty($row['cluster_secret_encrypted'])) $secret = Crypto::decrypt((string)$row['cluster_secret_encrypted']);
            }
        } catch (Throwable) {
        }
        if ($clusterId === '' || strlen($secret) < 32) {
            try {
                $node = new ClusterNodeConfigService();
                if ($clusterId === '') $clusterId = $node->clusterId();
                if (strlen($secret) < 32) $secret = $node->clusterSecret();
            } catch (Throwable) {
            }
        }
        if ($clusterId === '' || strlen($secret) < 32) return null;
        return [$clusterId, $secret];
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
