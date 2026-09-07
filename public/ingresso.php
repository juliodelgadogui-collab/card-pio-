<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\TenantBrandService;

function ticket_status_label(string $status): string
{
    return match (strtolower($status)) {
        'reserved' => 'Aguardando pagamento',
        'paid' => 'Válido',
        'checked_in' => 'Utilizado',
        'cancelled' => 'Cancelado',
        'refunded' => 'Estornado',
        default => 'Indisponível',
    };
}

function ticket_friendly_datetime(?string $value): string
{
    $time = $value ? strtotime($value) : false;
    return $time ? date('d/m/Y · H:i', $time) : '';
}

$token=trim((string)($_GET['t']??''));
$pdo=Database::connection();
$s=$pdo->prepare('SELECT t.*,e.name event_name,e.starts_at,e.venue,e.address,e.status event_status,b.name batch_name,c.name customer_name,tn.name tenant_name,tn.status tenant_status FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id JOIN tenants tn ON tn.id=t.tenant_id LEFT JOIN customers c ON c.id=t.customer_id WHERE t.qr_token=? LIMIT 1');
$s->execute([$token]);
$ticket=$s->fetch();
if(!$ticket){http_response_code(404);exit('Ingresso não encontrado.');}

$brandService=new TenantBrandService();
$tenantBrand=$brandService->get((int)$ticket['tenant_id']);
$visual=(bool)$tenantBrand['apply_web']?$tenantBrand:$brandService->defaults();
$brandName=trim((string)$tenantBrand['display_name'])?:((string)$ticket['tenant_name']);
$tagline=trim((string)$tenantBrand['tagline']);
$logo=(bool)$tenantBrand['apply_web']?trim((string)$tenantBrand['logo_url']):'';
$primary=(string)$visual['primary_color'];
$secondary=(string)$visual['secondary_color'];
$background=(string)$visual['background_color'];
$surface=(string)$visual['surface_color'];
$text=(string)$visual['text_color'];
$showEventMenu=(bool)$tenantBrand['show_eventmenu_brand'];

$entryEnabled=$ticket['tenant_status']==='active'&&$ticket['event_status']==='published'&&in_array($ticket['status'],['paid','checked_in'],true);
$qrSvg=null;
if($entryEnabled&&class_exists('Endroid\\QrCode\\QrCode')){
    try{
        $url=app_absolute_url('ingresso.php?t='.rawurlencode($token));
        $qr=new \Endroid\QrCode\QrCode(data:$url,size:320,margin:12);
        $writer=new \Endroid\QrCode\Writer\SvgWriter();
        $qrSvg=$writer->write($qr)->getString();
    }catch(Throwable){$qrSvg=null;}
}

