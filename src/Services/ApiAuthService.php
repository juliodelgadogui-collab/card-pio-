<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use RuntimeException;

final class ApiAuthService
{
    public function login(string $email,string $password,string $deviceId,string $deviceLabel=''):array
    {
        $email=mb_strtolower(trim($email));$deviceId=trim($deviceId);$deviceLabel=mb_substr(trim($deviceLabel),0,120);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='') throw new RuntimeException('Credenciais inválidas.');
        if(strlen($deviceId)<8) throw new RuntimeException('Identificador do aparelho inválido.');
        $throttle=new LoginThrottleService();$throttleKey=$throttle->key($email,$deviceId);$throttle->assertAllowed($throttleKey);
        $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT u.*,t.status tenant_status FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? LIMIT 1');$stmt->execute([$email]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||$user['tenant_status']!=='active'||$user['role']==='super_admin'||!password_verify($password,(string)$user['password_hash'])){$throttle->failed($throttleKey);throw new RuntimeException('E-mail ou senha inválidos.');}
        $throttle->succeeded($throttleKey);
        $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$deviceHash=hash('sha256',$deviceId);$expires=(new \DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');
        Database::transaction(function(\PDO $tx)use($user,$deviceHash,$deviceLabel,$hash,$expires):void{
            $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL')->execute([$user['tenant_id'],$user['id'],$deviceHash]);
            $tx->prepare('INSERT INTO api_tokens (tenant_id,user_id,token_hash,device_hash,device_label,expires_at) VALUES (?,?,?,?,?,?)')->execute([$user['tenant_id'],$user['id'],$hash,$deviceHash,$deviceLabel?:null,$expires]);
        });
        $this->audit((int)$user['tenant_id'],(int)$user['id'],'api.login',['device_label'=>$deviceLabel]);
        return ['token'=>$raw,'expires_at'=>$expires,'user'=>['id'=>(int)$user['id'],'tenant_id'=>(int)$user['tenant_id'],'name'=>(string)$user['name'],'email'=>(string)$user['email'],'role'=>(string)$user['role']]];
    }

    public function authenticate(string $rawToken,string $deviceId):array
    {
        $rawToken=trim($rawToken);$deviceId=trim($deviceId);if(strlen($rawToken)<32) throw new RuntimeException('Token inválido.');
        $hash=hash('sha256',$rawToken);$pdo=Database::connection();$stmt=$pdo->prepare('SELECT at.id token_id,at.device_hash,at.expires_at,u.*,t.status tenant_status FROM api_tokens at JOIN users u ON u.id=at.user_id JOIN tenants t ON t.id=at.tenant_id WHERE at.token_hash=? AND at.revoked_at IS NULL LIMIT 1');$stmt->execute([$hash]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||$user['tenant_status']!=='active'||strtotime((string)$user['expires_at'])<time()) throw new RuntimeException('Sessão do app expirada ou revogada.');
        if($user['device_hash']){if(strlen($deviceId)<8||!hash_equals((string)$user['device_hash'],hash('sha256',$deviceId))) throw new RuntimeException('Token não pertence a este aparelho.');}
        $pdo->prepare('UPDATE api_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['token_id']]);
        $_SESSION['user_id']=(int)$user['id'];$_SESSION['tenant_id']=(int)$user['tenant_id'];$_SESSION['role']=(string)$user['role'];$_SESSION['name']=(string)$user['name'];unset($_SESSION['acting_tenant_id']);
        return ['id'=>(int)$user['id'],'tenant_id'=>(int)$user['tenant_id'],'name'=>(string)$user['name'],'email'=>(string)$user['email'],'role'=>(string)$user['role'],'token_id'=>(int)$user['token_id']];
    }

    public function revoke(string $rawToken):void
    {
        $hash=hash('sha256',trim($rawToken));$pdo=Database::connection();$stmt=$pdo->prepare('SELECT tenant_id,user_id FROM api_tokens WHERE token_hash=? AND revoked_at IS NULL');$stmt->execute([$hash]);$row=$stmt->fetch();if(!$row)return;
        $pdo->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=? AND revoked_at IS NULL')->execute([$hash]);$this->audit((int)$row['tenant_id'],(int)$row['user_id'],'api.logout',[]);
    }

    public static function bearerToken():string
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if($header===''&&function_exists('getallheaders')){$h=getallheaders();$header=(string)($h['Authorization']??$h['authorization']??'');}
        if(!preg_match('/^Bearer\s+(.+)$/i',trim($header),$m)) return '';
        return trim($m[1]);
    }

    public static function deviceId():string
    {
        $header=(string)($_SERVER['HTTP_X_DEVICE_ID']??'');
        if($header===''&&function_exists('getallheaders')){$h=getallheaders();$header=(string)($h['X-Device-Id']??$h['x-device-id']??'');}
        return trim($header);
    }

    private function audit(int $tenantId,int $userId,string $action,array $metadata):void
    {
        try{Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?)')->execute([$tenantId,$userId,$action,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}
    }
}
