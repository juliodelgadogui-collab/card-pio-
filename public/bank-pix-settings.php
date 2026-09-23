<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/admin_helpers.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\BankPixGatewayService;
use EventMenu\Services\PaymentCertificateService;

if(!Auth::check())app_redirect('?route=login');
Auth::enforceCurrentUser();Auth::requirePermission('gateways.manage');$tenantId=em_require_tenant();$pdo=Database::connection();
$providers=['efi'=>'Efí','inter'=>'Banco Inter'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null)){em_flash('error','Sessão expirada. Atualize a página.');app_redirect('bank-pix-settings.php');}
    $provider=strtolower(trim((string)($_POST['provider']??'')));if(!isset($providers[$provider])){em_flash('error','Provedor inválido.');app_redirect('bank-pix-settings.php');}
    $q=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? LIMIT 1');$q->execute([$tenantId,$provider]);$existing=$q->fetch();$config=$existing?Crypto::decryptJson((string)$existing['config_encrypted']):[];
    try{
        foreach(['client_id','client_secret','pix_key','account_number','api_base','certificate_password']as$key){$value=trim((string)($_POST[$key]??''));if($value!=='')$config[$key]=$value;}
        $config['pix_enabled']=isset($_POST['pix_enabled']);$config['pix_default']=isset($_POST['pix_default']);$config['pix_priority']=max(1,min(999,(int)($_POST['pix_priority']??100)));
        $files=new PaymentCertificateService();
        if(isset($_FILES['certificate'])&&(int)($_FILES['certificate']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$old=$config['certificate_path']??null;$config['certificate_path']=$files->storeUploaded($tenantId,$_FILES['certificate'],$provider.'-certificate');$files->remove(is_string($old)?$old:null);}
        if(isset($_FILES['private_key'])&&(int)($_FILES['private_key']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$old=$config['private_key_path']??null;$config['private_key_path']=$files->storeUploaded($tenantId,$_FILES['private_key'],$provider.'-private-key');$files->remove(is_string($old)?$old:null);}
        if($provider==='efi'&&empty($config['api_base']))$config['api_base']='https://pix.api.efipay.com.br';
        if($provider==='inter'&&empty($config['api_base']))$config['api_base']='https://cdpj.partners.bancointer.com.br';
        $account=$provider==='inter'?trim((string)($config['account_number']??'')):trim((string)($config['pix_key']??''));
        if($config['pix_default']){
            $config['pix_enabled']=true;$others=$pdo->prepare('SELECT id,config_encrypted FROM payment_gateways WHERE tenant_id=? AND provider<>?');$others->execute([$tenantId,$provider]);foreach($others->fetchAll()as$other){$oc=Crypto::decryptJson((string)$other['config_encrypted']);if(array_key_exists('pix_default',$oc)&&$oc['pix_default']){$oc['pix_default']=false;$pdo->prepare('UPDATE payment_gateways SET config_encrypted=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([Crypto::encryptJson($oc),(int)$other['id'],$tenantId]);}}
        }
        (new BankPixGatewayService())->save($provider,$account,$config,isset($_POST['active']));em_flash('ok',$providers[$provider].' salvo. O certificado fica fora da pasta pública.');
    }catch(Throwable$e){em_flash('error',$e->getMessage());}
    app_redirect('bank-pix-settings.php');
}

$q=$pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider IN ("efi","inter") ORDER BY provider');$q->execute([$tenantId]);$rows=[];$configs=[];foreach($q->fetchAll()as$row){$rows[(string)$row['provider']]=$row;$configs[(string)$row['provider']]=Crypto::decryptJson((string)$row['config_encrypted']);}
em_header('Pix bancário','gateways');
?>
<section class="page-hero"><div><span class="eyebrow">PIX BANCÁRIO</span><h2>Receba Pix direto em outras instituições</h2><p>Efí e Banco Inter usam OAuth2 e certificado da própria conta. O EventMenu gera a cobrança, mostra o QR/Copia e Cola no app e confirma o pagamento consultando o banco.</p></div><div class="hero-actions"><a class="button secondary" href="<?=Security::e(app_url('?route=gateways'))?>">Mercado Pago / PagBank</a></div></section>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px">
<?php foreach($providers as$provider=>$label):$row=$rows[$provider]??null;$cfg=$configs[$provider]??[];$isInter=$provider==='inter';?>
<section class="card"><div class="section-head"><div><span class="eyebrow">PIX</span><h2><?=Security::e($label)?></h2></div><span class="status-pill <?=$row&&$row['active']?'active':''?>"><?=$row&&$row['active']?'Ativo':'Inativo'?></span></div>
<form method="post" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="provider" value="<?=Security::e($provider)?>">
<label class="span-2">Client ID<input name="client_id" value="<?=Security::e((string)($cfg['client_id']??''))?>" autocomplete="off"></label>
<label class="span-2">Client Secret<input type="password" name="client_secret" placeholder="Deixe vazio para manter o atual" autocomplete="new-password"></label>
<label class="span-2">Chave Pix da conta<input name="pix_key" value="<?=Security::e((string)($cfg['pix_key']??''))?>" placeholder="CPF, CNPJ, e-mail, telefone ou EVP"></label>
<?php if($isInter):?><label class="span-2">Conta corrente Inter<input name="account_number" value="<?=Security::e((string)($cfg['account_number']??''))?>" placeholder="Conta vinculada à integração"></label><?php endif;?>
<label class="span-2">API base<input name="api_base" value="<?=Security::e((string)($cfg['api_base']??($isInter?'https://cdpj.partners.bancointer.com.br':'https://pix.api.efipay.com.br')))?>"></label>
<label class="span-2">Certificado (.p12, .pfx, .pem, .crt ou .cer)<input type="file" name="certificate" accept=".p12,.pfx,.pem,.crt,.cer"><small><?=!empty($cfg['certificate_path'])?'Certificado já armazenado. Envie outro apenas para substituir.':'Obrigatório para ativar.'?></small></label>
<label class="span-2">Chave privada (.key ou .pem)<?=!$isInter?' · opcional se o certificado já contiver a chave':''?><input type="file" name="private_key" accept=".key,.pem"><small><?=!empty($cfg['private_key_path'])?'Chave privada já armazenada.':''?></small></label>
<label class="span-2">Senha do certificado, se houver<input type="password" name="certificate_password" placeholder="Deixe vazio para manter a atual"></label>
<div class="span-2 card" style="padding:14px"><label class="checkbox"><input type="checkbox" name="pix_enabled"<?=(!array_key_exists('pix_enabled',$cfg)||!empty($cfg['pix_enabled']))?' checked':''?>> Aceitar Pix por <?=Security::e($label)?></label><label class="checkbox"><input type="checkbox" name="pix_default"<?=!empty($cfg['pix_default'])?' checked':''?>> Usar como Pix principal</label><label>Prioridade<input type="number" min="1" max="999" name="pix_priority" value="<?=max(1,(int)($cfg['pix_priority']??100))?>"><small>Menor número = maior prioridade.</small></label></div>
<label class="checkbox span-2"><input type="checkbox" name="active"<?=em_checked($row['active']??0)?>> Integração ativa</label>
<button class="primary span-2">Salvar <?=Security::e($label)?></button></form></section>
<?php endforeach;?></div>
<section class="card" style="margin-top:16px"><strong>Segurança</strong><p class="muted">Os certificados enviados são gravados em <code>storage/private/payment-certificates</code>, com nome aleatório e fora da pasta pública. Senhas e credenciais ficam criptografadas com a APP_KEY do EventMenu.</p></section>
<?php em_footer();
