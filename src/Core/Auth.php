<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;

final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND status = "active" LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) return false;

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['tenant_id'] = $user['tenant_id'] !== null ? (int)$user['tenant_id'] : null;
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
        self::audit('auth.login', 'user', (string)$user['id']);
        return true;
    }

    public static function logout(): void
    {
        if (self::check()) self::audit('auth.logout', 'user', (string)self::id());
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool { return !empty($_SESSION['user_id']); }
    public static function id(): ?int { return self::check() ? (int)$_SESSION['user_id'] : null; }
    public static function tenantId(): ?int { return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null; }
    public static function role(): ?string { return $_SESSION['role'] ?? null; }
    public static function name(): string { return (string)($_SESSION['name'] ?? ''); }

    public static function can(string $permission): bool
    {
        $role = self::role();
        if ($role === 'super_admin') return true;
        $map = [
            'admin' => ['dashboard','catalog.manage','orders.manage','payments.manage','gateways.manage','events.manage','tickets.manage','users.manage','reports.view','delivery.assign'],
            'manager' => ['dashboard','catalog.manage','orders.manage','payments.manage','events.manage','tickets.manage','reports.view','delivery.assign'],
            'cashier' => ['dashboard','orders.manage','payments.manage'],
            'waiter' => ['dashboard','orders.create','orders.view'],
            'kitchen' => ['dashboard','orders.kitchen'],
            'delivery' => ['dashboard','orders.delivery'],
            'promoter' => ['dashboard','events.promoter','reports.own'],
        ];
        return in_array($permission, $map[$role] ?? [], true);
    }

    public static function requirePermission(string $permission): void
    {
        if (!self::check()) { header('Location: /?route=login'); exit; }
        if (!self::can($permission)) { http_response_code(403); exit('Acesso negado.'); }
    }

    public static function audit(string $action, ?string $entityType = null, ?string $entityId = null, array $metadata = []): void
    {
        try {
            $stmt = Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([self::tenantId(), self::id(), $action, $entityType, $entityId, Security::clientIp(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null]);
        } catch (\Throwable) {}
    }
}
