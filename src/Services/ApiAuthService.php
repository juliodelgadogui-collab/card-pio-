<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use PDO;
use RuntimeException;
use Throwable;

final class ApiAuthService
{
    private const ACCESS_TTL='+30 minutes';
    private const REFRESH_TTL='+30 days';

    public function login(string $email,string $password,string $deviceId,string $deviceLabel=''):array
    {
        (new ClientPolicyGateService())->assertCurrentRequestAllowed();
        $email=mb_strtolower(trim($email));$deviceId=trim($deviceId);$deviceLabel=mb_substr(trim($deviceLabel),0,120);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='')throw new RuntimeException('Credenciais inválidas.');
        if(strlen($deviceId)<8)throw new RuntimeException('Identificador do aparelho inválido.');
        $throttle=new LoginThrottleService();$throttleKey=$throttle->key($email,$deviceId);$throttle->assertAllowed($throttleKey);
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT u.*,t.status tenant_status,t.name tenant_name FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? LIMIT 1');$stmt->execute([$email]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||$user['tenant_status']!=='active'||$user['role']==='super_admin'||!password_verify($password,(string)$user['password_hash'])){$throttle->failed($throttleKey);throw new RuntimeException('E-mail ou senha inválidos.');}
        $throttle->succeeded($throttleKey);$deviceHash=hash('sha256',$deviceId);
        $tokens=Database::transaction(function(PDO $tx)use($user,$deviceHash,$deviceLabel):array{
            $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL')->execute([$user['tenant_id'],$user['id'],$deviceHash]);
            $tx->prepare('UPDATE api_refresh_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL')->execute([$user['tenant_id'],$user['id'],$deviceHash]);
            return $this->issuePair($tx,(int)$user['tenant_id'],(int)$user['id'],$deviceHash,$deviceLabel);
        });
        $this->audit((int)$user['tenant_id'],(int)$user['id'],'api.login',['device_label'=>$deviceLabel]);
        return $tokens+['user'=>$this->userPayload($user)];
    }

    public function refresh(string $rawRefreshToken,string $deviceId):array
    {
        (new ClientPolicyGateService())->assertCurrentRequestAllowed();
        $rawRefreshToken=trim($rawRefreshToken);$deviceId=trim($deviceId);if(strlen($rawRefreshToken)<32||strlen($deviceId)<8)throw new RuntimeException('Refresh token ou aparelho inválido.');
        $hash=hash('sha256',$rawRefreshToken);$deviceHash=hash('sha256',$deviceId);$pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT rt.*,u.name,u.email,u.role,u.status user_status,t.status tenant_status,t.name tenant_name FROM api_refresh_tokens rt JOIN users u ON u.id=rt.user_id JOIN tenants t ON t.id=rt.tenant_id WHERE rt.token_hash=? LIMIT 1');$stmt->execute([$hash]);$row=$stmt->fetch();
        if(!$row||$row['revoked_at']||$row['used_at']||strtotime((string)$row['expires_at'])<time()||$row['user_status']!=='active'||$row['tenant_status']!=='active')throw new RuntimeException('Refresh token expirado ou revogado.');
        if(!hash_equals((string)$row['device_hash'],$deviceHash))throw new RuntimeException('Refresh token não pertence a este aparelho.');
        $tokens=Database::transaction(function(PDO $tx)use($row,$hash,$deviceHash):array{
            $lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM api_refresh_tokens WHERE id=? AND token_hash=? FOR UPDATE'));$lock->execute([$row['id'],$hash]);$fresh=$lock->fetch();
            if(!$fresh||$fresh['revoked_at']||$fresh['used_at']||strtotime((string)$fresh['expires_at'])<time())throw new RuntimeException('Refresh token já utilizado ou expirado.');
            $tx->prepare('UPDATE api_refresh_tokens SET used_at=CURRENT_TIMESTAMP,revoked_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$fresh['id']]);
            if($fresh['access_token_id'])$tx->prepare('UPDATE api_tokens SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE id=?')->execute([$fresh['access_token_id']]);
            $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL')->execute([$fresh['tenant_id'],$fresh['user_id'],$deviceHash]);
            return $this->issuePair($tx,(int)$fresh['tenant_id'],(int)$fresh['user_id'],$deviceHash,'EventMenu GO');
        });
        $this->audit((int)$row['tenant_id'],(int)$row['user_id'],'api.token_refreshed',[]);
        return $tokens+['user'=>$this->userPayload($row)];
    }

    public function authenticate(string $rawToken,string $deviceId):array
    {
        (new ClientPolicyGateService())->assertCurrentRequestAllowed();
        $rawToken=trim($rawToken);$deviceId=trim($deviceId);if(strlen($rawToken)<32)throw new RuntimeException('Token inválido.');
        $hash=hash('sha256',$rawToken);$pdo=Database::connection();$stmt=$pdo->prepare('SELECT at.id token_id,at.device_hash,at.expires_at,at.last_used_at token_last_used_at,u.*,t.status tenant_status,t.name tenant_name FROM api_tokens at JOIN users u ON u.id=at.user_id JOIN tenants t ON t.id=at.tenant_id WHERE at.token_hash=? AND at.revoked_at IS NULL LIMIT 1');$stmt->execute([$hash]);$user=$stmt->fetch();
        if(!$user||$user['status']!=='active'||$user['tenant_status']!=='active'||strtotime((string)$user['expires_at'])<time())throw new RuntimeException('Sessão do app expirada ou revogada.');
        if($user['device_hash']){if(strlen($deviceId)<8||!hash_equals((string)$user['device_hash'],hash('sha256',$deviceId)))throw new RuntimeException('Token não pertence a este aparelho.');}

        // Em SQLite, autenticação precisa continuar sendo leitura. O app abre várias
        // APIs em paralelo (entregas, notificações, contexto, etc.) e gravar
        // last_used_at em toda requisição transforma cada GET em um escritor,
        // causando SQLITE_BUSY / database is locked. A emissão/renovação do token
        // já registra last_used_at. Em MySQL mantemos um touch amortizado.
        if(!Database::isSqlite($pdo)){
            $lastUsed=(string)($user['token_last_used_at']??'');
            $lastTs=$lastUsed!==''?strtotime($lastUsed):false;
            if($lastTs===false||$lastTs<time()-300){
                try{$pdo->prepare('UPDATE api_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['token_id']]);}catch(Throwable){}
            }
        }

        $_SESSION['user_id']=(int)$user['id'];$_SESSION['tenant_id']=(int)$user['tenant_id'];$_SESSION['role']=(string)$user['role'];$_SESSION['name']=(string)$user['name'];unset($_SESSION['acting_tenant_id']);
        return [
            'id'=>(int)$user['id'],
            'tenant_id'=>(int)$user['tenant_id'],
            'tenant_name'=>(string)$user['tenant_name'],
            'name'=>(string)$user['name'],
            'email'=>(string)$user['email'],
            'role'=>(string)$user['role'],
            'token_id'=>(int)$user['token_id'],
            'brand'=>(new TenantBrandService())->get((int)$user['tenant_id']),
        ];
    }

    public function revoke(string $rawToken,string $deviceId=''):void
    {
        $hash=hash('sha256',trim($rawToken));$deviceId=mb_substr(trim($deviceId),0,190);$pdo=Database::connection();$stmt=$pdo->prepare('SELECT id,tenant_id,user_id,device_hash FROM api_tokens WHERE token_hash=? AND revoked_at IS NULL');$stmt->execute([$hash]);$row=$stmt->fetch();if(!$row)return;
        Database::transaction(function(PDO $tx)use($row,$hash,$deviceId):void{
            $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=? AND revoked_at IS NULL')->execute([$hash]);
            if($row['device_hash'])$tx->prepare('UPDATE api_refresh_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL')->execute([$row['tenant_id'],$row['user_id'],$row['device_hash']]);
            $deviceHash=(string)($row['device_hash']??'');if($deviceHash==='')return;
            if($deviceId!==''&&hash_equals($deviceHash,hash('sha256',$deviceId))){$tx->prepare('UPDATE push_devices SET active=0,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_id=?')->execute([$row['tenant_id'],$row['user_id'],$deviceId]);return;}
            $d=$tx->prepare('SELECT id,device_id FROM push_devices WHERE tenant_id=? AND user_id=? AND active=1');$d->execute([$row['tenant_id'],$row['user_id']]);$u=$tx->prepare('UPDATE push_devices SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND user_id=?');foreach($d->fetchAll()as$pushDevice){if(hash_equals($deviceHash,hash('sha256',(string)$pushDevice['device_id'])))$u->execute([$pushDevice['id'],$row['tenant_id'],$row['user_id']]);}
        });
        $this->audit((int)$row['tenant_id'],(int)$row['user_id'],'api.logout',[]);
    }

    public static function bearerToken():string
    {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if($header===''&&function_exists('getallheaders')){$h=getallheaders();$header=(string)($h['Authorization']??$h['authorization']??'');}
        if(!preg_match('/^Bearer\s+(.+)$/i',trim($header),$m))return '';
        return trim($m[1]);
    }

    public static function deviceId():string
    {
        $header=(string)($_SERVER['HTTP_X_DEVICE_ID']??'');
        if($header===''&&function_exists('getallheaders')){$h=getallheaders();$header=(string)($h['X-Device-Id']??$h['x-device-id']??'');}
        return trim($header);
    }

    private function issuePair(PDO $tx,int $tenantId,int $userId,string $deviceHash,string $deviceLabel):array
    {
        $accessRaw=bin2hex(random_bytes(32));$refreshRaw=bin2hex(random_bytes(48));$accessHash=hash('sha256',$accessRaw);$refreshHash=hash('sha256',$refreshRaw);
        $accessExpires=(new \DateTimeImmutable(self::ACCESS_TTL))->format('Y-m-d H:i:s');$refreshExpires=(new \DateTimeImmutable(self::REFRESH_TTL))->format('Y-m-d H:i:s');
        $tx->prepare('INSERT INTO api_tokens (tenant_id,user_id,token_hash,device_hash,device_label,expires_at,last_used_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)')->execute([$tenantId,$userId,$accessHash,$deviceHash,$deviceLabel?:null,$accessExpires]);$accessId=(int)$tx->lastInsertId();
        $tx->prepare('INSERT INTO api_refresh_tokens (tenant_id,user_id,access_token_id,token_hash,device_hash,expires_at) VALUES (?,?,?,?,?,?)')->execute([$tenantId,$userId,$accessId,$refreshHash,$deviceHash,$refreshExpires]);
        return ['token'=>$accessRaw,'expires_at'=>$accessExpires,'refresh_token'=>$refreshRaw,'refresh_expires_at'=>$refreshExpires];
    }

    private function userPayload(array $user):array
    {
        $tenantId=(int)$user['tenant_id'];
        return [
            'id'=>(int)$user['id'],
            'tenant_id'=>$tenantId,
            'tenant_name'=>(string)($user['tenant_name']??''),
            'name'=>(string)$user['name'],
            'email'=>(string)$user['email'],
            'role'=>(string)$user['role'],
            'brand'=>(new TenantBrandService())->get($tenantId),
        ];
    }

    private function audit(int $tenantId,int $userId,string $action,array $metadata):void
    {
        try{Database::connection()->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,ip_address,user_agent,metadata) VALUES (?,?,?,?,?,?)')->execute([$tenantId,$userId,$action,Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}
    }
}
