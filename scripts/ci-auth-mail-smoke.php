<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\MailSettingsService;
use EventMenu\Services\OnboardingService;
use EventMenu\Services\PasswordResetService;

function auth_mail_fail(string $message): never {fwrite(STDERR,"AUTH/MAIL CI FAIL: {$message}\n");exit(1);}
function auth_mail_assert(bool $condition,string $message):void{if(!$condition)auth_mail_fail($message);}

try{
    $pdo=Database::connection();$driver=Database::driver($pdo);
    foreach(['mail_settings','password_reset_tokens'] as$table){try{$pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');}catch(Throwable$e){auth_mail_fail("Tabela {$table} ausente: ".$e->getMessage());}}

    $row=$pdo->query('SELECT u.id user_id,u.tenant_id,u.email FROM users u WHERE u.tenant_id IS NOT NULL ORDER BY u.id LIMIT 1')->fetch();
    auth_mail_assert(is_array($row),'Usuário tenant para teste não encontrado.');
    $userId=(int)$row['user_id'];$tenantId=(int)$row['tenant_id'];

    $mail=new MailSettingsService();
    $saved=$mail->save($tenantId,[
        'enabled'=>true,'host'=>'smtp.example.test','port'=>587,'encryption'=>'tls','username'=>'ci@example.test','password'=>'ci-secret-password','from_email'=>'ci@example.test','from_name'=>'EventMenu CI','reply_to_email'=>'reply@example.test',
    ]);
    auth_mail_assert($saved['enabled']===true,'SMTP não permaneceu ativo.');
    auth_mail_assert($saved['has_password']===true,'Senha SMTP não foi armazenada.');
    auth_mail_assert(!array_key_exists('password',$saved),'Senha SMTP vazou pela API pública de configuração.');
    $effective=$mail->effective($tenantId);
    auth_mail_assert(is_array($effective)&&($effective['password']??'')==='ci-secret-password','Senha SMTP criptografada não pôde ser recuperada internamente.');
    $mail->save($tenantId,[
        'enabled'=>true,'host'=>'smtp.example.test','port'=>465,'encryption'=>'ssl','username'=>'ci@example.test','password'=>'','from_email'=>'ci@example.test','from_name'=>'EventMenu CI','reply_to_email'=>'',
    ]);
    $effective2=$mail->effective($tenantId);
    auth_mail_assert(is_array($effective2)&&($effective2['password']??'')==='ci-secret-password','Senha SMTP foi apagada ao salvar campo vazio.');

    $slug='ci-onboarding-'.bin2hex(random_bytes(4));
    $settings=json_encode(['onboarding_required'=>true],JSON_UNESCAPED_UNICODE);
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['Onboarding CI',$slug,$settings]);
    $onboardingTenant=(int)$pdo->lastInsertId();
    $onboarding=new OnboardingService();
    auth_mail_assert($onboarding->shouldPrompt($onboardingTenant),'Empresa nova não entrou no onboarding.');
    $onboarding->complete($onboardingTenant);
    auth_mail_assert(!$onboarding->shouldPrompt($onboardingTenant),'Onboarding continuou obrigatório após conclusão.');

    $token=bin2hex(random_bytes(32));$tokenHash=hash('sha256',$token);$expires=gmdate('Y-m-d H:i:s',time()+1800);
    $pdo->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at,request_ip) VALUES (?,?,?,?)')->execute([$userId,$tokenHash,$expires,'127.0.0.1']);
    $reset=new PasswordResetService();
    $valid=$reset->validate($token);auth_mail_assert(is_array($valid)&&(int)$valid['id']===$userId,'Token válido de redefinição não foi reconhecido.');
    $newPassword='CiResetPassword!2026';$reset->reset($token,$newPassword);
    $q=$pdo->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$userId]);auth_mail_assert(password_verify($newPassword,(string)$q->fetchColumn()),'Senha não foi atualizada pelo reset.');
    auth_mail_assert($reset->validate($token)===null,'Token de redefinição pôde ser reutilizado.');

    echo "Auth/mail/onboarding smoke OK ({$driver})\n";
}catch(Throwable$e){auth_mail_fail($e->getMessage()."\n".$e->getTraceAsString());}
