<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\DeliveryPublicTrackingService;
use EventMenu\Services\OrderFulfillmentService;
use EventMenu\Services\PublicOrderPaymentService;
use EventMenu\Services\TenantBrandService;

$pdo=Database::connection();
$token=trim((string)($_GET['t']??''));
$error=null;

function p_money(int$c):string{return'R$ '.number_format($c/100,2,',','.');}
function p_qty(float|int|string$v):string{$q=(float)$v;return abs($q-round($q))<0.0005?(string)(int)round($q):rtrim(rtrim(number_format($q,3,',','.'),'0'),',');}
function p_channel(string$c):string{return match($c){'pickup'=>'Retirada no local','counter'=>'Balcão','delivery'=>'Entrega','table'=>'Mesa','event'=>'Evento','bar','event_bar'=>'Bar do evento',default=>'Pedido'};}
function p_payment_status(string$s):string{return match(strtolower($s)){'paid'=>'Pagamento confirmado','pending','processing','created'=>'Aguardando pagamento','refunded','partially_refunded'=>'Pagamento estornado','failed','cancelled'=>'Pagamento não concluído',default=>'Aguardando pagamento'};}
function p_order_status(string$s):string{return match(strtolower($s)){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','served'=>'Servido','completed'=>'Concluído','cancelled'=>'Cancelado',default=>'Em andamento'};}
function p_friendly_error(Throwable$e):string{$message=trim((string)$e->getMessage());if($message==='')return'Não foi possível iniciar o pagamento. Tente novamente.';$message=preg_replace('/^(financeiro|api|servidor|gateway)\s*:\s*/iu','',$message)??$message;if(preg_match('/sql|sqlite|mysql|pdo|http\s*\d|json|token|exception|database|stack|provider|webhook|endpoint/iu',$message))return'Não foi possível iniciar o pagamento. Tente novamente.';return mb_substr($message,0,200);}
function allowed_checkout_url(string$provider,string$url):bool{$p=parse_url($url);if(($p['scheme']??'')!=='https'||empty($p['host']))return false;$h=strtolower($p['host']);$allowed=['stripe'=>['stripe.com']];foreach($allowed[$provider]??[]as$suffix)if($h===$suffix||str_ends_with($h,'.'.$suffix))return true;return false;}
function p_timeline(string$channel,string$status):array{if($channel==='delivery')$steps=[['pending','Pedido recebido','Recebemos seu pedido.'],['preparing','Em preparo','A equipe está preparando tudo.'],['ready','Pronto','Pedido liberado para o entregador.'],['out_for_delivery','Saiu para entrega','Seu pedido está a caminho.'],['completed','Entregue','Entrega concluída.']];elseif($channel==='table')$steps=[['pending','Pedido recebido','Pedido enviado para atendimento.'],['preparing','Em preparo','A produção está preparando seu pedido.'],['ready','Pronto','Pedido pronto para servir.'],['completed','Servido','Atendimento concluído.']];else$steps=[['pending','Pedido recebido','Recebemos seu pedido.'],['preparing','Em preparo','A equipe está preparando tudo.'],['ready',$channel==='pickup'?'Pronto para retirada':'Pronto','Seu pedido está pronto.'],['completed','Concluído','Pedido finalizado.']];$rank=['pending'=>0,'confirmed'=>1,'preparing'=>1,'ready'=>2,'out_for_delivery'=>3,'served'=>3,'completed'=>99,'cancelled'=>-1];$current=$rank[$status]??0;foreach($steps as$i=>&$step){$step['done']=$status==='completed'||($status!=='cancelled'&&$i<$current);$step['current']=$status!=='cancelled'&&$status!=='completed'&&$i===$current;}unset($step);return$steps;}

// Keep Stripe as a legacy optional fallback. Mercado Pago/PagBank native flows
// are handled inside this page and never redirect to provider checkout pages.
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['legacy_provider'])){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='A página expirou. Atualize e tente novamente.';
    else{try{$provider=(string)$_POST['legacy_provider'];if($provider!=='stripe')throw new RuntimeException('Forma de pagamento indisponível.');$checkout=(new CheckoutService())->create($token,$provider);if(!allowed_checkout_url($checkout['provider'],$checkout['url']))throw new RuntimeException('Não foi possível abrir o pagamento com segurança.');header('Location: '.$checkout['url'],true,303);exit;}catch(Throwable$e){$error=p_friendly_error($e);}}
}

