<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use PDO;
use RuntimeException;

final class PasswordResetService
{
    public function request(string $email):void
    {
        $email=mb_strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return;$pdo=Database::connection();$s=$pdo->prepare('SELECT id,name,email,status FROM users WHERE email=? LIMIT 1');$s->execute([$email]);$u=$s->fetch();if(!$u||$u['status']!=='active')return;$raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=gmdate('Y-m-d H:i:s',time()+1800);
        Database::transaction(function(PDO $db)use($u,$hash,$expires){$db->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$u['id']]);$db->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,requested_ip) VALUES (?,?,?,?)')->execute([$u['id'],$hash,$expires,Security::clientIp()]);});
        $base=rtrim((string)env('APP_URL',''),'/');if($base==='')$base=(isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST']??'localhost').rtrim(dirname((string)($_SERVER['SCRIPT_NAME']??'/')),'/');$url=$base.'/?route=reset-password&token='.urlencode($raw);$body="Olá, ".($u['name']?:'usuário').".\n\nUse o link abaixo para redefinir sua senha do EventMenu Premium. O link expira em 30 minutos e só pode ser usado uma vez.\n\n{$url}\n\nSe você não solicitou esta alteração, ignore esta mensagem.";(new MailQueueService())->queue((string)$u['email'],'Redefinição de senha · EventMenu Premium',$body,'password-reset|'.$u['id'].'|'.$hash);
    }

    public function apply(string $rawToken,string $newPassword):void
    {
        if(strlen($newPassword)<10)throw new RuntimeException('Use uma senha com pelo menos 10 caracteres.');$hash=hash('sha256',trim($rawToken));Database::transaction(function(PDO $pdo)use($hash,$newPassword){$s=$pdo->prepare('SELECT prt.*,u.tenant_id,u.status user_status FROM password_reset_tokens prt JOIN users u ON u.id=prt.user_id WHERE prt.token_hash=? AND prt.used_at IS NULL AND prt.expires_at>UTC_TIMESTAMP() LIMIT 1 FOR UPDATE');$s->execute([$hash]);$row=$s->fetch();if(!$row||$row['user_status']!=='active')throw new RuntimeException('Link inválido, expirado ou já utilizado.');$uid=(int)$row['user_id'];$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),$uid]);$pdo->prepare('UPDATE password_reset_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL')->execute([$uid]);$pdo->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=UTC_TIMESTAMP(),revocation_reason="password_reset" WHERE user_id=? AND status="active"')->execute([$uid]);Auth::audit('password.reset.completed','user',(string)$uid);});
    }
}
