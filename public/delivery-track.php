<?php

declare(strict_types=1);

$token=trim((string)($_GET['token']??''));
$delyvre=strtolower(trim((string)($_GET['source']??'')))==='delyvre';
$nonce=base64_encode(random_bytes(18));
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; frame-src https://www.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'nonce-{$nonce}'; img-src 'self' data: https:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?=$delyvre?'#ff4f3d':'#f4f7fb'?>">
<meta name="robots" content="noindex,nofollow,noarchive">
<title><?=$delyvre?'DELYVRE · Acompanhar entrega':'Acompanhar entrega'?></title>
<style>
:root{--primary:<?=$delyvre?'#ff4f3d':'#5b34d6'?>;--secondary:#159b63;--bg:<?=$delyvre?'#fffaf7':'#f4f7fb'?>;--surface:#fff;--text:<?=$delyvre?'#211b19':'#111827'?>;--muted:<?=$delyvre?'#756c68':'#667085'?>;--border:<?=$delyvre?'#eee5e1':'#e7eaf0'?>;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);background:var(--bg)}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}.wrap{max-width:820px;margin:0 auto;padding:max(18px,env(safe-area-inset-top)) 16px max(40px,env(safe-area-inset-bottom))}.brand{display:flex;align-items:center;gap:12px;margin-bottom:18px}.brand-logo{width:46px;height:46px;object-fit:contain;border-radius:14px;background:var(--surface);display:none;border:1px solid var(--border)}.brand-name{font-weight:900;letter-spacing:-.025em;font-size:21px}.brand-tagline{font-size:13px;color:var(--muted);margin-top:2px}.powered{font-size:12px;color:var(--muted);text-align:center;margin-top:14px}.card{background:var(--surface);border:1px solid var(--border);border-radius:24px;padding:22px;box-shadow:0 14px 36px rgba(54,34,27,.07)}h1{font-size:30px;letter-spacing:-.04em;margin:0 0 8px}.muted{color:var(--muted);line-height:1.55}.status{display:inline-flex;padding:8px 12px;border-radius:999px;background:color-mix(in srgb,var(--primary) 12%,white);color:var(--primary);font-weight:800;margin:12px 0}.steps{display:grid;gap:9px;margin:20px 0}.step{display:flex;justify-content:space-between;gap:16px;padding:13px 14px;background:color-mix(in srgb,var(--bg) 75%,var(--surface));border-radius:14px;border:1px solid color-mix(in srgb,var(--border) 80%,transparent)}.done{font-weight:800;color:var(--secondary)}.pending{color:var(--muted)}.map{width:100%;height:340px;border:0;border-radius:18px;background:#eef2f7;margin-top:14px}.hidden{display:none!important}.error{color:#b42318;background:#fef3f2;padding:12px;border-radius:12px}.updated{font-size:13px;color:var(--muted);margin-top:10px}.delyvre-mark{display:grid;place-items:center;width:46px;height:46px;flex:0 0 auto;border-radius:15px;background:linear-gradient(145deg,#ff6a58,#ff4f3d);color:#fff;font-size:20px;font-weight:950;box-shadow:0 8px 20px rgba(255,79,61,.22)}.delivery-note{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px 13px;border-radius:14px;background:<?=$delyvre?'#fff3ef':'#f7f5ff'?>;color:var(--muted);font-size:12px;line-height:1.45}.delivery-note strong{color:var(--text)}@media(max-width:560px){.wrap{padding-left:12px;padding-right:12px}.card{padding:17px;border-radius:21px}h1{font-size:26px}.map{height:300px}.brand-name{font-size:19px}.step{font-size:13px}}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important;transition:none!important}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <?php if($delyvre):?><div class="delyvre-mark" aria-hidden="true">D</div><?php else:?><img id="brandLogo" class="brand-logo" alt=""><?php endif;?>
    <div><div id="brandName" class="brand-name"><?=$delyvre?'DELYVRE':'EventMenu'?></div><div id="brandTagline" class="brand-tagline<?=$delyvre?'':' hidden'?>"><?=$delyvre?'Escolha. Peça. Receba.':''?></div></div>
  </div>
  <main class="card">
    <h1 id="title">Acompanhando sua entrega</h1>
    <div id="status" class="status">Atualizando...</div>
    <p id="message" class="muted">Buscando o andamento mais recente do pedido.</p>
    <div class="steps">
      <div class="step"><span>Pedido retirado</span><span id="pickup" class="pending">Pendente</span></div>
      <div class="step"><span>Rota iniciada</span><span id="route" class="pending">Pendente</span></div>
      <div class="step"><span>Chegada ao endereço</span><span id="arrival" class="pending">Pendente</span></div>
      <div class="step"><span>Entrega concluída</span><span id="complete" class="pending">Pendente</span></div>
    </div>
    <section id="mapSection" class="hidden">
      <iframe id="map" class="map" title="Posição aproximada da entrega" loading="lazy" referrerpolicy="no-referrer"></iframe>
      <div id="updated" class="updated"></div>
      <div class="delivery-note"><span aria-hidden="true">⌖</span><span><strong>Localização aproximada.</strong> A posição aparece apenas enquanto a rota de entrega estiver ativa e com atualização recente.</span></div>
    </section>
    <div id="error" class="error hidden"></div>
  </main>
  <div id="powered" class="powered"><?=$delyvre?'DELYVRE · Escolha. Peça. Receba.':'Tecnologia EventMenu'?></div>
</div>
<script nonce="<?=htmlspecialchars($nonce,ENT_QUOTES,'UTF-8')?>">
const token=<?=json_encode($token,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const delyvreMode=<?=$delyvre?'true':'false'?>;
const $=id=>document.getElementById(id);
const done=(id,value)=>{const el=$(id);el.textContent=value?'Concluído':'Pendente';el.className=value?'done':'pending'};
const label=status=>({ready:'Pedido pronto',out_for_delivery:'Saiu para entrega',completed:'Entrega concluída',cancelled:'Pedido cancelado'})[status]||'Acompanhando pedido';
const safeHex=(value,fallback)=>/^#[0-9a-f]{6}$/i.test(String(value||''))?String(value):fallback;
function applyBrand(brand){
  if(!brand)return;
  if(delyvreMode){
    const restaurant=String(brand.display_name||'').trim();
    $('brandName').textContent='DELYVRE';
    $('brandTagline').textContent=restaurant?'Entrega de '+restaurant:'Escolha. Peça. Receba.';
    $('brandTagline').classList.remove('hidden');
    document.title='DELYVRE · Acompanhar entrega';
    return;
  }
  const root=document.documentElement.style;
  root.setProperty('--primary',safeHex(brand.primary_color,'#5b34d6'));
  root.setProperty('--secondary',safeHex(brand.secondary_color,'#159b63'));
  root.setProperty('--bg',safeHex(brand.background_color,'#f4f7fb'));
  root.setProperty('--surface',safeHex(brand.surface_color,'#ffffff'));
  root.setProperty('--text',safeHex(brand.text_color,'#111827'));
  const name=String(brand.display_name||'EventMenu').trim();
  $('brandName').textContent=name;
  document.title='Acompanhar entrega • '+name;
  const tagline=String(brand.tagline||'').trim();
  $('brandTagline').textContent=tagline;
  $('brandTagline').classList.toggle('hidden',!tagline);
  const logo=String(brand.logo_url||'').trim();
  if(/^https:\/\//i.test(logo)){$('brandLogo').src=logo;$('brandLogo').alt='Logo de '+name;$('brandLogo').style.display='block'}
  else{$('brandLogo').removeAttribute('src');$('brandLogo').style.display='none'}
  $('powered').classList.toggle('hidden',brand.show_eventmenu_brand===false);
}
function mapUrl(lat,lng){const d=.008;const bbox=[lng-d,lat-d,lng+d,lat+d].join(',');return 'https://www.openstreetmap.org/export/embed.html?bbox='+encodeURIComponent(bbox)+'&layer=mapnik&marker='+encodeURIComponent(lat+','+lng)}
async function refresh(){
  if(!token){showError('Link de acompanhamento inválido.');return}
  try{
    const r=await fetch('api-delivery-track.php?token='+encodeURIComponent(token),{cache:'no-store',credentials:'omit'});const j=await r.json();
    if(!r.ok||!j.ok)throw new Error(j.error||'Acompanhamento indisponível.');
    const t=j.tracking;applyBrand(t.brand);$('title').textContent='Pedido #'+t.order_id;$('status').textContent=label(t.status);
    done('pickup',!!t.picked_up_at);done('route',!!t.route_started_at);done('arrival',!!t.arrived_at);done('complete',!!t.completed_at||t.status==='completed');
    if(t.status==='completed')$('message').textContent='Seu pedido foi entregue. Obrigado!';
    else if(t.status==='cancelled')$('message').textContent='Este pedido foi cancelado.';
    else if(t.tracking_active)$('message').textContent='O entregador está a caminho. A posição é atualizada enquanto a rota estiver ativa.';
    else if(t.arrived_at)$('message').textContent='O entregador informou que chegou ao endereço.';
    else $('message').textContent='A entrega está sendo preparada para a próxima etapa.';
    if(t.location&&t.tracking_active){$('map').src=mapUrl(Number(t.location.latitude),Number(t.location.longitude));$('mapSection').classList.remove('hidden');$('updated').textContent='Última posição recebida: '+String(t.location.received_at||'');}
    else{$('mapSection').classList.add('hidden');$('map').removeAttribute('src')}
    $('error').classList.add('hidden');
    if(t.status==='completed'||t.status==='cancelled')return;
    setTimeout(refresh,10000);
  }catch(e){showError(e.message||'Não foi possível atualizar a entrega.');setTimeout(refresh,20000)}
}
function showError(message){$('error').textContent=message;$('error').classList.remove('hidden');$('status').textContent='Indisponível'}
refresh();
</script>
</body>
</html>