$s=$pdo->prepare('SELECT o.*,t.name tenant_name,t.slug tenant_slug,c.name customer_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.public_token=? AND t.status="active" LIMIT 1');$s->execute([$token]);$order=$s->fetch();if(!$order){http_response_code(404);exit('Pedido não encontrado.');}

$brandService=new TenantBrandService();$tenantBrand=$brandService->get((int)$order['tenant_id']);$visual=(bool)$tenantBrand['apply_web']?$tenantBrand:$brandService->defaults();$brandName=trim((string)$tenantBrand['display_name'])?:((string)$order['tenant_name']);$tagline=trim((string)$tenantBrand['tagline']);$logo=(bool)$tenantBrand['apply_web']?trim((string)$tenantBrand['logo_url']):'';$primary=(string)$visual['primary_color'];$secondary=(string)$visual['secondary_color'];$background=(string)$visual['background_color'];$surface=(string)$visual['surface_color'];$text=(string)$visual['text_color'];$showEventMenu=(bool)$tenantBrand['show_eventmenu_brand'];

$itemsQ=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$itemsQ->execute([$order['id']]);$items=$itemsQ->fetchAll();
$legacyStripe=$pdo->prepare('SELECT 1 FROM payment_gateways WHERE tenant_id=? AND active=1 AND provider="stripe" LIMIT 1');$legacyStripe->execute([$order['tenant_id']]);$legacyStripeEnabled=(bool)$legacyStripe->fetchColumn();
$paymentService=new PublicOrderPaymentService();try{$paymentMethods=$paymentService->methods($pdo,$token);$paymentProfile=$paymentService->currentProfile($pdo,$token);}catch(Throwable$e){$paymentMethods=['pix'=>[],'card'=>[],'cash'=>false,'currency'=>'BRL'];$paymentProfile=['email'=>'','document_configured'=>false,'document_masked'=>''];if($error===null)$error=p_friendly_error($e);}
$pixProvider=(string)($paymentMethods['pix'][0]['provider']??'');$cardMethod=$paymentMethods['card'][0]??null;$cardTypes=is_array($cardMethod)?(array)($cardMethod['payment_types']??[]):[];$hasNativePayment=$pixProvider!==''||is_array($cardMethod)||!empty($paymentMethods['cash']);

