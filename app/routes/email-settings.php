<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\MailSettingsService;
use EventMenu\Services\SmtpMailerService;

Auth::requirePermission('settings.manage');
$scopeTenantId = Auth::tenantId();
$service = new MailSettingsService();
$settings = $service->get($scopeTenantId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $settings = $service->save($scopeTenantId, [
                'enabled'=>isset($_POST['enabled']),
                'host'=>$_POST['host']??'',
                'port'=>$_POST['port']??587,
                'encryption'=>$_POST['encryption']??'tls',
                'username'=>$_POST['username']??'',
                'password'=>$_POST['password']??'',
                'from_email'=>$_POST['from_email']??'',
                'from_name'=>$_POST['from_name']??'',
                'reply_to_email'=>$_POST['reply_to_email']??'',
            ]);
            Auth::audit('mail.settings_saved','mail_settings',$service->scopeKey($scopeTenantId),['enabled'=>$settings['enabled'],'host'=>$settings['host'],'port'=>$settings['port'],'encryption'=>$settings['encryption']]);
            em_flash('ok','Configuração de e-mail salva com segurança.');
            em_go('email-settings');
        }
        if ($action === 'test') {
            $u=$pdo->prepare('SELECT email,name FROM users WHERE id=? LIMIT 1');$u->execute([Auth::id()]);$user=$u->fetch();
            if(!$user||!filter_var((string)$user['email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Seu usuário não possui um e-mail válido para o teste.');
            $html='<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto"><h2>Teste de e-mail concluído</h2><p>Esta mensagem confirma que o EventMenu conseguiu autenticar e enviar pelo SMTP configurado.</p><p><strong>Empresa/escopo:</strong> '.Security::e($scopeTenantId?'Empresa '.$scopeTenantId:'Plataforma').'</p></div>';
            (new SmtpMailerService())->send($scopeTenantId,(string)$user['email'],(string)$user['name'],'Teste de e-mail — EventMenu',$html,'Teste de e-mail do EventMenu concluído com sucesso.');
            Auth::audit('mail.test_sent','mail_settings',$service->scopeKey($scopeTenantId),['recipient'=>(string)$user['email']]);
            em_flash('ok','E-mail de teste enviado para '.(string)$user['email'].'.');
            em_go('email-settings');
        }
    } catch (Throwable $e) {
        em_flash('error',$e->getMessage());
        em_go('email-settings');
    }
}

em_header($scopeTenantId?'E-mail da empresa':'E-mail da plataforma','email-settings');
?>
<section class="page-hero">
  <div><span class="eyebrow">SMTP</span><h2><?= $scopeTenantId?'Envio de e-mail da empresa':'E-mail padrão da plataforma' ?></h2><p><?= $scopeTenantId?'Usado para recuperação de senha e futuros avisos por e-mail. Se estiver desativado, o EventMenu pode usar o SMTP padrão da plataforma quando existir.':'Este SMTP funciona como padrão para a plataforma e como fallback das empresas que não configurarem um servidor próprio.' ?></p></div>
  <?php if($scopeTenantId):?><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=settings')) ?>">Voltar às configurações</a></div><?php endif;?>
</section>

<div class="settings-layout">
<div class="settings-main">
<section class="card">
  <div class="section-head"><div><span class="eyebrow">CONEXÃO</span><h2>Servidor SMTP</h2></div><span class="status-pill <?= !empty($settings['enabled'])?'active':'' ?>"><?= !empty($settings['enabled'])?'Ativo':'Desativado' ?></span></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save">
    <label class="checkbox span-2"><input type="checkbox" name="enabled"<?= em_checked($settings['enabled']) ?>> Ativar envio por e-mail</label>
    <label class="span-2">Servidor SMTP<input name="host" value="<?= Security::e($settings['host']) ?>" placeholder="smtp.seudominio.com"></label>
    <label>Porta<input type="number" min="1" max="65535" name="port" value="<?= (int)$settings['port'] ?>"></label>
    <label>Segurança<select name="encryption"><option value="tls"<?= em_selected($settings['encryption'],'tls') ?>>STARTTLS / TLS (recomendado)</option><option value="ssl"<?= em_selected($settings['encryption'],'ssl') ?>>SSL direto</option><option value="none"<?= em_selected($settings['encryption'],'none') ?>>Sem criptografia</option></select></label>
    <label class="span-2">Usuário SMTP<input name="username" autocomplete="username" value="<?= Security::e($settings['username']) ?>" placeholder="contato@seudominio.com"></label>
    <label class="span-2">Senha SMTP<input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($settings['has_password'])?'Senha já salva — deixe vazio para manter':'Informe a senha do SMTP' ?>"><small><?= !empty($settings['has_password'])?'A senha está criptografada no banco e nunca é exibida novamente.':'A senha será criptografada com a APP_KEY antes de ser armazenada.' ?></small></label>
    <label>E-mail remetente<input type="email" name="from_email" value="<?= Security::e($settings['from_email']) ?>" placeholder="nao-responda@seudominio.com"></label>
    <label>Nome remetente<input name="from_name" value="<?= Security::e($settings['from_name']) ?>" placeholder="Minha Empresa"></label>
    <label class="span-2">Responder para (opcional)<input type="email" name="reply_to_email" value="<?= Security::e($settings['reply_to_email']) ?>" placeholder="atendimento@seudominio.com"></label>
    <div class="span-2 actions"><button class="primary" type="submit">Salvar configuração</button></div>
  </form>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">VALIDAÇÃO</span><h2>Testar envio</h2></div></div>
  <p class="muted">Salve primeiro. Depois o sistema tentará enviar uma mensagem para o e-mail do usuário que está logado.</p>
  <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="test"><button class="secondary" type="submit"<?= empty($settings['enabled'])?' disabled':'' ?>>Enviar e-mail de teste</button></form>
</section>
</div>
<aside class="settings-side">
<section class="card"><span class="eyebrow">PORTAS COMUNS</span><h3>Configuração rápida</h3><p class="muted"><strong>587 + TLS</strong> é o padrão recomendado. Alguns provedores usam <strong>465 + SSL</strong>. Use “sem criptografia” apenas em rede controlada.</p></section>
<section class="card"><span class="eyebrow">SEGURANÇA</span><h3>Senha protegida</h3><p class="muted">A senha SMTP é armazenada criptografada por AES-256-GCM usando a APP_KEY da instalação. Ela não aparece no formulário depois de salva.</p></section>
</aside>
</div>
<?php em_footer(); ?>
