<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/admin_helpers.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

if(!Auth::check())app_redirect('?route=login');
Auth::enforceCurrentUser();
Auth::requirePermission('settings.manage');
$tenantId=em_require_tenant();
$pdo=Database::connection();

$t=$pdo->prepare('SELECT name,slug,settings FROM tenants WHERE id=? LIMIT 1');$t->execute([$tenantId]);$tenant=$t->fetch();if(!$tenant)exit('Empresa não encontrada.');
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];
$g=$pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id=? AND provider='mercadopago' LIMIT 1");$g->execute([$tenantId]);$gateway=$g->fetch();$mp=$gateway?Crypto::decryptJson((string)$gateway['config_encrypted']):[];$hasWebhookSecret=$gateway&&!empty($gateway['webhook_secret_encrypted']);

$moneyToCents=static function(mixed$value):int{$raw=trim((string)$value);if($raw==='')return 0;$raw=str_replace(['R$',' '],'',$raw);if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);return max(0,(int)round((float)$raw*100));};
$discoverMercadoPagoAccount=static function(string$accessToken):string{
    if($accessToken==='')return'';$ch=curl_init('https://api.mercadopago.com/users/me');if($ch===false)return'';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($raw===false||$status<200||$status>=300)return'';$data=json_decode((string)$raw,true);$id=is_array($data)?trim((string)($data['id']??'')):'';return preg_match('/^\d+$/',$id)?$id:'';
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null)){em_flash('error','Sessão expirada. Atualize a página.');app_redirect('delivery-settings.php');}
    try{
        $settings['delivery_paused']=!empty($_POST['delivery_paused']);
        $settings['delivery_pickup_enabled']=!empty($_POST['delivery_pickup_enabled']);
        $settings['delivery_cash_enabled']=!empty($_POST['delivery_cash_enabled']);
        $settings['delivery_fee_cents']=$moneyToCents($_POST['delivery_fee']??0);
        $settings['min_delivery_order_cents']=$moneyToCents($_POST['minimum_order']??0);
        $settings['delivery_radius_km']=max(0,min(200,(float)str_replace(',','.',(string)($_POST['delivery_radius_km']??0))));
        $settings['delivery_eta_minutes']=max(5,min(240,(int)($_POST['delivery_eta_minutes']??45)));
        $lat=trim((string)($_POST['delivery_origin_latitude']??''));$lng=trim((string)($_POST['delivery_origin_longitude']??''));
        $settings['delivery_origin_latitude']=$lat!==''?(float)$lat:null;$settings['delivery_origin_longitude']=$lng!==''?(float)$lng:null;
        $settings['delivery_schedule_note']=mb_substr(trim((string)($_POST['delivery_schedule_note']??'')),0,500);
        $pdo->prepare('UPDATE tenants SET settings=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);

        $accessInput=trim((string)($_POST['mp_access_token']??''));$access=$accessInput!==''?$accessInput:(string)($mp['access_token']??'');
        $public=trim((string)($_POST['mp_public_key']??''));if($public==='')$public=(string)($mp['public_key']??'');
        $clientId=trim((string)($_POST['mp_client_id']??''));if($clientId==='')$clientId=(string)($mp['client_id']??'');
        $clientSecretInput=trim((string)($_POST['mp_client_secret']??''));$clientSecret=$clientSecretInput!==''?$clientSecretInput:(string)($mp['client_secret']??'');
        $webhookSecret=trim((string)($_POST['mp_webhook_secret']??''));$existingWebhookEncrypted=(string)($gateway['webhook_secret_encrypted']??'');$webhookEncrypted=$existingWebhookEncrypted;if($webhookSecret!=='')$webhookEncrypted=Crypto::encrypt($webhookSecret);

        $pixEnabled=!empty($_POST['mp_pix_enabled']);$creditEnabled=!empty($_POST['mp_credit_enabled']);$debitEnabled=!empty($_POST['mp_debit_enabled']);$cardEnabled=$creditEnabled||$debitEnabled;$activeRequested=!empty($_POST['mp_active']);$maxInstallments=max(1,min(12,(int)($_POST['mp_max_installments']??12)));
        if($cardEnabled&&$public==='')throw new RuntimeException('Informe a Public Key para aceitar cartão de crédito ou débito no aplicativo.');
        if(($activeRequested||$pixEnabled||$cardEnabled)&&$access==='')throw new RuntimeException('Informe o Access Token do Mercado Pago.');

        $account=trim((string)($gateway['account_reference']??''));
        if($access!==''){
            $discovered=$discoverMercadoPagoAccount($access);
            if($discovered!=='')$account=$discovered;
            elseif($accessInput!==''&&($activeRequested||$pixEnabled||$cardEnabled))throw new RuntimeException('Não foi possível validar o Access Token nem identificar automaticamente a conta Mercado Pago.');
        }
        if($activeRequested&&$account==='')throw new RuntimeException('Não foi possível identificar automaticamente a conta recebedora do Mercado Pago. Confira o Access Token.');

        $mp['public_key']=$public;$mp['access_token']=$access;$mp['client_id']=$clientId;$mp['client_secret']=$clientSecret;$mp['pix_enabled']=$pixEnabled;$mp['card_enabled']=$cardEnabled;$mp['credit_enabled']=$creditEnabled;$mp['debit_enabled']=$debitEnabled;$mp['max_installments']=$maxInstallments;
        $encrypted=Crypto::encryptJson($mp);$active=($activeRequested&&$access!==''&&$account!=='')?1:0;
        if($gateway)$pdo->prepare('UPDATE payment_gateways SET account_reference=?,config_encrypted=?,webhook_secret_encrypted=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$account?:null,$encrypted,$webhookEncrypted?:null,$active,(int)$gateway['id'],$tenantId]);
        else{$pdo->prepare("INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,'mercadopago',?,?,?,?)")->execute([$tenantId,$account?:null,$encrypted,$webhookEncrypted?:null,$active]);$gateway=['id'=>(int)$pdo->lastInsertId(),'account_reference'=>$account,'active'=>$active,'webhook_secret_encrypted'=>$webhookEncrypted];}
        $gateway['account_reference']=$account;$gateway['active']=$active;$gateway['webhook_secret_encrypted']=$webhookEncrypted;$hasWebhookSecret=$webhookEncrypted!=='';
        Auth::audit('delivery.settings_saved','tenant',(string)$tenantId,['delivery_paused'=>$settings['delivery_paused'],'cash'=>$settings['delivery_cash_enabled'],'pix'=>$mp['pix_enabled'],'card'=>$mp['card_enabled'],'credit'=>$mp['credit_enabled'],'debit'=>$mp['debit_enabled'],'mercadopago_account'=>$account!==''?'configured':'missing','client_id'=>$clientId!==''?'configured':'missing','client_secret'=>$clientSecret!==''?'configured':'missing','webhook_secret'=>$hasWebhookSecret?'configured':'missing']);
        em_flash('ok','Configurações do EventMenu Delivery salvas.');app_redirect('delivery-settings.php');
    }catch(Throwable$e){em_flash('error',$e->getMessage());app_redirect('delivery-settings.php');}
}

$creditChecked=!array_key_exists('credit_enabled',$mp)||!empty($mp['credit_enabled']);$debitChecked=!array_key_exists('debit_enabled',$mp)||!empty($mp['debit_enabled']);$connectedAccount=trim((string)($gateway['account_reference']??''));
em_header('Configurações do Delivery','delivery');
?>
<section class="page-hero"><div><span class="eyebrow">EVENTMENU DELIVERY</span><h2>Loja, entrega e pagamento</h2><p>Controle o que o cliente vê no app e quais pagamentos pode usar sem sair do EventMenu.</p></div><div class="hero-actions"><a class="button secondary" href="<?=Security::e(app_url('?route=delivery'))?>">Voltar ao Delivery</a><a class="button secondary" href="<?=Security::e(app_url('bank-pix-settings.php'))?>">Pix Efí / Inter</a></div></section>
<?php em_flash_render(); ?>
<form method="post" class="grid" style="gap:16px"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrfToken())?>">
<section class="card"><div class="section-head"><div><span class="eyebrow">OPERAÇÃO</span><h3>Entrega da <?=Security::e((string)$tenant['name'])?></h3></div></div>
<label><input type="checkbox" name="delivery_paused" value="1" <?=$settings['delivery_paused']??false?'checked':''?>> Pausar novos pedidos do aplicativo</label>
<label><input type="checkbox" name="delivery_pickup_enabled" value="1" <?=!empty($settings['delivery_pickup_enabled'])?'checked':''?>> Permitir retirada no local</label>
<label><input type="checkbox" name="delivery_cash_enabled" value="1" <?=!empty($settings['delivery_cash_enabled'])?'checked':''?>> Aceitar dinheiro na entrega</label>
<div class="form-grid"><label>Taxa de entrega (R$)<input name="delivery_fee" inputmode="decimal" value="<?=Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','.'))?>"></label><label>Pedido mínimo (R$)<input name="minimum_order" inputmode="decimal" value="<?=Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','.'))?>"></label><label>Raio máximo (km)<input name="delivery_radius_km" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_radius_km']??0))?>"></label><label>Previsão média (minutos)<input name="delivery_eta_minutes" type="number" min="5" max="240" value="<?=Security::e((string)($settings['delivery_eta_minutes']??45))?>"></label><label>Latitude da loja<input name="delivery_origin_latitude" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_origin_latitude']??''))?>"></label><label>Longitude da loja<input name="delivery_origin_longitude" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_origin_longitude']??''))?>"></label></div>
<label>Observação de horário<input name="delivery_schedule_note" maxlength="500" value="<?=Security::e((string)($settings['delivery_schedule_note']??''))?>" placeholder="Ex.: Seg a Dom · 18h às 23h"></label></section>

