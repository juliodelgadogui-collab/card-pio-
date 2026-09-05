<?php

declare(strict_types=1);

namespace EventMenu\Core;

use RuntimeException;

final class Auth
{
    public static function attempt(string $email, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.status="active" LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        $user = $stmt->fetch();
        if (!$user || ($user['tenant_id'] !== null && $user['tenant_status'] !== 'active') || !password_verify($password, $user['password_hash'])) return false;
        session_regenerate_id(true);
        unset($_SESSION['acting_tenant_id']);
        self::setSession($user);
        $pdo->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
        self::audit('auth.login','user',(string)$user['id']);
        return true;
    }

    private static function setSession(array $user): void
    {
        $_SESSION['user_id']=(int)$user['id'];
        $_SESSION['tenant_id']=$user['tenant_id']!==null?(int)$user['tenant_id']:null;
        $_SESSION['role']=$user['role'];
        $_SESSION['name']=$user['name'];
        if(($user['role']??'')!=='super_admin') unset($_SESSION['acting_tenant_id']);
    }

    public static function enforceCurrentUser(): void
    {
        if (!self::check()) return;
        $stmt=Database::connection()->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? LIMIT 1');
        $stmt->execute([self::id()]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||($user['tenant_id']!==null&&$user['tenant_status']!=='active')){self::logout();\app_redirect('?route=login&blocked=1');}
        self::setSession($user);

        if(self::isSuperAdmin() && isset($_SESSION['acting_tenant_id'])){
            $tenantId=(int)$_SESSION['acting_tenant_id'];
            $t=Database::connection()->prepare('SELECT status FROM tenants WHERE id=? LIMIT 1');
            $t->execute([$tenantId]);
            $status=$t->fetchColumn();
            if($status!=='active') unset($_SESSION['acting_tenant_id']);
        }
    }

    public static function logout(): void
    {
        if (self::check()) self::audit('auth.logout','user',(string)self::id());
        $_SESSION=[];
        if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}
        session_destroy();
    }

    public static function check(): bool { return !empty($_SESSION['user_id']); }
    public static function id(): ?int { return self::check()?(int)$_SESSION['user_id']:null; }
    public static function role(): ?string { return $_SESSION['role']??null; }
    public static function name(): string { return (string)($_SESSION['name']??''); }
    public static function isSuperAdmin(): bool { return self::role()==='super_admin'; }

    public static function tenantId(): ?int
    {
        if(self::isSuperAdmin()) return isset($_SESSION['acting_tenant_id'])?(int)$_SESSION['acting_tenant_id']:null;
        return isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:null;
    }

    public static function actingTenantId(): ?int
    {
        return self::isSuperAdmin()&&isset($_SESSION['acting_tenant_id'])?(int)$_SESSION['acting_tenant_id']:null;
    }

    public static function actAsTenant(int $tenantId): void
    {
        if(!self::isSuperAdmin()) throw new RuntimeException('Apenas o Super ADM pode selecionar uma empresa.');
        $stmt=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? AND status="active" LIMIT 1');
        $stmt->execute([$tenantId]);
        if(!$stmt->fetchColumn()) throw new RuntimeException('Empresa indisponível para acesso.');
        $_SESSION['acting_tenant_id']=$tenantId;
        self::audit('platform.tenant_selected','tenant',(string)$tenantId);
    }

    public static function clearTenantContext(): void
    {
        if(!self::isSuperAdmin()) return;
        $previous=self::actingTenantId();
        unset($_SESSION['acting_tenant_id']);
        if($previous) self::audit('platform.tenant_left','tenant',(string)$previous);
    }

    public static function can(string $permission): bool
    {
        $role=self::role();if($role==='super_admin')return true;
        $map=[
            'admin'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','payments.manage','gateways.manage','events.manage','tickets.manage','users.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage'],
            'manager'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','payments.manage','events.manage','tickets.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign'],
            'cashier'=>['dashboard','orders.manage','orders.view','orders.create','payments.manage','customers.manage','tables.manage'],
            'waiter'=>['dashboard','orders.create','orders.view','tables.manage'],
            'kitchen'=>['dashboard','orders.kitchen','orders.view'],
            'delivery'=>['dashboard','orders.delivery','orders.view'],
            'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
        ];
        return in_array($permission,$map[$role]??[],true);
    }

    public static function requirePermission(string $permission): void
    {
        if(!self::check()) \app_redirect('?route=login');
        if(!self::can($permission)){http_response_code(403);exit('Acesso negado.');}
    }

    public static function audit(string $action,?string $entityType=null,?string $entityId=null,array $metadata=[]): void
    {
        try{$stmt=Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([self::tenantId(),self::id(),$action,$entityType,$entityId,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}
    }
}
