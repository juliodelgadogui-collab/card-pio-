<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use PDO;
use RuntimeException;

final class PasswordResetService
{
    /**
     * Solicita redefinição sem revelar se o e-mail existe.
     * Retorna true apenas para uso interno quando o envio foi realmente feito.
     */
    public function request(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $limiter = new ApiRateLimitService();
        $limiter->assertAllowed('password.reset', $limiter->requestSubject($email), 5, 900, 'Muitas solicitações de recuperação. Aguarde alguns minutos.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        $pdo = Database::connection();
        $s = $pdo->prepare('SELECT u.id,u.tenant_id,u.name,u.email,u.status,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? LIMIT 1');
        $s->execute([$email]);
        $user = $s->fetch(PDO::FETCH_ASSOC);
        if (!$user || (string)$user['status'] !== 'active') return false;
        if ($user['tenant_id'] !== null && (string)($user['tenant_status'] ?? '') !== 'active') return false;

        $tenantId = $user['tenant_id'] !== null ? (int)$user['tenant_id'] : null;
        if (!(new MailSettingsService())->effective($tenantId)) return false;

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);
        $ip = Security::clientIp();

        Database::transaction(function (PDO $tx) use ($user, $hash, $expiresAt, $ip): void {
            $tx->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([(int)$user['id']]);
            $tx->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,request_ip) VALUES (?,?,?,?)')->execute([(int)$user['id'],$hash,$expiresAt,mb_substr($ip,0,64)]);
        });

        $url = app_url('?route=reset-password&token='.rawurlencode($token));
        $name = trim((string)$user['name']) ?: 'usuário';
        $subject = 'Redefinição de senha — EventMenu';
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#1f2430">'
            .'<h2 style="color:#4d2db7">Redefinição de senha</h2>'
            .'<p>Olá, '.$safeName.'. Recebemos um pedido para alterar sua senha do EventMenu.</p>'
            .'<p><a href="'.$safeUrl.'" style="display:inline-block;padding:12px 18px;background:#4d2db7;color:#fff;text-decoration:none;border-radius:9px">Criar nova senha</a></p>'
            .'<p>Este link expira em <strong>30 minutos</strong> e só pode ser usado uma vez.</p>'
            .'<p style="color:#687083;font-size:13px">Se você não solicitou a alteração, ignore este e-mail. Sua senha atual continuará válida.</p>'
            .'</div>';
        $text = "Olá, {$name}.\n\nUse o link abaixo para criar uma nova senha do EventMenu:\n{$url}\n\nO link expira em 30 minutos e só pode ser usado uma vez.";

        try {
            (new SmtpMailerService())->send($tenantId, (string)$user['email'], $name, $subject, $html, $text);
            return true;
        } catch (\Throwable $e) {
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE token_hash=?')->execute([$hash]);
            error_log('EventMenu password reset mail failed: '.$e->getMessage());
            return false;
        }
    }

    /** @return array{id:int,name:string,email:string}|null */
    public function validate(string $token): ?array
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $hash = hash('sha256', $token);
        $s = Database::connection()->prepare('SELECT prt.*,u.name,u.email,u.status,t.status tenant_status FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id LEFT JOIN tenants t ON t.id=u.tenant_id WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>CURRENT_TIMESTAMP LIMIT 1');
        $s->execute([$hash]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string)$row['status'] !== 'active') return null;
        if ($row['tenant_status'] !== null && (string)$row['tenant_status'] !== 'active') return null;
        return ['id'=>(int)$row['user_id'],'name'=>(string)$row['name'],'email'=>(string)$row['email']];
    }

    public function reset(string $token, string $password): void
    {
        $token = strtolower(trim($token));
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new RuntimeException('Este link de redefinição é inválido ou expirou.');
        if (strlen($password) < 8) throw new RuntimeException('A nova senha precisa ter pelo menos 8 caracteres.');
        if (strlen($password) > 200) throw new RuntimeException('Senha inválida.');
        $hash = hash('sha256', $token);

        Database::transaction(function (PDO $pdo) use ($hash, $password): void {
            $sql = Database::portableSql($pdo, 'SELECT prt.*,u.tenant_id,u.status,t.status tenant_status FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id LEFT JOIN tenants t ON t.id=u.tenant_id WHERE prt.token_hash=? LIMIT 1 FOR UPDATE');
            $s = $pdo->prepare($sql);$s->execute([$hash]);$row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['used_at'] !== null || strtotime((string)$row['expires_at']) <= time()) throw new RuntimeException('Este link de redefinição é inválido ou expirou.');
            if ((string)$row['status'] !== 'active' || ($row['tenant_id'] !== null && (string)$row['tenant_status'] !== 'active')) throw new RuntimeException('Este acesso não está disponível.');
            $userId = (int)$row['user_id'];
            $pdo->prepare('UPDATE users SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$userId]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=? AND used_at IS NULL')->execute([$userId]);
            try {$pdo->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);} catch (\Throwable) {}
            try {$pdo->prepare('UPDATE api_refresh_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE user_id=? AND revoked_at IS NULL')->execute([$userId]);} catch (\Throwable) {}
            try {
                $pdo->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)')->execute([
                    $row['tenant_id'] !== null ? (int)$row['tenant_id'] : null,$userId,'auth.password_reset','user',(string)$userId,Security::clientIp(),mb_substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),json_encode(['method'=>'email_token'],JSON_UNESCAPED_UNICODE)
                ]);
            } catch (\Throwable) {}
        });
    }

    public function cleanup(): int
    {
        $s = Database::connection()->prepare('DELETE FROM password_reset_tokens WHERE expires_at<CURRENT_TIMESTAMP OR used_at IS NOT NULL');
        $s->execute();
        return $s->rowCount();
    }
}
