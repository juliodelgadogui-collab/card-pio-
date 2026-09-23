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
    $time=$value?strtotime($value):false;
    return $time?date('d/m/Y · H:i',$time):'';
}

function ticket_color(mixed $value,string $fallback): string
{
    $v=strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$fallback;
}

$token=trim((string)($_GET['t']??''));
$pdo=Database::connection();
$s=$pdo->prepare('SELECT t.*,e.name event_name,e.public_subtitle,e.starts_at,e.ends_at,e.venue,e.address,e.banner_url,e.primary_color,e.secondary_color,e.text_color,e.status event_status,b.name batch_name,c.name customer_name,tn.name tenant_name,tn.status tenant_status,tt.name ticket_type_name,tt.access_area FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id JOIN tenants tn ON tn.id=t.tenant_id LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.qr_token=? LIMIT 1');
$s->execute([$token]);
$ticket=$s->fetch();
if(!$ticket){http_response_code(404);exit('Ingresso não encontrado.');}

$brandService=new TenantBrandService();
$tenantBrand=$brandService->get((int)$ticket['tenant_id']);
$visual=(bool)$tenantBrand['apply_web']?$tenantBrand:$brandService->defaults();
$brandName=trim((string)$tenantBrand['display_name'])?:((string)$ticket['tenant_name']);
$tagline=trim((string)$tenantBrand['tagline']);
$logo=(bool)$tenantBrand['apply_web']?trim((string)$tenantBrand['logo_url']):'';
$primary=ticket_color($ticket['primary_color']??'',(string)$visual['primary_color']);
$secondary=ticket_color($ticket['secondary_color']??'',(string)$visual['secondary_color']);
$background=(string)$visual['background_color'];
$surface=(string)$visual['surface_color'];
$text=ticket_color($ticket['text_color']??'',(string)$visual['text_color']);
$showEventMenu=(bool)$tenantBrand['show_eventmenu_brand'];
$banner=trim((string)($ticket['banner_url']??''));

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
$typeName=trim((string)($ticket['ticket_type_name']??''))?:'Ingresso';
$subtitle=trim((string)($ticket['public_subtitle']??''));
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, private, max-age=0');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?= Security::e($ticket['event_name']) ?> — <?= Security::e($brandName) ?></title>
<style>
:root{--primary:<?=Security::e($primary)?>;--secondary:<?=Security::e($secondary)?>;--bg:<?=Security::e($background)?>;--surface:<?=Security::e($surface)?>;--text:<?=Security::e($text)?>;--muted:color-mix(in srgb,var(--text) 62%,transparent);--line:color-mix(in srgb,var(--text) 13%,transparent);--soft:color-mix(in srgb,var(--primary) 10%,var(--surface));--shadow:0 22px 65px rgba(30,24,55,.13)}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,color-mix(in srgb,var(--primary) 10%,var(--bg)),var(--bg) 42%);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}.ticket-shell{max-width:600px;margin:0 auto;padding:20px 14px 60px}.brand-row{display:flex;align-items:center;justify-content:space-between;gap:11px;margin-bottom:14px}.brand-wrap{display:flex;align-items:center;gap:10px}.brand-logo{width:44px;height:44px;border-radius:13px;object-fit:cover;background:var(--soft)}.brand-mark{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:var(--primary);color:#fff;font-weight:950;font-size:20px}.brand-name{font-weight:950;font-size:18px}.brand-tagline{font-size:12px;color:var(--muted);margin-top:2px}.private-label{font-size:11px;color:var(--muted)}.ticket-card{overflow:hidden;background:var(--surface);border:1px solid var(--line);border-radius:28px;box-shadow:var(--shadow)}.ticket-hero{height:250px;position:relative;background:linear-gradient(135deg,var(--secondary),var(--primary));background-position:center;background-size:cover}.ticket-hero:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(10,8,26,.06),rgba(10,8,26,.84))}.ticket-hero-body{position:absolute;z-index:1;left:0;right:0;bottom:0;padding:24px;color:#fff}.eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:900}.ticket-hero h1{font-size:31px;line-height:1.03;letter-spacing:-.9px;margin:7px 0 7px}.ticket-hero p{margin:0;line-height:1.4;font-size:14px;color:#f1edf8}.ticket-body{text-align:center;padding:22px 26px 26px;position:relative}.ticket-body:before,.ticket-body:after{content:"";position:absolute;top:-15px;width:30px;height:30px;border-radius:50%;background:var(--bg)}.ticket-body:before{left:-16px}.ticket-body:after{right:-16px}.event-meta{color:var(--muted);line-height:1.55;margin-bottom:14px}.badges{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin:10px 0 16px}.badge{display:inline-flex;padding:7px 10px;border-radius:999px;background:var(--soft);color:var(--primary);font-size:11px;font-weight:850}.status-valid{background:#e9f8ef;color:#17623a}.status-used{background:#eeeaf8;color:#56457d}.holder-label{font-size:10px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted);font-weight:850}.holder{font-size:20px;font-weight:900;margin:4px 0 10px}.access{font-size:12px;color:var(--muted);margin-bottom:4px}.qr{max-width:310px;margin:16px auto 8px}.qr svg{display:block;max-width:100%;height:auto;background:#fff;border-radius:18px;padding:12px}.qr-unavailable{padding:16px;border-radius:14px;background:var(--soft);color:var(--muted);margin:18px 0}.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.09em;word-break:break-all;font-size:13px;color:var(--muted);margin:8px 0}.notice{padding:14px;border-radius:13px;margin-top:15px;background:var(--soft);color:var(--text);line-height:1.45}.notice.ok{background:#eaf8ef;color:#17623a}.notice.error{background:#fff0f1;color:#9a3844}.actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}.action{border:0;border-radius:12px;padding:13px 14px;font:inherit;font-weight:900;cursor:pointer}.action.primary{background:var(--primary);color:#fff}.action.secondary{background:var(--soft);color:var(--primary)}.action-status{min-height:20px;text-align:center;font-size:12px;color:var(--muted);margin-top:8px}.foot{text-align:center;color:var(--muted);font-size:11px;padding:22px 0}@media(max-width:520px){.ticket-shell{padding:12px 9px 50px}.ticket-card{border-radius:22px}.ticket-hero{height:270px}.ticket-hero-body{padding:21px}.ticket-hero h1{font-size:28px}.ticket-body{padding:20px 17px 24px}.private-label{display:none}.actions{grid-template-columns:1fr}}
</style>
</head>
<body>
<main class="ticket-shell">
<header class="brand-row"><div class="brand-wrap"><?php if($logo!==''):?><img class="brand-logo" src="<?=Security::e($logo)?>" alt="Logo de <?=Security::e($brandName)?>"><?php else:?><div class="brand-mark"><?=Security::e(mb_strtoupper(mb_substr($brandName,0,1)))?></div><?php endif;?><div><div class="brand-name"><?=Security::e($brandName)?></div><?php if($tagline!==''):?><div class="brand-tagline"><?=Security::e($tagline)?></div><?php endif;?></div></div><span class="private-label">Ingresso individual</span></header>
<article class="ticket-card" id="visual-ticket">
<section class="ticket-hero"<?php if($banner!==''):?> style="background-image:url('<?=Security::e($banner)?>')"<?php endif;?>><div class="ticket-hero-body"><span class="eyebrow">Seu ingresso</span><h1><?=Security::e($ticket['event_name'])?></h1><?php if($subtitle!==''):?><p><?=Security::e($subtitle)?></p><?php endif;?></div></section>
<section class="ticket-body"><div class="event-meta"><strong><?=Security::e(ticket_friendly_datetime((string)$ticket['starts_at']))?></strong><?php if(trim((string)$ticket['venue'])!==''):?><br><?=Security::e($ticket['venue'])?><?php endif;?><?php if(trim((string)$ticket['address'])!==''):?><br><?=Security::e($ticket['address'])?><?php endif;?></div><div class="badges"><span class="badge"><?=Security::e($typeName)?></span><span class="badge"><?=Security::e($ticket['batch_name'])?></span><span class="badge<?= $ticket['status']==='paid'?' status-valid':($ticket['status']==='checked_in'?' status-used':'') ?>"><?=Security::e(ticket_status_label((string)$ticket['status']))?></span></div><div class="holder-label">Titular / comprador</div><div class="holder"><?=Security::e($customer)?></div><?php if(trim((string)($ticket['access_area']??''))!==''):?><div class="access">Acesso: <?=Security::e($ticket['access_area'])?></div><?php endif;?>
<?php if($entryEnabled):?>
    <?php if($qrSvg):?><div class="qr" id="ticket-qr"><?=$qrSvg?></div><?php else:?><div class="qr-unavailable">O QR não pôde ser exibido agora. Apresente o código do ingresso à equipe na entrada.</div><?php endif;?>
    <p class="code"><?=Security::e($ticket['code'])?></p>
    <div class="notice<?= $ticket['status']==='checked_in'?'':' ok' ?>"><?= $ticket['status']==='checked_in'?'Este ingresso já foi utilizado.':'Apresente este QR na entrada. Ele é individual e válido para uma única entrada.' ?></div>
<?php elseif($ticket['tenant_status']!=='active'):?>
    <div class="notice error">Este ingresso está temporariamente indisponível. Entre em contato com <?=Security::e($brandName)?>.</div>
<?php elseif($ticket['event_status']==='cancelled'):?>
    <div class="notice error">Este evento foi cancelado. O ingresso não está válido para entrada.</div>
<?php elseif($ticket['event_status']!=='published'):?>
    <div class="notice error">A validação de entrada deste evento não está disponível neste momento.</div>
<?php elseif($ticket['status']==='reserved'):?>
    <div class="notice">Reserva aguardando pagamento<?= $reservedUntil!==''?' até '.Security::e($reservedUntil):'' ?>.</div>
<?php else:?>
    <div class="notice error">Este ingresso não está válido para entrada.</div>
<?php endif;?>
</section></article>
<?php if($entryEnabled):?><div class="actions"><button class="action primary" type="button" id="save-ticket">Salvar ingresso como imagem</button><button class="action secondary" type="button" id="share-ticket">Compartilhar ingresso</button></div><div class="action-status" id="action-status" role="status"></div><?php endif;?>
<div class="foot"><?= $showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName) ?></div>
</main>
<?php if($entryEnabled):?><script>
(()=>{'use strict';const data={event:<?=json_encode((string)$ticket['event_name'],JSON_UNESCAPED_UNICODE)?>,subtitle:<?=json_encode($subtitle,JSON_UNESCAPED_UNICODE)?>,date:<?=json_encode(ticket_friendly_datetime((string)$ticket['starts_at']),JSON_UNESCAPED_UNICODE)?>,venue:<?=json_encode((string)($ticket['venue']??''),JSON_UNESCAPED_UNICODE)?>,address:<?=json_encode((string)($ticket['address']??''),JSON_UNESCAPED_UNICODE)?>,holder:<?=json_encode($customer,JSON_UNESCAPED_UNICODE)?>,type:<?=json_encode($typeName,JSON_UNESCAPED_UNICODE)?>,batch:<?=json_encode((string)$ticket['batch_name'],JSON_UNESCAPED_UNICODE)?>,code:<?=json_encode((string)$ticket['code'],JSON_UNESCAPED_UNICODE)?>,status:<?=json_encode(ticket_status_label((string)$ticket['status']),JSON_UNESCAPED_UNICODE)?>,banner:<?=json_encode($banner,JSON_UNESCAPED_SLASHES)?>,primary:<?=json_encode($primary)?>,secondary:<?=json_encode($secondary)?>};const status=document.getElementById('action-status');function msg(v){if(status)status.textContent=v}function rounded(ctx,x,y,w,h,r){ctx.beginPath();ctx.roundRect(x,y,w,h,r);ctx.fill()}function wrap(ctx,text,x,y,max,width,lineHeight){const words=String(text||'').split(/\s+/);let line='',lines=0;for(const word of words){const test=line?line+' '+word:word;if(ctx.measureText(test).width>width&&line){ctx.fillText(line,x,y+lines*lineHeight,max);line=word;lines++}else line=test}if(line){ctx.fillText(line,x,y+lines*lineHeight,max);lines++}return lines}async function optionalBanner(ctx){if(!data.banner)return false;try{const r=await fetch(data.banner,{mode:'cors',credentials:'omit',cache:'force-cache'});if(!r.ok)throw new Error();const blob=await r.blob(),url=URL.createObjectURL(blob),img=new Image();await new Promise((ok,fail)=>{img.onload=ok;img.onerror=fail;img.src=url});const ratio=Math.max(1080/img.width,470/img.height),w=img.width*ratio,h=img.height*ratio;ctx.drawImage(img,(1080-w)/2,(470-h)/2,w,h);URL.revokeObjectURL(url);return true}catch(_){return false}}async function qrImage(){const svg=document.querySelector('#ticket-qr svg');if(!svg)return null;const source=new XMLSerializer().serializeToString(svg),url=URL.createObjectURL(new Blob([source],{type:'image/svg+xml'})),img=new Image();try{await new Promise((ok,fail)=>{img.onload=ok;img.onerror=fail;img.src=url});return{img,url}}catch(_){URL.revokeObjectURL(url);return null}}async function makeImage(){const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1600;const ctx=canvas.getContext('2d');const grad=ctx.createLinearGradient(0,0,1080,500);grad.addColorStop(0,data.secondary);grad.addColorStop(1,data.primary);ctx.fillStyle=grad;ctx.fillRect(0,0,1080,500);const hasBanner=await optionalBanner(ctx);if(hasBanner){const shade=ctx.createLinearGradient(0,0,0,500);shade.addColorStop(0,'rgba(10,8,25,.08)');shade.addColorStop(1,'rgba(10,8,25,.88)');ctx.fillStyle=shade;ctx.fillRect(0,0,1080,500)}ctx.fillStyle='#fff';ctx.font='900 28px system-ui';ctx.fillText('SEU INGRESSO',70,305);ctx.font='900 64px system-ui';wrap(ctx,data.event,70,365,940,940,70);if(data.subtitle){ctx.font='500 28px system-ui';wrap(ctx,data.subtitle,70,455,940,940,36)}ctx.fillStyle='#f7f7fb';ctx.fillRect(0,500,1080,1100);ctx.fillStyle='#201d2b';ctx.font='800 33px system-ui';ctx.fillText(data.date,70,580);ctx.font='500 26px system-ui';ctx.fillStyle='#645f6f';wrap(ctx,[data.venue,data.address].filter(Boolean).join(' · '),70,625,940,940,34);ctx.fillStyle='#ebe7f7';rounded(ctx,70,705,940,125,24);ctx.fillStyle=data.primary;ctx.font='900 25px system-ui';ctx.fillText((data.type+' · '+data.batch).toUpperCase(),100,757);ctx.fillStyle='#201d2b';ctx.font='900 34px system-ui';ctx.fillText(data.holder,100,802);const qr=await qrImage();if(qr){ctx.fillStyle='#fff';rounded(ctx,305,875,470,470,28);ctx.drawImage(qr.img,330,900,420,420);URL.revokeObjectURL(qr.url)}ctx.textAlign='center';ctx.fillStyle='#645f6f';ctx.font='600 24px ui-monospace,monospace';ctx.fillText(data.code,540,1390);ctx.fillStyle=data.status==='Válido'?'#17623a':data.primary;ctx.font='900 26px system-ui';ctx.fillText(data.status.toUpperCase(),540,1442);ctx.fillStyle='#77717f';ctx.font='500 21px system-ui';ctx.fillText('Apresente o QR na entrada · ingresso individual',540,1495);ctx.fillText('Tecnologia EventMenu',540,1540);return canvas}document.getElementById('save-ticket')?.addEventListener('click',async()=>{msg('Gerando imagem…');try{const canvas=await makeImage(),a=document.createElement('a');a.download='ingresso-'+String(data.code).replace(/[^a-zA-Z0-9_-]/g,'')+'.png';a.href=canvas.toDataURL('image/png',1);a.click();msg('Imagem do ingresso gerada.')}catch(e){msg('Não foi possível gerar a imagem agora. O ingresso continua disponível nesta tela.')}});document.getElementById('share-ticket')?.addEventListener('click',async()=>{const share={title:'Ingresso — '+data.event,text:data.event+' · '+data.date,url:location.href};try{if(navigator.share){await navigator.share(share);msg('Ingresso compartilhado.')}else{await navigator.clipboard.writeText(location.href);msg('Link do ingresso copiado.')}}catch(e){if(e?.name!=='AbortError')msg('Não foi possível compartilhar agora.')}})})();
</script><?php endif;?>
</body>
</html>