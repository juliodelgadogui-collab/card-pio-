<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Security;
use EventMenu\Services\GatewayService;
use EventMenu\Services\NfcDeviceService;
use EventMenu\Services\PagBankSecurityService;

Auth::requirePermission('gateways.manage');
$tenantId=em_require_tenant();
$paymentRoles=['admin','manager','cashier'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='gateway-save'){
        $provider=(string)($_POST['provider']??'');
        $s=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=?');
        $s->execute([$tenantId,$provider]);
        $existing=$s->fetch();
        $current=$existing?Crypto::decryptJson($existing['config_encrypted']):[];
        $config=$current;
        foreach(['secret_key','access_token','token','tap_on_email','tap_on_token','tap_on_app_key'] as $key){$value=trim((string)($_POST[$key]??''));if($value!=='')$config[$key]=$value;}
        if($provider==='pagbank'){
            try{
                $postedBase=trim((string)($_POST['api_base']??''));
                $config['api_base']=PagBankSecurityService::apiBase($postedBase!==''?$postedBase:(string)($config['api_base']??''));
                $environment=strtolower(trim((string)($_POST['tap_on_environment']??($config['tap_on_environment']??'production'))));
                if(!in_array($environment,['production','sandbox'],true))throw new RuntimeException('Ambiente Tap On inválido.');
                $config['tap_on_environment']=$environment;
            }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('gateways');}
        }
        $account=trim((string)($_POST['account_reference']??($existing['account_reference']??'')));
        $webhook=trim((string)($_POST['webhook_secret']??''));
        try{
            (new GatewayService())->save($provider,$account,$config,$webhook,isset($_POST['active']));
            em_flash('ok','Gateway salvo com credenciais criptografadas.');
        }catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('gateways');
    }

    if($action==='nfc-pair'){
        Auth::requirePermission('nfc.manage');
        try{
            $userId=(int)($_POST['user_id']??0);
            (new NfcDeviceService())->pair((string)($_POST['device_identifier']??''),$userId>0?$userId:null,(string)($_POST['name']??''));
            em_flash('ok','Dispositivo NFC pareado com identificador protegido por HMAC.');
        }catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('gateways');
    }

    if($action==='nfc-revoke'){
        Auth::requirePermission('nfc.manage');
        try{(new NfcDeviceService())->revoke((int)($_POST['id']??0),'manual_admin');em_flash('ok','Dispositivo revogado.');}catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('gateways');
    }
}

$tenant=$pdo->prepare('SELECT slug FROM tenants WHERE id=?');$tenant->execute([$tenantId]);$tenantSlug=(string)$tenant->fetchColumn();
$g=$pdo->prepare('SELECT id,provider,account_reference,active,created_at,updated_at FROM payment_gateways WHERE tenant_id=? ORDER BY provider');$g->execute([$tenantId]);$gateways=[];foreach($g->fetchAll() as $row)$gateways[$row['provider']]=$row;
$placeholders=implode(',',array_fill(0,count($paymentRoles),'?'));
$users=$pdo->prepare('SELECT id,name,role FROM users WHERE tenant_id=? AND status="active" AND role IN ('.$placeholders.') ORDER BY name');
$users->execute(array_merge([$tenantId],$paymentRoles));$users=$users->fetchAll();
$d=$pdo->prepare('SELECT d.*,u.name user_name,u.role user_role FROM nfc_devices d LEFT JOIN users u ON u.id=d.user_id WHERE d.tenant_id=? ORDER BY d.id DESC');$d->execute([$tenantId]);$devices=$d->fetchAll();

