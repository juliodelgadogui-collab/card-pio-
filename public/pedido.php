<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\DeliveryPublicTrackingService;
use EventMenu\Services\OrderFulfillmentService;
use EventMenu\Services\TenantBrandService;

$pdo=Database::connection();
$token=trim((string)($_GET['t']??''));
$error=null;

function p_money(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
function p_qty(float|int|string $v):string{$q=(float)$v;return abs($q-round($q))<0.0005?(string)(int)round($q):rtrim(rtrim(number_format($q,3,',','.'),'0'),',');}
function p_channel(string $c):string{return match($c){'pickup'=>'Retirada no local','counter'=>'Balcão','delivery'=>'Entrega','table'=>'Mesa','event'=>'Evento','bar','event_bar'=>'Bar do evento',default=>'Pedido'};}
function p_payment_status(string $s):string{return match(strtolower($s)){'paid'=>'Pagamento confirmado','pending','processing','created'=>'Aguardando pagamento','refunded','partially_refunded'=>'Pagamento estornado','failed','cancelled'=>'Pagamento não concluído',default=>'Aguardando pagamento'};}
function p_order_status(string $s):string{return match(strtolower($s)){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','served'=>'Servido','completed'=>'Concluído','cancelled'=>'Cancelado',default=>'Em andamento'};}
function p_friendly_error(Throwable $e):string{
    $message=trim((string)$e->getMessage());
    if($message==='')return 'Não foi possível iniciar o pagamento. Tente novamente.';
    $message=preg_replace('/^(financeiro|api|servidor|gateway)\s*:\s*/iu','',$message)??$message;
    if(preg_match('/sql|sqlite|mysql|pdo|http\s*\d|json|token|exception|database|stack|provider|webhook|endpoint/iu',$message))return 'Não foi possível iniciar o pagamento. Tente novamente.';
    return mb_substr($message,0,200);
}
function allowed_checkout_url(string $provider,string $url):bool{
    $p=parse_url($url);if(($p['scheme']??'')!=='https'||empty($p['host']))return false;
    $h=strtolower($p['host']);
    $allowed=['stripe'=>['stripe.com'],'mercadopago'=>['mercadopago.com','mercadopago.com.br'],'pagbank'=>['pagseguro.uol.com.br','pagbank.com.br']];
    foreach($allowed[$provider]??[]as$suffix)if($h===$suffix||str_ends_with($h,'.'.$suffix))return true;
    return false;
}
function p_timeline(string$channel,string$status):array{
    if($channel==='delivery')$steps=[['pending','Pedido recebido','Recebemos seu pedido.'],['preparing','Em preparo','A equipe está preparando tudo.'],['ready','Pronto','Pedido liberado para o entregador.'],['out_for_delivery','Saiu para entrega','Seu pedido está a caminho.'],['completed','Entregue','Entrega concluída.']];
    elseif($channel==='table')$steps=[['pending','Pedido recebido','Pedido enviado para atendimento.'],['preparing','Em preparo','A produção está preparando seu pedido.'],['ready','Pronto','Pedido pronto para servir.'],['completed','Servido','Atendimento concluído.']];
    else$steps=[['pending','Pedido recebido','Recebemos seu pedido.'],['preparing','Em preparo','A equipe está preparando tudo.'],['ready',$channel==='pickup'?'Pronto para retirada':'Pronto','Seu pedido está pronto.'],['completed','Concluído','Pedido finalizado.']];
    $rank=['pending'=>0,'confirmed'=>1,'preparing'=>1,'ready'=>2,'out_for_delivery'=>3,'served'=>3,'completed'=>99,'cancelled'=>-1];
    $current=$rank[$status]??0;
    foreach($steps as$i=>&$step){$step['done']=$status==='completed'||($status!=='cancelled'&&$i<$current);$step['current']=$status!=='cancelled'&&$status!=='completed'&&$i===$current;}
    unset($step);return$steps;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='A página expirou. Atualize e tente novamente.';
    else{
        try{
            $provider=(string)($_POST['provider']??'');
            $checkout=(new CheckoutService())->create($token,$provider);
            if(!allowed_checkout_url($checkout['provider'],$checkout['url']))throw new RuntimeException('Não foi possível abrir o pagamento com segurança.');
            header('Location: '.$checkout['url'],true,303);exit;
        }catch(Throwable $e){$error=p_friendly_error($e);}
    }
}

$s=$pdo->prepare('SELECT o.*,t.name tenant_name,t.slug tenant_slug,c.name customer_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.public_token=? AND t.status="active" LIMIT 1');
$s->execute([$token]);$order=$s->fetch();
if(!$order){http_response_code(404);exit('Pedido não encontrado.');}

$brandService=new TenantBrandService();
$tenantBrand=$brandService->get((int)$order['tenant_id']);
$visual=(bool)$tenantBrand['apply_web']?$tenantBrand:$brandService->defaults();
$brandName=trim((string)$tenantBrand['display_name'])?:((string)$order['tenant_name']);
$tagline=trim((string)$tenantBrand['tagline']);
$logo=(bool)$tenantBrand['apply_web']?trim((string)$tenantBrand['logo_url']):'';
$primary=(string)$visual['primary_color'];
$secondary=(string)$visual['secondary_color'];
$background=(string)$visual['background_color'];
$surface=(string)$visual['surface_color'];
$text=(string)$visual['text_color'];
$showEventMenu=(bool)$tenantBrand['show_eventmenu_brand'];

$itemsQ=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$itemsQ->execute([$order['id']]);$items=$itemsQ->fetchAll();
$g=$pdo->prepare('SELECT provider FROM payment_gateways WHERE tenant_id=? AND active=1 AND provider IN ("stripe","pagbank","mercadopago") ORDER BY provider');$g->execute([$order['tenant_id']]);$gateways=$g->fetchAll(PDO::FETCH_COLUMN);
$ticketsQ=$pdo->prepare('SELECT t.*,e.name event_name,e.starts_at event_starts_at,e.venue event_venue,e.address event_address,b.name batch_name,tt.name ticket_type_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.order_id=? ORDER BY t.id');$ticketsQ->execute([$order['id']]);$tickets=$ticketsQ->fetchAll();
$eventInfo=$tickets?$tickets[0]:null;
if(!$eventInfo&&!empty($order['event_id'])){
    $ev=$pdo->prepare('SELECT name event_name,starts_at event_starts_at,venue event_venue,address event_address FROM events WHERE id=? AND tenant_id=? LIMIT 1');
    $ev->execute([(int)$order['event_id'],(int)$order['tenant_id']]);$eventInfo=$ev->fetch()?:null;
    if($eventInfo){$eventInfo['ticket_type_name']='';$eventInfo['batch_name']='';}
}
$paid=(string)$order['payment_status']==='paid';
$cancelled=(string)$order['status']==='cancelled';
$isEventBar=in_array((string)$order['channel'],['bar','event_bar'],true)&&!empty($order['event_id']);
$fulfillment=new OrderFulfillmentService();
$hasOrderQr=($fulfillment->supportsChannel((string)$order['channel'])||$isEventBar)&&!empty($order['public_token']);
$showOrderQr=$hasOrderQr&&(!$isEventBar||$paid);
$qrDataUri=$showOrderQr?$fulfillment->qrDataUri((string)$order['public_token'],300):null;
try{$progress=$hasOrderQr&&!$isEventBar?$fulfillment->publicProgress((int)$order['tenant_id'],(int)$order['id']):null;}catch(Throwable){$progress=null;}
$trackingLink=null;
if((string)$order['channel']==='delivery'){
    try{$trackingLink=(new DeliveryPublicTrackingService())->existingForPublicOrder((int)$order['tenant_id'],(int)$order['id'],(string)$order['public_token']);}catch(Throwable){$trackingLink=null;}
}
$customer=trim((string)($order['customer_name']??''));
if($customer===''||strtolower($customer)==='null')$customer='Cliente';
$autoRefresh=!$cancelled&&(!$paid||!in_array((string)$order['status'],['completed','cancelled'],true));
$timeline=p_timeline((string)$order['channel'],(string)$order['status']);
$returnState=trim((string)($_GET['retorno']??''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<meta http-equiv="Cache-Control" content="no-store">
<title>Pedido #<?= (int)$order['id'] ?> — <?= Security::e($brandName) ?></title>
<link rel="stylesheet" href="<?= Security::e(app_url('assets/order-premium-v5.css')) ?>">
<style>:root{--op-primary:<?= Security::e($primary) ?>;--op-secondary:<?= Security::e($secondary) ?>;--op-bg:<?= Security::e($background) ?>;--op-surface:<?= Security::e($surface) ?>;--op-text:<?= Security::e($text) ?>}</style>
</head>
<body>
<main class="shell" data-order-page data-auto-refresh="<?= $autoRefresh?'1':'0' ?>">
<header class="top">
  <div class="brand-wrap">
    <?php if($logo!==''):?><img class="brand-logo" src="<?= Security::e($logo) ?>" alt="Logo de <?= Security::e($brandName) ?>" width="46" height="46"><?php else:?><div class="brand-mark" aria-hidden="true"><?= Security::e(mb_strtoupper(mb_substr($brandName,0,1))) ?></div><?php endif;?>
    <div><div class="brand-name"><?= Security::e($brandName) ?></div><?php if($tagline!==''):?><div class="org"><?= Security::e($tagline) ?></div><?php endif;?></div>
  </div>
  <div class="top-state">Acompanhe tudo por aqui</div>
</header>

<section class="card">
  <div class="status-row">
    <div class="title">
      <span class="channel"><?= Security::e(p_channel((string)$order['channel'])) ?></span>
      <h1>Pedido #<?= (int)$order['id'] ?></h1>
      <p><?= Security::e($customer) ?> · <?= Security::e(date('d/m/Y · H:i',strtotime((string)$order['created_at']))) ?></p>
      <div class="title-meta">
        <span class="status-pill"><?= Security::e(p_order_status((string)$order['status'])) ?></span>
        <span class="payment-pill<?= $paid?' paid':'' ?>"><?= $paid?'✓ ':'' ?><?= Security::e(p_payment_status((string)$order['payment_status'])) ?></span>
      </div>
    </div>
  </div>

  <?php if($eventInfo):?>
  <div class="event-box">
    <small><?= $isEventBar?'PEDIDO NO EVENTO':'SEU EVENTO' ?></small>
    <h2><?= Security::e($eventInfo['event_name']) ?></h2>
    <div class="event-meta">
      <span><?= Security::e(date('d/m/Y · H:i',strtotime((string)$eventInfo['event_starts_at']))) ?></span>
      <?php if($eventInfo['event_venue']):?><span>• <?= Security::e($eventInfo['event_venue']) ?></span><?php endif;?>
      <?php if($eventInfo['ticket_type_name']):?><span>• <?= Security::e($eventInfo['ticket_type_name']) ?></span><?php endif;?>
    </div>
  </div>
  <?php endif;?>

  <?php if($error):?><div class="alert error" role="alert"><?= Security::e($error) ?></div><?php endif;?>
  <?php if($returnState==='cancelado'):?><div class="alert">O pagamento não foi concluído. Seu pedido continua aqui para você tentar novamente.</div><?php endif;?>

  <?php if(!$isEventBar):?>
  <section class="timeline-card" aria-label="Andamento do pedido">
    <div class="timeline-head"><strong>Andamento do pedido</strong><span>Atualização automática</span></div>
    <div class="timeline">
      <?php foreach($timeline as$step):?>
      <div class="timeline-step<?= $step['done']?' done':'' ?><?= $step['current']?' current':'' ?>">
        <div class="timeline-dot"><?= $step['done']?'✓':($step['current']?'●':'○') ?></div>
        <div class="timeline-label"><strong><?= Security::e($step[1]) ?></strong><small><?= Security::e($step[2]) ?></small></div>
      </div>
      <?php endforeach;?>
    </div>
    <?php if($trackingLink):?>
    <div class="tracking-action"><span>O entregador está em rota. Você pode acompanhar a posição enquanto o rastreamento estiver ativo.</span><a href="<?= Security::e($trackingLink['url']) ?>">Acompanhar entrega</a></div>
    <?php endif;?>
  </section>
  <?php endif;?>

  <div class="items">
    <?php foreach($items as$i):?>
    <div class="item"><div><strong><?= Security::e($i['name_snapshot']) ?></strong><small><?= p_qty($i['quantity']) ?> × <?= p_money((int)$i['unit_price_cents']) ?></small></div><strong><?= p_money((int)$i['total_cents']) ?></strong></div>
    <?php endforeach;?>
  </div>

  <div class="totals">
    <?php if(isset($order['subtotal_cents'])):?><div class="total-row"><span>Subtotal</span><span><?= p_money((int)$order['subtotal_cents']) ?></span></div><?php endif;?>
    <?php if((int)($order['discount_cents']??0)>0):?><div class="total-row"><span>Desconto</span><span>− <?= p_money((int)$order['discount_cents']) ?></span></div><?php endif;?>
    <?php if((int)($order['delivery_fee_cents']??0)>0):?><div class="total-row"><span>Entrega</span><span><?= p_money((int)$order['delivery_fee_cents']) ?></span></div><?php endif;?>
    <div class="total-row grand"><span>Total</span><span><?= p_money((int)$order['total_cents']) ?></span></div>
  </div>

  <?php if($cancelled):?>
    <div class="alert error">Este pedido foi cancelado.</div>
  <?php elseif($paid):?>
    <div class="alert ok">✓ <?= $isEventBar&&$order['status']==='ready'?'Pagamento confirmado. Seu pedido está pronto para retirada.':'Pagamento confirmado. Continue acompanhando o andamento do pedido por aqui.' ?></div>
  <?php elseif((int)$order['total_cents']>0):?>
    <div class="section-title"><h2>Pagamento</h2><small>Ambiente seguro</small></div>
    <?php if($gateways):?>
    <div class="payment-card">
      <h3>Continuar para pagamento</h3>
      <p>Escolha uma opção disponível. A confirmação volta automaticamente para o EventMenu.</p>
      <div class="pay-grid">
        <?php foreach($gateways as$index=>$provider):?>
        <form method="post" data-payment-form>
          <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
          <input type="hidden" name="provider" value="<?= Security::e($provider) ?>">
          <button class="<?= $index===0?'primary':'secondary-pay' ?>"><?= $index===0?'Continuar para pagamento':'Outra opção de pagamento' ?></button>
        </form>
        <?php endforeach;?>
      </div>
      <div class="secure-note">🔒 Seus dados de pagamento não ficam expostos nesta página.</div>
    </div>
    <?php else:?><div class="alert">O pagamento online não está disponível para este pedido. Entre em contato com <?= Security::e($brandName) ?>.</div><?php endif;?>
  <?php endif;?>

  <?php if($showOrderQr):?>
  <section class="qr-card">
    <div class="qr-box"><img src="<?= Security::e((string)$qrDataUri) ?>" alt="QR do pedido"></div>
    <div>
      <small style="font-weight:900;color:var(--op-primary)">QR DO PEDIDO</small>
      <h2><?= $isEventBar?'Apresente no bar':($order['channel']==='pickup'?'Apresente na retirada':'Identificação do pedido') ?></h2>
      <p><?= $isEventBar?'Quando o pedido estiver pronto, mostre este QR no bar. A equipe confere e confirma a retirada.':($order['channel']==='pickup'?'Mostre este QR ao responsável pela retirada.':'Use este QR quando a equipe solicitar a identificação do pedido.') ?></p>
      <?php if($progress):?><div class="progress"><div><small>Pedido</small><strong><?= p_qty($progress['ordered_quantity']) ?></strong></div><div><small>Entregue</small><strong><?= p_qty($progress['fulfilled_quantity']) ?></strong></div><div><small>Falta</small><strong><?= p_qty($progress['remaining_quantity']) ?></strong></div></div><?php endif;?>
    </div>
  </section>
  <?php elseif($isEventBar&&!$paid):?><div class="alert">O QR de retirada será liberado assim que o pagamento for confirmado.</div>
  <?php elseif($order['channel']==='table'):?><div class="alert ok">Pedido vinculado à mesa. Não é necessário apresentar QR.</div><?php endif;?>

  <?php if($paid&&$tickets):?>
  <div class="section-title"><h2>Seus ingressos</h2></div>
  <div class="ticket-list">
    <?php foreach($tickets as$t):$ticketUrl=app_url('ingresso.php?t='.rawurlencode((string)$t['qr_token']));?>
    <a class="ticket-link" href="<?= Security::e($ticketUrl) ?>"><div><strong><?= Security::e($t['ticket_type_name']?:'Ingresso') ?></strong><br><small><?= Security::e($t['batch_name']) ?> · <?= Security::e($t['code']) ?></small></div><span>Abrir ingresso →</span></a>
    <?php endforeach;?>
  </div>
  <?php endif;?>
</section>
<div class="foot"><?= $showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName) ?></div>
</main>
<?php if($autoRefresh):?><div class="sync-indicator">Acompanhamento atualizado automaticamente</div><?php endif;?>
<script src="<?= Security::e(app_url('assets/order-premium-v5.js')) ?>" defer></script>
</body>
</html>
