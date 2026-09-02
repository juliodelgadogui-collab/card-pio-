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
    private static array $permissionCache=[];

    public static function attempt(string $email,string $password):bool
    {
        $pdo=Database::connection();$email=mb_strtolower(trim($email));$blocked=self::isLoginBlocked($pdo,$email);
        $stmt=$pdo->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.status="active" LIMIT 1');$stmt->execute([$email]);$user=$stmt->fetch();
        $valid=$user&&($user['tenant_id']===null||$user['tenant_status']==='active')&&password_verify($password,(string)$user['password_hash']);
        if(!$valid||$blocked){if(!$blocked)self::registerLoginFailure($pdo,$email);return false;}
        self::clearLoginThrottle($pdo,$email);session_regenerate_id(true);unset($_SESSION['super_admin_tenant_id'],$_SESSION['unit_id']);self::setSession($user);self::$permissionCache=[];
        self::ensureSelectedUnit();
        $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$user['id']]);self::audit('auth.login','user',(string)$user['id']);return true;
    }

    private static function setSession(array $user):void
    {
        $_SESSION['user_id']=(int)$user['id'];$_SESSION['role']=$user['role'];$_SESSION['name']=$user['name'];
        if($user['role']==='super_admin')$_SESSION['tenant_id']=isset($_SESSION['super_admin_tenant_id'])?(int)$_SESSION['super_admin_tenant_id']:null;
        else{unset($_SESSION['super_admin_tenant_id']);$_SESSION['tenant_id']=$user['tenant_id']!==null?(int)$user['tenant_id']:null;}
    }

    private static function appBasePath():string
    {
        $script=(string)($_SERVER['SCRIPT_NAME']??'');$scriptDir=$script!==''?str_replace('\\','/',dirname($script)):'';if($scriptDir==='/'||$scriptDir==='.')$scriptDir='';
        $configured=(string)env('APP_URL','');$configuredPath=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';$configuredPath=rtrim($configuredPath,'/');if($configuredPath==='/')$configuredPath='';
        return $scriptDir!==''?$scriptDir:$configuredPath;
    }
    public static function appUrl(string $path=''):string{$base=self::appBasePath();if($path==='')return $base!==''?$base.'/':'/';if(!str_starts_with($path,'/'))$path='/'.$path;return $base.$path;}

    public static function enforceCurrentUser():void
    {
        if(!self::check())return;$pdo=Database::connection();$stmt=$pdo->prepare('SELECT u.*,t.status tenant_status FROM users u LEFT JOIN tenants t ON t.id=u.tenant_id WHERE u.id=? LIMIT 1');$stmt->execute([self::id()]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||($user['tenant_id']!==null&&$user['tenant_status']!=='active')){self::logout();header('Location: '.self::appUrl('/?route=login&blocked=1'));exit;}
        if(($user['role']??'')==='super_admin'&&isset($_SESSION['super_admin_tenant_id'])){$check=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND status="active"');$check->execute([(int)$_SESSION['super_admin_tenant_id']]);if(!$check->fetchColumn()){unset($_SESSION['super_admin_tenant_id'],$_SESSION['unit_id']);}}
        self::setSession($user);self::ensureSelectedUnit();self::$permissionCache=[];
    }

    public static function selectTenant(?int $tenantId):void
    {
        if(self::role()!=='super_admin')throw new RuntimeException('Apenas Super ADM pode selecionar empresa.');
        unset($_SESSION['unit_id']);
        if($tenantId===null||$tenantId<1){unset($_SESSION['super_admin_tenant_id']);$_SESSION['tenant_id']=null;return;}
        $stmt=Database::connection()->prepare('SELECT id FROM tenants WHERE id=? LIMIT 1');$stmt->execute([$tenantId]);if(!$stmt->fetchColumn())throw new RuntimeException('Empresa não encontrada.');$_SESSION['super_admin_tenant_id']=$tenantId;$_SESSION['tenant_id']=$tenantId;
    }

    public static function unitId():?int{return isset($_SESSION['unit_id'])?(int)$_SESSION['unit_id']:null;}
    public static function selectUnit(?int $unitId):void
    {
        $tenantId=self::tenantId();if(!$tenantId)throw new RuntimeException('Empresa não selecionada.');
        if($unitId===null||$unitId<1){
            if(!in_array(self::role(),['super_admin','admin','manager'],true)&&self::availableUnits())throw new RuntimeException('Seu perfil precisa operar em uma unidade selecionada.');
            unset($_SESSION['unit_id']);return;
        }
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT id,status FROM business_units WHERE id=? AND tenant_id=? LIMIT 1');$stmt->execute([$unitId,$tenantId]);$unit=$stmt->fetch();if(!$unit||$unit['status']!=='active')throw new RuntimeException('Unidade indisponível.');
        if(!in_array(self::role(),['super_admin','admin','manager'],true)){
            $check=$pdo->prepare('SELECT 1 FROM user_units WHERE tenant_id=? AND user_id=? AND unit_id=? LIMIT 1');$check->execute([$tenantId,self::id(),$unitId]);if(!$check->fetchColumn())throw new RuntimeException('Seu usuário não possui acesso a esta unidade.');
        }
        $_SESSION['unit_id']=$unitId;self::audit('unit.selected','business_unit',(string)$unitId);
    }
    public static function availableUnits():array
    {
        $tenantId=self::tenantId();if(!$tenantId)return[];$pdo=Database::connection();
        if(in_array(self::role(),['super_admin','admin','manager'],true)){$s=$pdo->prepare('SELECT id,name,slug,status FROM business_units WHERE tenant_id=? AND status="active" ORDER BY name');$s->execute([$tenantId]);return$s->fetchAll();}
        $s=$pdo->prepare('SELECT bu.id,bu.name,bu.slug,bu.status FROM business_units bu JOIN user_units uu ON uu.unit_id=bu.id AND uu.user_id=? WHERE bu.tenant_id=? AND bu.status="active" ORDER BY bu.name');$s->execute([self::id(),$tenantId]);return$s->fetchAll();
    }
    private static function ensureSelectedUnit():void
    {
        if(!self::tenantId())return;
        try{
            $units=self::availableUnits();$ids=array_map(fn($r)=>(int)$r['id'],$units);$unit=self::unitId();
            if($unit&&in_array($unit,$ids,true))return;
            unset($_SESSION['unit_id']);
            if(!in_array(self::role(),['super_admin','admin','manager'],true)&&$ids)$_SESSION['unit_id']=$ids[0];
        }catch(\Throwable){unset($_SESSION['unit_id']);}
    }

    public static function logout():void
    {
        if(self::check())self::audit('auth.logout','user',(string)self::id());$_SESSION=[];self::$permissionCache=[];
        if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();
    }

    public static function check():bool{return!empty($_SESSION['user_id']);}
    public static function id():?int{return self::check()?(int)$_SESSION['user_id']:null;}
    public static function tenantId():?int{return isset($_SESSION['tenant_id'])?(int)$_SESSION['tenant_id']:null;}
    public static function role():?string{return $_SESSION['role']??null;}
    public static function name():string{return(string)($_SESSION['name']??'');}
    public static function homeRoute():string{return match(self::role()){'super_admin'=>'superadmin','kitchen'=>'kitchen','delivery'=>'my-deliveries','counter'=>'pos','cashier'=>'pos','waiter'=>'restaurant','promoter'=>'guests',default=>'dashboard'};}
    public static function roleLabel(?string $role=null):string{return match($role??self::role()){'super_admin'=>'Super Administrador','admin'=>'Administrador','manager'=>'Gerente','cashier'=>'Caixa','counter'=>'Balconista','waiter'=>'Garçom','kitchen'=>'Cozinha','delivery'=>'Motoboy / Entregador','promoter'=>'Promotor',default=>(string)($role??self::role()??'')};}

    public static function can(string $permission):bool
    {
        $role=self::role();if($role==='super_admin')return true;
        $hardDeny=[
            'gateways.manage'=>['manager','cashier','counter','waiter','kitchen','delivery','promoter'],
            'nfc.manage'=>['manager','cashier','counter','waiter','kitchen','delivery','promoter'],
            'users.manage'=>['cashier','counter','waiter','kitchen','delivery','promoter'],
            'payments.manage'=>['counter','waiter','kitchen','delivery','promoter'],
            'refunds.manage'=>['cashier','counter','waiter','kitchen','delivery','promoter'],
        ];
        if(in_array($role,$hardDeny[$permission]??[],true))return false;
        $map=[
            'admin'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','refunds.manage','fulfillment.manage','gateways.manage','events.manage','tickets.manage','users.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage','units.manage','notifications.view','backup.manage','legal.manage','exports.view'],
            'manager'=>['dashboard','catalog.manage','orders.manage','orders.view','orders.create','orders.kitchen','payments.manage','refunds.manage','fulfillment.manage','events.manage','tickets.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','units.manage','notifications.view','exports.view'],
            'cashier'=>['dashboard','orders.manage','orders.view','orders.create','payments.manage','fulfillment.manage','customers.manage','tables.manage','notifications.view'],
            'counter'=>['orders.create','fulfillment.manage','counter.orders'],
            'waiter'=>['dashboard','orders.create','orders.view','tables.manage','fulfillment.manage','waiter.calls'],
            'kitchen'=>['orders.kitchen'],
            'delivery'=>['orders.delivery'],
            'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
        ];
        $base=in_array($permission,$map[$role]??[],true);$userId=self::id();$tenantId=self::tenantId();if(!$userId||!$tenantId)return$base;
        $key=$userId.':'.$permission;if(array_key_exists($key,self::$permissionCache))return self::$permissionCache[$key];
        try{$s=Database::connection()->prepare('SELECT allowed FROM user_permissions WHERE tenant_id=? AND user_id=? AND permission_key=? LIMIT 1');$s->execute([$tenantId,$userId,$permission]);$v=$s->fetchColumn();if($v!==false)return self::$permissionCache[$key]=(bool)$v;}catch(\Throwable){}
        return self::$permissionCache[$key]=$base;
    }

    public static function requirePermission(string $permission):void{if(!self::check()){header('Location: '.self::appUrl('/?route=login'));exit;}if(!self::can($permission)){http_response_code(403);exit('Acesso negado.');}}
    public static function audit(string $action,?string $entityType=null,?string $entityId=null,array $metadata=[]):void{try{$stmt=Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?,?,?)');$stmt->execute([self::tenantId(),self::id(),$action,$entityType,$entityId,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}}
    private static function throttleKey(string $email):string{$secret=(string)env('APP_KEY','eventmenu-login-throttle');return hash_hmac('sha256',$email.'|'.Security::clientIp(),$secret);}
    private static function isLoginBlocked(PDO $pdo,string $email):bool{try{$stmt=$pdo->prepare('SELECT locked_until>NOW() FROM login_throttles WHERE key_hash=? LIMIT 1');$stmt->execute([self::throttleKey($email)]);return (bool)$stmt->fetchColumn();}catch(\Throwable){return false;}}
    private static function registerLoginFailure(PDO $pdo,string $email):void{$key=self::throttleKey($email);try{Database::transaction(function(PDO $db)use($key):void{$stmt=$db->prepare('SELECT attempts,window_started_at<DATE_SUB(NOW(),INTERVAL '.self::LOGIN_WINDOW_MINUTES.' MINUTE) expired,locked_until>NOW() locked FROM login_throttles WHERE key_hash=? FOR UPDATE');$stmt->execute([$key]);$row=$stmt->fetch();if(!$row){$db->prepare('INSERT INTO login_throttles (key_hash,attempts,window_started_at) VALUES (?,1,NOW())')->execute([$key]);return;}if((int)$row['locked']===1)return;if((int)$row['expired']===1){$db->prepare('UPDATE login_throttles SET attempts=1,window_started_at=NOW(),locked_until=NULL WHERE key_hash=?')->execute([$key]);return;}$attempts=(int)$row['attempts']+1;if($attempts>=self::LOGIN_MAX_ATTEMPTS)$db->prepare('UPDATE login_throttles SET attempts=?,locked_until=DATE_ADD(NOW(),INTERVAL '.self::LOGIN_LOCK_MINUTES.' MINUTE) WHERE key_hash=?')->execute([$attempts,$key]);else$db->prepare('UPDATE login_throttles SET attempts=? WHERE key_hash=?')->execute([$attempts,$key]);});}catch(\Throwable){}}
    private static function clearLoginThrottle(PDO $pdo,string $email):void{try{$pdo->prepare('DELETE FROM login_throttles WHERE key_hash=?')->execute([self::throttleKey($email)]);}catch(\Throwable){}}
}
