<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;
use Throwable;

final class ClientPolicyGateService
{
    private const CLOCK_SKEW_SECONDS = 300;

    /**
     * Aplica a política central somente a clientes EventMenu identificados.
     * Chamadas web tradicionais continuam inalteradas.
     */
    public function assertCurrentRequestAllowed(): void
    {
        $platform = strtolower(trim((string)($_SERVER['HTTP_X_EVENTMENU_CLIENT'] ?? '')));
        if (!in_array($platform, ['android', 'windows'], true)) return;

        $nodeRole = 'primary';
        try { $nodeRole = (new PlatformFailoverService())->nodeRole(); } catch (Throwable) {}

        try {
            $policy = $this->effectivePolicy(new ClientPolicyService(), $platform);
        } catch (Throwable $e) {
            // Produção é fail-closed por padrão. O modo legado existe apenas como
            // válvula explícita de migração para uma instalação antiga que ainda
            // não recebeu a migration/política inicial. Nunca é ativado sozinho.
            if ($nodeRole === 'primary' && $this->legacyFailOpenEnabled()) return;
            throw new RuntimeException(
                $nodeRole === 'contingency'
                    ? 'Autorização remota indisponível ou expirada no servidor de contingência. Tente o servidor principal.'
                    : 'Autorização remota indisponível. O EventMenu bloqueou este cliente por segurança.',
                0,
                $e,
            );
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
        if ($minVersion !== '' && $clientVersion === '') {
            throw new RuntimeException('Versão do aplicativo não identificada. Atualize o cliente EventMenu.');
        }
        if ($minVersion !== '' && version_compare($this->versionCore($clientVersion), $this->versionCore($minVersion), '<')) {
            throw new RuntimeException('Atualização obrigatória. Versão mínima permitida: ' . $minVersion . '.');
        }

        $allowed = (array)($policy['allowed_signing_fingerprints'] ?? []);
        if ($allowed !== []) {
            $received = $this->normalizeFingerprint((string)($_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT'] ?? ''));
            $normalizedAllowed = array_values(array_filter(array_map(
                fn($value) => $this->normalizeFingerprint((string)$value),
                $allowed,
            )));
            if ($received === '' || !in_array($received, $normalizedAllowed, true)) {
                throw new RuntimeException('Assinatura deste aplicativo/programa não está autorizada.');
            }
        }

        $feature = $this->featureForCurrentEndpoint();
        $features = is_array($policy['features'] ?? null) ? $policy['features'] : [];
        if ($feature !== null && $features !== [] && empty($features[$feature])) {
            throw new RuntimeException('Este recurso foi desativado remotamente pelo administrador: ' . $feature . '.');
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
                if ($cached === null) throw new RuntimeException('Política assinada ausente, inválida ou expirada.');
                return $cached;
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

        try {
            $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($payload) || (string)($payload['platform'] ?? '') !== $platform) return null;
        if (!$this->validEnvelopeWindow($payload)) return null;

        return [
            'platform' => $platform,
            'enabled' => !empty($payload['enabled']),
            'min_version' => trim((string)($payload['min_version'] ?? '')),
            'recommended_version' => trim((string)($payload['recommended_version'] ?? '')),
            'maintenance' => !empty($payload['maintenance']),
            'maintenance_message' => trim((string)($payload['maintenance_message'] ?? '')),
            'features' => is_array($payload['features'] ?? null) ? $payload['features'] : [],
            'allowed_signing_fingerprints' => is_array($payload['allowed_signing_fingerprints'] ?? null)
                ? array_values($payload['allowed_signing_fingerprints'])
                : [],
            'config_version' => (int)($payload['config_version'] ?? 0),
            'ttl_seconds' => max(300, min(604800, (int)($payload['ttl_seconds'] ?? 86400))),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function validEnvelopeWindow(array $payload): bool
    {
        $issuedAt = strtotime(trim((string)($payload['issued_at'] ?? '')));
        $expiresAt = strtotime(trim((string)($payload['expires_at'] ?? '')));
        if ($issuedAt === false || $expiresAt === false || $expiresAt <= $issuedAt) return false;
        $now = time();
        if ($issuedAt > $now + self::CLOCK_SKEW_SECONDS) return false;
        if ($expiresAt < $now - self::CLOCK_SKEW_SECONDS) return false;
        if ($expiresAt - $issuedAt > 604800 + self::CLOCK_SKEW_SECONDS) return false;
        return true;
    }

    private function featureForCurrentEndpoint(): ?string
    {
        $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        return match ($script) {
            'api-hub.php' => 'hub',
            'api-go-delivery.php' => 'delivery',
            'api-go-expedition.php' => 'expedition',
            'api-go-inventory.php' => 'inventory_alerts',
            'api-go-events.php' => 'events',
            default => null,
        };
    }

    private function legacyFailOpenEnabled(): bool
    {
        return filter_var(env('EVENTMENU_CLIENT_POLICY_FAIL_OPEN', 'false'), FILTER_VALIDATE_BOOL);
    }

    private function versionCore(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\d+(?:\.\d+){0,3}/', $value, $matches)) return $matches[0];
        return '0.0.0';
    }

    private function normalizeFingerprint(string $value): string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $value) ?? '');
        return strlen($hex) === 64 ? $hex : '';
    }
}
