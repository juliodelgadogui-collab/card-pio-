<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

final class ClusterSyncService
{
    /** @return array<string,mixed> */
    public function pushControlPlane(): array
    {
        $failover = new PlatformFailoverService();
        if (!$failover->isPrimaryNode()) throw new RuntimeException('Somente o servidor principal pode iniciar a sincronização.');
        $settings = $failover->get();
        if (empty($settings['enabled']) || trim((string)$settings['contingency_url']) === '') {
            throw new RuntimeException('Configure e ative o servidor de contingência antes de sincronizar.');
        }
        if ((string)$settings['last_health_status'] !== 'healthy' || empty($settings['verified_at'])) {
            throw new RuntimeException('Teste e verifique o servidor adicional antes de sincronizar.');
        }

        [$clusterId, $secret] = $this->credentials();
        $policy = new ClientPolicyService();
        $bundle = [
            'schema' => 1,
            'routing' => $failover->routingConfig(),
            'policy_public_key' => $policy->publicKeyBundle(),
            'policies' => [
                'android' => $policy->signedEnvelope('android'),
                'windows' => $policy->signedEnvelope('windows'),
            ],
        ];
        $nonce = bin2hex(random_bytes(16));
        $payload = [
            'cluster_id' => $clusterId,
            'timestamp' => time(),
            'nonce' => $nonce,
            'type' => 'control_plane',
            'bundle' => $bundle,
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $raw, $secret);
        $url = rtrim((string)$settings['contingency_url'], '/') . '/api-cluster-sync.php?action=push';
        $response = $this->postJson($url, $raw, [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'X-EventMenu-Cluster-Signature: ' . $signature,
        ]);
        $json = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($json) || empty($json['ok'])) {
            $message = is_array($json) ? trim((string)($json['error'] ?? '')) : '';
            throw new RuntimeException($message !== '' ? $message : 'O servidor adicional recusou a sincronização.');
        }
        if (!hash_equals($clusterId, (string)($json['cluster_id'] ?? ''))) throw new RuntimeException('Confirmação recebida de outro cluster.');
        if (!hash_equals($nonce, (string)($json['nonce'] ?? ''))) throw new RuntimeException('Confirmação da sincronização não corresponde ao envio atual.');
        $bundleHash = hash('sha256', json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (!hash_equals($bundleHash, (string)($json['bundle_hash'] ?? ''))) throw new RuntimeException('Hash confirmado pelo servidor adicional não corresponde ao pacote enviado.');
        $expectedAck = hash_hmac('sha256', $clusterId . "\n" . $nonce . "\n" . $bundleHash, $secret);
        if (!hash_equals($expectedAck, (string)($json['ack'] ?? ''))) throw new RuntimeException('Assinatura de confirmação do servidor adicional é inválida.');

        return [
            'ok' => true,
            'message' => 'Configuração e políticas dos aplicativos sincronizadas com o servidor adicional.',
            'bundle_hash' => $bundleHash,
            'synced_at' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function acceptControlPlane(string $raw, string $signature): array
    {
        $failover = new PlatformFailoverService();
        if ($failover->nodeRole() !== 'contingency') throw new RuntimeException('Este endpoint de recebimento só funciona no servidor de contingência.');
        [$clusterId, $secret] = $this->credentials();
        if ($raw === '' || strlen($raw) > 2_000_000) throw new RuntimeException('Pacote de sincronização inválido.');
        if ($signature === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            throw new RuntimeException('Assinatura da sincronização inválida.');
        }
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Pacote de sincronização inválido.');
        if (!hash_equals($clusterId, trim((string)($payload['cluster_id'] ?? '')))) throw new RuntimeException('Pacote pertence a outro cluster.');
        $timestamp = (int)($payload['timestamp'] ?? 0);
        if ($timestamp < 1 || abs(time() - $timestamp) > 300) throw new RuntimeException('Pacote de sincronização expirado.');
        $nonce = trim((string)($payload['nonce'] ?? ''));
        if (strlen($nonce) < 16) throw new RuntimeException('Nonce de sincronização inválido.');
        if ((string)($payload['type'] ?? '') !== 'control_plane') throw new RuntimeException('Tipo de sincronização não suportado.');
        $bundle = $payload['bundle'] ?? null;
        if (!is_array($bundle)) throw new RuntimeException('Conteúdo da sincronização ausente.');
        $publicKey = $bundle['policy_public_key'] ?? null;
        $policies = $bundle['policies'] ?? null;
        if (!is_array($publicKey) || !is_array($policies)) throw new RuntimeException('Políticas dos clientes ausentes.');
        if (empty($publicKey['key_id']) || empty($publicKey['public_key_pem'])) throw new RuntimeException('Chave pública de política ausente.');

        $policyService = new ClientPolicyService();
        $this->verifyEnvelope($policies['android'] ?? null, 'android', $publicKey);
        $this->verifyEnvelope($policies['windows'] ?? null, 'windows', $publicKey);
        $policyService->cacheKeyBundle([
            'key_id' => (string)$publicKey['key_id'],
            'public_key_pem' => (string)$publicKey['public_key_pem'],
        ]);
        $policyService->cacheEnvelope('android', (array)$policies['android']);
        $policyService->cacheEnvelope('windows', (array)$policies['windows']);
        $this->writeBundleSnapshot($bundle);

        $bundleHash = hash('sha256', json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return [
            'ok' => true,
            'service' => 'eventmenu-cluster-sync',
            'cluster_id' => $clusterId,
            'nonce' => $nonce,
            'bundle_hash' => $bundleHash,
            'ack' => hash_hmac('sha256', $clusterId . "\n" . $nonce . "\n" . $bundleHash, $secret),
            'stored_at' => gmdate('c'),
        ];
    }

    /** @param mixed $envelope @param array<string,mixed> $publicKey */
    private function verifyEnvelope(mixed $envelope, string $platform, array $publicKey): void
    {
        if (!is_array($envelope)) throw new RuntimeException('Política ' . $platform . ' ausente.');
        if ((string)($envelope['algorithm'] ?? '') !== 'RS256') throw new RuntimeException('Algoritmo de política inválido.');
        if (!hash_equals((string)$publicKey['key_id'], (string)($envelope['key_id'] ?? ''))) throw new RuntimeException('Política assinada por chave inesperada.');
        $payloadRaw = base64_decode((string)($envelope['payload_b64'] ?? ''), true);
        $signature = base64_decode((string)($envelope['signature_b64'] ?? ''), true);
        if ($payloadRaw === false || $signature === false) throw new RuntimeException('Política assinada corrompida.');
        $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || (string)($payload['platform'] ?? '') !== $platform) throw new RuntimeException('Política de plataforma inválida.');
        $ok = openssl_verify($payloadRaw, $signature, (string)$publicKey['public_key_pem'], OPENSSL_ALGO_SHA256);
        if ($ok !== 1) throw new RuntimeException('Assinatura digital da política ' . $platform . ' é inválida.');
    }

    /** @return array{0:string,1:string} */
    private function credentials(): array
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
        if ($clusterId === '' || strlen($secret) < 32) throw new RuntimeException('Cluster/segredo de contingência ainda não configurados.');
        return [$clusterId, $secret];
    }

    /** @param array<string,mixed> $bundle */
    private function writeBundleSnapshot(array $bundle): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/cluster';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar storage/cluster.');
        $path = $dir . '/control-plane.json';
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('Não foi possível salvar o pacote de controle.');
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Não foi possível publicar o pacote de controle.'); }
    }

    /** @param list<string> $headers @return array{status:int,body:string} */
    private function postJson(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL precisa estar ativa no servidor principal.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a sincronização HTTP.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) throw new RuntimeException($error !== '' ? $error : 'Falha ao conectar ao servidor adicional.');
        return ['status' => $status, 'body' => (string)$response];
    }
}
