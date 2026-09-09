<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppCloudService;
use EventMenu\Services\WhatsAppSettingsService;

Auth::requirePermission('settings.manage');
$tenantId=em_require_tenant();$service=new WhatsAppSettingsService();$settings=$service->get($tenantId);

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'save');
    try{
        if($action==='save'){
            $settings=$service->save($tenantId,[
                'enabled'=>isset($_POST['enabled']),'phone_number_id'=>$_POST['phone_number_id']??'',
                'access_token'=>$_POST['access_token']??'','graph_version'=>$_POST['graph_version']??'v25.0',
                'confirmation_template'=>$_POST['confirmation_template']??'eventmenu_order_confirmation',
                'tracking_template'=>$_POST['tracking_template']??'eventmenu_delivery_tracking','language_code'=>$_POST['language_code']??'pt_BR',
            ]);
            Auth::audit('whatsapp.settings_saved','whatsapp_settings',$service->scopeKey($tenantId),['enabled'=>$settings['enabled'],'phone_number_id'=>$settings['phone_number_id'],'graph_version'=>$settings['graph_version']]);
            em_flash('ok','Configuração do WhatsApp salva.');em_go('whatsapp-settings');
        }
        if($action==='test'){
            $phone=(string)($_POST['test_phone']??'');if(trim($phone)==='')throw new RuntimeException('Informe um número para o teste.');
            $effective=$service->effective($tenantId);if(!$effective)throw new RuntimeException('Ative e salve o WhatsApp antes do teste.');
            (new WhatsAppCloudService())->sendTemplate($tenantId,$phone,(string)$effective['confirmation_template'],(string)$effective['language_code'],['Teste EventMenu','0',rtrim((string)env('APP_URL',''),'/')]);
            Auth::audit('whatsapp.test_sent','whatsapp_settings',$service->scopeKey($tenantId),[]);em_flash('ok','Mensagem de teste enviada para o WhatsApp informado.');em_go('whatsapp-settings');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('whatsapp-settings');}
}

em_header('WhatsApp da empresa','whatsapp-settings');
?>
<section class="page-hero"><div><span class="eyebrow">WHATSAPP CLOUD API</span><h2>Confirmações e acompanhamento automáticos</h2><p>Quando o cliente tiver telefone, o EventMenu pode enviar confirmação do pedido e, no delivery, o link privado de rastreamento assim que a rota começar.</p></div><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=settings')) ?>">Voltar</a></div></section>
<div class="settings-layout"><div class="settings-main">
<section class="card"><div class="section-head"><div><span class="eyebrow">META</span><h2>Conexão oficial</h2></div><span class="status-pill <?= !empty($settings['enabled'])?'active':'' ?>"><?= !empty($settings['enabled'])?'Ativo':'Desativado' ?></span></div>
<form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save">
<label class="checkbox span-2"><input type="checkbox" name="enabled"<?= em_checked($settings['enabled']) ?>> Ativar mensagens automáticas por WhatsApp</label>
<label class="span-2">Phone Number ID<input name="phone_number_id" inputmode="numeric" value="<?= Security::e($settings['phone_number_id']) ?>" placeholder="ID numérico fornecido pela Meta"></label>
<label class="span-2">Access token permanente<input type="password" name="access_token" autocomplete="new-password" placeholder="<?= !empty($settings['has_access_token'])?'Token já salvo — deixe vazio para manter':'Cole o token permanente' ?>"><small>O token é criptografado com a APP_KEY e nunca volta a ser exibido.</small></label>
<label>Graph API<select name="graph_version"><?php foreach(['v25.0','v24.0','v23.0'] as$v):?><option value="<?= $v ?>"<?= em_selected($settings['graph_version'],$v) ?>><?= $v ?></option><?php endforeach;?></select></label>
<label>Idioma<input name="language_code" value="<?= Security::e($settings['language_code']) ?>" placeholder="pt_BR"></label>
<label class="span-2">Template — confirmação do pedido<input name="confirmation_template" value="<?= Security::e($settings['confirmation_template']) ?>" placeholder="eventmenu_order_confirmation"></label>
<label class="span-2">Template — saiu para entrega<input name="tracking_template" value="<?= Security::e($settings['tracking_template']) ?>" placeholder="eventmenu_delivery_tracking"></label>
<div class="span-2 actions"><button class="primary" type="submit">Salvar WhatsApp</button></div></form></section>
<section class="card"><div class="section-head"><div><span class="eyebrow">TESTE</span><h2>Enviar mensagem de teste</h2></div></div><p class="muted">O template de confirmação precisa estar aprovado na Meta antes do teste.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="test"><label class="span-2">WhatsApp de destino<input name="test_phone" inputmode="tel" placeholder="(22) 99999-9999"></label><div class="span-2 actions"><button class="secondary" type="submit"<?= empty($settings['enabled'])?' disabled':'' ?>>Enviar teste</button></div></form></section>
</div><aside class="settings-side">
<section class="card"><span class="eyebrow">TEMPLATES</span><h3>3 variáveis no corpo</h3><p class="muted">Crie e aprove dois templates na Meta. Os dois devem receber, nesta ordem: <strong>1. nome do cliente</strong>, <strong>2. número do pedido</strong> e <strong>3. link privado</strong>.</p></section>
<section class="card"><span class="eyebrow">AUTOMAÇÃO</span><h3>Sem travar o caixa</h3><p class="muted">As mensagens são enviadas pela fila do sistema. Falha do WhatsApp ou do e-mail nunca impede a criação do pedido.</p></section>
<section class="card"><span class="eyebrow">PRIVACIDADE</span><h3>Destino mascarado</h3><p class="muted">O histórico técnico guarda apenas hash e uma versão mascarada do destino. Tokens e links privados não são gravados em texto aberto no log de comunicação.</p></section>
</aside></div>
<?php em_footer(); ?>