em_header('Gateways e NFC','gateways');
?><p class="muted">Segredos são criptografados com APP_KEY e nunca são exibidos novamente pelo painel.</p><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))"><?php foreach(['stripe'=>'Stripe','pagbank'=>'PagBank','mercadopago'=>'Mercado Pago'] as $provider=>$label):$row=$gateways[$provider]??null;?><section class="card"><div class="section-head"><h2><?= $label ?></h2><span class="badge"><?= $row&&$row['active']?'Ativo':'Inativo' ?></span></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="gateway-save"><input type="hidden" name="provider" value="<?= $provider ?>"><?php if($provider==='stripe'):?><label class="span-2">Account ID (acct_...)<input name="account_reference" value="<?= Security::e($row['account_reference']??'') ?>" required></label><label class="span-2">Secret key<input type="password" name="secret_key" placeholder="Deixe vazio para manter a atual"></label><label class="span-2">Segredo do webhook<input type="password" name="webhook_secret" placeholder="Deixe vazio para manter o atual"></label><?php elseif($provider==='mercadopago'):?><label class="span-2">Collector ID<input name="account_reference" value="<?= Security::e($row['account_reference']??'') ?>" required></label><label class="span-2">Access token<input type="password" name="access_token" placeholder="Deixe vazio para manter o atual"></label><label class="span-2">Segredo do webhook<input type="password" name="webhook_secret" placeholder="Deixe vazio para manter o atual"></label><?php else:$current=[];if($row){$full=$pdo->prepare('SELECT config_encrypted FROM payment_gateways WHERE id=? AND tenant_id=?');$full->execute([$row['id'],$tenantId]);$enc=$full->fetchColumn();if($enc)$current=Crypto::decryptJson((string)$enc);}?><label class="span-2">Vínculo da credencial<input value="<?= Security::e($row['account_reference']??'Será gerado ao salvar o token') ?>" disabled></label><label class="span-2">Token PagBank API<input type="password" name="token" placeholder="Deixe vazio para manter o atual"></label><label class="span-2">API PagBank<select name="api_base"><option value="https://api.pagseguro.com"<?= em_selected($current['api_base']??'https://api.pagseguro.com','https://api.pagseguro.com') ?>>Produção · api.pagseguro.com</option><option value="https://sandbox.api.pagseguro.com"<?= em_selected($current['api_base']??'','https://sandbox.api.pagseguro.com') ?>>Sandbox · sandbox.api.pagseguro.com</option></select></label><hr class="span-2" style="border-color:var(--line);width:100%"><div class="span-2"><strong>Tap On / NFC — validação no servidor</strong></div><label class="span-2">AppKey Tap On<input type="password" name="tap_on_app_key" placeholder="Deixe vazio para manter a atual"></label><label>Ambiente Tap On<select name="tap_on_environment"><option value="production"<?= em_selected($current['tap_on_environment']??'production','production') ?>>Produção</option><option value="sandbox"<?= em_selected($current['tap_on_environment']??'','sandbox') ?>>Sandbox</option></select></label><label>E-mail PagBank para consulta<input type="email" name="tap_on_email" placeholder="Deixe vazio para manter o atual"></label><label class="span-2">Token de consulta Tap On<input type="password" name="tap_on_token" placeholder="Deixe vazio para manter o atual"></label><p class="muted span-2">O retorno “aprovado” do Android não confirma o pedido. O EventMenu consulta o PagBank pelo transactionCode e confere o valor antes de marcar como pago. Os tokens ficam apenas criptografados no servidor. A API base aceita somente os hosts oficiais de produção ou sandbox.</p><?php endif;?><label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($row['active']??0) ?>> Gateway ativo</label><button class="primary span-2">Salvar <?= $label ?></button></form><p class="muted">Webhook: <code><?= Security::e(rtrim((string)env('APP_URL',''),'/').'/webhook.php?provider='.$provider.'&tenant='.$tenantSlug) ?></code></p></section><?php endforeach;?></div>
<section class="card" style="margin-top:18px"><div class="section-head"><h2>Dispositivos NFC PagBank</h2><span class="muted">HMAC · rate limit · revogação</span></div><div class="grid" style="grid-template-columns:minmax(280px,1fr) minmax(0,2fr)"><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="nfc-pair"><label class="span-2">Nome do aparelho<input name="name" maxlength="120" placeholder="Caixa 01"></label><label class="span-2">Identificador do dispositivo<input name="device_identifier" required minlength="8" maxlength="255" autocomplete="off"></label><label class="span-2">Usuário autorizado<select name="user_id"><option value="0">Compartilhado entre perfis de pagamento</option><?php foreach($users as $u):?><option value="<?= (int)$u['id'] ?>"><?= Security::e($u['name'].' · '.$u['role']) ?></option><?php endforeach;?></select></label><button class="secondary span-2">Parear dispositivo</button><p class="muted span-2">Somente administrador pode configurar NFC. O identificador bruto nunca é armazenado; o servidor usa HMAC com APP_KEY. Até 5 pareamentos por janela de 15 minutos; excesso bloqueia novos pareamentos por 30 minutos.</p></form><div class="table-wrap"><table class="table"><thead><tr><th>Dispositivo</th><th>Usuário</th><th>Status</th><th>Pareamento</th><th>Último pagamento</th><th></th></tr></thead><tbody><?php foreach($devices as $device):?><tr><td><?= Security::e($device['name']??'Sem nome') ?><br><code><?= Security::e(substr($device['device_identifier_hash'],0,12)) ?>…</code><br><small class="muted"><?= Security::e($device['identifier_version']??'sha256') ?></small></td><td><?= Security::e($device['user_name']??'Compartilhado') ?><?php if($device['user_role']):?><br><span class="muted"><?= Security::e($device['user_role']) ?></span><?php endif;?></td><td><span class="badge"><?= Security::e($device['status']) ?></span><?php if(!empty($device['revocation_reason'])):?><br><small class="muted"><?= Security::e($device['revocation_reason']) ?></small><?php endif;?></td><td><?= (int)$device['pairing_attempts'] ?>/5<?php if(!empty($device['pairing_locked_until'])):?><br><small class="muted">bloqueado até <?= Security::e($device['pairing_locked_until']) ?> UTC</small><?php endif;?></td><td><?= Security::e($device['last_payment_at']??'—') ?></td><td><?php if($device['status']!=='revoked'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="nfc-revoke"><input type="hidden" name="id" value="<?= (int)$device['id'] ?>"><button class="secondary">Revogar</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></div></section><?php em_footer();