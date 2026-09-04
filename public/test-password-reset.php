<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;

$root=dirname(__DIR__);
$storage=$root.'/storage';
$installed=$storage.'/installed.lock';
$codeFile=$storage.'/test-recovery-code.txt';
$isTest=strtolower((string)env('APP_ENV',''))==='testing'&&strtolower((string)env('DB_DRIVER',''))==='sqlite';

if(!$isTest){http_response_code(403);exit('Esta ferramenta só funciona em instalações SQLite de teste.');}
if(!is_file($installed)){http_response_code(403);exit('O EventMenu ainda não está instalado.');}
if(!is_dir($storage)||!is_writable($storage)){http_response_code(500);exit('A pasta storage precisa ter permissão de escrita.');}

if(!is_file($codeFile)){
    $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code='';for($i=0;$i<12;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];
    if(file_put_contents($codeFile,$code."\n",LOCK_EX)===false){http_response_code(500);exit('Não foi possível criar o código de recuperação em storage.');}
    @chmod($codeFile,0600);
}

$error=null;$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada. Atualize a página.';
    else{
        try{
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $code=strtoupper(trim((string)($_POST['recovery_code']??'')));
            $password=(string)($_POST['password']??'');
            $confirm=(string)($_POST['password_confirm']??'');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe o e-mail do administrador.');
            if(strlen($password)<10)throw new RuntimeException('A nova senha precisa ter pelo menos 10 caracteres.');
            if($password!==$confirm)throw new RuntimeException('As duas senhas não são iguais.');
            $expected=strtoupper(trim((string)file_get_contents($codeFile)));
            if($expected===''||!hash_equals($expected,$code))throw new RuntimeException('Código de recuperação inválido.');

            $pdo=Database::connection();
            $stmt=$pdo->prepare('SELECT u.id,u.tenant_id FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.role="admin" AND u.status="active" AND t.status="active" LIMIT 1');
            $stmt->execute([$email]);$user=$stmt->fetch();
            if(!$user)throw new RuntimeException('Administrador não encontrado com esse e-mail.');

            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
            try{$pdo->exec('DELETE FROM login_throttles');}catch(Throwable){}
            try{$pdo->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=NOW(),revocation_reason="password_reset_test" WHERE user_id=? AND status="active"')->execute([(int)$user['id']]);}catch(Throwable){}
            try{$pdo->prepare('INSERT INTO audit_logs (tenant_id,user_id,action,entity_type,entity_id,ip_address,user_agent,metadata) VALUES (?,NULL,"auth.test_password_reset","user",?,?,?,?)')->execute([(int)$user['tenant_id'],(string)$user['id'],Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),json_encode(['mode'=>'sqlite_test'],JSON_UNESCAPED_UNICODE)]);}catch(Throwable){}
            $pdo->commit();
            @unlink($codeFile);
            $success=true;
        }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
    }
}

$configured=(string)env('APP_URL','');$base=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';$base=rtrim($base,'/');
function tr_url(string $path):string{global $base;if(!str_starts_with($path,'/'))$path='/'.$path;return$base.$path;}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Redefinir senha de teste — EventMenu</title><link rel="stylesheet" href="<?= Security::e(tr_url('/assets/app.css')) ?>"></head><body class="auth-page"><main class="auth-card" style="max-width:560px"><div class="brand">EventMenu <span>Premium</span></div><h1>Redefinir senha do teste</h1><?php if($success):?><div class="alert ok"><strong>Senha alterada com sucesso.</strong><br>O código de recuperação foi invalidado.</div><p><a class="button primary" href="<?= Security::e(tr_url('/?route=login')) ?>">Entrar no sistema</a></p><?php else:?><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><div class="alert">Abra no cPanel o arquivo <code>storage/test-recovery-code.txt</code> e copie o código. Esta ferramenta existe somente no modo SQLite de teste.</div><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail do administrador<input type="email" name="email" required autofocus autocomplete="username"></label><label>Código de recuperação<input name="recovery_code" required maxlength="20" autocomplete="one-time-code" style="text-transform:uppercase"></label><label>Nova senha <small>(mínimo 10 caracteres)</small><input type="password" id="newPassword" name="password" minlength="10" required autocomplete="new-password"></label><label>Confirmar nova senha<input type="password" id="confirmPassword" name="password_confirm" minlength="10" required autocomplete="new-password"></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" id="showPasswords" style="width:auto"> Mostrar senhas</label><button class="primary" type="submit">Salvar nova senha</button></form><p class="muted">Depois de uma troca bem-sucedida, o arquivo de código é apagado automaticamente.</p><?php endif;?></main><script>document.getElementById('showPasswords')?.addEventListener('change',function(){for(const id of ['newPassword','confirmPassword']){const el=document.getElementById(id);if(el)el.type=this.checked?'text':'password';}});</script></body></html>
