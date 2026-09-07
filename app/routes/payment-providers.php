<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\Payments\PaymentProviderRegistry;
use EventMenu\Services\Payments\PaymentProviderSettingsService;
use EventMenu\Services\Payments\SumUpProvider;

Auth::requirePermission('gateways.manage');
$tenantId=em_require_tenant();
$settingsService=new PaymentProviderSettingsService();
$registry=new PaymentProviderRegistry();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='sumup-connect'){
            $url=(new SumUpProvider())->authorizationUrl();
            header('Location: '.$url, true, 302);
            exit;
        }
        if($action==='provider-save'){
            $provider=strtolower(trim((string)($_POST['provider']??'')));
            $account=trim((string)($_POST['account_reference']??''));
            $allowed=['pagbank','mercadopago','pagarme','asaas','openpix'];
            if(!in_array($provider,$allowed,true))throw new RuntimeException('Provedor manual inválido.');
            $incoming=[];
            foreach(['token','api_base','legacy_email','legacy_token','tap_on_app_key','tap_on_query_base','access_token','secret_key','api_key','app_id'] as$key){
                if(array_key_exists($key,$_POST))$incoming[$key]=(string)$_POST[$key];
            }
            $settingsService->saveGateway($provider,$account,$incoming,isset($_POST['active']));
            em_flash('ok','Configuração de '.$provider.' salva com segurança.');
            em_go('payment-providers');
        }
        if($action==='provider-active'){
            $provider=strtolower(trim((string)($_POST['provider']??'')));
            $settingsService->setConnectedProviderActive($provider,isset($_POST['active']));
            em_flash('ok','Status do provedor atualizado.');
            em_go('payment-providers');
        }
        if($action==='preferences'){
            $card=trim((string)($_POST['card_present_provider']??''));
            $pix=trim((string)($_POST['pix_provider']??''));
            $fallback=trim((string)($_POST['pix_fallback_provider']??''));
            $registry->setPreferences($card?:null,$pix?:null,$fallback?:null);
            em_flash('ok','Preferências de pagamento atualizadas.');
            em_go('payment-providers');
        }
        throw new RuntimeException('Ação inválida.');
    }catch(Throwable$e){
        em_flash('error',$e->getMessage());
        em_go('payment-providers');
    }
}

$overview=$settingsService->overview();
$catalog=$overview['catalog'];$providers=$overview['providers'];$prefs=$overview['preferences'];
$activeCard=[];$activePix=[];
foreach($providers as$code=>$row){
    if(empty($row['active']))continue;
    $meta=$catalog[$code]??[];
    if(!empty($meta['card_present']))$activeCard[$code]=(string)($meta['label']??$code);
    if(!empty($meta['pix']))$activePix[$code]=(string)($meta['label']??$code);
}
$sumup=$providers['sumup']??null;
$manual=[
    'pagbank'=>['label'=>'PagBank','account'=>'Referência da conta','fields'=>[['token','Token PagBank','password'],['api_base','API base','text']]],
    'mercadopago'=>['label'=>'Mercado Pago','account'=>'Collector ID','fields'=>[['access_token','Access token','password']]],
    'pagarme'=>['label'=>'Pagar.me','account'=>'Account / Merchant ID','fields'=>[['secret_key','Secret key','password']]],
    'asaas'=>['label'=>'Asaas','account'=>'Identificação da conta','fields'=>[['api_key','API Key','password'],['api_base','API base','text']]],
    'openpix'=>['label'=>'OpenPix / Woovi','account'=>'Identificação da conta','fields'=>[['app_id','App ID','password']]],
];

em_header('Provedores de pagamento','gateways');
?>
<section class="page-hero">
    <div><span class="eyebrow">PAGAMENTOS INTEGRADOS</span><h2>Conta do restaurante e provedores</h2><p>Conecte a conta que receberá o dinheiro. O aplicativo nunca guarda segredo permanente: ele solicita ao servidor somente a sessão necessária para cada cobrança.</p></div>
    <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=gateways')) ?>">Dispositivos NFC</a><a class="button secondary" href="<?= Security::e(app_url('?route=payments')) ?>">Transações</a></div>
</section>

<div class="alert"><strong>SumUp Tap to Pay:</strong> o cartão é aproximado no próprio Android do EventMenu GO. A confirmação final continua sendo feita no servidor consultando a SumUp antes de liberar o pedido.</div>