<section class="card"><div class="section-head"><div><span class="eyebrow">PAGAMENTO NO APP</span><h3>Mercado Pago</h3></div><span class="status-pill <?=!empty($gateway['active'])?'active':''?>"><?=!empty($gateway['active'])?'Ativo':'Configurar'?></span></div>
<p class="muted">A Public Key é a única credencial enviada ao APK e serve apenas para tokenização oficial do cartão. Access Token, Client ID e Client Secret permanecem no servidor; Access Token e Client Secret ficam dentro da configuração criptografada. Mercado Pago não é usado como NFC neste fluxo.</p>
<?php if($connectedAccount!==''):?><div class="alert ok"><strong>Conta Mercado Pago conectada:</strong> <?=Security::e($connectedAccount)?> <span class="muted">· Collector ID detectado automaticamente pelo Access Token.</span></div><?php endif;?>
<div class="form-grid">
<label><strong>1. Public Key</strong><input name="mp_public_key" value="<?=Security::e((string)($mp['public_key']??''))?>" autocomplete="off" placeholder="APP_USR-..."><small>Pode ser enviada ao APK somente para tokenizar o cartão.</small></label>
<label><strong>2. Access Token</strong><input name="mp_access_token" type="password" value="" autocomplete="new-password" placeholder="<?=!empty($mp['access_token'])?'Já configurado — deixe vazio para manter':'Informe o Access Token'?>"><small>Fica somente no servidor. Ao salvar, o EventMenu valida a conta e detecta o Collector ID automaticamente.</small></label>
<label><strong>3. Client ID</strong><input name="mp_client_id" value="<?=Security::e((string)($mp['client_id']??''))?>" autocomplete="off" placeholder="Client ID"><small>Armazenado no servidor para integrações da conta.</small></label>
<label><strong>4. Client Secret</strong><input name="mp_client_secret" type="password" value="" autocomplete="new-password" placeholder="<?=!empty($mp['client_secret'])?'Já configurado — deixe vazio para manter':'Informe o Client Secret'?>"><small>Fica somente no servidor e nunca é retornado ao APK.</small></label>
</div>
<div class="card" style="padding:16px;margin-top:14px"><div class="section-head"><div><span class="eyebrow">FORMAS ACEITAS</span><h4>PIX e cartões</h4></div></div>
<div class="form-grid"><label class="checkbox"><input type="checkbox" name="mp_pix_enabled" value="1" <?=!array_key_exists('pix_enabled',$mp)||!empty($mp['pix_enabled'])?'checked':''?>> Aceitar PIX</label><label class="checkbox"><input type="checkbox" name="mp_credit_enabled" value="1" <?=$creditChecked?'checked':''?>> Aceitar cartão de crédito</label><label class="checkbox"><input type="checkbox" name="mp_debit_enabled" value="1" <?=$debitChecked?'checked':''?>> Aceitar cartão de débito</label><label>Máximo de parcelas no crédito<input name="mp_max_installments" type="number" min="1" max="12" value="<?=Security::e((string)($mp['max_installments']??12))?>"><small>Débito é sempre à vista. O app mostra somente parcelas reais retornadas pelo Mercado Pago e dentro deste limite.</small></label></div>
<label class="checkbox" style="margin-top:12px"><input type="checkbox" name="mp_active" value="1" <?=!empty($gateway['active'])?'checked':''?>> <strong>Mercado Pago ativo</strong></label>
</div>
<div class="card" style="padding:16px;margin-top:14px"><span class="eyebrow">WEBHOOK · REDUNDÂNCIA</span><h4>Confirmação automática do Mercado Pago</h4><p class="muted">O webhook continua sendo a primeira confirmação. Se ele atrasar ou falhar, o status consultado pelo app faz uma segunda verificação diretamente na API do Mercado Pago antes de marcar o pedido como pago.</p><label>Assinatura secreta do webhook<input name="mp_webhook_secret" type="password" value="" autocomplete="new-password" placeholder="<?=$hasWebhookSecret?'Já configurado — deixe vazio para manter':'Cole a assinatura secreta do webhook'?>"><small>Recomendado para a confirmação imediata por webhook. O segredo existente nunca é exibido novamente.</small></label><div class="alert"><strong>URL do webhook:</strong> <?=Security::e(app_absolute_url('webhook.php?provider=mercadopago&tenant='.(string)$tenant['slug']))?></div><?php if(!$hasWebhookSecret):?><div class="alert warning">Webhook ainda sem assinatura secreta. O checkout pode usar a consulta direta redundante, mas configure a assinatura no Mercado Pago para manter as duas camadas de confirmação.</div><?php endif;?></div>
</section>
<div><button class="primary" type="submit">Salvar configurações</button></div></form>
<?php em_footer(); ?>
