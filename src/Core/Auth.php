<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;

final class Auth
{
    private const LOGIN_WINDOW_MINUTES=15;
    private const LOGIN_MAX_ATTEMPTS=5;
    private const LOGIN_LOCK_MINUTES=15;

    public static function attempt(string $email,string $password):bool
    {
        $pdo=Database::connection();
        $email=mb_strtolower(trim($email));
        $blocked=self::isLoginBlocked($pdo,$email);

        $stmt=$pdo->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.status="active" LIMIT 1');
        $stmt->execute([$email]);
        $user=$stmt->fetch();
        $credentialsValid=$user&&($user['tenant_id']===null||$user['tenant_status']==='active')&&password_verify($password,(string)$user['password_hash']);

        if(!$credentialsValid||$blocked){
            if(!$blocked)self::registerLoginFailure($pdo,$email);
            return false;
        }

        self::clearLoginThrottle($pdo,$email);
        session_regenerate_id(true);
        unset($_SESSION['super_admin_tenant_id']);
        self::setSession($user);
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);
        self::audit('auth.login','user',(string)$user['id']);
        return true;
    }

    private static function setSession(array $user):void
    {
        $_SESSION['user_id']=(int)$user['id'];
        $_SESSION['role']=$user['role'];
        $_SESSION['name']=$user['name'];
        if($user['role']==='super_admin'){
            $_SESSION['tenant_id']=isset($_SESSION['super_admin_tenant_id'])?(int)$_SESSION['super_admin_tenant_id']:null;
        }else{
            unset($_SESSION['super_admin_tenant_id']);
            $_SESSION['tenant_id']=$user['tenant_id']!==null?(int)$user['tenant_id']:null;
        }
    }

    public static function enforceCurrentUser():void
    {
        if(!self::check())return;
        $stmt=Database::connection()->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? LIMIT 1');
        $stmt->execute([self::id()]);
        $user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||($user['tenant_id']!==null&&$user['tenant_status']!=='active')){
            self::logout();
            header('Location: /?route=login&blocked=1');
            exit;
        }
        if(($user['role']??'')==='super_admin'&&isset($_SESSION['super_admin_tenant_id'])){
            $check=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? AND status="active"');
            $check->execute([(int)$_SESSION['super_admin_tenant_id']]);
            if(!$check->fetchColumn())unset($_SESSION['super_admin_tenant_id']);
        }
        self::setSession($user);
    }

    public static function selectTenant(?int $tenantId):void
    {
        if(self::role()!=='super_admin')throw new RuntimeException('Apenas Super ADM pode selecionar empresa.');
        if($tenantId===null||$tenantId<1){
            unset($_SESSION['super_admin_tenant_id']);
            $_SESSION['tenant_id']=null;
            return;
        }
        $stmt=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? LIMIT 1');
        $stmt->execute([$tenantId]);
        if(!$stmt->fetchColumn())throw new RuntimeException('Empresa não encontrada.');
        $_SESSION['super_admin_tenant_id']=$tenantId;
        $_SESSION['tenant_id']=$tenantId;
    }

    public static function logout():void
    {
        if(self::check())self::audit('auth.logout','user',(string)self::id());
        $_SESSION=[];
        if(ini_get('session.use_cookies')){
            $p=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);
        }
        session_destroy();
    }

    public static function check():bool{return!empty($_SESSION['user_id']);}
    public static function id():?int{return self::check()?(int)$_SESSION['user_id']:null;}
    public static function tenantId():?int{return isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:null;}
    public static function role():?string{return $_SESSION['role']??null;}
    public static function name():string{return(string)($_SESSION['name']??'');}

    public static function can(string $permission):bool
    {
        $role=self::role();
        if($role==='super_admin')return true;
        $map=[
            'admin'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','gateways.manage','events.manage','tickets.manage','users.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage'],
            'manager'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','events.manage','tickets.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign'],
            'cashier'=>['dashboard','orders.manage','orders.view','orders.create','payments.manage','customers.manage','tables.manage'],
            'waiter'=>['dashboard','orders.create','orders.view','tables.manage'],
            'kitchen'=>['dashboard','orders.kitchen','orders.view'],
            'delivery'=>['dashboard','orders.delivery','orders.view'],
            'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
        ];
        return in_array($permission,$map[$role]??[],true);
    }

    public static function requirePermission(string $permission):void
    {
        if(!self::check()){header('Location: /?route=login');exit;}
        if(!self::can($permission)){http_response_code(403);exit('Acesso negado.');}
    }

    public static function audit(string $action,?string $entityType=null,?string $entityId=null,array $metadata=[]):void
    {
        try{
            $stmt=Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([self::tenantId(),self::id(),$action,$entityType,$entityId,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
        }catch(\Throwable){}
    }

    private static function throttleKey(string $email):string
    {
        $secret=(string)env('APP_KEY','eventmenu-login-throttle');
        return hash_hmac('sha256',$email.'|'.Security::clientIp(),$secret);
    }

    private static function isLoginBlocked(PDO $pdo,string $email):bool
    {
        $stmt=$pdo->prepare('SELECT locked_until>NOW() FROM login_throttles WHERE key_hash=? LIMIT 1');
        $stmt->execute([self::throttleKey($email)]);
        return (bool)$stmt->fetchColumn();
    }

    private static function registerLoginFailure(PDO $pdo,string $email):void
    {
        $key=self::throttleKey($email);
        Database::transaction(function(PDO $db)use($key):void{
            $stmt=$db->prepare('SELECT attempts,window_started_at<DATE_SUB(NOW(),INTERVAL '.self::LOGIN_WINDOW_MINUTES.' MINUTE) expired,locked_until>NOW() locked FROM login_throttles WHERE key_hash=? FOR UPDATE');
            $stmt->execute([$key]);
            $row=$stmt->fetch();
            if(!$row){
                $db->prepare('INSERT INTO login_throttles (key_hash,attempts,window_started_at) VALUES (?,1,NOW())')->execute([$key]);
                return;
            }
            if((int)$row['locked']===1)return;
            if((int)$row['expired']===1){
                $db->prepare('UPDATE login_throttles SET attempts=1,window_started_at=NOW(),locked_until=NULL WHERE key_hash=?')->execute([$key]);
                return;
            }
            $attempts=(int)$row['attempts']+1;
            if($attempts>=self::LOGIN_MAX_ATTEMPTS){
                $db->prepare('UPDATE login_throttles SET attempts=?,locked_until=DATE_ADD(NOW(),INTERVAL '.self::LOGIN_LOCK_MINUTES.' MINUTE) WHERE key_hash=?')->execute([$attempts,$key]);
            }else{
                $db->prepare('UPDATE login_throttles SET attempts=? WHERE key_hash=?')->execute([$attempts,$key]);
            }
        });
    }

    private static function clearLoginThrottle(PDO $pdo,string $email):void
    {
        $pdo->prepare('DELETE FROM login_throttles WHERE key_hash=?')->execute([self::throttleKey($email)]);
    }
}
