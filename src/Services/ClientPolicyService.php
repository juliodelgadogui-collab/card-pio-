<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

final class ClientPolicyService
{
    private const PLATFORMS = ['android', 'windows'];
    private const SIGNING_ID = 1;

    /** @return array<string,mixed> */
    public function get(string $platform): array
    {
        $platform = $this->platform($platform);
        $stmt = Database::connection()->prepare('SELECT * FROM platform_client_policies WHERE platform=? LIMIT 1');
        $stmt->execute([$platform]);
        $row = $stmt->fetch();
        if (!$row) {
            return [
                'platform' => $platform,
                'enabled' => true,
                'min_version' => $platform === 'android' ? '0.2.0' : '',
                'recommended_version' => '',
                'maintenance' => false,
                'maintenance_message' => '',
                'features' => [],
                'allowed_signing_fingerprints' => [],
                'config_version' => 0,
                'ttl_seconds' => 86400,
            ];
        }
        return $this->normalizePolicy($row);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(string $platform, array $input): array
    {
        if (!Auth::isSuperAdmin()) throw new RuntimeException('Política dos aplicativos restrita ao Super ADM.');
        if (!(new PlatformFailoverService())->isPrimaryNode()) throw new RuntimeException('A política só pode ser alterada no servidor principal.');
        $platform = $this->platform($platform);
        $current = $this->get($platform);
        $enabled = array_key_exists('enabled', $input) ? !empty($input['enabled']) : (bool)$current['enabled'];
        $maintenance = array_key_exists('maintenance', $input) ? !empty($input['maintenance']) : (bool)$current['maintenance'];
        $minVersion = mb_substr(trim((string)($input['min_version'] ?? $current['min_version'])), 0, 40);
        $recommended = mb_substr(trim((string)($input['recommended_version'] ?? $current['recommended_version'])), 0, 40);
        $message = mb_substr(trim((string)($input['maintenance_message'] ?? $current['maintenance_message'])), 0, 500);
        $ttl = max(300, min(604800, (int)($input['ttl_seconds'] ?? $current['ttl_seconds'])));
        $features = $this->normalizeFeatures($input['features'] ?? $current['features']);
        $fingerprints = $this->normalizeFingerprints($input['allowed_signing_fingerprints'] ?? $current['allowed_signing_fingerprints']);

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT platform FROM platform_client_policies WHERE platform=?');
        $exists->execute([$platform]);
        if ($exists->fetchColumn()) {
            $pdo->prepare('UPDATE platform_client_policies SET enabled=?,min_version=?,recommended_version=?,maintenance=?,maintenance_message=?,features_json=?,allowed_signing_fingerprints_json=?,ttl_seconds=?,config_version=config_version+1,updated_at=CURRENT_TIMESTAMP WHERE platform=?')
                ->execute([
                    $enabled ? 1 : 0,
                    $minVersion,
                    $recommended,
                    $maintenance ? 1 : 0,
                    $message !== '' ? $message : null,
                    json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($fingerprints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $ttl,
                    $platform,
                ]);
        } else {
            $pdo->prepare('INSERT INTO platform_client_policies (platform,enabled,min_version,recommended_version,maintenance,maintenance_message,features_json,allowed_signing_fingerprints_json,ttl_seconds) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $platform,
                    $enabled ? 1 : 0,
                    $minVersion,
                    $recommended,
                    $maintenance ? 1 : 0,
                    $message !== '' ? $message : null,
                    json_encode($features, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    json_encode($fingerprints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $ttl,
                ]);
        }

        Auth::audit('platform.client_policy_saved', 'client_policy', $platform, [
            'enabled' => $enabled,
            'maintenance' => $maintenance,
            'min_version' => $minVersion,
            'recommended_version' => $recommended,
            'features' => array_keys(array_filter($features)),
        ]);
        return $this->get($platform);
    }

    /** @return array{key_id:string,public_key_pem:string} */
    public function publicKeyBundle(): array
    {
        $row = $this->signingRow();
        if (!$row) {
            if (!(new PlatformFailoverService())->isPrimaryNode()) {
                $cached = $this->cachedKeyBundle();
                if ($cached) return $cached;
                throw new RuntimeException('Chave pública de política ainda não sincronizada.');
            }
            $row = $this->createSigningKey();
        }
        return ['key_id' => (string)$row['key_id'], 'public_key_pem' => (string)$row['public_key_pem']];
    }

    /** @return array<string,mixed> */
    public function signedEnvelope(string $platform): array
    {
        if (!(new PlatformFailoverService())->isPrimaryNode()) {
            $cached = $this->cachedEnvelope($platform);
            if ($cached) return $cached;
            throw new RuntimeException('Política ainda não sincronizada neste servidor.');
        }

        $policy = $this->get($platform);
        $release = (new ClientReleaseService())->get($platform);
        $key = $this->signingRow() ?: $this->createSigningKey();
        $privatePem = Crypto::decrypt((string)$key['private_key_encrypted']);
        $now = time();
        $routing = (new PlatformFailoverService())->routingConfig();
        $payload = [
            'schema' => 1,
            'platform' => (string)$policy['platform'],
            'enabled' => (bool)$policy['enabled'],
            'maintenance' => (bool)$policy['maintenance'],
            'maintenance_message' => (string)$policy['maintenance_message'],
            'min_version' => (string)$policy['min_version'],
            'recommended_version' => (string)$policy['recommended_version'],
            'features' => (array)$policy['features'],
            'allowed_signing_fingerprints' => (array)$policy['allowed_signing_fingerprints'],
            'config_version' => (int)$policy['config_version'],
            'release' => [
                'published' => (bool)$release['published'],
                'version' => (string)$release['version'],
                'download_url' => (string)$release['download_url'],
                'sha256' => (string)$release['sha256'],
                'release_notes' => (string)$release['release_notes'],
                'config_version' => (int)$release['config_version'],
            ],
            'cluster_id' => (string)($routing['cluster_id'] ?? ''),
            'primary_url' => (string)($routing['primary_url'] ?? ''),
            'contingency_url' => (string)($routing['contingency_url'] ?? ''),
            'contingency_mode' => (string)($routing['mode'] ?? 'read_only'),
            'issued_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + (int)$policy['ttl_seconds']),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = '';
        if (!openssl_sign($json, $signature, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Não foi possível assinar a política dos aplicativos.');
        }
        $envelope = [
            'ok' => true,
            'service' => 'eventmenu-client-policy',
            'algorithm' => 'RS256',
            'key_id' => (string)$key['key_id'],
            'payload_b64' => base64_encode($json),
            'signature_b64' => base64_encode($signature),
            'payload' => $payload,
        ];
        $this->cacheEnvelope($platform, $envelope);
        $this->cacheKeyBundle(['key_id' => (string)$key['key_id'], 'public_key_pem' => (string)$key['public_key_pem']]);
        return $envelope;
    }

    /** @param array<string,mixed> $envelope */
    public function cacheEnvelope(string $platform, array $envelope): void
    {
        $platform = $this->platform($platform);
        $dir = $this->cacheDir();
        $this->atomicJson($dir . '/client-policy-' . $platform . '.json', $envelope);
    }

    /** @return array<string,mixed>|null */
    public function cachedEnvelope(string $platform): ?array
    {
        $platform = $this->platform($platform);
        $path = $this->cacheDir() . '/client-policy-' . $platform . '.json';
        if (!is_file($path)) return null;
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array{key_id:string,public_key_pem:string} $bundle */
    public function cacheKeyBundle(array $bundle): void
    {
        $this->atomicJson($this->cacheDir() . '/client-policy-public-key.json', $bundle);
    }

    /** @return array{key_id:string,public_key_pem:string}|null */
    public function cachedKeyBundle(): ?array
    {
        $path = $this->cacheDir() . '/client-policy-public-key.json';
        if (!is_file($path)) return null;
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || empty($data['key_id']) || empty($data['public_key_pem'])) return null;
            return ['key_id' => (string)$data['key_id'], 'public_key_pem' => (string)$data['public_key_pem']];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed>|false */
    private function signingRow(): array|false
    {
        $stmt = Database::connection()->prepare('SELECT * FROM platform_policy_signing WHERE id=? LIMIT 1');
        $stmt->execute([self::SIGNING_ID]);
        return $stmt->fetch();
    }

    /** @return array<string,mixed> */
    private function createSigningKey(): array
    {
        if (!(new PlatformFailoverService())->isPrimaryNode()) throw new RuntimeException('A chave de assinatura só pode ser criada no servidor principal.');
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);
        if ($resource === false) throw new RuntimeException('OpenSSL não conseguiu criar a chave de política.');
        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) throw new RuntimeException('OpenSSL não conseguiu exportar a chave privada de política.');
        $details = openssl_pkey_get_details($resource);
        $publicPem = is_array($details) ? (string)($details['key'] ?? '') : '';
        if ($publicPem === '') throw new RuntimeException('OpenSSL não conseguiu obter a chave pública de política.');
        $keyId = substr(hash('sha256', $publicPem), 0, 32);
        $encrypted = Crypto::encrypt($privatePem);
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO platform_policy_signing (id,key_id,public_key_pem,private_key_encrypted) VALUES (?,?,?,?)')
            ->execute([self::SIGNING_ID, $keyId, $publicPem, $encrypted]);
        $this->cacheKeyBundle(['key_id' => $keyId, 'public_key_pem' => $publicPem]);
        return [
            'id' => self::SIGNING_ID,
            'key_id' => $keyId,
            'public_key_pem' => $publicPem,
            'private_key_encrypted' => $encrypted,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizePolicy(array $row): array
    {
        $features = json_decode((string)($row['features_json'] ?? '{}'), true);
        $fingerprints = json_decode((string)($row['allowed_signing_fingerprints_json'] ?? '[]'), true);
        return [
            'platform' => (string)$row['platform'],
            'enabled' => !empty($row['enabled']),
            'min_version' => trim((string)($row['min_version'] ?? '')),
            'recommended_version' => trim((string)($row['recommended_version'] ?? '')),
            'maintenance' => !empty($row['maintenance']),
            'maintenance_message' => trim((string)($row['maintenance_message'] ?? '')),
            'features' => is_array($features) ? $features : [],
            'allowed_signing_fingerprints' => is_array($fingerprints) ? array_values($fingerprints) : [],
            'config_version' => (int)($row['config_version'] ?? 0),
            'ttl_seconds' => max(300, min(604800, (int)($row['ttl_seconds'] ?? 86400))),
        ];
    }

    private function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PLATFORMS, true)) throw new RuntimeException('Plataforma inválida.');
        return $platform;
    }

    /** @return array<string,bool> */
    private function normalizeFeatures(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $key => $enabled) {
            $name = preg_replace('/[^a-z0-9_.-]/i', '', (string)$key) ?? '';
            if ($name !== '') $out[mb_substr($name, 0, 80)] = (bool)$enabled;
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> */
    private function normalizeFingerprints(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) $value = $decoded;
            else $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)$item) ?? '');
            if (strlen($hex) === 64) $out[] = implode(':', str_split($hex, 2));
        }
        return array_values(array_unique($out));
    }

    private function cacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cluster';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar o cache de contingência.');
        return $dir;
    }

    /** @param array<string,mixed> $data */
    private function atomicJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar o cache de política.');
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Não foi possível publicar o cache de política.'); }
    }
}