<section class="card" style="margin-top:14px">
    <div class="section-head"><div><span class="eyebrow">SUMUP</span><h2>Pagamento por aproximação dentro do app</h2><p class="muted">OAuth por empresa. Client secret e refresh token nunca vão para o APK.</p></div><span class="status-pill <?= $sumup&&$sumup['active']?'paid':'suspended' ?>"><?= $sumup&&$sumup['active']?'Conectado':'Não conectado' ?></span></div>
    <?php if($sumup): ?><p><strong>Conta recebedora:</strong> <?= Security::e((string)$sumup['account_reference']) ?></p><?php endif; ?>
    <div class="actions">
        <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="sumup-connect"><button class="primary"><?= $sumup?'Reconectar conta SumUp':'Conectar conta SumUp' ?></button></form>
        <?php if($sumup): ?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="provider-active"><input type="hidden" name="provider" value="sumup"><label class="checkbox"><input type="checkbox" name="active"<?= em_checked($sumup['active']) ?>> Ativa</label><button class="secondary compact">Salvar status</button></form><?php endif; ?>
    </div>
    <p class="muted">Para funcionar em produção o servidor precisa de SUMUP_CLIENT_ID, SUMUP_CLIENT_SECRET, SUMUP_AFFILIATE_KEY e, quando fornecido pela SumUp, SUMUP_APP_ID.</p>
</section>

<section class="card" style="margin-top:14px">
    <div class="section-head"><div><span class="eyebrow">PREFERÊNCIAS</span><h2>Qual provedor o EventMenu usa</h2><p class="muted">O restaurante pode usar SumUp no cartão e outro banco/gateway no PIX.</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="preferences">
        <label>Cartão por aproximação<select name="card_present_provider"><option value="">Automático</option><?php foreach($activeCard as$code=>$label):?><option value="<?= Security::e($code) ?>"<?= em_selected($prefs['card_present_provider'],$code) ?>><?= Security::e($label) ?></option><?php endforeach;?></select></label>
        <label>PIX principal<select name="pix_provider"><option value="">Automático</option><?php foreach($activePix as$code=>$label):?><option value="<?= Security::e($code) ?>"<?= em_selected($prefs['pix_provider'],$code) ?>><?= Security::e($label) ?></option><?php endforeach;?></select></label>
        <label>PIX reserva<select name="pix_fallback_provider"><option value="">Sem reserva</option><?php foreach($activePix as$code=>$label):?><option value="<?= Security::e($code) ?>"<?= em_selected($prefs['pix_fallback_provider'],$code) ?>><?= Security::e($label) ?></option><?php endforeach;?></select></label>
        <button class="primary">Salvar preferências</button>
    </form>
</section>

<section class="gateway-config-list" style="margin-top:14px">
<?php foreach($manual as$code=>$definition):$row=$providers[$code]??null;$cfg=$row['config']??[]; ?>
    <details class="card gateway-config-card"<?= $row&&$row['active']?' open':'' ?>>
        <summary><span><b><?= Security::e($definition['label']) ?></b><small><?= $row&&$row['active']?'Ativo':'Inativo' ?></small></span><span class="gateway-config-action">Configurar PIX</span></summary>
        <form method="post" class="form-grid gateway-form"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="provider-save"><input type="hidden" name="provider" value="<?= Security::e($code) ?>">
            <label class="span-2"><?= Security::e($definition['account']) ?><input name="account_reference" value="<?= Security::e((string)($row['account_reference']??'')) ?>" placeholder="Identificação da conta recebedora"></label>
            <?php foreach($definition['fields'] as[$name,$label,$type]):$configured=!empty($cfg[$name.'_configured']);?>
                <label class="span-2"><?= Security::e($label) ?><input type="<?= Security::e($type) ?>" name="<?= Security::e($name) ?>" value="<?= $type==='password'?'':Security::e((string)($cfg[$name]??'')) ?>" placeholder="<?= $type==='password'&&$configured?'Configurado — deixe vazio para manter':'' ?>"></label>
            <?php endforeach;?>
            <label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($row['active']??false) ?>> Provedor ativo</label>
            <button class="primary span-2">Salvar <?= Security::e($definition['label']) ?></button>
        </form>
    </details>
<?php endforeach;?>
</section>

<section class="card" style="margin-top:14px"><span class="eyebrow">ARQUITETURA</span><h2>O que fica em cada lugar</h2><p><strong>Android:</strong> SDK Tap to Pay, NFC, escolha crédito/débito e parcelas. <strong>Servidor:</strong> OAuth, tokens, conta recebedora, idempotência, conciliação e confirmação definitiva.</p></section>
<?php em_footer();
