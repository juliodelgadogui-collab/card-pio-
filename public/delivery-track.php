<?php

declare(strict_types=1);

$token=trim((string)($_GET['token']??''));
$nonce=base64_encode(random_bytes(18));
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; frame-src https://www.openstreetmap.org; style-src 'self' 'unsafe-inline'; script-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Acompanhar entrega • EventMenu</title>
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#111827;background:#f4f7fb}*{box-sizing:border-box}body{margin:0;min-height:100vh}.wrap{max-width:820px;margin:0 auto;padding:24px 16px 40px}.brand{font-weight:850;letter-spacing:-.02em;font-size:21px;margin-bottom:18px}.card{background:#fff;border:1px solid #e7eaf0;border-radius:20px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.06)}h1{font-size:28px;margin:0 0 8px}.muted{color:#667085}.status{display:inline-flex;padding:7px 11px;border-radius:999px;background:#ede9fe;color:#5b21b6;font-weight:750;margin:12px 0}.steps{display:grid;gap:9px;margin:18px 0}.step{display:flex;justify-content:space-between;padding:12px 14px;background:#f8fafc;border-radius:12px}.done{font-weight:750;color:#047857}.pending{color:#667085}.map{width:100%;height:340px;border:0;border-radius:16px;background:#eef2f7;margin-top:14px}.hidden{display:none}.error{color:#b42318;background:#fef3f2;padding:12px;border-radius:12px}.updated{font-size:13px;color:#667085;margin-top:10px}@media(max-width:560px){h1{font-size:24px}.card{padding:17px}.map{height:300px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">EventMenu</div>
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
      <iframe id="map" class="map" title="Posição atual da entrega" loading="lazy" referrerpolicy="no-referrer"></iframe>
      <div id="updated" class="updated"></div>
    </section>
    <div id="error" class="error hidden"></div>
  </main>
</div>
<script nonce="<?=htmlspecialchars($nonce,ENT_QUOTES,'UTF-8')?>">
const token=<?=json_encode($token,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>;
const $=id=>document.getElementById(id);
const done=(id,value)=>{const el=$(id);el.textContent=value?'Concluído':'Pendente';el.className=value?'done':'pending'};
const label=status=>({ready:'Pedido pronto',out_for_delivery:'Saiu para entrega',completed:'Entrega concluída',cancelled:'Pedido cancelado'})[status]||'Acompanhando pedido';
function mapUrl(lat,lng){const d=.008;const bbox=[lng-d,lat-d,lng+d,lat+d].join(',');return 'https://www.openstreetmap.org/export/embed.html?bbox='+encodeURIComponent(bbox)+'&layer=mapnik&marker='+encodeURIComponent(lat+','+lng)}
async function refresh(){
  if(!token){showError('Link de acompanhamento inválido.');return}
  try{
    const r=await fetch('api-delivery-track.php?token='+encodeURIComponent(token),{cache:'no-store',credentials:'omit'});const j=await r.json();
    if(!r.ok||!j.ok)throw new Error(j.error||'Acompanhamento indisponível.');
    const t=j.tracking;$('title').textContent='Pedido #'+t.order_id;$('status').textContent=label(t.status);
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
