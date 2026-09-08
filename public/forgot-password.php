<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Security;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\PasswordResetService;

$message=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada. Atualize a página e tente novamente.';
    else{
        try{(new PasswordResetService())->request((string)($_POST['email']??''));$message='Se este e-mail estiver cadastrado e o envio estiver configurado, você receberá um link válido por 30 minutos.';}
        catch(ApiRateLimitExceededException){$error='Foram feitas muitas solicitações. Aguarde alguns minutos antes de tentar novamente.';}
        catch(Throwable){$message='Se este e-mail estiver cadastrado e o envio estiver configurado, você receberá um link válido por 30 minutos.';}
    }
}
$css=Security::e(app_url('assets/auth-v2.css'));
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#5b34d6"><title>Recuperar senha — EventMenu</title><link rel="stylesheet" href="<?= $css ?>"></head><body class="auth-v2"><div class="auth-shell"><section class="auth-showcase"><div class="auth-brandline"><span class="auth-brandmark">E</span><div>EventMenu<small>Gestão conectada</small></div></div><div class="auth-pitch"><span class="auth-kicker">ACESSO SEGURO</span><h1>Recupere seu acesso sem depender de suporte.</h1><p>O link de redefinição é temporário, funciona uma única vez e invalida os acessos móveis antigos depois da troca de senha.</p></div><div class="auth-showcase-foot">EventMenu Premium · segurança por padrão</div></section><main class="auth-main"><section class="auth-panel"><div class="auth-mobile-brand"><span class="auth-brandmark">E</span> EventMenu</div><a class="auth-back" href="<?= Security::e(app_url('?route=login')) ?>">← Voltar para o login</a><div class="auth-panel-head"><h2>Esqueci minha senha</h2><p>Informe o mesmo e-mail usado para entrar no sistema.</p></div><?php if($error):?><div class="auth-alert error"><?= Security::e($error) ?></div><?php endif;?><?php if($message):?><div class="auth-alert ok"><?= Security::e($message) ?></div><?php endif;?><form method="post" class="auth-form" data-auth-form><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label class="auth-field"><span>E-mail</span><span class="auth-input-wrap"><input type="email" name="email" autocomplete="email" required autofocus placeholder="voce@empresa.com"></span></label><button class="auth-submit" type="submit"><span class="label">Enviar link de recuperação</span><span class="busy">Enviando…</span></button></form><div class="auth-help"><strong>Não recebeu?</strong> Confira spam e lixo eletrônico. Se sua empresa ainda não configurou SMTP, um administrador precisa fazer isso em Configurações → E-mail.</div></section></main></div><script>document.querySelector('[data-auth-form]')?.addEventListener('submit',e=>{const b=e.currentTarget.querySelector('.auth-submit');if(b){b.disabled=true;b.classList.add('is-loading')}});</script></body></html>