$ticketsQ=$pdo->prepare('SELECT t.*,e.name event_name,e.starts_at event_starts_at,e.venue event_venue,e.address event_address,b.name batch_name,tt.name ticket_type_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.order_id=? ORDER BY t.id');$ticketsQ->execute([$order['id']]);$tickets=$ticketsQ->fetchAll();$eventInfo=$tickets?$tickets[0]:null;if(!$eventInfo&&!empty($order['event_id'])){$ev=$pdo->prepare('SELECT name event_name,starts_at event_starts_at,venue event_venue,address event_address FROM events WHERE id=? AND tenant_id=? LIMIT 1');$ev->execute([(int)$order['event_id'],(int)$order['tenant_id']]);$eventInfo=$ev->fetch()?:null;if($eventInfo){$eventInfo['ticket_type_name']='';$eventInfo['batch_name']='';}}
$paid=(string)$order['payment_status']==='paid';$cancelled=(string)$order['status']==='cancelled';$isEventBar=in_array((string)$order['channel'],['bar','event_bar'],true)&&!empty($order['event_id']);$fulfillment=new OrderFulfillmentService();$hasOrderQr=($fulfillment->supportsChannel((string)$order['channel'])||$isEventBar)&&!empty($order['public_token']);$showOrderQr=$hasOrderQr&&(!$isEventBar||$paid);$qrDataUri=$showOrderQr?$fulfillment->qrDataUri((string)$order['public_token'],300):null;try{$progress=$hasOrderQr&&!$isEventBar?$fulfillment->publicProgress((int)$order['tenant_id'],(int)$order['id']):null;}catch(Throwable){$progress=null;}$trackingLink=null;if((string)$order['channel']==='delivery'){try{$trackingLink=(new DeliveryPublicTrackingService())->existingForPublicOrder((int)$order['tenant_id'],(int)$order['id'],(string)$order['public_token']);}catch(Throwable){$trackingLink=null;}}
$customer=trim((string)($order['customer_name']??''));if($customer===''||strtolower($customer)==='null')$customer='Cliente';
// Do not reload the page while the customer is typing card data. Native payment
// status has its own polling below. After payment, normal order auto-refresh resumes.
$autoRefresh=!$cancelled&&$paid&&!in_array((string)$order['status'],['completed','cancelled'],true);$timeline=p_timeline((string)$order['channel'],(string)$order['status']);$returnState=trim((string)($_GET['retorno']??''));$csrf=Security::csrfToken();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?=Security::e($primary)?>">
<meta http-equiv="Cache-Control" content="no-store">
<meta name="referrer" content="no-referrer">
<title>Pedido #<?=(int)$order['id']?> — <?=Security::e($brandName)?></title>
<link rel="stylesheet" href="<?=Security::e(app_url('assets/order-premium-v5.css'))?>">
<style>
:root{--op-primary:<?=Security::e($primary)?>;--op-secondary:<?=Security::e($secondary)?>;--op-bg:<?=Security::e($background)?>;--op-surface:<?=Security::e($surface)?>;--op-text:<?=Security::e($text)?>}
.native-pay{display:grid;gap:14px}.pay-method{border:1px solid rgba(98,54,223,.18);border-radius:18px;padding:16px;background:var(--op-surface)}.pay-method h3{margin:0 0 6px}.pay-method p{margin:0 0 12px;color:#706b7e}.payer-grid,.card-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.native-pay label{display:grid;gap:5px;font-size:.86rem;font-weight:800}.native-pay input,.native-pay select{width:100%;box-sizing:border-box;padding:12px;border:1px solid #d8d3e4;border-radius:12px;background:#fff;color:#242136}.pay-actions{display:flex;gap:9px;flex-wrap:wrap}.native-pay button{border:0;border-radius:12px;padding:12px 16px;font-weight:900;cursor:pointer}.native-primary{background:var(--op-primary);color:#fff}.native-secondary{background:#eee9fb;color:var(--op-primary)}.native-pay button:disabled{opacity:.55;cursor:not-allowed}.native-feedback{display:none;padding:11px 13px;border-radius:12px;background:#f3efff;color:#44396a;font-weight:700}.native-feedback.show{display:block}.native-feedback.error{background:#fff0f0;color:#9b1c1c}.native-feedback.ok{background:#eaf9f0;color:#17653a}.pix-result{display:none;gap:10px;text-align:center}.pix-result.show{display:grid}.pix-result img{max-width:240px;width:100%;margin:auto;border-radius:14px}.pix-code{font-size:.82rem;word-break:break-all;text-align:left;background:#f6f4fb;padding:10px;border-radius:10px}.card-type-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}.card-type-row label{display:flex;align-items:center;gap:6px;background:#f5f2fb;padding:8px 11px;border-radius:999px}.card-type-row input{width:auto}.mp-field{min-height:48px;border:1px solid #d8d3e4;border-radius:12px;padding:2px 9px;background:#fff}.saved-doc{font-size:.8rem;color:#716b80;margin-top:3px}.secure-note strong{color:var(--op-primary)}
@media(max-width:640px){.payer-grid,.card-grid{grid-template-columns:1fr}.pay-actions button{width:100%}}
</style>
<?php if(is_array($cardMethod)):?><script src="https://sdk.mercadopago.com/js/v2"></script><?php endif;?>
</head>
<body>
<main class="shell" data-order-page data-auto-refresh="<?=$autoRefresh?'1':'0'?>">
<header class="top"><div class="brand-wrap"><?php if($logo!==''):?><img class="brand-logo" src="<?=Security::e($logo)?>" alt="Logo de <?=Security::e($brandName)?>" width="46" height="46"><?php else:?><div class="brand-mark" aria-hidden="true"><?=Security::e(mb_strtoupper(mb_substr($brandName,0,1)))?></div><?php endif;?><div><div class="brand-name"><?=Security::e($brandName)?></div><?php if($tagline!==''):?><div class="org"><?=Security::e($tagline)?></div><?php endif;?></div></div><div class="top-state">Acompanhe tudo por aqui</div></header>

<section class="card">
<div class="status-row"><div class="title"><span class="channel"><?=Security::e(p_channel((string)$order['channel']))?></span><h1>Pedido #<?=(int)$order['id']?></h1><p><?=Security::e($customer)?> · <?=Security::e(date('d/m/Y · H:i',strtotime((string)$order['created_at'])))?></p><div class="title-meta"><span class="status-pill"><?=Security::e(p_order_status((string)$order['status']))?></span><span class="payment-pill<?=$paid?' paid':''?>"><?=$paid?'✓ ':''?><?=Security::e(p_payment_status((string)$order['payment_status']))?></span></div></div></div>

<?php if($eventInfo):?><div class="event-box"><small><?=$isEventBar?'PEDIDO NO EVENTO':'SEU EVENTO'?></small><h2><?=Security::e($eventInfo['event_name'])?></h2><div class="event-meta"><span><?=Security::e(date('d/m/Y · H:i',strtotime((string)$eventInfo['event_starts_at'])))?></span><?php if($eventInfo['event_venue']):?><span>• <?=Security::e($eventInfo['event_venue'])?></span><?php endif;?><?php if($eventInfo['ticket_type_name']):?><span>• <?=Security::e($eventInfo['ticket_type_name'])?></span><?php endif;?></div></div><?php endif;?>
<?php if($error):?><div class="alert error" role="alert"><?=Security::e($error)?></div><?php endif;?>
<?php if($returnState==='cancelado'):?><div class="alert">O pagamento não foi concluído. Seu pedido continua aqui para você tentar novamente.</div><?php endif;?>

<?php if(!$isEventBar):?><section class="timeline-card" aria-label="Andamento do pedido"><div class="timeline-head"><strong>Andamento do pedido</strong><span>Atualização automática</span></div><div class="timeline"><?php foreach($timeline as$step):?><div class="timeline-step<?=$step['done']?' done':''?><?=$step['current']?' current':''?>"><div class="timeline-dot"><?=$step['done']?'✓':($step['current']?'●':'○')?></div><div class="timeline-label"><strong><?=Security::e($step[1])?></strong><small><?=Security::e($step[2])?></small></div></div><?php endforeach;?></div><?php if($trackingLink):?><div class="tracking-action"><span>O entregador está em rota. Você pode acompanhar a posição enquanto o rastreamento estiver ativo.</span><a href="<?=Security::e($trackingLink['url'])?>">Acompanhar entrega</a></div><?php endif;?></section><?php endif;?>

<div class="items"><?php foreach($items as$i):?><div class="item"><div><strong><?=Security::e($i['name_snapshot'])?></strong><small><?=p_qty($i['quantity'])?> × <?=p_money((int)$i['unit_price_cents'])?></small></div><strong><?=p_money((int)$i['total_cents'])?></strong></div><?php endforeach;?></div>
<div class="totals"><?php if(isset($order['subtotal_cents'])):?><div class="total-row"><span>Subtotal</span><span><?=p_money((int)$order['subtotal_cents'])?></span></div><?php endif;?><?php if((int)($order['discount_cents']??0)>0):?><div class="total-row"><span>Desconto</span><span>− <?=p_money((int)$order['discount_cents'])?></span></div><?php endif;?><?php if((int)($order['delivery_fee_cents']??0)>0):?><div class="total-row"><span>Entrega</span><span><?=p_money((int)$order['delivery_fee_cents'])?></span></div><?php endif;?><div class="total-row grand"><span>Total</span><span><?=p_money((int)$order['total_cents'])?></span></div></div>

<?php if($cancelled):?><div class="alert error">Este pedido foi cancelado.</div>
<?php elseif($paid):?><div class="alert ok">✓ <?=$isEventBar&&$order['status']==='ready'?'Pagamento confirmado. Seu pedido está pronto para retirada.':'Pagamento confirmado. Continue acompanhando o andamento do pedido por aqui.'?></div>
<?php elseif((int)$order['total_cents']>0):?>
<div class="section-title"><h2>Como deseja pagar?</h2><small>Sem sair do EventMenu</small></div>
<?php if($hasNativePayment):?>
<div class="payment-card native-pay" id="native-payment">
  <div class="pay-method">
    <h3>Dados do pagador</h3>
    <p>Preencha uma vez nesta compra. O documento é usado para identificar o pagamento e não aparece no comprovante público.</p>
    <div class="payer-grid">
      <label>E-mail<input id="payer-email" type="email" autocomplete="email" value="<?=Security::e((string)($paymentProfile['email']??''))?>" placeholder="voce@email.com"></label>
      <label>CPF/CNPJ<input id="payer-document" inputmode="numeric" autocomplete="off" maxlength="18" placeholder="<?=$paymentProfile['document_configured']?Security::e((string)$paymentProfile['document_masked']):'Somente números'?>"><span class="saved-doc"><?=$paymentProfile['document_configured']?'Documento já salvo para PIX. No cartão, informe novamente apenas para a tokenização segura.':'Informe o documento do pagador.'?></span></label>
    </div>
  </div>

  <?php if($pixProvider!==''):?>
  <div class="pay-method" id="pix-method">
    <h3>PIX</h3><p>O QR Code e o Copia e Cola aparecem aqui mesmo. A confirmação vem diretamente do provedor.</p>
    <div class="pay-actions"><button type="button" class="native-primary" id="generate-pix">Gerar PIX</button></div>
    <div class="pix-result" id="pix-result"><img id="pix-image" alt="QR Code PIX" hidden><div class="pix-code" id="pix-code"></div><button type="button" class="native-secondary" id="copy-pix">Copiar código PIX</button><small id="pix-expiry"></small></div>
  </div>
  <?php endif;?>

  <?php if(is_array($cardMethod)):?>
  <div class="pay-method">
    <h3>Cartão de crédito ou débito</h3>
    <p>Os campos sensíveis são tokenizados pelo SDK oficial do Mercado Pago. Número e CVV não são enviados ao servidor EventMenu.</p>
    <form id="form-checkout">
      <div class="card-type-row">
        <?php if(in_array('credit_card',$cardTypes,true)):?><label><input type="radio" name="eventmenu-card-type" value="credit_card" checked> Crédito</label><?php endif;?>
        <?php if(in_array('debit_card',$cardTypes,true)):?><label><input type="radio" name="eventmenu-card-type" value="debit_card" <?=!in_array('credit_card',$cardTypes,true)?'checked':''?>> Débito</label><?php endif;?>
      </div>
      <div class="card-grid">
        <label>Número do cartão<div id="form-checkout__cardNumber" class="mp-field"></div></label>
        <label>Nome no cartão<input id="form-checkout__cardholderName" type="text" autocomplete="cc-name"></label>
        <label>Validade<div id="form-checkout__expirationDate" class="mp-field"></div></label>
        <label>CVV<div id="form-checkout__securityCode" class="mp-field"></div></label>
        <label>Banco emissor<select id="form-checkout__issuer"></select></label>
        <label>Parcelas<select id="form-checkout__installments"></select></label>
        <label>Tipo de documento<select id="form-checkout__identificationType"></select></label>
        <label>Documento<input id="form-checkout__identificationNumber" type="text" inputmode="numeric" autocomplete="off"></label>
        <label style="grid-column:1/-1">E-mail<input id="form-checkout__cardholderEmail" type="email" autocomplete="email" value="<?=Security::e((string)($paymentProfile['email']??''))?>"></label>
      </div>
      <div class="pay-actions" style="margin-top:12px"><button class="native-primary" type="submit" id="card-submit">Pagar com cartão</button></div>
    </form>
  </div>
  <?php endif;?>

  <?php if(!empty($paymentMethods['cash'])):?>
  <div class="pay-method"><h3>Dinheiro</h3><p>O pedido continua como não pago até a equipe confirmar o recebimento.</p><div class="payer-grid"><label>Troco para (opcional)<input id="cash-change" inputmode="decimal" placeholder="Ex: 100,00"></label></div><div class="pay-actions" style="margin-top:10px"><button type="button" class="native-secondary" id="select-cash">Vou pagar em dinheiro</button></div></div>
  <?php endif;?>

  <div class="native-feedback" id="payment-feedback" role="status" aria-live="polite"></div>
  <div class="secure-note">🔒 <strong>Confirmação no servidor:</strong> nenhum botão desta página consegue marcar o pedido como pago. O EventMenu só liquida após validar o pagamento no provedor.</div>
</div>
<?php endif;?>

<?php if($legacyStripeEnabled):?><div class="payment-card" style="margin-top:12px"><h3>Outra forma online</h3><p>Stripe permanece disponível como integração legada, sem alterar o fluxo nativo de PIX/cartão acima.</p><form method="post" data-payment-form><input type="hidden" name="_csrf" value="<?=Security::e($csrf)?>"><input type="hidden" name="legacy_provider" value="stripe"><button class="secondary-pay">Pagar com Stripe</button></form></div><?php endif;?>
<?php if(!$hasNativePayment&&!$legacyStripeEnabled):?><div class="alert">O pagamento online não está disponível para este pedido. Entre em contato com <?=Security::e($brandName)?>.</div><?php endif;?>
<?php endif;?>

<?php if($showOrderQr):?><section class="qr-card"><div class="qr-box"><img src="<?=Security::e((string)$qrDataUri)?>" alt="QR do pedido"></div><div><small style="font-weight:900;color:var(--op-primary)">QR DO PEDIDO</small><h2><?=$isEventBar?'Apresente no bar':($order['channel']==='pickup'?'Apresente na retirada':'Identificação do pedido')?></h2><p><?=$isEventBar?'Quando o pedido estiver pronto, mostre este QR no bar. A equipe confere e confirma a retirada.':($order['channel']==='pickup'?'Mostre este QR ao responsável pela retirada.':'Use este QR quando a equipe solicitar a identificação do pedido.')?></p><?php if($progress):?><div class="progress"><div><small>Pedido</small><strong><?=p_qty($progress['ordered_quantity'])?></strong></div><div><small>Entregue</small><strong><?=p_qty($progress['fulfilled_quantity'])?></strong></div><div><small>Falta</small><strong><?=p_qty($progress['remaining_quantity'])?></strong></div></div><?php endif;?></div></section><?php elseif($isEventBar&&!$paid):?><div class="alert">O QR de retirada será liberado assim que o pagamento for confirmado.</div><?php elseif($order['channel']==='table'):?><div class="alert ok">Pedido vinculado à mesa. Não é necessário apresentar QR.</div><?php endif;?>

<?php if($paid&&$tickets):?><div class="section-title"><h2>Seus ingressos</h2></div><div class="ticket-list"><?php foreach($tickets as$t):$ticketUrl=app_url('ingresso.php?t='.rawurlencode((string)$t['qr_token']));?><a class="ticket-link" href="<?=Security::e($ticketUrl)?>"><div><strong><?=Security::e($t['ticket_type_name']?:'Ingresso')?></strong><br><small><?=Security::e($t['batch_name'])?> · <?=Security::e($t['code'])?></small></div><span>Abrir ingresso →</span></a><?php endforeach;?></div><?php endif;?>
</section>
<div class="foot"><?=$showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName)?></div>
</main>
<?php if($autoRefresh):?><div class="sync-indicator">Acompanhamento atualizado automaticamente</div><?php endif;?>
<script src="<?=Security::e(app_url('assets/order-premium-v5.js'))?>" defer></script>
<?php if(!$paid&&!$cancelled&&$hasNativePayment):?>
<script>
(()=>{
'use strict';
const endpoint=<?=json_encode(app_url('api-order-payment.php'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const orderToken=<?=json_encode($token,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const csrf=<?=json_encode($csrf,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const configuredDocument=<?=$paymentProfile['document_configured']?'true':'false'?>;
const feedback=document.getElementById('payment-feedback');
let polling=null;
function show(message,type=''){if(!feedback)return;feedback.className='native-feedback show'+(type?' '+type:'');feedback.textContent=message;}
function digits(v){return String(v||'').replace(/\D+/g,'');}
function moneyToCents(v){const n=Number(String(v||'').trim().replace(/\./g,'').replace(',','.'));return Number.isFinite(n)?Math.max(0,Math.round(n*100)):null;}
async function parseResponse(response){let data=null;try{data=await response.json()}catch(_){throw new Error('Resposta inválida do servidor.')}if(!response.ok||data?.ok===false)throw new Error(data?.message||'Não foi possível concluir.');return data;}
async function post(action,payload={}){const response=await fetch(endpoint+'?action='+encodeURIComponent(action),{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({t:orderToken,_csrf:csrf,...payload})});return parseResponse(response);}
async function savePayer(email,document,required=true){email=String(email||'').trim();document=digits(document);if(!email.includes('@'))throw new Error('Informe um e-mail válido.');if(document.length===11||document.length===14){const result=await post('profile',{email,document});return result.profile;}if(required||!configuredDocument)throw new Error('Informe CPF ou CNPJ válido do pagador.');return null;}
async function status(){const response=await fetch(endpoint+'?action=status&t='+encodeURIComponent(orderToken),{credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json'}});const data=await parseResponse(response);if(String(data.payment?.payment_status||'').toLowerCase()==='paid'){clearInterval(polling);show('Pagamento confirmado. Atualizando pedido…','ok');setTimeout(()=>location.reload(),700);return true;}return false;}
function startPolling(){clearInterval(polling);status().catch(()=>{});polling=setInterval(()=>status().catch(()=>{}),5000);}

const pixButton=document.getElementById('generate-pix');
if(pixButton){pixButton.addEventListener('click',async()=>{pixButton.disabled=true;try{const email=document.getElementById('payer-email')?.value||'';const doc=document.getElementById('payer-document')?.value||'';if(digits(doc).length||!configuredDocument)await savePayer(email,doc,!configuredDocument);else if(!String(email).includes('@'))throw new Error('Informe um e-mail válido.');show('Gerando seu PIX…');const result=await post('pix',{provider:<?=json_encode($pixProvider)?>});const payment=result.payment||{};const code=String(payment.copy_paste||'');if(!code)throw new Error('O provedor não retornou o PIX Copia e Cola.');document.getElementById('pix-code').textContent=code;const image=document.getElementById('pix-image');if(image&&payment.image_url){image.src=payment.image_url;image.hidden=false;}const expiry=document.getElementById('pix-expiry');if(expiry&&payment.expires_at)expiry.textContent='Válido até '+payment.expires_at;document.getElementById('pix-result')?.classList.add('show');show('PIX criado. A confirmação será feita automaticamente pelo servidor.','ok');startPolling();}catch(error){show(error.message||'Não foi possível gerar o PIX.','error');}finally{pixButton.disabled=false;}});}
const copyPix=document.getElementById('copy-pix');if(copyPix)copyPix.addEventListener('click',async()=>{const code=document.getElementById('pix-code')?.textContent||'';if(!code)return;try{await navigator.clipboard.writeText(code);show('Código PIX copiado.','ok');}catch(_){show('Selecione e copie o código PIX acima.');}});

const cashButton=document.getElementById('select-cash');if(cashButton)cashButton.addEventListener('click',async()=>{cashButton.disabled=true;try{const raw=document.getElementById('cash-change')?.value||'';const change=raw.trim()===''?null:moneyToCents(raw);if(raw.trim()!==''&&change===null)throw new Error('Informe um valor de troco válido.');await post('cash',{change_for_cents:change});show('Pagamento em dinheiro selecionado. A equipe confirmará o recebimento.','ok');}catch(error){show(error.message||'Não foi possível selecionar dinheiro.','error');}finally{cashButton.disabled=false;}});

<?php if(is_array($cardMethod)):?>
const cardFormElement=document.getElementById('form-checkout');
if(cardFormElement&&window.MercadoPago){
  const mp=new MercadoPago(<?=json_encode((string)$cardMethod['public_key'])?>,{locale:'pt-BR'});
  const cardForm=mp.cardForm({
    amount:<?=json_encode(number_format(((int)$order['total_cents'])/100,2,'.',''))?>,
    iframe:true,
    form:{
      id:'form-checkout',
      cardNumber:{id:'form-checkout__cardNumber',placeholder:'Número do cartão'},
      expirationDate:{id:'form-checkout__expirationDate',placeholder:'MM/AA'},
      securityCode:{id:'form-checkout__securityCode',placeholder:'CVV'},
      cardholderName:{id:'form-checkout__cardholderName',placeholder:'Nome no cartão'},
      issuer:{id:'form-checkout__issuer',placeholder:'Banco emissor'},
      installments:{id:'form-checkout__installments',placeholder:'Parcelas'},
      identificationType:{id:'form-checkout__identificationType',placeholder:'Tipo de documento'},
      identificationNumber:{id:'form-checkout__identificationNumber',placeholder:'CPF/CNPJ'},
      cardholderEmail:{id:'form-checkout__cardholderEmail',placeholder:'E-mail'}
    },
    callbacks:{
      onFormMounted:error=>{if(error)show('Não foi possível carregar os campos seguros do cartão.','error');},
      onSubmit:async event=>{
        event.preventDefault();const submit=document.getElementById('card-submit');submit.disabled=true;
        try{
          const data=cardForm.getCardFormData();const selected=document.querySelector('input[name="eventmenu-card-type"]:checked')?.value||'';if(!selected)throw new Error('Escolha crédito ou débito.');const doc=digits(data.identificationNumber||'');const email=String(data.cardholderEmail||'').trim();await savePayer(email,doc,true);show('Enviando pagamento tokenizado…');
          const installments=selected==='debit_card'?1:Math.max(1,Number(data.installments||1));
          const result=await post('card',{card_token:data.token,payment_method_id:data.paymentMethodId,payment_type_id:selected,installments,issuer_id:data.issuerId||''});const statusValue=String(result.payment?.status||'').toLowerCase();if(statusValue==='paid'){show('Pagamento aprovado. Atualizando pedido…','ok');setTimeout(()=>location.reload(),700);}else{show('Pagamento enviado. Aguardando confirmação do Mercado Pago.','ok');startPolling();}
        }catch(error){show(error.message||'Não foi possível processar o cartão.','error');}finally{submit.disabled=false;}
      },
      onFetching:resource=>{const progress=document.querySelector('.progress-bar');if(progress)progress.removeAttribute('value');return()=>progress?.setAttribute('value','0');}
    }
  });
}
<?php endif;?>

if(<?=json_encode(in_array((string)$order['payment_status'],['pending','processing','created'],true))?>)startPolling();
})();
</script>
<?php endif;?>
</body>
</html>
