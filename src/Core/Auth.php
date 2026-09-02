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
        session_regenerate_id(true);unset($_SESSION['super_admin_tenant_id']);self::setSession($user);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);self::audit('auth.login','user',(string)$user['id']);return true;
    }

    private static function setSession(array $user): void
    {
        $_SESSION['user_id']=(int)$user['id'];$_SESSION['role']=$user['role'];$_SESSION['name']=$user['name'];
        if($user['role']==='super_admin'){$_SESSION['tenant_id']=isset($_SESSION['super_admin_tenant_id'])?(int)$_SESSION['super_admin_tenant_id']:null;}else{unset($_SESSION['super_admin_tenant_id']);$_SESSION['tenant_id']=$user['tenant_id']!==null?(int)$user['tenant_id']:null;}
    }

    public static function enforceCurrentUser(): void
    {
        if(!self::check())return;$stmt=Database::connection()->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? LIMIT 1');$stmt->execute([self::id()]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||($user['tenant_id']!==null&&$user['tenant_status']!=='active')){self::logout();header('Location: /?route=login&blocked=1');exit;}
        if(($user['role']??'')==='super_admin'&&isset($_SESSION['super_admin_tenant_id'])){$check=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? AND status="active"');$check->execute([(int)$_SESSION['super_admin_tenant_id']]);if(!$check->fetchColumn())unset($_SESSION['super_admin_tenant_id']);}
        self::setSession($user);
    }

    public static function selectTenant(?int $tenantId): void
    {
        if(self::role()!=='super_admin')throw new RuntimeException('Apenas Super ADM pode selecionar empresa.');if($tenantId===null||$tenantId<1){unset($_SESSION['super_admin_tenant_id']);$_SESSION['tenant_id']=null;return;}$stmt=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? LIMIT 1');$stmt->execute([$tenantId]);if(!$stmt->fetchColumn())throw new RuntimeException('Empresa não encontrada.');$_SESSION['super_admin_tenant_id']=$tenantId;$_SESSION['tenant_id']=$tenantId;
    }

    public static function logout(): void
    {
        if(self::check())self::audit('auth.logout','user',(string)self::id());$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();
    }

    public static function check():bool{return!empty($_SESSION['user_id']);}
    public static function id():?int{return self::check()?(int)$_SESSION['user_id']:null;}
    public static function tenantId():?int{return isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:null;}
    public static function role():?string{return $_SESSION['role']??null;}
    public static function name():string{return(string)($_SESSION['name']??'');}

    public static function can(string $permission): bool
    {
        $role=self::role();if($role==='super_admin')return true;
        $map=[
            'admin'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','gateways.manage','events.manage','tickets.manage','users.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage'],
            'manager'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','events.manage','tickets.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign'],
            'cashier'=>['dashboard','orders.manage','orders.view','orders.create','payments.manage','customers.manage','tables.manage'],
            'waiter'=>['dashboard','orders.create','orders.view','tables.manage'],
            'kitchen'=>['dashboard','orders.kitchen','orders.view'],
            'delivery'=>['dashboard','orders.delivery','orders.view'],
            'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
        ];return in_array($permission,$map[$role]??[],true);
    }

    public static function requirePermission(string $permission):void{if(!self::check()){header('Location: /?route=login');exit;}if(!self::can($permission)){http_response_code(403);exit('Acesso negado.');}}
    public static function audit(string $action,?string $entityType=null,?string $entityId=null,array $metadata=[]):void{try{$stmt=Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([self::tenantId(),self::id(),$action,$entityType,$entityId,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}}
}
