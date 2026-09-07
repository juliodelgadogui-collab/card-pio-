<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\OrderFulfillmentService;
use EventMenu\Services\TenantBrandService;

$pdo=Database::connection();
$token=trim((string)($_GET['t']??''));
$error=null;

function p_money(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
function p_qty(float|int|string $v):string{$q=(float)$v;return abs($q-round($q))<0.0005?(string)(int)round($q):rtrim(rtrim(number_format($q,3,',','.'),'0'),',');}
function p_channel(string $c):string{return match($c){'pickup'=>'Retirada no local','counter'=>'Balcão','delivery'=>'Delivery','table'=>'Mesa','event'=>'Evento','bar','event_bar'=>'Bar do evento',default=>'Pedido'};}
function p_payment_status(string $s):string{return match(strtolower($s)){'paid'=>'Pago','pending','processing'=>'Pagamento em processamento','refunded'=>'Estornado','failed','cancelled'=>'Pagamento não concluído',default=>'Aguardando pagamento'};}
function p_order_status(string $s):string{return match(strtolower($s)){'pending'=>'Recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto para retirada','out_for_delivery'=>'Saiu para entrega','served','completed'=>'Concluído','cancelled'=>'Cancelado',default=>'Em andamento'};}
function p_friendly_error(Throwable $e):string{
    $message=trim((string)$e->getMessage());
    if($message==='')return 'Não foi possível iniciar o pagamento. Tente novamente.';
    $message=preg_replace('/^(financeiro|api|servidor|gateway)\s*:\s*/iu','',$message)??$message;
    if(preg_match('/sql|sqlite|mysql|pdo|http\s*\d|json|token|exception|database|stack/iu',$message))return 'Não foi possível iniciar o pagamento. Tente novamente.';
    return $message;
}
function allowed_checkout_url(string $provider,string $url):bool{$p=parse_url($url);if(($p['scheme']??'')!=='https'||empty($p['host']))return false;$h=strtolower($p['host']);$allowed=['stripe'=>['stripe.com'],'mercadopago'=>['mercadopago.com','mercadopago.com.br'],'pagbank'=>['pagseguro.uol.com.br','pagbank.com.br']];foreach($allowed[$provider]??[]as$suffix){if($h===$suffix||str_ends_with($h,'.'.$suffix))return true;}return false;}

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
$s->execute([$token]);
$order=$s->fetch();
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

$items=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$order['id']]);$items=$items->fetchAll();
$g=$pdo->prepare('SELECT provider FROM payment_gateways WHERE tenant_id=? AND active=1 AND provider IN ("stripe","pagbank","mercadopago") ORDER BY provider');$g->execute([$order['tenant_id']]);$gateways=$g->fetchAll(PDO::FETCH_COLUMN);
$tickets=$pdo->prepare('SELECT t.*,e.name event_name,e.starts_at event_starts_at,e.venue event_venue,e.address event_address,b.name batch_name,tt.name ticket_type_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.order_id=? ORDER BY t.id');$tickets->execute([$order['id']]);$tickets=$tickets->fetchAll();
$eventInfo=$tickets?$tickets[0]:null;
if(!$eventInfo&&!empty($order['event_id'])){$ev=$pdo->prepare('SELECT name event_name,starts_at event_starts_at,venue event_venue,address event_address FROM events WHERE id=? AND tenant_id=? LIMIT 1');$ev->execute([(int)$order['event_id'],(int)$order['tenant_id']]);$eventInfo=$ev->fetch()?:null;if($eventInfo){$eventInfo['ticket_type_name']='';$eventInfo['batch_name']='';}}
$paid=(string)$order['payment_status']==='paid';
$cancelled=(string)$order['status']==='cancelled';
$isEventBar=in_array((string)$order['channel'],['bar','event_bar'],true)&&!empty($order['event_id']);
$fulfillment=new OrderFulfillmentService();
$hasOrderQr=($fulfillment->supportsChannel((string)$order['channel'])||$isEventBar)&&!empty($order['public_token']);
$showOrderQr=$hasOrderQr&&(!$isEventBar||$paid);
$qrDataUri=$showOrderQr?$fulfillment->qrDataUri((string)$order['public_token'],300):null;
try{$progress=$hasOrderQr&&!$isEventBar?$fulfillment->publicProgress((int)$order['tenant_id'],(int)$order['id']):null;}catch(Throwable){$progress=null;}
$customer=trim((string)($order['customer_name']??''));
if($customer===''||strtolower($customer)==='null')$customer='Cliente';
$autoRefresh=!$cancelled&&(!$paid||($isEventBar&&!in_array((string)$order['status'],['completed','cancelled'],true)));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<meta http-equiv="Cache-Control" content="no-store">
<title>Pedido #<?= (int)$order['id'] ?> — <?= Security::e($brandName) ?></title>
<style>
:root{--primary:<?= Security::e($primary) ?>;--secondary:<?= Security::e($secondary) ?>;--bg:<?= Security::e($background) ?>;--surface:<?= Security::e($surface) ?>;--text:<?= Security::e($text) ?>;--muted:color-mix(in srgb,var(--text) 62%,transparent);--line:color-mix(in srgb,var(--text) 12%,transparent);--soft:color-mix(in srgb,var(--primary) 10%,var(--surface));--ok:<?= Security::e($secondary) ?>;--danger:#b23d4a;--shadow:0 14px 40px rgba(35,28,58,.08)}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}button,input{font:inherit}.shell{width:min(820px,100%);margin:auto;padding:18px 16px 70px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:16px}.brand-wrap{display:flex;align-items:center;gap:11px;min-width:0}.brand-logo{width:44px;height:44px;border-radius:13px;object-fit:cover;background:var(--soft)}.brand-mark{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:var(--primary);color:#fff;font-weight:950;font-size:20px}.brand-name{font-size:19px;font-weight:950;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.org{font-size:12px;color:var(--muted)}.card{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:22px;box-shadow:var(--shadow)}.status-row{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.channel,.status-pill,.payment{display:inline-flex;padding:7px 10px;border-radius:999px;font-size:11px;font-weight:850}.channel{background:var(--soft);color:var(--primary)}.status-pill{background:color-mix(in srgb,var(--secondary) 12%,var(--surface));color:var(--secondary)}.payment{background:color-mix(in srgb,var(--text) 6%,var(--surface));color:var(--muted)}.payment.paid{background:color-mix(in srgb,var(--secondary) 13%,var(--surface));color:var(--secondary)}.title h1{font-size:34px;letter-spacing:-1px;margin:10px 0 3px}.title p{margin:0;color:var(--muted)}.title-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}.event-box{margin:18px 0;padding:16px;border-radius:16px;background:linear-gradient(135deg,color-mix(in srgb,var(--primary) 75%,#111),var(--primary));color:#fff}.event-box small{opacity:.75}.event-box h2{margin:5px 0 7px;font-size:22px}.event-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:12px;opacity:.9}.items{margin:18px 0;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}.item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:15px;padding:13px 0;border-bottom:1px solid var(--line)}.item:last-child{border-bottom:0}.item strong{display:block}.item small{color:var(--muted)}.totals{display:grid;gap:8px;margin:14px 0}.total-row{display:flex;justify-content:space-between;gap:12px}.total-row.grand{font-size:21px;font-weight:950;padding-top:10px;border-top:1px solid var(--line)}.alert{padding:13px 14px;border-radius:13px;margin:15px 0;background:color-mix(in srgb,#e69b28 11%,var(--surface));border:1px solid color-mix(in srgb,#e69b28 28%,var(--surface));color:var(--text)}.alert.ok{background:color-mix(in srgb,var(--secondary) 10%,var(--surface));border-color:color-mix(in srgb,var(--secondary) 25%,var(--surface));color:var(--secondary)}.alert.error{background:#fff0f1;border-color:#efc9ce;color:#9a3844}.section-title{margin:24px 0 10px}.section-title h2{margin:0;font-size:21px}.pay-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.pay-grid button,.button{width:100%;border:0;border-radius:12px;padding:13px 15px;font-weight:900;cursor:pointer}.primary{background:var(--primary);color:#fff}.qr-card{display:grid;grid-template-columns:210px minmax(0,1fr);gap:20px;align-items:center;margin-top:20px;padding:18px;border-radius:18px;background:var(--soft);border:1px solid var(--line)}.qr-box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:10px}.qr-box img{display:block;width:100%;height:auto}.qr-card h2{margin:4px 0 8px}.progress{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}.progress div{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px}.progress small,.progress strong{display:block}.progress small{font-size:10px;color:var(--muted)}.progress strong{font-size:20px}.ticket-list{display:grid;gap:9px;margin-top:10px}.ticket-link{display:flex;justify-content:space-between;gap:12px;align-items:center;border:1px solid var(--line);border-radius:13px;padding:12px 14px;color:var(--text);text-decoration:none}.ticket-link strong{color:var(--primary)}.foot{text-align:center;color:var(--muted);font-size:11px;padding:24px 0}
@media(max-width:620px){.shell{padding:12px 10px 50px}.top{align-items:flex-start}.org{display:none}.card{padding:17px;border-radius:16px}.title h1{font-size:29px}.status-row{flex-direction:column}.qr-card{grid-template-columns:1fr}.qr-box{max-width:245px;margin:auto}.progress{grid-template-columns:repeat(3,1fr)}.event-meta{display:grid;gap:5px}.ticket-link{align-items:flex-start;flex-direction:column}}
</style>
</head>
<body>
<main class="shell">
<header class="top">
  <div class="brand-wrap">
    <?php if($logo!==''):?><img class="brand-logo" src="<?= Security::e($logo) ?>" alt="Logo de <?= Security::e($brandName) ?>"><?php else:?><div class="brand-mark"><?= Security::e(mb_strtoupper(mb_substr($brandName,0,1))) ?></div><?php endif;?>
    <div><div class="brand-name"><?= Security::e($brandName) ?></div><?php if($tagline!==''):?><div class="org"><?= Security::e($tagline) ?></div><?php endif;?></div>
  </div>
  <div class="org">Acompanhe seu pedido</div>
</header>
<section class="card">
  <div class="status-row">
    <div class="title">
      <span class="channel"><?= Security::e(p_channel((string)$order['channel'])) ?></span>
      <h1>Pedido #<?= (int)$order['id'] ?></h1>
      <p><?= Security::e($customer) ?> · <?= Security::e(date('d/m/Y · H:i',strtotime((string)$order['created_at']))) ?></p>
      <div class="title-meta"><span class="status-pill"><?= Security::e(p_order_status((string)$order['status'])) ?></span><span class="payment<?= $paid?' paid':'' ?>"><?= Security::e(p_payment_status((string)$order['payment_status'])) ?></span></div>
    </div>
  </div>

<?php if($eventInfo):?><div class="event-box"><small><?= $isEventBar?'PEDIDO NO EVENTO':'SEU EVENTO' ?></small><h2><?= Security::e($eventInfo['event_name']) ?></h2><div class="event-meta"><span><?= Security::e(date('d/m/Y · H:i',strtotime((string)$eventInfo['event_starts_at']))) ?></span><?php if($eventInfo['event_venue']):?><span>• <?= Security::e($eventInfo['event_venue']) ?></span><?php endif;?><?php if($eventInfo['ticket_type_name']):?><span>• <?= Security::e($eventInfo['ticket_type_name']) ?></span><?php endif;?></div></div><?php endif;?>
<?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?>

<div class="items"><?php foreach($items as$i):?><div class="item"><div><strong><?= Security::e($i['name_snapshot']) ?></strong><small><?= p_qty($i['quantity']) ?> × <?= p_money((int)$i['unit_price_cents']) ?></small></div><strong><?= p_money((int)$i['total_cents']) ?></strong></div><?php endforeach;?></div>
<div class="totals"><?php if((int)$order['discount_cents']>0):?><div class="total-row"><span>Desconto</span><strong>- <?= p_money((int)$order['discount_cents']) ?></strong></div><?php endif;?><?php if((int)($order['delivery_fee_cents']??0)>0):?><div class="total-row"><span>Taxa de entrega</span><strong><?= p_money((int)$order['delivery_fee_cents']) ?></strong></div><?php endif;?><div class="total-row grand"><span>Total</span><span><?= p_money((int)$order['total_cents']) ?></span></div></div>

<?php if($cancelled):?>
  <div class="alert error">Este pedido foi cancelado.</div>
<?php elseif($paid):?>
  <div class="alert ok"><?= $isEventBar&&$order['status']==='ready'?'Seu pedido está pronto. Vá ao bar e apresente o QR abaixo.':'Pagamento confirmado. Continue acompanhando o andamento do pedido por aqui.' ?></div>
<?php elseif((int)$order['total_cents']>0):?>
  <div class="section-title"><h2>Escolha como pagar</h2></div>
  <?php if($gateways):?><div class="pay-grid"><?php foreach($gateways as$provider):?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="provider" value="<?= Security::e($provider) ?>"><button class="primary">Pagar com <?= Security::e(['stripe'=>'Stripe','mercadopago'=>'Mercado Pago','pagbank'=>'PagBank'][$provider]??'pagamento online') ?></button></form><?php endforeach;?></div><p style="color:var(--muted);font-size:12px">Você será direcionado com segurança para concluir o pagamento.</p><?php else:?><div class="alert">O pagamento online não está disponível para este pedido. Entre em contato com <?= Security::e($brandName) ?>.</div><?php endif;?>
<?php endif;?>

<?php if($showOrderQr):?><section class="qr-card"><div class="qr-box"><img src="<?= Security::e((string)$qrDataUri) ?>" alt="QR do pedido"></div><div><small style="font-weight:900;color:var(--primary)">QR DO PEDIDO</small><h2><?= $isEventBar?'Apresente no bar':($order['channel']==='pickup'?'Apresente na retirada':'Identificação do pedido') ?></h2><p style="color:var(--muted)"><?= $isEventBar?'Quando o pedido estiver pronto, mostre este QR no bar. A equipe confere os itens e confirma a entrega pelo EventMenu GO.':($order['channel']==='pickup'?'Mostre este QR ao responsável pela retirada.':'Use este QR quando a equipe solicitar a identificação do pedido.') ?></p><?php if($progress):?><div class="progress"><div><small>Pedido</small><strong><?= p_qty($progress['ordered_quantity']) ?></strong></div><div><small>Entregue</small><strong><?= p_qty($progress['fulfilled_quantity']) ?></strong></div><div><small>Falta</small><strong><?= p_qty($progress['remaining_quantity']) ?></strong></div></div><?php endif;?></div></section><?php elseif($isEventBar&&!$paid):?><div class="alert">O QR de retirada será liberado aqui assim que o pagamento for confirmado.</div><?php elseif($order['channel']==='table'):?><div class="alert ok">Pedido vinculado à mesa. Não é necessário apresentar QR.</div><?php endif;?>

<?php if($paid&&$tickets):?><div class="section-title"><h2>Seus ingressos</h2></div><div class="ticket-list"><?php foreach($tickets as$t):$ticketUrl=app_url('ingresso.php?t='.rawurlencode((string)$t['qr_token']));?><a class="ticket-link" href="<?= Security::e($ticketUrl) ?>"><div><strong><?= Security::e($t['ticket_type_name']?:'Ingresso') ?></strong><br><small><?= Security::e($t['batch_name']) ?> · <?= Security::e($t['code']) ?></small></div><span>Abrir ingresso →</span></a><?php endforeach;?></div><?php endif;?>
</section>
<div class="foot"><?= $showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName) ?></div>
</main>
<?php if($autoRefresh):?><script>setTimeout(()=>location.reload(),15000);</script><?php endif;?>
</body>
</html>