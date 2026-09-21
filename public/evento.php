<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\TenantBrandService;
use EventMenu\Services\TicketService;

function ev_money(int $c): string { return 'R$ '.number_format($c/100,2,',','.'); }
function ev_color(mixed $v,string $fallback): string { $v=strtolower(trim((string)$v)); return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$fallback; }
function ev_public_error(Throwable $e): string {
    $message=trim((string)$e->getMessage());
    if($message==='') return 'Não foi possível concluir a reserva. Tente novamente.';
    $message=preg_replace('/^(financeiro|api|servidor|gateway|ticket)\s*:\s*/iu','',$message)??$message;
    if(preg_match('/sql|sqlite|mysql|pdo|http\s*\d|json|token|exception|database|stack|foreign key/iu',$message)) return 'Não foi possível concluir a reserva. Tente novamente.';
    return $message;
}

$pdo=Database::connection();
$slug=trim((string)($_GET['evento']??''));
$tenantSlug=trim((string)($_GET['empresa']??''));
$error=null;
if($slug===''){http_response_code(404);exit('Evento não encontrado.');}

if($tenantSlug!==''){
    $s=$pdo->prepare('SELECT e.*,t.name tenant_name,t.slug tenant_slug FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE t.slug=? AND e.slug=? AND e.status="published" AND t.status="active" LIMIT 1');
    $s->execute([$tenantSlug,$slug]);
    $event=$s->fetch();
}else{
    $s=$pdo->prepare('SELECT e.*,t.name tenant_name,t.slug tenant_slug FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.slug=? AND e.status="published" AND t.status="active" ORDER BY e.id LIMIT 2');
    $s->execute([$slug]);
    $matches=$s->fetchAll();
    if(count($matches)!==1){http_response_code(404);exit(count($matches)>1?'Este link antigo é ambíguo. Solicite o link atualizado do evento.':'Evento não encontrado.');}
    $event=$matches[0];
    $tenantSlug=(string)$event['tenant_slug'];
    if($_SERVER['REQUEST_METHOD']==='GET'){
        header('Location: '.app_url('evento.php?empresa='.rawurlencode($tenantSlug).'&evento='.rawurlencode($slug)),true,301);
        exit;
    }
}
if(!$event||!TenantFeatures::events((int)($event['tenant_id']??0))){http_response_code(404);exit('Evento não encontrado.');}

$brandService=new TenantBrandService();
$tenantBrand=$brandService->get((int)$event['tenant_id']);
$visual=(bool)$tenantBrand['apply_web']?$tenantBrand:$brandService->defaults();
$brandName=trim((string)$tenantBrand['display_name'])?:((string)$event['tenant_name']);
$tagline=trim((string)$tenantBrand['tagline']);
$logo=(bool)$tenantBrand['apply_web']?trim((string)$tenantBrand['logo_url']):'';
$brandPrimary=(string)$visual['primary_color'];
$brandSecondary=(string)$visual['secondary_color'];
$background=(string)$visual['background_color'];
$surface=(string)$visual['surface_color'];
$text=(string)$visual['text_color'];
$showEventMenu=(bool)$tenantBrand['show_eventmenu_brand'];
$barOrdering=(int)($event['bar_enabled']??0)===1&&(int)($event['bar_unit_id']??0)>0&&TenantFeatures::menu((int)$event['tenant_id']);
$barUrl=$barOrdering?app_url('event-bar.php?empresa='.rawurlencode($tenantSlug).'&evento='.rawurlencode($slug)):'';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sua sessão expirou. Atualize a página.';
    else{
        try{
            $reservation=(new TicketService())->reservePublic(
                (int)$event['id'],
                (int)($_POST['batch_id']??0),
                (int)($_POST['quantity']??1),
                [
                    'name'=>(string)($_POST['name']??''),
                    'email'=>(string)($_POST['email']??''),
                    'phone'=>(string)($_POST['phone']??''),
                ],
                trim((string)($_POST['coupon']??''))?:null,
                trim((string)($_POST['promoter']??''))?:null,
            );
            header('Location: '.app_url('evento-pedido.php?t='.rawurlencode($reservation['public_token'])),true,303);
            exit;
        }catch(Throwable $e){$error=ev_public_error($e);}
    }
}

