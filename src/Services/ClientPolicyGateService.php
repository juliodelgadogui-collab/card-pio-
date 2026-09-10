<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;
use Throwable;

final class ClientPolicyGateService
{
    /**
     * Aplica a política central quando a chamada declara ser de um cliente
     * EventMenu conhecido. Chamadas web tradicionais continuam inalteradas.
     */
    public function assertCurrentRequestAllowed(): void
    {
        $platform = strtolower(trim((string)($_SERVER['HTTP_X_EVENTMENU_CLIENT'] ?? '')));
        if (!in_array($platform, ['android', 'windows'], true)) return;

        try {
            $service = new ClientPolicyService();
            $policy = $this->effectivePolicy($service, $platform);
        } catch (Throwable) {
            // Compatibilidade durante a primeira atualização: antes da migration
            // 105 existir, não bloqueia todo o sistema.
            return;
        }

        if (empty($policy['enabled'])) {
            throw new RuntimeException($platform === 'android'
                ? 'Esta versão do EventMenu GO foi desativada pelo administrador.'
                : 'Este EventMenu Desktop foi desativado pelo administrador.');
        }

        if (!empty($policy['maintenance'])) {
            $message = trim((string)($policy['maintenance_message'] ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Aplicativo temporariamente em manutenção.');
        }

        $clientVersion = trim((string)($_SERVER['HTTP_X_EVENTMENU_VERSION'] ?? ''));
        $minVersion = trim((string)($policy['min_version'] ?? ''));
        if ($minVersion !== '' && $clientVersion !== '' && version_compare($this->versionCore($clientVersion), $this->versionCore($minVersion), '<')) {
            throw new RuntimeException('Atualização obrigatória. Versão mínima permitida: ' . $minVersion . '.');
        }
        if ($minVersion !== '' && $clientVersion === '') {
            throw new RuntimeException('Versão do aplicativo não identificada. Atualize o cliente EventMenu.');
        }

        $allowed = (array)($policy['allowed_signing_fingerprints'] ?? []);
        if ($allowed !== []) {
            $received = $this->normalizeFingerprint((string)($_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT'] ?? ''));
            $normalizedAllowed = array_values(array_filter(array_map(fn($v) => $this->normalizeFingerprint((string)$v), $allowed)));
            if ($received === '' || !in_array($received, $normalizedAllowed, true)) {
                throw new RuntimeException('Assinatura deste aplicativo/programa não está autorizada.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function effectivePolicy(ClientPolicyService $service, string $platform): array
    {
        $failover = new PlatformFailoverService();
        if ($failover->nodeRole() === 'contingency') {
            $settings = $failover->get();
            if ((string)($settings['mode'] ?? 'read_only') === 'read_only') {
                $cached = $this->verifiedCachedPolicy($service, $platform);
                if ($cached !== null) return $cached;
            }
        }
        return $service->get($platform);
    }

    /** @return array<string,mixed>|null */
    private function verifiedCachedPolicy(ClientPolicyService $service, string $platform): ?array
    {
        $envelope = $service->cachedEnvelope($platform);
        $key = $service->cachedKeyBundle();
        if (!$envelope || !$key) return null;
        if ((string)($envelope['algorithm'] ?? '') !== 'RS256') return null;
        if (!hash_equals((string)$key['key_id'], (string)($envelope['key_id'] ?? ''))) return null;

        $payloadRaw = base64_decode((string)($envelope['payload_b64'] ?? ''), true);
        $signature = base64_decode((string)($envelope['signature_b64'] ?? ''), true);
        if ($payloadRaw === false || $signature === false) return null;
        if (openssl_verify($payloadRaw, $signature, (string)$key['public_key_pem'], OPENSSL_ALGO_SHA256) !== 1) return null;

        $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || (string)($payload['platform'] ?? '') !== $platform) return null;
        return [
            'platform' => $platform,
            'enabled' => !empty($payload['enabled']),
            'min_version' => trim((string)($payload['min_version'] ?? '')),
            'recommended_version' => trim((string)($payload['recommended_version'] ?? '')),
            'maintenance' => !empty($payload['maintenance']),
            'maintenance_message' => trim((string)($payload['maintenance_message'] ?? '')),
            'features' => is_array($payload['features'] ?? null) ? $payload['features'] : [],
            'allowed_signing_fingerprints' => is_array($payload['allowed_signing_fingerprints'] ?? null) ? array_values($payload['allowed_signing_fingerprints']) : [],
            'config_version' => (int)($payload['config_version'] ?? 0),
            'ttl_seconds' => 86400,
        ];
    }

    private function versionCore(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\d+(?:\.\d+){0,3}/', $value, $m)) return $m[0];
        return '0.0.0';
    }

    private function normalizeFingerprint(string $value): string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $value) ?? '');
        return strlen($hex) === 64 ? $hex : '';
    }
}
