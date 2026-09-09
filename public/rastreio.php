<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Security;
use EventMenu\Services\DeliveryPublicTrackingService;

$token = strtolower(trim((string)($_GET['t'] ?? '')));
$initial = (new DeliveryPublicTrackingService())->snapshot($token);

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if (!$initial) {
    http_response_code(404);
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rastreamento indisponível</title><style>body{font-family:system-ui,-apple-system,sans-serif;background:#f4f6f8;color:#172033;margin:0}.empty{max-width:560px;margin:12vh auto;padding:28px}.box{background:#fff;border-radius:24px;padding:28px;box-shadow:0 18px 60px rgba(16,24,40,.08)}h1{margin-top:0}</style></head><body><main class="empty"><div class="box"><h1>Rastreamento indisponível</h1><p>Este link expirou ou a entrega não está mais disponível para acompanhamento.</p></div></main></body></html><?php
    exit;
}

$tenantName = Security::e((string)($initial['tenant_name'] ?? 'EventMenu'));
$orderId = (int)($initial['order_id'] ?? 0);
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#111827">
<title>Pedido #<?= $orderId ?> · <?= $tenantName ?></title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
:root{--ink:#172033;--muted:#667085;--bg:#f3f5f8;--card:#fff;--line:#e6e9ef;--ok:#087443;--okbg:#e9f8ef;--warn:#9a6700;--warnbg:#fff5d6;--bad:#b42318;--badbg:#feeceb;--brand:#111827}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#eef2f7 0,#f7f8fa 360px);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}
.shell{max-width:920px;margin:auto;padding:18px 16px 34px}.top{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:8px 2px 16px}.brand{font-weight:900;letter-spacing:-.02em}.order{font-size:13px;color:var(--muted);font-weight:700}.card{background:var(--card);border:1px solid rgba(16,24,40,.06);border-radius:26px;box-shadow:0 18px 55px rgba(16,24,40,.07);overflow:hidden;margin-bottom:14px}.hero{padding:24px}.hero h1{font-size:clamp(28px,6vw,42px);letter-spacing:-.04em;line-height:1.02;margin:14px 0 8px}.hero p{margin:0;color:var(--muted);font-size:16px}.chip{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;font-size:13px;font-weight:800;background:var(--okbg);color:var(--ok)}.chip.warn{background:var(--warnbg);color:var(--warn)}.chip.bad{background:var(--badbg);color:var(--bad)}.dot{width:9px;height:9px;border-radius:50%;background:currentColor;box-shadow:0 0 0 5px currentColor;opacity:.8;animation:pulse 1.8s infinite}@keyframes pulse{0%,100%{box-shadow:0 0 0 0 currentColor}50%{box-shadow:0 0 0 6px transparent}}
.metrics{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid var(--line);margin-top:22px}.metric{padding:16px 12px 4px;text-align:center}.metric+.metric{border-left:1px solid var(--line)}.metric strong{display:block;font-size:19px}.metric span{display:block;color:var(--muted);font-size:12px;margin-top:3px}
.mapwrap{position:relative}.map{height:min(58vh,520px);min-height:360px;background:#dde3ea}.mapcontrols{position:absolute;z-index:500;right:12px;top:12px;display:flex;gap:8px}.mapbtn{border:0;background:#fff;padding:10px 13px;border-radius:14px;box-shadow:0 6px 24px #0002;font-weight:800;color:var(--ink);cursor:pointer}.bike-pin{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#111827;color:white;border:4px solid white;box-shadow:0 6px 20px #0004;font-size:23px}.bike-wrap{background:none!important;border:none!important}.leaflet-control-attribution{font-size:9px!important}
.progress{padding:22px 24px}.progress h2{margin:0 0 18px;font-size:18px}.steps{display:grid;gap:0}.step{display:grid;grid-template-columns:26px 1fr;gap:12px;min-height:54px;position:relative}.step:not(:last-child):before{content:"";position:absolute;left:11px;top:23px;bottom:-4px;width:2px;background:#d9dee7}.step.done:not(:last-child):before{background:#16a34a}.bullet{width:24px;height:24px;border-radius:50%;background:#edf0f4;border:2px solid #d2d7df;display:grid;place-items:center;font-size:12px;font-weight:900}.step.done .bullet{background:#16a34a;border-color:#16a34a;color:#fff}.step .txt strong{display:block;font-size:14px}.step .txt span{color:var(--muted);font-size:12px}.privacy{padding:18px 22px;color:var(--muted);font-size:12px;line-height:1.5}.offline-banner{display:none;background:var(--warnbg);color:#7a4d00;padding:11px 16px;text-align:center;font-size:13px;font-weight:700}.offline-banner.show{display:block}.map-ended{height:100%;min-height:360px;display:grid;place-items:center;text-align:center;padding:28px;color:var(--muted);background:linear-gradient(145deg,#f7f8fa,#edf1f5)}.map-ended strong{display:block;color:var(--ink);font-size:22px;margin-bottom:6px}
@media(max-width:620px){.shell{padding:8px 8px 24px}.top{padding:10px 7px 12px}.hero{padding:21px 18px}.metrics{grid-template-columns:repeat(3,1fr)}.metric strong{font-size:16px}.metric{padding:14px 5px 3px}.map{height:52vh;min-height:330px}.progress{padding:20px 18px}.card{border-radius:22px}}
</style>
</head>
<body>
<div id="offline" class="offline-banner">Conexão instável. Mantendo a última posição conhecida.</div>
<main class="shell">
    <header class="top"><div class="brand"><?= $tenantName ?></div><div class="order">Pedido #<?= $orderId ?></div></header>

    <section class="card hero">
        <span id="signalChip" class="chip"><span class="dot"></span><span id="signalText">Conectando ao entregador</span></span>
        <h1 id="headline">Seu pedido está a caminho 🛵</h1>
        <p id="subline">Acompanhando a entrega em tempo real.</p>
        <div class="metrics">
            <div class="metric"><strong id="updated">—</strong><span>última atualização</span></div>
            <div class="metric"><strong id="speed">—</strong><span>velocidade</span></div>
            <div class="metric"><strong id="distance">—</strong><span>trajeto registrado</span></div>
        </div>
    </section>

    <section class="card mapwrap">
        <div id="map" class="map" aria-label="Mapa da entrega"></div>
        <div class="mapcontrols"><button id="followBtn" class="mapbtn" type="button">◎ Seguir</button></div>
    </section>

    <section class="card progress">
        <h2>Andamento do pedido</h2>
        <div class="steps">
            <div id="stepPickup" class="step"><div class="bullet">1</div><div class="txt"><strong>Pedido retirado</strong><span>O entregador recebeu seu pedido</span></div></div>
            <div id="stepRoute" class="step"><div class="bullet">2</div><div class="txt"><strong>Saiu para entrega</strong><span>Entrega em deslocamento</span></div></div>
            <div id="stepArrival" class="step"><div class="bullet">3</div><div class="txt"><strong>Chegou ao endereço</strong><span>O entregador confirmou a chegada</span></div></div>
            <div id="stepDone" class="step"><div class="bullet">✓</div><div class="txt"><strong>Entregue</strong><span>Pedido finalizado</span></div></div>
        </div>
    </section>

    <section class="card privacy">A localização é compartilhada somente durante a operação da entrega. O link é privado, expira automaticamente e não permite acesso a outras informações da conta.</section>
</main>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const token=<?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
const initial=<?= json_encode($initial, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const map=L.map('map',{zoomControl:false,attributionControl:true}).setView([-14.2,-51.9],4);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);
L.control.zoom({position:'bottomright'}).addTo(map);
const icon=L.divIcon({className:'bike-wrap',html:'<div class="bike-pin">🛵</div>',iconSize:[44,44],iconAnchor:[22,22]});
let marker=null,trail=L.polyline([],{weight:5,opacity:.75,lineCap:'round'}).addTo(map),follow=true,firstFix=true,pollTimer=null,animation=null,ended=false;
const $=id=>document.getElementById(id);
map.on('dragstart zoomstart',()=>{follow=false;$('followBtn').textContent='◎ Centralizar'});
$('followBtn').onclick=()=>{follow=true;$('followBtn').textContent='✓ Seguindo';if(marker)map.flyTo(marker.getLatLng(),Math.max(map.getZoom(),16),{duration:.6})};
function ageText(seconds){if(seconds==null||seconds>99999)return 'sem sinal';if(seconds<8)return 'agora';if(seconds<60)return `há ${seconds}s`;const m=Math.floor(seconds/60);return `há ${m} min`}
function distanceText(m){if(!m)return '—';return m<1000?`${m} m`:`${(m/1000).toFixed(1)} km`}
function setDone(id,done){$(id).classList.toggle('done',!!done)}
function animateMarker(lat,lng){const next=L.latLng(lat,lng);if(!marker){marker=L.marker(next,{icon,zIndexOffset:1000}).addTo(map);return}if(animation)cancelAnimationFrame(animation);const start=marker.getLatLng(),started=performance.now(),duration=2600;function frame(now){const p=Math.min(1,(now-started)/duration),ease=1-Math.pow(1-p,3);marker.setLatLng([start.lat+(next.lat-start.lat)*ease,start.lng+(next.lng-start.lng)*ease]);if(p<1)animation=requestAnimationFrame(frame)}animation=requestAnimationFrame(frame)}
function endMap(){if(ended)return;ended=true;if(animation)cancelAnimationFrame(animation);if(marker){map.removeLayer(marker);marker=null}trail.setLatLngs([]);$('followBtn').style.display='none';const node=document.getElementById('map');node.innerHTML='<div class="map-ended"><div><strong>Entrega finalizada ✓</strong>O compartilhamento da localização foi encerrado.</div></div>'}
function apply(x){
 const sig=x.signal||{status:'offline',label:'Sem sinal',age_seconds:999999},chip=$('signalChip');chip.className='chip'+(sig.status==='live'?'':sig.status==='weak'?' warn':' bad');$('signalText').textContent=sig.status==='completed'?'Entrega concluída':sig.status==='mock'?'Atualizando localização':sig.label||'GPS';
 $('updated').textContent=ageText(sig.age_seconds);$('speed').textContent=x.speed_kmh!=null&&x.speed_kmh>=2?`${Math.round(x.speed_kmh)} km/h`:'—';$('distance').textContent=distanceText(x.route_distance_m||0);
 setDone('stepPickup',!!x.picked_up_at);setDone('stepRoute',!!x.route_started_at);setDone('stepArrival',!!x.arrived_at);setDone('stepDone',x.order_status==='completed'||!!x.completed_at);
 if(x.order_status==='completed'){$('headline').textContent='Pedido entregue ✓';$('subline').textContent='Entrega concluída. O compartilhamento de localização foi encerrado.';chip.className='chip';endMap();if(pollTimer){clearInterval(pollTimer);pollTimer=null};return}
 if(sig.status==='offline'){$('headline').textContent='Seu pedido continua em rota';$('subline').textContent='O sinal do entregador está temporariamente indisponível. Mostramos a última posição conhecida.'}
 else if(sig.status==='weak'){$('headline').textContent='Seu pedido está a caminho 🛵';$('subline').textContent='Sinal de GPS oscilando, mas o acompanhamento continua ativo.'}
 else{$('headline').textContent='Seu pedido está a caminho 🛵';$('subline').textContent='A posição do entregador é atualizada automaticamente.'}
 const points=(x.history||[]).map(p=>[p.latitude,p.longitude]);trail.setLatLngs(points);
 if(x.latitude!=null&&x.longitude!=null){animateMarker(x.latitude,x.longitude);const target=L.latLng(x.latitude,x.longitude);if(firstFix){if(points.length>1){const bounds=L.latLngBounds(points);bounds.extend(target);map.fitBounds(bounds.pad(.22),{maxZoom:16})}else map.setView(target,16);firstFix=false}else if(follow)map.panTo(target,{animate:true,duration:.7})}
}
async function tick(){if(document.hidden||ended)return;try{const r=await fetch('api-delivery-track.php?t='+encodeURIComponent(token),{cache:'no-store',headers:{Accept:'application/json'}});if(!r.ok)throw new Error('tracking');const j=await r.json();if(!j.ok)throw new Error('tracking');$('offline').classList.remove('show');apply(j.tracking)}catch(e){$('offline').classList.add('show')}}
apply(initial);if(!ended)pollTimer=setInterval(tick,6000);document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!ended)tick()});
</script>
</body>
</html>