$b=$pdo->prepare('SELECT b.*,tt.name ticket_type_name,tt.description ticket_type_description,tt.access_area,tt.capacity_total ticket_type_capacity,tt.active ticket_type_active FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.event_id=? AND b.active=1 ORDER BY COALESCE(tt.sort_order,9999),tt.name,b.id');
$b->execute([$event['id']]);
$batches=$b->fetchAll();
$count=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');
$count->execute([$event['tenant_id'],$event['id']]);
$usedCapacity=(int)$count->fetchColumn();
$guestCount=$pdo->prepare('SELECT COALESCE(SUM(1+COALESCE(plus_ones,0)),0) FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');
$guestCount->execute([$event['tenant_id'],$event['id']]);
$usedCapacity+=(int)$guestCount->fetchColumn();
$eventCapacity=$event['capacity_total']!==null?(int)$event['capacity_total']:null;
$eventRemaining=$eventCapacity!==null?max(0,$eventCapacity-$usedCapacity):null;
$typeUsage=[];
$tu=$pdo->prepare('SELECT b.ticket_type_id,COUNT(*) used FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id IS NOT NULL AND t.status IN ("reserved","paid","checked_in") GROUP BY b.ticket_type_id');
$tu->execute([$event['tenant_id'],$event['id']]);
foreach($tu->fetchAll() as $r)$typeUsage[(int)$r['ticket_type_id']]=(int)$r['used'];

