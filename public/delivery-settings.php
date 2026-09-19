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

$t=$pdo->prepare('SELECT name,settings FROM tenants WHERE id=? LIMIT 1');$t->execute([$tenantId]);$tenant=$t->fetch();if(!$tenant)exit('Empresa não encontrada.');
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];
$g=$pdo->prepare("SELECT * FROM payment_gateways WHERE tenant_id=? AND provider='mercadopago' LIMIT 1");$g->execute([$tenantId]);$gateway=$g->fetch();$mp=$gateway?Crypto::decryptJson((string)$gateway['config_encrypted']):[];

$moneyToCents=static function(mixed$value):int{$raw=trim((string)$value);if($raw==='')return 0;$raw=str_replace(['R$',' '],'',$raw);if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);return max(0,(int)round((float)$raw*100));};

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

        $access=trim((string)($_POST['mp_access_token']??''));if($access==='')$access=(string)($mp['access_token']??'');
        $public=trim((string)($_POST['mp_public_key']??''));if($public==='')$public=(string)($mp['public_key']??'');
        $mp['access_token']=$access;$mp['public_key']=$public;$mp['pix_enabled']=!empty($_POST['mp_pix_enabled']);$mp['card_enabled']=!empty($_POST['mp_card_enabled']);$mp['max_installments']=max(1,min(12,(int)($_POST['mp_max_installments']??12)));
        $encrypted=Crypto::encrypt($mp);$active=(!empty($_POST['mp_active'])&&$access!=='')?1:0;
        if($gateway)$pdo->prepare('UPDATE payment_gateways SET config_encrypted=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$encrypted,$active,(int)$gateway['id'],$tenantId]);
        else{$pdo->prepare("INSERT INTO payment_gateways (tenant_id,provider,config_encrypted,active) VALUES (?,'mercadopago',?,?)")->execute([$tenantId,$encrypted,$active]);$gateway=['id'=>(int)$pdo->lastInsertId()];}
        Auth::audit('delivery.settings_saved','tenant',(string)$tenantId,['delivery_paused'=>$settings['delivery_paused'],'cash'=>$settings['delivery_cash_enabled'],'pix'=>$mp['pix_enabled'],'card'=>$mp['card_enabled']]);
        em_flash('ok','Configurações do EventMenu Delivery salvas.');app_redirect('delivery-settings.php');
    }catch(Throwable$e){em_flash('error',$e->getMessage());app_redirect('delivery-settings.php');}
}

em_header('Configurações do Delivery','delivery');
?>
<section class="page-hero"><div><span class="eyebrow">EVENTMENU DELIVERY</span><h2>Loja, entrega e pagamento</h2><p>Controle o que o cliente vê no app e quais pagamentos pode usar sem sair do EventMenu.</p></div><div class="hero-actions"><a class="button secondary" href="<?=Security::e(app_url('?route=delivery'))?>">Voltar ao Delivery</a></div></section>
<?php em_flash_render(); ?>
<form method="post" class="grid" style="gap:16px"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrfToken())?>">
<section class="card"><div class="section-head"><div><span class="eyebrow">OPERAÇÃO</span><h3>Entrega da <?=Security::e((string)$tenant['name'])?></h3></div></div>
<label><input type="checkbox" name="delivery_paused" value="1" <?=$settings['delivery_paused']??false?'checked':''?>> Pausar novos pedidos do aplicativo</label>
<label><input type="checkbox" name="delivery_pickup_enabled" value="1" <?=!empty($settings['delivery_pickup_enabled'])?'checked':''?>> Permitir retirada no local</label>
<label><input type="checkbox" name="delivery_cash_enabled" value="1" <?=!empty($settings['delivery_cash_enabled'])?'checked':''?>> Aceitar dinheiro na entrega</label>
<div class="form-grid"><label>Taxa de entrega (R$)<input name="delivery_fee" inputmode="decimal" value="<?=Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','.'))?>"></label><label>Pedido mínimo (R$)<input name="minimum_order" inputmode="decimal" value="<?=Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','.'))?>"></label><label>Raio máximo (km)<input name="delivery_radius_km" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_radius_km']??0))?>"></label><label>Previsão média (minutos)<input name="delivery_eta_minutes" type="number" min="5" max="240" value="<?=Security::e((string)($settings['delivery_eta_minutes']??45))?>"></label><label>Latitude da loja<input name="delivery_origin_latitude" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_origin_latitude']??''))?>"></label><label>Longitude da loja<input name="delivery_origin_longitude" inputmode="decimal" value="<?=Security::e((string)($settings['delivery_origin_longitude']??''))?>"></label></div>
<label>Observação de horário<input name="delivery_schedule_note" maxlength="500" value="<?=Security::e((string)($settings['delivery_schedule_note']??''))?>" placeholder="Ex.: Seg a Dom · 18h às 23h"></label></section>
<section class="card"><div class="section-head"><div><span class="eyebrow">PAGAMENTO NO APP</span><h3>Mercado Pago</h3></div></div><p class="muted">O Access Token fica criptografado no servidor. O app recebe somente a Public Key necessária para tokenizar o cartão; número e CVV não passam pelo EventMenu.</p>
<label><input type="checkbox" name="mp_active" value="1" <?=!empty($gateway['active'])?'checked':''?>> Conta Mercado Pago ativa</label>
<div class="form-grid"><label>Public Key<input name="mp_public_key" value="<?=Security::e((string)($mp['public_key']??''))?>" autocomplete="off" placeholder="APP_USR-..."></label><label>Access Token<input name="mp_access_token" type="password" value="" autocomplete="new-password" placeholder="Deixe vazio para manter o atual"></label><label><input type="checkbox" name="mp_pix_enabled" value="1" <?=!array_key_exists('pix_enabled',$mp)||!empty($mp['pix_enabled'])?'checked':''?>> PIX dentro do app</label><label><input type="checkbox" name="mp_card_enabled" value="1" <?=!empty($mp['card_enabled'])?'checked':''?>> Cartão dentro do app</label><label>Máximo de parcelas<input name="mp_max_installments" type="number" min="1" max="12" value="<?=Security::e((string)($mp['max_installments']??12))?>"></label></div></section>
<div><button class="primary" type="submit">Salvar configurações</button></div></form>
<?php em_footer(); ?>
