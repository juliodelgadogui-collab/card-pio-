<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use RuntimeException;
use Throwable;

final class ClusterNodeConfigService
{
    /** @return array<string,mixed> */
    public function get(): array
    {
        $path = $this->configPath();
        if (!is_file($path)) return [];
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function role(): string
    {
        $env = strtolower(trim((string)env('EVENTMENU_NODE_ROLE', '')));
        if (in_array($env, ['primary', 'contingency'], true)) return $env;
        $role = strtolower(trim((string)($this->get()['node_role'] ?? '')));
        return in_array($role, ['primary', 'contingency'], true) ? $role : '';
    }

    public function clusterId(): string
    {
        $env = trim((string)env('EVENTMENU_CLUSTER_ID', ''));
        if ($env !== '') return $env;
        return trim((string)($this->get()['cluster_id'] ?? ''));
    }

    public function clusterSecret(): string
    {
        $env = trim((string)env('EVENTMENU_CLUSTER_SECRET', ''));
        if ($env !== '') return $env;
        $encrypted = trim((string)($this->get()['cluster_secret_encrypted'] ?? ''));
        if ($encrypted === '') return '';
        try { return Crypto::decrypt($encrypted); } catch (Throwable) { return ''; }
    }

    public function mode(): string
    {
        $env = strtolower(trim((string)env('EVENTMENU_FAILOVER_MODE', '')));
        if (in_array($env, ['shared_db', 'read_only'], true)) return $env;
        $mode = strtolower(trim((string)($this->get()['mode'] ?? 'read_only')));
        return in_array($mode, ['shared_db', 'read_only'], true) ? $mode : 'read_only';
    }

    public function primaryUrl(): string
    {
        return rtrim(trim((string)($this->get()['primary_url'] ?? '')), '/');
    }

    public function isBootstrapped(): bool
    {
        return $this->clusterId() !== '' && strlen($this->clusterSecret()) >= 32;
    }

    /**
     * Primeira configuração do nó de contingência. O segredo é recebido apenas
     * por HTTPS e armazenado cifrado com a APP_KEY local.
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function acceptBootstrap(array $payload, string $raw, string $signature): array
    {
        if ($this->role() !== 'contingency') throw new RuntimeException('Este servidor não está em modo de contingência.');
        if ($this->isBootstrapped() || is_file($this->usedPath())) throw new RuntimeException('Este servidor adicional já foi inicializado.');

        $bootstrapToken = trim((string)env('EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN', ''));
        if (strlen($bootstrapToken) < 24) throw new RuntimeException('Código temporário de instalação não configurado no servidor adicional.');
        $expected = hash_hmac('sha256', $raw, $bootstrapToken);
        if ($signature === '' || !hash_equals($expected, $signature)) throw new RuntimeException('Código/assinatura de inicialização inválido.');

        $timestamp = (int)($payload['timestamp'] ?? 0);
        if ($timestamp < 1 || abs(time() - $timestamp) > 300) throw new RuntimeException('Solicitação de inicialização expirada.');
        $nonce = trim((string)($payload['nonce'] ?? ''));
        $clusterId = trim((string)($payload['cluster_id'] ?? ''));
        $clusterSecret = trim((string)($payload['cluster_secret'] ?? ''));
        $primaryUrl = rtrim(trim((string)($payload['primary_url'] ?? '')), '/');
        $mode = strtolower(trim((string)($payload['mode'] ?? 'read_only')));
        if (strlen($nonce) < 16 || strlen($clusterId) < 16 || strlen($clusterSecret) < 32) throw new RuntimeException('Dados do cluster inválidos.');
        if (!filter_var($primaryUrl, FILTER_VALIDATE_URL) || strtolower((string)parse_url($primaryUrl, PHP_URL_SCHEME)) !== 'https') throw new RuntimeException('URL principal inválida.');
        if (!in_array($mode, ['shared_db', 'read_only'], true)) throw new RuntimeException('Modo de contingência inválido.');

        $data = [
            'schema' => 1,
            'node_role' => 'contingency',
            'cluster_id' => $clusterId,
            'cluster_secret_encrypted' => Crypto::encrypt($clusterSecret),
            'primary_url' => $primaryUrl,
            'mode' => $mode,
            'configured_at' => gmdate('c'),
        ];
        $this->atomicJson($this->configPath(), $data);
        $this->atomicJson($this->usedPath(), ['used_at' => gmdate('c'), 'cluster_id' => $clusterId]);

        $ack = hash_hmac('sha256', $clusterId . "\n" . $nonce . "\nconfigured", $clusterSecret);
        return [
            'ok' => true,
            'service' => 'eventmenu-cluster-bootstrap',
            'cluster_id' => $clusterId,
            'nonce' => $nonce,
            'node_role' => 'contingency',
            'ack' => $ack,
            'configured_at' => gmdate('c'),
        ];
    }

    private function storageDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/cluster';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível preparar storage/cluster.');
        return $dir;
    }

    private function configPath(): string { return $this->storageDir() . '/node-config.json'; }
    private function usedPath(): string { return $this->storageDir() . '/bootstrap-used.json'; }

    /** @param array<string,mixed> $data */
    private function atomicJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar a configuração local do cluster.');
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Não foi possível publicar a configuração local do cluster.'); }
    }
}
