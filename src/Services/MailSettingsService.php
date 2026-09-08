<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class MailSettingsService
{
    public function scopeKey(?int $tenantId): string
    {
        return $tenantId ? 'tenant:' . $tenantId : 'platform';
    }

    /** @return array<string,mixed> */
    public function get(?int $tenantId, bool $fallbackToPlatform = false): array
    {
        $row = $this->find($this->scopeKey($tenantId));
        if ($row && (int)$row['enabled'] === 1) return $this->publicRow($row);
        if ($fallbackToPlatform && $tenantId !== null) {
            $platform = $this->find('platform');
            if ($platform && (int)$platform['enabled'] === 1) return $this->publicRow($platform);
        }
        return $row ? $this->publicRow($row) : $this->defaults($tenantId);
    }

    /** @return array<string,mixed>|null */
    public function effective(?int $tenantId): ?array
    {
        $row = $this->find($this->scopeKey($tenantId));
        if (!$row || (int)$row['enabled'] !== 1) {
            if ($tenantId !== null) $row = $this->find('platform');
        }
        if (!$row || (int)$row['enabled'] !== 1) return null;
        $password = '';
        if (!empty($row['password_encrypted'])) $password = Crypto::decrypt((string)$row['password_encrypted']);
        return $this->publicRow($row) + ['password' => $password];
    }

    /** @param array<string,mixed> $input */
    public function save(?int $tenantId, array $input): array
    {
        $scope = $this->scopeKey($tenantId);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $host = mb_substr(trim((string)($input['host'] ?? '')), 0, 190);
        $port = max(1, min(65535, (int)($input['port'] ?? 587)));
        $encryption = strtolower(trim((string)($input['encryption'] ?? 'tls')));
        if (!in_array($encryption, ['none','tls','ssl'], true)) $encryption = 'tls';
        $username = mb_substr(trim((string)($input['username'] ?? '')), 0, 190);
        $fromEmail = mb_strtolower(trim((string)($input['from_email'] ?? '')));
        $fromName = mb_substr(trim((string)($input['from_name'] ?? 'EventMenu')), 0, 160);
        $replyTo = mb_strtolower(trim((string)($input['reply_to_email'] ?? '')));
        $password = (string)($input['password'] ?? '');

        if ($enabled) {
            if ($host === '') throw new RuntimeException('Informe o servidor SMTP.');
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail remetente válido.');
            if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail de resposta válido.');
        }

        $pdo = Database::connection();
        $existing = $this->find($scope);
        $passwordEncrypted = $existing['password_encrypted'] ?? null;
        if ($password !== '') $passwordEncrypted = Crypto::encrypt($password);
        if ($username !== '' && !$passwordEncrypted && $enabled) throw new RuntimeException('Informe a senha SMTP.');

        if ($existing) {
            $sql = 'UPDATE mail_settings SET tenant_id=?,enabled=?,host=?,port=?,encryption=?,username=?,password_encrypted=?,from_email=?,from_name=?,reply_to_email=?,updated_at=CURRENT_TIMESTAMP WHERE scope_key=?';
            $pdo->prepare($sql)->execute([$tenantId,$enabled,$host,$port,$encryption,$username?:null,$passwordEncrypted,$fromEmail?:null,$fromName?:null,$replyTo?:null,$scope]);
        } else {
            $sql = 'INSERT INTO mail_settings (scope_key,tenant_id,enabled,host,port,encryption,username,password_encrypted,from_email,from_name,reply_to_email) VALUES (?,?,?,?,?,?,?,?,?,?,?)';
            $pdo->prepare($sql)->execute([$scope,$tenantId,$enabled,$host,$port,$encryption,$username?:null,$passwordEncrypted,$fromEmail?:null,$fromName?:null,$replyTo?:null]);
        }
        return $this->get($tenantId);
    }

    /** @return array<string,mixed>|null */
    private function find(string $scope): ?array
    {
        $s = Database::connection()->prepare('SELECT * FROM mail_settings WHERE scope_key=? LIMIT 1');
        $s->execute([$scope]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicRow(array $row): array
    {
        return [
            'scope_key' => (string)$row['scope_key'],
            'tenant_id' => $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,
            'enabled' => (int)$row['enabled'] === 1,
            'host' => (string)($row['host'] ?? ''),
            'port' => (int)($row['port'] ?? 587),
            'encryption' => (string)($row['encryption'] ?? 'tls'),
            'username' => (string)($row['username'] ?? ''),
            'from_email' => (string)($row['from_email'] ?? ''),
            'from_name' => (string)($row['from_name'] ?? ''),
            'reply_to_email' => (string)($row['reply_to_email'] ?? ''),
            'has_password' => !empty($row['password_encrypted']),
        ];
    }

    /** @return array<string,mixed> */
    private function defaults(?int $tenantId): array
    {
        return [
            'scope_key' => $this->scopeKey($tenantId),'tenant_id'=>$tenantId,'enabled'=>false,
            'host'=>'','port'=>587,'encryption'=>'tls','username'=>'','from_email'=>'','from_name'=>'EventMenu','reply_to_email'=>'','has_password'=>false,
        ];
    }
}
