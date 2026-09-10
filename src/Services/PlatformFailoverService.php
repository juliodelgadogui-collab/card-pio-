<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class PlatformFailoverService
{
    private const SETTINGS_ID = 1;
    private const MODES = ['shared_db', 'read_only'];

    /** @return array<string,mixed> */
    public function get(): array
    {
        $row = $this->row();
        if ($row) return $this->normalizeRow($row);

        return [
            'id' => self::SETTINGS_ID,
            'enabled' => false,
            'primary_url' => $this->normalizeUrl(app_absolute_url(''), true),
            'contingency_url' => '',
            'mode' => 'read_only',
            'cluster_id' => '',
            'config_version' => 0,
            'last_health_status' => 'unknown',
            'last_health_message' => '',
            'last_health_db_driver' => '',
            'last_health_writable' => false,
            'last_health_checked_at' => '',
            'verified_at' => '',
            'has_secret' => false,
        ];
    }

    /**
     * Configuração de infraestrutura. Somente o Super ADM pode alterá-la.
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function save(array $input): array
    {
        if (!Auth::isSuperAdmin()) throw new RuntimeException('A configuração de contingência é restrita ao Super ADM.');

        $existing = $this->row();
        $primary = $this->normalizeUrl((string)($input['primary_url'] ?? app_absolute_url('')), false);
        $contingencyRaw = trim((string)($input['contingency_url'] ?? ''));
        $contingency = $contingencyRaw === '' ? '' : $this->normalizeUrl($contingencyRaw, false);
        if ($contingency !== '' && hash_equals(mb_strtolower($primary), mb_strtolower($contingency))) {
            throw new RuntimeException('O servidor de contingência precisa usar uma URL diferente do servidor principal.');
        }

        $mode = (string)($input['mode'] ?? ($existing['mode'] ?? 'read_only'));
        if (!in_array($mode, self::MODES, true)) throw new RuntimeException('Modo de contingência inválido.');
        $enabled = !empty($input['enabled']) && $contingency !== '';

        $clusterId = trim((string)($existing['cluster_id'] ?? ''));
        if ($clusterId === '') $clusterId = bin2hex(random_bytes(16));

        $newSecret = trim((string)($input['new_secret'] ?? ''));
        $secretChanged = $newSecret !== '';
        if ($secretChanged && strlen($newSecret) < 32) {
            throw new RuntimeException('O segredo do cluster precisa ter pelo menos 32 caracteres.');
        }

        $secretPlain = '';
        if ($secretChanged) {
            $secretPlain = $newSecret;
        } elseif ($existing && !empty($existing['cluster_secret_encrypted'])) {
            $secretPlain = Crypto::decrypt((string)$existing['cluster_secret_encrypted']);
        } else {
            $secretPlain = bin2hex(random_bytes(32));
            $secretChanged = true;
        }
        $secretEncrypted = Crypto::encrypt($secretPlain);

        $connectionChanged = !$existing
            || !hash_equals(mb_strtolower(trim((string)($existing['primary_url'] ?? ''))), mb_strtolower($primary))
            || !hash_equals(mb_strtolower(trim((string)($existing['contingency_url'] ?? ''))), mb_strtolower($contingency))
            || (string)($existing['mode'] ?? '') !== $mode
            || $secretChanged;

        $pdo = Database::connection();
        if ($existing) {
            $sql = 'UPDATE platform_failover_settings SET enabled=?,primary_url=?,contingency_url=?,mode=?,cluster_id=?,cluster_secret_encrypted=?,config_version=config_version+1,updated_at=CURRENT_TIMESTAMP';
            $args = [$enabled ? 1 : 0, $primary, $contingency !== '' ? $contingency : null, $mode, $clusterId, $secretEncrypted];
            if ($connectionChanged) {
                $sql .= ',last_health_status="unknown",last_health_message=NULL,last_health_db_driver=NULL,last_health_writable=0,last_health_checked_at=NULL,verified_at=NULL';
            }
            $sql .= ' WHERE id=?';
            $args[] = self::SETTINGS_ID;
            $pdo->prepare($sql)->execute($args);
        } else {
            $pdo->prepare('INSERT INTO platform_failover_settings (id,enabled,primary_url,contingency_url,mode,cluster_id,cluster_secret_encrypted) VALUES (?,?,?,?,?,?,?)')
                ->execute([self::SETTINGS_ID, $enabled ? 1 : 0, $primary, $contingency !== '' ? $contingency : null, $mode, $clusterId, $secretEncrypted]);
        }

        Auth::audit('platform.failover_saved', 'platform_failover', (string)self::SETTINGS_ID, [
            'enabled' => $enabled,
            'primary_url' => $primary,
            'contingency_url' => $contingency,
            'mode' => $mode,
            'secret_rotated' => $secretChanged,
        ]);

        $saved = $this->get();
        if ($secretChanged) $saved['_secret_once'] = $secretPlain;
        return $saved;
    }

    /** @return array<string,mixed> */
    public function routingConfig(): array
    {
        $settings = $this->get();
        $verified = (string)$settings['verified_at'] !== '';
        $enabled = !empty($settings['enabled']) && (string)$settings['contingency_url'] !== '' && $verified;
        $mode = (string)$settings['mode'];
        $writable = $enabled && $mode === 'shared_db' && !empty($settings['last_health_writable']);

        return [
            'primary_url' => (string)$settings['primary_url'],
            'contingency_url' => $enabled ? (string)$settings['contingency_url'] : '',
            'enabled' => $enabled,
            'mode' => $mode,
            'contingency_writable' => $writable,
            'cluster_id' => (string)$settings['cluster_id'],
            'config_version' => (int)$settings['config_version'],
            'verified_at' => (string)$settings['verified_at'],
            'last_health_status' => (string)$settings['last_health_status'],
        ];
    }

    /**
     * O principal chama este método pelo cron e também no botão "Testar".
     * @return array<string,mixed>
     */
    public function probeSecondary(): array
    {
        $settings = $this->get();
        if (!$this->isPrimaryNode()) return ['status' => 'skipped', 'message' => 'Este nó não é o principal.'];
        if (empty($settings['enabled']) || trim((string)$settings['contingency_url']) === '') {
            return ['status' => 'disabled', 'message' => 'Contingência desativada.'];
        }

        $row = $this->row();
        if (!$row) return ['status' => 'misconfigured', 'message' => 'Configuração de contingência ausente.'];
        $secret = Crypto::decrypt((string)$row['cluster_secret_encrypted']);
        if (strlen($secret) < 32) return $this->recordHealth('misconfigured', 'Segredo do cluster inválido.', '', false, false);

        $clusterId = (string)$row['cluster_id'];
        $nonce = bin2hex(random_bytes(16));
        $timestamp = time();
        $primaryUrl = (string)$settings['primary_url'];
        $payload = [
            'cluster_id' => $clusterId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'primary_url' => $primaryUrl,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $clusterId . "\n" . $timestamp . "\n" . $nonce . "\n" . $primaryUrl, $secret);
        $url = rtrim((string)$settings['contingency_url'], '/') . '/api-cluster.php?action=verify';

        try {
            $response = $this->postJson($url, $body, [
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
                'X-EventMenu-Cluster-Signature: ' . $signature,
            ]);
            $json = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($json) || empty($json['ok'])) {
                $message = is_array($json) ? trim((string)($json['error'] ?? '')) : '';
                throw new RuntimeException($message !== '' ? $message : 'Resposta inválida do servidor de contingência.');
            }
            if (!hash_equals($clusterId, (string)($json['cluster_id'] ?? ''))) throw new RuntimeException('O servidor adicional pertence a outro cluster.');
            if (!hash_equals($nonce, (string)($json['nonce'] ?? ''))) throw new RuntimeException('A confirmação do servidor adicional não corresponde ao teste atual.');
            if ((string)($json['node_role'] ?? '') !== 'contingency') throw new RuntimeException('A URL informada não está identificada como servidor de contingência.');

            $dbDriver = strtolower(trim((string)($json['db_driver'] ?? '')));
            $writable = !empty($json['writable']);
            $proofExpected = hash_hmac(
                'sha256',
                $clusterId . "\n" . $nonce . "\n" . (string)($json['node_role'] ?? '') . "\n" . ($writable ? '1' : '0') . "\n" . $dbDriver,
                $secret,
            );
            if (!hash_equals($proofExpected, (string)($json['proof'] ?? ''))) throw new RuntimeException('A prova criptográfica do servidor adicional é inválida.');

            if ((string)$settings['mode'] === 'shared_db' && (!$writable || $dbDriver !== 'mysql')) {
                return $this->recordHealth(
                    'misconfigured',
                    'Para contingência com escrita, o servidor adicional precisa usar MySQL/MariaDB compartilhado e informar capacidade de escrita.',
                    $dbDriver,
                    $writable,
                    false,
                );
            }

            $message = (string)$settings['mode'] === 'shared_db'
                ? 'Servidor adicional verificado e pronto para leitura e escrita.'
                : 'Servidor adicional verificado em modo somente leitura.';
            return $this->recordHealth('healthy', $message, $dbDriver, $writable, true);
        } catch (Throwable $e) {
            return $this->recordHealth('offline', mb_substr($e->getMessage(), 0, 480), '', false, false);
        }
    }

    /**
     * Executado no nó adicional para provar ao principal que ambos conhecem o mesmo segredo.
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function verifyIncoming(array $body, string $signature): array
    {
        $clusterId = trim((string)($body['cluster_id'] ?? ''));
        $nonce = trim((string)($body['nonce'] ?? ''));
        $primaryUrl = trim((string)($body['primary_url'] ?? ''));
        $timestamp = (int)($body['timestamp'] ?? 0);
        if ($clusterId === '' || strlen($nonce) < 16 || $timestamp < 1 || abs(time() - $timestamp) > 300) {
            throw new RuntimeException('Desafio do cluster inválido ou expirado.');
        }

        $expectedCluster = $this->localClusterId();
        if ($expectedCluster === '' || !hash_equals($expectedCluster, $clusterId)) throw new RuntimeException('Cluster não reconhecido neste servidor.');
        $secret = $this->localClusterSecret();
        if (strlen($secret) < 32) throw new RuntimeException('Segredo do cluster não configurado neste servidor.');

        $expectedSignature = hash_hmac('sha256', $clusterId . "\n" . $timestamp . "\n" . $nonce . "\n" . $primaryUrl, $secret);
        if ($signature === '' || !hash_equals($expectedSignature, $signature)) throw new RuntimeException('Assinatura do cluster inválida.');

        $role = $this->nodeRole();
        $dbDriver = strtolower(trim((string)env('DB_CONNECTION', '')));
        $settings = $this->get();
        $writable = $role === 'contingency'
            && $dbDriver === 'mysql'
            && (string)$settings['mode'] === 'shared_db'
            && !empty($settings['enabled']);
        if (strtolower(trim((string)env('EVENTMENU_NODE_WRITABLE', 'auto'))) === 'false') $writable = false;

        $proof = hash_hmac(
            'sha256',
            $clusterId . "\n" . $nonce . "\n" . $role . "\n" . ($writable ? '1' : '0') . "\n" . $dbDriver,
            $secret,
        );

        return [
            'ok' => true,
            'service' => 'eventmenu-cluster',
            'cluster_id' => $clusterId,
            'nonce' => $nonce,
            'node_role' => $role,
            'db_driver' => $dbDriver,
            'writable' => $writable,
            'proof' => $proof,
            'time' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function publicHealth(): array
    {
        $settings = $this->get();
        return [
            'ok' => true,
            'service' => 'eventmenu-cluster',
            'cluster_id' => $this->localClusterId(),
            'node_role' => $this->nodeRole(),
            'mode' => (string)$settings['mode'],
            'db_driver' => strtolower(trim((string)env('DB_CONNECTION', ''))),
            'time' => gmdate('c'),
        ];
    }

    public function isPrimaryNode(): bool
    {
        return $this->nodeRole() === 'primary';
    }

    public function nodeRole(): string
    {
        $explicit = strtolower(trim((string)env('EVENTMENU_NODE_ROLE', '')));
        if (in_array($explicit, ['primary', 'contingency'], true)) return $explicit;

        try {
            $settings = $this->get();
            $current = $this->normalizeUrl(app_absolute_url(''), true);
            $secondary = trim((string)$settings['contingency_url']);
            if ($secondary !== '' && hash_equals(mb_strtolower($secondary), mb_strtolower($current))) return 'contingency';
            $primary = trim((string)$settings['primary_url']);
            if ($primary !== '' && hash_equals(mb_strtolower($primary), mb_strtolower($current))) return 'primary';
        } catch (Throwable) {
        }
        return 'primary';
    }

    private function localClusterId(): string
    {
        try {
            $row = $this->row();
            if ($row && trim((string)$row['cluster_id']) !== '') return trim((string)$row['cluster_id']);
        } catch (Throwable) {
        }
        return trim((string)env('EVENTMENU_CLUSTER_ID', ''));
    }

    private function localClusterSecret(): string
    {
        try {
            $row = $this->row();
            if ($row && !empty($row['cluster_secret_encrypted'])) return Crypto::decrypt((string)$row['cluster_secret_encrypted']);
        } catch (Throwable) {
        }
        return trim((string)env('EVENTMENU_CLUSTER_SECRET', ''));
    }

    /** @return array<string,mixed>|null */
    private function row(): ?array
    {
        try {
            $s = Database::connection()->prepare('SELECT * FROM platform_failover_settings WHERE id=? LIMIT 1');
            $s->execute([self::SETTINGS_ID]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? self::SETTINGS_ID),
            'enabled' => (int)($row['enabled'] ?? 0) === 1,
            'primary_url' => rtrim((string)($row['primary_url'] ?? ''), '/'),
            'contingency_url' => rtrim((string)($row['contingency_url'] ?? ''), '/'),
            'mode' => (string)($row['mode'] ?? 'read_only'),
            'cluster_id' => (string)($row['cluster_id'] ?? ''),
            'config_version' => (int)($row['config_version'] ?? 0),
            'last_health_status' => (string)($row['last_health_status'] ?? 'unknown'),
            'last_health_message' => (string)($row['last_health_message'] ?? ''),
            'last_health_db_driver' => (string)($row['last_health_db_driver'] ?? ''),
            'last_health_writable' => (int)($row['last_health_writable'] ?? 0) === 1,
            'last_health_checked_at' => (string)($row['last_health_checked_at'] ?? ''),
            'verified_at' => (string)($row['verified_at'] ?? ''),
            'has_secret' => !empty($row['cluster_secret_encrypted']),
        ];
    }

    /** @return array<string,mixed> */
    private function recordHealth(string $status, string $message, string $dbDriver, bool $writable, bool $verified): array
    {
        $pdo = Database::connection();
        $sql = 'UPDATE platform_failover_settings SET last_health_status=?,last_health_message=?,last_health_db_driver=?,last_health_writable=?,last_health_checked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
        if ($verified) $sql .= ',verified_at=COALESCE(verified_at,CURRENT_TIMESTAMP)';
        $sql .= ' WHERE id=?';
        $pdo->prepare($sql)->execute([$status, mb_substr($message, 0, 480), $dbDriver !== '' ? $dbDriver : null, $writable ? 1 : 0, self::SETTINGS_ID]);
        return [
            'status' => $status,
            'message' => $message,
            'db_driver' => $dbDriver,
            'writable' => $writable,
            'verified' => $verified,
        ];
    }

    private function normalizeUrl(string $url, bool $allowEmpty): string
    {
        $url = rtrim(trim($url), '/');
        if ($url === '') {
            if ($allowEmpty) return '';
            throw new RuntimeException('Informe a URL do servidor.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Informe uma URL válida, incluindo https://.');
        $parts = parse_url($url);
        if (!is_array($parts)) throw new RuntimeException('URL do servidor inválida.');
        if (!empty($parts['query']) || !empty($parts['fragment'])) throw new RuntimeException('A URL do servidor não pode conter parâmetros ou fragmentos.');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !$local) throw new RuntimeException('HTTPS é obrigatório entre os servidores.');
        return $url;
    }

    /** @param list<string> $headers @return array{status:int,body:string} */
    private function postJson(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL precisa estar ativa no servidor principal.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar o teste HTTP.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 9,
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