$customer=trim((string)($ticket['customer_name']??''));
if($customer===''||strtolower($customer)==='null')$customer='Ingresso';
$reservedUntil=ticket_friendly_datetime($ticket['reserved_until']??null);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<meta http-equiv="Cache-Control" content="no-store">
<title><?= Security::e($ticket['event_name']) ?> — <?= Security::e($brandName) ?></title>
<style>
:root{--primary:<?= Security::e($primary) ?>;--secondary:<?= Security::e($secondary) ?>;--bg:<?= Security::e($background) ?>;--surface:<?= Security::e($surface) ?>;--text:<?= Security::e($text) ?>;--muted:color-mix(in srgb,var(--text) 62%,transparent);--line:color-mix(in srgb,var(--text) 13%,transparent);--soft:color-mix(in srgb,var(--primary) 10%,var(--surface));--shadow:0 20px 55px rgba(30,24,55,.10)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,color-mix(in srgb,var(--primary) 8%,var(--bg)),var(--bg) 40%);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.ticket-shell{max-width:560px;margin:0 auto;padding:24px 16px 60px}.brand-row{display:flex;align-items:center;gap:11px;margin-bottom:16px}.brand-logo{width:44px;height:44px;border-radius:13px;object-fit:cover;background:var(--soft)}.brand-mark{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:var(--primary);color:#fff;font-weight:950;font-size:20px}.brand-name{font-weight:950;font-size:18px}.brand-tagline{font-size:12px;color:var(--muted);margin-top:2px}.ticket-card{text-align:center;padding:28px;background:var(--surface);border:1px solid var(--line);border-radius:28px;box-shadow:var(--shadow)}.eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--primary);font-weight:900}.ticket-card h1{font-size:30px;line-height:1.06;letter-spacing:-.8px;margin:8px 0 12px}.event-meta{color:var(--muted);line-height:1.55;margin-bottom:18px}.badges{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin:12px 0 18px}.badge{display:inline-flex;padding:7px 10px;border-radius:999px;background:var(--soft);color:var(--primary);font-size:11px;font-weight:850}.status-valid{background:color-mix(in srgb,var(--secondary) 13%,var(--surface));color:var(--secondary)}.holder{font-size:19px;font-weight:850;margin:12px 0}.qr{max-width:330px;margin:18px auto}.qr svg{display:block;max-width:100%;height:auto;background:#fff;border-radius:18px;padding:12px}.qr-unavailable{padding:16px;border-radius:14px;background:var(--soft);color:var(--muted);margin:18px 0}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;word-break:break-all;font-size:13px;color:var(--muted)}.notice{padding:14px;border-radius:13px;margin-top:16px;background:var(--soft);color:var(--text);line-height:1.45}.notice.ok{background:color-mix(in srgb,var(--secondary) 10%,var(--surface));color:var(--secondary)}.notice.error{background:#fff0f1;color:#9a3844}.foot{text-align:center;color:var(--muted);font-size:11px;padding:22px 0}@media(max-width:520px){.ticket-shell{padding:16px 10px 50px}.ticket-card{padding:22px 17px;border-radius:22px}.ticket-card h1{font-size:27px}}
</style>
</head>
<body>
<main class="ticket-shell">
<header class="brand-row">
<?php if($logo!==''):?><img class="brand-logo" src="<?= Security::e($logo) ?>" alt="Logo de <?= Security::e($brandName) ?>"><?php else:?><div class="brand-mark"><?= Security::e(mb_strtoupper(mb_substr($brandName,0,1))) ?></div><?php endif;?>
<div><div class="brand-name"><?= Security::e($brandName) ?></div><?php if($tagline!==''):?><div class="brand-tagline"><?= Security::e($tagline) ?></div><?php endif;?></div>
</header>
<article class="ticket-card">
<span class="eyebrow">Seu ingresso</span>
<h1><?= Security::e($ticket['event_name']) ?></h1>
<div class="event-meta"><?= Security::e(ticket_friendly_datetime((string)$ticket['starts_at'])) ?><?php if(trim((string)$ticket['venue'])!==''):?><br><?= Security::e($ticket['venue']) ?><?php endif;?><?php if(trim((string)$ticket['address'])!==''):?><br><?= Security::e($ticket['address']) ?><?php endif;?></div>
<div class="badges"><span class="badge"><?= Security::e($ticket['batch_name']) ?></span><span class="badge<?= in_array($ticket['status'],['paid','checked_in'],true)?' status-valid':'' ?>"><?= Security::e(ticket_status_label((string)$ticket['status'])) ?></span></div>
<div class="holder"><?= Security::e($customer) ?></div>

<?php if($entryEnabled):?>
    <?php if($qrSvg):?><div class="qr"><?= $qrSvg ?></div><?php else:?><div class="qr-unavailable">O QR não pôde ser exibido agora. Apresente o código do ingresso à equipe na entrada.</div><?php endif;?>
    <p class="code"><?= Security::e($ticket['code']) ?></p>
    <div class="notice<?= $ticket['status']==='checked_in'?'':' ok' ?>"><?= $ticket['status']==='checked_in'?'Este ingresso já foi utilizado.':'Apresente este QR na entrada. Ele é individual e válido para uma única entrada.' ?></div>
<?php elseif($ticket['tenant_status']!=='active'):?>
    <div class="notice error">Este ingresso está temporariamente indisponível. Entre em contato com <?= Security::e($brandName) ?>.</div>
<?php elseif($ticket['event_status']==='cancelled'):?>
    <div class="notice error">Este evento foi cancelado. O ingresso não está válido para entrada.</div>
<?php elseif($ticket['event_status']!=='published'):?>
    <div class="notice error">A entrada deste evento ainda não está liberada.</div>
<?php elseif($ticket['status']==='reserved'):?>
    <div class="notice">Reserva aguardando pagamento<?= $reservedUntil!==''?' até '.Security::e($reservedUntil):'' ?>.</div>
<?php else:?>
    <div class="notice error">Este ingresso não está válido para entrada.</div>
<?php endif;?>
</article>
<div class="foot"><?= $showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName) ?></div>
</main>
</body>
</html>
