<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class DeliveryCustomerAuthService
{
    public function register(PDO $pdo, array $payload): array
    {
        $name=mb_substr(trim((string)($payload['name']??'')),0,160);
        $email=mb_strtolower(trim((string)($payload['email']??'')));
        $phone=mb_substr(trim((string)($payload['phone']??'')),0,40);
        $password=(string)($payload['password']??'');
        if($name==='')throw new RuntimeException('Informe seu nome.');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
        if(strlen($password)<8)throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');
        if($phone!==''&&strlen(preg_replace('/\D+/','',$phone)??'')<10)throw new RuntimeException('Informe um telefone válido.');

        $q=$pdo->prepare('SELECT * FROM delivery_customer_accounts WHERE email=? LIMIT 1');$q->execute([$email]);$existing=$q->fetch();
        if($existing){
            if(!empty($existing['email_verified_at']))throw new RuntimeException('Já existe uma conta com este e-mail.');
            $pdo->prepare('UPDATE delivery_customer_accounts SET name=?,phone=?,password_hash=?,status="pending_verification",updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$phone?:null,password_hash($password,PASSWORD_DEFAULT),(int)$existing['id']]);
            $accountId=(int)$existing['id'];
        }else{
            $pdo->prepare('INSERT INTO delivery_customer_accounts (name,email,phone,password_hash,status,marketing_opt_in) VALUES (?,?,?,?,"pending_verification",?)')->execute([$name,$email,$phone?:null,password_hash($password,PASSWORD_DEFAULT),!empty($payload['marketing_opt_in'])?1:0]);
            $accountId=(int)$pdo->lastInsertId();
        }
        $sent=$this->sendVerification($pdo,$accountId);
        return ['account_id'=>$accountId,'email'=>$email,'email_verification_required'=>true,'email_sent'=>$sent];
    }

    public function verifyEmail(PDO $pdo,string $token): array
    {
        $row=$this->consumeToken($pdo,$token,'verify_email');
        $pdo->prepare('UPDATE delivery_customer_accounts SET email_verified_at=COALESCE(email_verified_at,CURRENT_TIMESTAMP),status="active",updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['account_id']]);
        return $this->accountById($pdo,(int)$row['account_id']);
    }

    public function resendVerification(PDO $pdo,string $email): bool
    {
        $email=mb_strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return true;
        $q=$pdo->prepare('SELECT id,email_verified_at FROM delivery_customer_accounts WHERE email=? LIMIT 1');$q->execute([$email]);$row=$q->fetch();
        if(!$row||!empty($row['email_verified_at']))return true;
        $this->sendVerification($pdo,(int)$row['id']);
        return true;
    }

    public function login(PDO $pdo,array $payload,string $userAgent='',string $ip=''): array
    {
        $email=mb_strtolower(trim((string)($payload['email']??'')));$password=(string)($payload['password']??'');
        $q=$pdo->prepare('SELECT * FROM delivery_customer_accounts WHERE email=? LIMIT 1');$q->execute([$email]);$row=$q->fetch();
        if(!$row||!password_verify($password,(string)$row['password_hash']))throw new RuntimeException('E-mail ou senha inválidos.');
        if(empty($row['email_verified_at']))throw new RuntimeException('Confirme seu e-mail antes de entrar.');
        if((string)$row['status']!=='active')throw new RuntimeException('Esta conta não está disponível para acesso.');
        if(password_needs_rehash((string)$row['password_hash'],PASSWORD_DEFAULT))$pdo->prepare('UPDATE delivery_customer_accounts SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$row['id']]);
        $raw=$this->randomToken();$expires=gmdate('Y-m-d H:i:s',time()+60*60*24*30);$device=mb_substr(trim((string)($payload['device_name']??'')),0,160);
        $pdo->prepare('INSERT INTO delivery_customer_sessions (account_id,token_hash,device_name,user_agent,ip_hash,expires_at) VALUES (?,?,?,?,?,?)')->execute([(int)$row['id'],$this->hashToken($raw),$device?:null,mb_substr($userAgent,0,500)?:null,$ip!==''?hash('sha256',$ip):null,$expires]);
        return ['access_token'=>$raw,'token_type'=>'Bearer','expires_at'=>$expires,'customer'=>$this->safeAccount($row)];
    }

    public function authenticate(PDO $pdo,string $rawToken): array
    {
        $rawToken=trim($rawToken);if($rawToken==='')throw new RuntimeException('Entre na sua conta para continuar.');
        $q=$pdo->prepare("SELECT a.*,s.id session_id,s.expires_at FROM delivery_customer_sessions s JOIN delivery_customer_accounts a ON a.id=s.account_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>CURRENT_TIMESTAMP AND a.status='active' AND a.email_verified_at IS NOT NULL LIMIT 1");$q->execute([$this->hashToken($rawToken)]);$row=$q->fetch();
        if(!$row)throw new RuntimeException('Sua sessão expirou. Entre novamente.');
        $pdo->prepare('UPDATE delivery_customer_sessions SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['session_id']]);
        return $row;
    }

    public function logout(PDO $pdo,string $rawToken): void
    {
        if(trim($rawToken)==='')return;$pdo->prepare('UPDATE delivery_customer_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE token_hash=? AND revoked_at IS NULL')->execute([$this->hashToken($rawToken)]);
    }

    public function me(PDO $pdo,int $accountId): array
    {
        $account=$this->accountById($pdo,$accountId);
        $q=$pdo->prepare('SELECT * FROM delivery_customer_addresses WHERE account_id=? ORDER BY is_default DESC,id DESC');$q->execute([$accountId]);
        $account['addresses']=array_map([$this,'safeAddress'],$q->fetchAll());
        return $account;
    }

    public function updateProfile(PDO $pdo,int $accountId,array $payload): array
    {
        $name=mb_substr(trim((string)($payload['name']??'')),0,160);$phone=mb_substr(trim((string)($payload['phone']??'')),0,40);
        if($name==='')throw new RuntimeException('Informe seu nome.');
        $pdo->prepare('UPDATE delivery_customer_accounts SET name=?,phone=?,marketing_opt_in=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$name,$phone?:null,!empty($payload['marketing_opt_in'])?1:0,$accountId]);
        return $this->me($pdo,$accountId);
    }

    public function saveAddress(PDO $pdo,int $accountId,array $payload): array
    {
        $id=max(0,(int)($payload['id']??0));$label=mb_substr(trim((string)($payload['label']??'Casa')),0,80)?:'Casa';$street=mb_substr(trim((string)($payload['street']??'')),0,220);$number=mb_substr(trim((string)($payload['number']??'')),0,40);$city=mb_substr(trim((string)($payload['city']??'')),0,160);$state=mb_strtoupper(mb_substr(trim((string)($payload['state']??'')),0,2));
        if($street===''||$number===''||$city===''||strlen($state)!==2)throw new RuntimeException('Complete o endereço de entrega.');
        $isDefault=!empty($payload['is_default'])?1:0;if($isDefault)$pdo->prepare('UPDATE delivery_customer_addresses SET is_default=0 WHERE account_id=?')->execute([$accountId]);
        $values=[
            $label,mb_substr(trim((string)($payload['recipient_name']??'')),0,160)?:null,mb_substr(trim((string)($payload['phone']??'')),0,40)?:null,mb_substr(trim((string)($payload['postal_code']??'')),0,16)?:null,$street,$number,mb_substr(trim((string)($payload['complement']??'')),0,180)?:null,mb_substr(trim((string)($payload['neighborhood']??'')),0,160)?:null,$city,$state,mb_substr(trim((string)($payload['reference']??'')),0,300)?:null,$this->coordinate($payload['latitude']??null,-90,90),$this->coordinate($payload['longitude']??null,-180,180),$isDefault
        ];
        if($id>0){$check=$pdo->prepare('SELECT id FROM delivery_customer_addresses WHERE id=? AND account_id=?');$check->execute([$id,$accountId]);if(!$check->fetchColumn())throw new RuntimeException('Endereço não encontrado.');$pdo->prepare('UPDATE delivery_customer_addresses SET label=?,recipient_name=?,phone=?,postal_code=?,street=?,number=?,complement=?,neighborhood=?,city=?,state=?,reference=?,latitude=?,longitude=?,is_default=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND account_id=?')->execute([...$values,$id,$accountId]);}
        else{$pdo->prepare('INSERT INTO delivery_customer_addresses (account_id,label,recipient_name,phone,postal_code,street,number,complement,neighborhood,city,state,reference,latitude,longitude,is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$accountId,...$values]);$id=(int)$pdo->lastInsertId();if(!$isDefault){$count=$pdo->prepare('SELECT COUNT(*) FROM delivery_customer_addresses WHERE account_id=?');$count->execute([$accountId]);if((int)$count->fetchColumn()===1)$pdo->prepare('UPDATE delivery_customer_addresses SET is_default=1 WHERE id=?')->execute([$id]);}}
        $q=$pdo->prepare('SELECT * FROM delivery_customer_addresses WHERE id=? AND account_id=?');$q->execute([$id,$accountId]);return $this->safeAddress($q->fetch()?:[]);
    }

    public function deleteAddress(PDO $pdo,int $accountId,int $id): void
    {
        $q=$pdo->prepare('SELECT is_default FROM delivery_customer_addresses WHERE id=? AND account_id=?');$q->execute([$id,$accountId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Endereço não encontrado.');$pdo->prepare('DELETE FROM delivery_customer_addresses WHERE id=? AND account_id=?')->execute([$id,$accountId]);if((int)$row['is_default']===1)$pdo->prepare('UPDATE delivery_customer_addresses SET is_default=1 WHERE id=(SELECT id FROM delivery_customer_addresses WHERE account_id=? ORDER BY id DESC LIMIT 1)')->execute([$accountId]);
    }

    public function forgotPassword(PDO $pdo,string $email): void
    {
        $email=mb_strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return;$q=$pdo->prepare("SELECT id,name,email FROM delivery_customer_accounts WHERE email=? AND status<>'blocked' LIMIT 1");$q->execute([$email]);$row=$q->fetch();if(!$row)return;
        $raw=$this->issueToken($pdo,(int)$row['id'],'password_reset',30*60);$url=\app_absolute_url('delivery-reset-password.php?token='.rawurlencode($raw));$name=(string)$row['name'];$html='<h2>Redefinir senha</h2><p>Olá, '.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'.</p><p>Use o botão abaixo para criar uma nova senha do EventMenu Delivery.</p><p><a href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'" style="display:inline-block;padding:12px 18px;background:#d62828;color:#fff;text-decoration:none;border-radius:8px">Criar nova senha</a></p><p>O link expira em 30 minutos.</p>';
        (new SmtpMailerService())->send(null,$email,$name,'Redefinir senha · EventMenu Delivery',$html,'Redefina sua senha: '.$url);
    }

    public function resetPassword(PDO $pdo,string $token,string $password): void
    {
        if(strlen($password)<8)throw new RuntimeException('A senha precisa ter pelo menos 8 caracteres.');$row=$this->consumeToken($pdo,$token,'password_reset');$id=(int)$row['account_id'];$pdo->prepare('UPDATE delivery_customer_accounts SET password_hash=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);$pdo->prepare('UPDATE delivery_customer_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE account_id=? AND revoked_at IS NULL')->execute([$id]);
    }

    public function registerPushDevice(PDO $pdo,int $accountId,array $payload): void
    {
        $token=trim((string)($payload['push_token']??''));if($token==='')throw new RuntimeException('Token de notificação inválido.');$platform=mb_substr(strtolower(trim((string)($payload['platform']??'android'))),0,30)?:'android';$deviceHash=trim((string)($payload['device_id_hash']??''));
        $existing=$pdo->prepare('SELECT id FROM delivery_customer_push_devices WHERE push_token=? LIMIT 1');$existing->execute([$token]);$id=$existing->fetchColumn();if($id)$pdo->prepare('UPDATE delivery_customer_push_devices SET account_id=?,platform=?,device_id_hash=?,active=1,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$accountId,$platform,$deviceHash?:null,$id]);else$pdo->prepare('INSERT INTO delivery_customer_push_devices (account_id,platform,push_token,device_id_hash,active) VALUES (?,?,?,?,1)')->execute([$accountId,$platform,$token,$deviceHash?:null]);
    }

    private function sendVerification(PDO $pdo,int $accountId): bool
    {
        $account=$this->accountById($pdo,$accountId);$raw=$this->issueToken($pdo,$accountId,'verify_email',24*60*60);$url=\app_absolute_url('delivery-confirm-email.php?token='.rawurlencode($raw));$name=(string)$account['name'];$email=(string)$account['email'];$safeName=htmlspecialchars($name,ENT_QUOTES,'UTF-8');$safeUrl=htmlspecialchars($url,ENT_QUOTES,'UTF-8');$html='<h2>Confirme seu e-mail</h2><p>Olá, '.$safeName.'.</p><p>Confirme seu cadastro para entrar no EventMenu Delivery e fazer pedidos.</p><p><a href="'.$safeUrl.'" style="display:inline-block;padding:12px 18px;background:#d62828;color:#fff;text-decoration:none;border-radius:8px">Confirmar meu e-mail</a></p><p>Este link expira em 24 horas.</p>';
        try{(new SmtpMailerService())->send(null,$email,$name,'Confirme seu e-mail · EventMenu Delivery',$html,'Confirme seu e-mail: '.$url);return true;}catch(\Throwable $e){error_log('[eventmenu-delivery-email] '.$e::class.': '.$e->getMessage());return false;}
    }

    private function issueToken(PDO $pdo,int $accountId,string $purpose,int $ttl): string
    {
        $pdo->prepare('UPDATE delivery_customer_auth_tokens SET used_at=CURRENT_TIMESTAMP WHERE account_id=? AND purpose=? AND used_at IS NULL')->execute([$accountId,$purpose]);$raw=$this->randomToken();$pdo->prepare('INSERT INTO delivery_customer_auth_tokens (account_id,purpose,token_hash,expires_at) VALUES (?,?,?,?)')->execute([$accountId,$purpose,$this->hashToken($raw),gmdate('Y-m-d H:i:s',time()+$ttl)]);return $raw;
    }

    private function consumeToken(PDO $pdo,string $raw,string $purpose): array
    {
        $q=$pdo->prepare('SELECT * FROM delivery_customer_auth_tokens WHERE purpose=? AND token_hash=? AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP LIMIT 1');$q->execute([$purpose,$this->hashToken(trim($raw))]);$row=$q->fetch();if(!$row)throw new RuntimeException('Este link é inválido ou expirou.');$pdo->prepare('UPDATE delivery_customer_auth_tokens SET used_at=CURRENT_TIMESTAMP WHERE id=? AND used_at IS NULL')->execute([(int)$row['id']]);return $row;
    }

    private function accountById(PDO $pdo,int $id): array{$q=$pdo->prepare('SELECT * FROM delivery_customer_accounts WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('Conta não encontrada.');return $this->safeAccount($row);}
    private function safeAccount(array $row): array{return ['id'=>(int)$row['id'],'name'=>(string)$row['name'],'email'=>(string)$row['email'],'phone'=>(string)($row['phone']??''),'email_verified'=>!empty($row['email_verified_at']),'email_verified_at'=>$row['email_verified_at']??null,'status'=>(string)$row['status'],'marketing_opt_in'=>(bool)($row['marketing_opt_in']??0)];}
    private function safeAddress(array $row): array{return ['id'=>(int)($row['id']??0),'label'=>(string)($row['label']??''),'recipient_name'=>(string)($row['recipient_name']??''),'phone'=>(string)($row['phone']??''),'postal_code'=>(string)($row['postal_code']??''),'street'=>(string)($row['street']??''),'number'=>(string)($row['number']??''),'complement'=>(string)($row['complement']??''),'neighborhood'=>(string)($row['neighborhood']??''),'city'=>(string)($row['city']??''),'state'=>(string)($row['state']??''),'reference'=>(string)($row['reference']??''),'latitude'=>$row['latitude']!==null?(float)$row['latitude']:null,'longitude'=>$row['longitude']!==null?(float)$row['longitude']:null,'is_default'=>(bool)($row['is_default']??0)];}
    private function coordinate(mixed $value,float $min,float $max): ?float{if($value===null||$value==='')return null;if(!is_numeric($value))throw new RuntimeException('Localização inválida.');$v=(float)$value;if(!is_finite($v)||$v<$min||$v>$max)throw new RuntimeException('Localização inválida.');return $v;}
    private function randomToken(): string{return bin2hex(random_bytes(32));}
    private function hashToken(string $raw): string{return hash('sha256',$raw);}
}
