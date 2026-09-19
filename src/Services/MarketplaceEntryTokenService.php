<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class MarketplaceEntryTokenService
{
    private const VERSION = 1;
    private const TTL_SECONDS = 1200;

    /**
     * $legacyCampaignCode is intentionally ignored. Older internal callers may
     * still pass a fourth argument, but campaign attribution is server-only.
     */
    public function issue(PDO $pdo, int $tenantId, int $unitId, ?string $legacyCampaignCode = null): array
    {
        (new MarketplaceCommissionService())->assertTenantCanReceive($pdo, $tenantId, $unitId);
        $this->purgeExpired($pdo);
        $nonce = bin2hex(random_bytes(24));
        $now = time();
        $expires = $now + self::TTL_SECONDS;
        $campaignCode = (new MarketplaceCampaignService())->codeForCheckout($pdo, $tenantId, $unitId, gmdate('Y-m-d H:i:s', $now));
        $payload = [
            'v' => self::VERSION,
            'tenant_id' => $tenantId,
            'unit_id' => $unitId,
            'campaign' => $campaignCode,
            'iat' => $now,
            'exp' => $expires,
            'nonce' => $nonce,
        ];
        $encoded = $this->b64url(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = $this->b64url(hash_hmac('sha256', $encoded, $this->key(), true));
        $token = $encoded . '.' . $signature;
        $pdo->prepare('INSERT INTO marketplace_entry_tokens (tenant_id,unit_id,nonce_hash,campaign_code,expires_at) VALUES (?,?,?,?,?)')
            ->execute([$tenantId, $unitId, hash('sha256', $nonce), $campaignCode, gmdate('Y-m-d H:i:s', $expires)]);
        return ['token' => $token, 'expires_at' => gmdate('c', $expires), 'expires_in' => self::TTL_SECONDS];
    }

    public function consume(PDO $pdo, string $token): array
    {
        $claims = $this->decodeAndVerify($token);
        $nonceHash = hash('sha256', (string)$claims['nonce']);
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM marketplace_entry_tokens WHERE nonce_hash=? AND tenant_id=? AND unit_id=? FOR UPDATE'));
        $s->execute([$nonceHash, (int)$claims['tenant_id'], (int)$claims['unit_id']]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException('Esta sessão do marketplace não é mais válida. Atualize a loja e tente novamente.');
        if (!empty($row['used_at'])) throw new RuntimeException('Esta sessão de compra já foi utilizada. Atualize a loja para continuar.');
        if (strtotime((string)$row['expires_at']) < time()) throw new RuntimeException('Sua sessão de compra expirou. Atualize a loja e tente novamente.');
        if ((string)($row['campaign_code'] ?? '') !== (string)($claims['campaign'] ?? '')) throw new RuntimeException('Sessão do marketplace inválida.');
        (new MarketplaceCommissionService())->assertTenantCanReceive($pdo, (int)$claims['tenant_id'], (int)$claims['unit_id']);
        $update = $pdo->prepare('UPDATE marketplace_entry_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL');
        $update->execute([(int)$row['id']]);
        if ($update->rowCount() !== 1) throw new RuntimeException('Esta sessão de compra já foi utilizada. Atualize a loja para continuar.');
        return $claims;
    }

    public function decodeAndVerify(string $token): array
    {
        $token = trim($token);
        $parts = explode('.', $token);
        if (count($parts) !== 2) throw new RuntimeException('Sessão do marketplace inválida.');
        [$encoded, $provided] = $parts;
        $expected = $this->b64url(hash_hmac('sha256', $encoded, $this->key(), true));
        if (!hash_equals($expected, $provided)) throw new RuntimeException('Sessão do marketplace inválida.');
        $json = $this->b64urlDecode($encoded);
        try { $claims = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new RuntimeException('Sessão do marketplace inválida.'); }
        if (!is_array($claims) || (int)($claims['v'] ?? 0) !== self::VERSION) throw new RuntimeException('Sessão do marketplace inválida.');
        $tenantId = (int)($claims['tenant_id'] ?? 0);
        $unitId = (int)($claims['unit_id'] ?? 0);
        $exp = (int)($claims['exp'] ?? 0);
        $iat = (int)($claims['iat'] ?? 0);
        $nonce = (string)($claims['nonce'] ?? '');
        if ($tenantId < 1 || $unitId < 1 || $exp < time() || $iat < 1 || $iat > time() + 60 || !preg_match('/^[a-f0-9]{48}$/', $nonce)) throw new RuntimeException('Sua sessão de compra expirou. Atualize a loja e tente novamente.');
        return $claims;
    }

    private function purgeExpired(PDO $pdo): void
    {
        // Opportunistic cleanup; active tokens are never removed.
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
        $delete = $pdo->prepare('DELETE FROM marketplace_entry_tokens WHERE (used_at IS NOT NULL OR expires_at<CURRENT_TIMESTAMP) AND created_at<?');
        $delete->execute([$cutoff]);
    }

    private function key(): string
    {
        $key = trim((string)env('APP_KEY', ''));
        if (strlen($key) < 24) throw new RuntimeException('O servidor ainda não está preparado para iniciar compras no EventMenu Delivery.');
        return hash('sha256', 'eventmenu-marketplace-entry|' . $key, true);
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string
    {
        $pad = strlen($value) % 4;
        if ($pad) $value .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new RuntimeException('Sessão do marketplace inválida.');
        return $decoded;
    }
}