$primary=ev_color($event['primary_color']??'',$brandPrimary);
$secondary=ev_color($event['secondary_color']??'',$brandSecondary);
$subtitle=trim((string)($event['public_subtitle']??''));
$eventEnded=!empty($event['ends_at'])&&strtotime((string)$event['ends_at'])<time();
$salesEnabled=(int)($event['sales_enabled']??1)===1&&!$eventEnded;
$starts=strtotime((string)$event['starts_at']);
$mapUrl=trim((string)($event['map_url']??''));
$lat=$event['latitude']!==null?(float)$event['latitude']:null;
$lng=$event['longitude']!==null?(float)$event['longitude']:null;
$mapEmbed=$lat!==null&&$lng!==null?'https://maps.google.com/maps?q='.rawurlencode($lat.','.$lng).'&z=15&output=embed':'';
$banner=trim((string)($event['banner_url']??''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<title><?= Security::e($event['name']) ?> — <?= Security::e($brandName) ?></title>
<style>
:root{--primary:<?= Security::e($primary) ?>;--secondary:<?= Security::e($secondary) ?>;--bg:<?= Security::e($background) ?>;--surface:<?= Security::e($surface) ?>;--text:<?= Security::e($text) ?>;--muted:color-mix(in srgb,var(--text) 62%,transparent);--line:color-mix(in srgb,var(--text) 12%,transparent);--soft:color-mix(in srgb,var(--primary) 9%,var(--surface));--shadow:0 14px 38px rgba(44,34,79,.08)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}a{text-decoration:none;color:inherit}button,input,select{font:inherit}.shell{width:min(1120px,100%);margin:auto;padding:18px 16px 70px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:14px}.brand-wrap{display:flex;align-items:center;gap:10px;min-width:0}.brand-logo{width:44px;height:44px;border-radius:13px;object-fit:cover;background:var(--soft)}.brand-mark{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;background:var(--primary);color:#fff;font-weight:950;font-size:20px}.brand-name{font-size:20px;font-weight:950;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.brand-tagline,.organizer{font-size:12px;color:var(--muted)}.hero{position:relative;overflow:hidden;min-height:390px;border-radius:24px;background:linear-gradient(135deg,var(--secondary),var(--primary));display:flex;align-items:flex-end;box-shadow:var(--shadow)}.hero.banner{background-position:center;background-size:cover}.hero:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(20,12,51,.08),rgba(20,12,51,.82))}.hero-body{position:relative;z-index:1;color:#fff;padding:42px;max-width:820px}.eyebrow{font-size:11px;font-weight:900;letter-spacing:.13em;text-transform:uppercase;color:#ebe6ff}.hero h1{font-size:clamp(42px,7vw,76px);line-height:.98;letter-spacing:-2px;margin:8px 0 12px}.subtitle{font-size:20px;line-height:1.4;margin:0 0 12px}.description{font-size:15px;line-height:1.6;color:#f3f0fa}.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}.pill{padding:8px 11px;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.12);font-size:12px;font-weight:800}.nav{position:sticky;top:0;z-index:5;display:flex;justify-content:space-between;align-items:center;gap:10px;background:color-mix(in srgb,var(--surface) 94%,transparent);backdrop-filter:blur(12px);border:1px solid var(--line);border-radius:14px;padding:9px;margin:12px 0 16px}.nav-links{display:flex;gap:4px;overflow:auto}.nav a{padding:9px 11px;border-radius:9px;font-size:13px;font-weight:800;white-space:nowrap}.cta{background:var(--primary);color:#fff}.layout{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(290px,.65fr);gap:16px;align-items:start}.card{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:var(--shadow)}.section-head{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:16px}.section-head h2,.card h2{margin:3px 0;font-size:26px}.muted{color:var(--muted)}.ticket-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ticket{border:1px solid var(--line);border-radius:16px;padding:17px;background:var(--surface)}.ticket-top{display:flex;justify-content:space-between;gap:12px}.ticket h3{font-size:19px;margin:4px 0}.price{font-size:20px;font-weight:950;color:var(--primary);white-space:nowrap}.ticket p{font-size:13px;line-height:1.5;color:var(--muted)}.availability{font-size:12px;color:var(--muted);margin-top:5px}.buy{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin-top:14px}.buy label{display:grid;gap:5px;font-size:12px;font-weight:750;color:var(--muted)}.buy input,.buy select{width:100%;border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--surface);color:var(--text);outline:0}.buy input:focus,.buy select:focus{border-color:var(--primary);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary) 12%,transparent)}.buy button{grid-column:1/-1;border:0;border-radius:11px;padding:12px;background:var(--primary);color:#fff;font-weight:900;cursor:pointer}.alert,.closed{padding:13px 14px;border-radius:12px;background:color-mix(in srgb,#e69b28 10%,var(--surface));border:1px solid color-mix(in srgb,#e69b28 25%,var(--surface));color:var(--text);margin-bottom:14px}.side-stack{display:grid;gap:14px}.capacity{display:block;font-size:42px;font-weight:950;color:var(--primary);margin-top:6px}.side-card p{font-size:14px;line-height:1.55}.benefits{display:flex;flex-wrap:wrap;gap:7px}.benefit{background:var(--soft);color:var(--secondary);padding:7px 9px;border-radius:999px;font-size:11px;font-weight:800}.map iframe{width:100%;height:230px;border:0;border-radius:12px}.map-link{display:inline-block;margin-top:10px;color:var(--primary);font-weight:850}.bar-button{display:flex;align-items:center;justify-content:center;margin-top:12px;padding:12px 14px;border-radius:11px;background:var(--primary);color:#fff;font-weight:900}.powered{text-align:center;color:var(--muted);font-size:12px;margin-top:26px}
@media(max-width:850px){.layout{grid-template-columns:1fr}.ticket-grid{grid-template-columns:1fr}.side-stack{grid-template-columns:repeat(2,minmax(0,1fr))}.hero{min-height:350px}.hero-body{padding:30px}}
@media(max-width:600px){.shell{padding:10px 10px 55px}.top{padding:4px}.organizer{display:none}.hero{min-height:360px;border-radius:18px}.hero-body{padding:24px}.hero h1{font-size:44px;letter-spacing:-1px}.subtitle{font-size:18px}.description{font-size:14px}.nav{overflow:auto}.nav-links{min-width:max-content}.nav .cta{display:none}.card{padding:17px;border-radius:15px}.section-head{align-items:flex-start;flex-direction:column}.section-head h2,.card h2{font-size:23px}.side-stack{grid-template-columns:1fr}.buy{grid-template-columns:1fr}.buy button{grid-column:auto}.ticket h3{font-size:18px}.price{font-size:18px}}
</style>
</head>
<body>
<main class="shell">
<header class="top">
  <div class="brand-wrap">
    <?php if($logo!==''):?><img class="brand-logo" src="<?= Security::e($logo) ?>" alt="Logo de <?= Security::e($brandName) ?>"><?php else:?><div class="brand-mark"><?= Security::e(mb_strtoupper(mb_substr($brandName,0,1))) ?></div><?php endif;?>
    <div><div class="brand-name"><?= Security::e($brandName) ?></div><?php if($tagline!==''):?><div class="brand-tagline"><?= Security::e($tagline) ?></div><?php endif;?></div>
  </div>
  <div class="organizer">Organização · <?= Security::e($brandName) ?></div>
</header>

<section class="hero<?= $banner!==''?' banner':'' ?>"<?php if($banner!==''):?> style="background-image:url('<?= Security::e($banner) ?>')"<?php endif;?>>
  <div class="hero-body">
    <span class="eyebrow"><?= Security::e($event['event_type']??'Evento') ?></span>
    <h1><?= Security::e($event['name']) ?></h1>
    <?php if($subtitle):?><p class="subtitle"><?= Security::e($subtitle) ?></p><?php endif;?>
    <?php if($event['description']):?><div class="description"><?= nl2br(Security::e($event['description'])) ?></div><?php endif;?>
    <div class="meta"><span class="pill"><?= Security::e(date('d/m/Y',$starts)) ?></span><span class="pill"><?= Security::e(date('H:i',$starts)) ?></span><?php if($event['venue']):?><span class="pill"><?= Security::e($event['venue']) ?></span><?php endif;?><?php if($eventCapacity!==null):?><span class="pill">Capacidade <?= $eventCapacity ?></span><?php endif;?></div>
  </div>
</section>

<nav class="nav"><div class="nav-links"><a href="#ingressos">Ingressos</a><?php if($barOrdering):?><a href="<?= Security::e($barUrl) ?>">Pedir no bar</a><?php endif;?><a href="#local">Local</a><a href="#experiencia">Experiência</a></div><a class="cta" href="#ingressos">Comprar ingresso →</a></nav>
<?php if($error):?><div class="alert"><?= Security::e($error) ?></div><?php endif;?>

<div class="layout">
<section class="card" id="ingressos">
  <div class="section-head"><div><span class="eyebrow" style="color:var(--primary)">Ingressos</span><h2>Escolha seu ingresso</h2></div><span class="muted">QR individual após confirmação</span></div>
  <?php if(!$salesEnabled):?>
    <div class="closed"><?= $eventEnded?'Este evento já foi encerrado.':'Venda online temporariamente indisponível.' ?></div>
  <?php elseif(!$batches):?>
    <div class="closed">Nenhum lote disponível no momento.</div>
  <?php else:?>
    <div class="ticket-grid">
    <?php foreach($batches as $batch):
        $available=max(0,(int)$batch['quantity_total']-(int)$batch['quantity_sold']-(int)$batch['quantity_reserved']);
        if($eventRemaining!==null)$available=min($available,$eventRemaining);
        if(!empty($batch['ticket_type_id'])&&!empty($batch['ticket_type_capacity'])){
            $available=min($available,max(0,(int)$batch['ticket_type_capacity']-($typeUsage[(int)$batch['ticket_type_id']]??0)));
        }
        $open=(!$batch['sales_start']||strtotime($batch['sales_start'])<=time())&&(!$batch['sales_end']||strtotime($batch['sales_end'])>=time());
        $typeActive=empty($batch['ticket_type_id'])||(int)$batch['ticket_type_active']===1;
    ?>
      <article class="ticket">
        <div class="ticket-top"><div><span class="eyebrow" style="color:var(--primary)"><?= Security::e($batch['ticket_type_name']?:'Ingresso') ?></span><h3><?= Security::e($batch['name']) ?></h3><?php if($batch['access_area']):?><div class="availability">Acesso: <?= Security::e($batch['access_area']) ?></div><?php endif;?></div><div class="price"><?= (int)$batch['price_cents']===0?'Grátis':ev_money((int)$batch['price_cents']) ?></div></div>
        <?php if($batch['ticket_type_description']):?><p><?= Security::e($batch['ticket_type_description']) ?></p><?php endif;?>
        <div class="availability"><?= $available ?> disponível(is)</div>
        <?php if($available>0&&$open&&$typeActive):?>
          <form method="post" class="buy">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
            <label>Quantidade<select name="quantity"><?php for($i=1;$i<=min(10,$available);$i++):?><option value="<?= $i ?>"><?= $i ?></option><?php endfor;?></select></label>
            <label>Nome<input name="name" required autocomplete="name"></label>
            <label>E-mail<input type="email" name="email" autocomplete="email"></label>
            <label>Telefone<input name="phone" autocomplete="tel"></label>
            <label>Cupom<input name="coupon" placeholder="Opcional"></label>
            <label>Promotor / afiliado<input name="promoter" placeholder="Opcional"></label>
            <button>Reservar e pagar</button>
          </form>
        <?php else:?><div class="closed">Lote indisponível no momento.</div><?php endif;?>
      </article>
    <?php endforeach;?>
    </div>
  <?php endif;?>
</section>

<aside class="side-stack">
  <section class="card side-card" id="local">
    <span class="eyebrow" style="color:var(--primary)">Local</span>
    <h2><?= Security::e($event['venue']?:'A definir') ?></h2>
    <?php if($event['address']):?><p><?= Security::e($event['address']) ?></p><?php endif;?>
    <?php if($mapEmbed):?><div class="map"><iframe loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= Security::e($mapEmbed) ?>"></iframe></div><?php endif;?>
    <?php if($mapUrl):?><a class="map-link" target="_blank" rel="noopener" href="<?= Security::e($mapUrl) ?>">Abrir no mapa →</a><?php endif;?>
  </section>
  <?php if($eventCapacity!==null):?><section class="card side-card"><span class="eyebrow" style="color:var(--primary)">Capacidade</span><span class="capacity"><?= $eventRemaining ?></span><p class="muted">vaga(s) ainda disponíveis considerando ingressos, reservas, convidados e acompanhantes.</p></section><?php endif;?>
  <?php if($barOrdering):?><section class="card side-card"><span class="eyebrow" style="color:var(--primary)">Bar do evento</span><h2>Peça pelo celular</h2><p>Escolha seus produtos, pague online e retire apresentando o QR do pedido.</p><a class="bar-button" href="<?= Security::e($barUrl) ?>">Ver cardápio do bar →</a></section><?php endif;?>
  <section class="card side-card" id="experiencia"><span class="eyebrow" style="color:var(--primary)">Experiência</span><h2>Entrada por QR</h2><p>Cada ingresso possui QR individual e validação única no check-in.</p><div class="benefits"><span class="benefit">QR individual</span><span class="benefit">Check-in</span><span class="benefit">Pagamento online</span><?php if((int)($event['bar_enabled']??1)):?><span class="benefit">Bar no evento</span><?php endif;?></div></section>
</aside>
</div>
<div class="powered"><?= $showEventMenu?'Tecnologia EventMenu':Security::e($tagline!==''?$tagline:$brandName) ?></div>
</main>
</body>
</html>