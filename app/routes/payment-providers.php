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
        if($action==='runtime-options'){
            $registry->setRuntimeOptions(
                isset($_POST['card_present_enabled']),
                isset($_POST['pix_enabled']),
                isset($_POST['cash_enabled']),
                isset($_POST['external_terminal_enabled']),
                isset($_POST['external_terminal_reference_required'])
            );
            em_flash('ok','Formas de pagamento do aplicativo atualizadas.');
            em_go('payment-providers');
        }
        throw new RuntimeException('Ação inválida.');
    }catch(Throwable$e){
        em_flash('error',$e->getMessage());
        em_go('payment-providers');
    }
}

$overview=$settingsService->overview();
$catalog=$overview['catalog'];$providers=$overview['providers'];$prefs=$overview['preferences'];$runtime=$registry->runtimeOptions($tenantId);
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

em_header('Provedores de pagamento','payment-providers');
?>
<section class="page-hero">
    <div><span class="eyebrow">PAGAMENTOS INTEGRADOS</span><h2>Conta do restaurante e formas de receber</h2><p>Configure uma vez no servidor. O mesmo APK atende todas as empresas e consulta estas opções ao entrar no turno.</p></div>
    <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=gateways')) ?>">Dispositivos</a><a class="button secondary" href="<?= Security::e(app_url('?route=payments')) ?>">Transações</a></div>
</section>

<section class="card" style="margin-top:14px">
    <div class="section-head"><div><span class="eyebrow">APP EVENTMENU GO</span><h2>Formas de pagamento liberadas</h2><p class="muted">Você pode ativar e desativar métodos por empresa sem recompilar o aplicativo. O NFC só aparece quando a versão instalada também contém um SDK compatível.</p></div></div>
    <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="runtime-options">
        <label class="checkbox span-2"><input type="checkbox" name="pix_enabled"<?= em_checked($runtime['pix_enabled']) ?>> <span><strong>PIX no aplicativo</strong><small>Gera QR/copia-e-cola pelo provedor configurado e confirma no servidor.</small></span></label>
        <label class="checkbox span-2"><input type="checkbox" name="cash_enabled"<?= em_checked($runtime['cash_enabled']) ?>> <span><strong>Dinheiro</strong><small>Exige caixa aberto e registra a entrada no caixa da unidade.</small></span></label>
        <label class="checkbox span-2"><input type="checkbox" name="external_terminal_enabled"<?= em_checked($runtime['external_terminal_enabled']) ?>> <span><strong>Pago na maquininha externa</strong><small>Para máquina que ainda não possui integração automática. Exige usuário autorizado e deixa auditoria.</small></span></label>
        <label class="checkbox span-2"><input type="checkbox" name="external_terminal_reference_required"<?= em_checked($runtime['external_terminal_reference_required']) ?>> <span><strong>Exigir NSU/código da maquininha</strong><small>Recomendado para impedir confirmação duplicada e facilitar conciliação.</small></span></label>
        <label class="checkbox span-2"><input type="checkbox" name="card_present_enabled"<?= em_checked($runtime['card_present_enabled']) ?>> <span><strong>NFC / Tap to Pay dentro do EventMenu</strong><small>Chave de ativação do servidor. Neste momento deixe desligada até instalar a variante do APK que contém o SDK SumUp.</small></span></label>
        <button class="primary span-2">Salvar formas de pagamento</button>
    </form>
</section>

<div class="alert" style="margin-top:14px"><strong>Como funciona a ativação do NFC:</strong> o código fica preparado no EventMenu GO. Quando tivermos a versão com SDK SumUp, basta conectar a conta, selecionar SumUp abaixo e ativar “NFC / Tap to Pay”. Não existe APK diferente por restaurante.</div>

<section class="card" style="margin-top:14px">
    <div class="section-head"><div><span class="eyebrow">SUMUP</span><h2>Pagamento por aproximação dentro do app</h2><p class="muted">OAuth por empresa. Client secret e refresh token nunca vão para o APK.</p></div><span class="status-pill <?= $sumup&&$sumup['active']?'paid':'suspended' ?>"><?= $sumup&&$sumup['active']?'Conectado':'Não conectado' ?></span></div>
    <?php if($sumup): ?><p><strong>Conta recebedora:</strong> <?= Security::e((string)$sumup['account_reference']) ?></p><?php endif; ?>
    <div class="actions">
        <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="sumup-connect"><button class="primary"><?= $sumup?'Reconectar conta SumUp':'Conectar conta SumUp' ?></button></form>
        <?php if($sumup): ?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="provider-active"><input type="hidden" name="provider" value="sumup"><label class="checkbox"><input type="checkbox" name="active"<?= em_checked($sumup['active']) ?>> Ativa</label><button class="secondary compact">Salvar status</button></form><?php endif; ?>
    </div>
    <p class="muted">Para produção o servidor usa SUMUP_CLIENT_ID, SUMUP_CLIENT_SECRET, SUMUP_AFFILIATE_KEY e, quando fornecido, SUMUP_APP_ID. As credenciais privadas do Maven servem somente para compilar o SDK e não são por restaurante.</p>
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

<section class="card" style="margin-top:14px"><span class="eyebrow">ARQUITETURA</span><h2>O que fica em cada lugar</h2><p><strong>Android:</strong> telas de cobrança e, na variante compatível, SDK Tap to Pay. <strong>Servidor:</strong> quais formas estão habilitadas, OAuth, tokens, conta recebedora, idempotência, auditoria, conciliação e confirmação definitiva.</p></section>
<?php em_footer();
