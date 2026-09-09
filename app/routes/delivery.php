<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryProgressService;
use EventMenu\Services\DeliveryTrackingService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\WorkShiftService;

Auth::requirePermission('orders.delivery');
$tenantId = em_require_tenant();

try {
    $unit = (new OperatingUnitService())->requireCurrent();
} catch (Throwable $e) {
    em_header('Entregas', 'delivery');
    ?><div class="alert error"><?= Security::e($e->getMessage()) ?></div><?php
    em_footer();
    return;
}

$unitId = (int)$unit['id'];
$shift = null;
try { $shift = (new WorkShiftService())->current(); } catch (Throwable) {}
$isDeliveryUser = Auth::role() === 'delivery';

if ($isDeliveryUser && $shift && $shift['unit_id'] !== null && (int)$shift['unit_id'] !== $unitId) {
    em_header('Entregas', 'delivery');
    ?><div class="alert error">Seu turno de entrega está aberto em outra unidade.</div><?php
    em_footer();
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'tracking') {
            $token = (new DeliveryTrackingService())->publicToken($tenantId, $id);
            $url = rtrim((string)env('APP_URL', ''), '/') . '/rastreio.php?t=' . $token;
            em_flash('ok', 'Link privado do cliente: ' . $url);
        } else {
            if (!$isDeliveryUser) throw new RuntimeException('As etapas da entrega devem ser registradas pelo entregador responsável.');
            $progress = new DeliveryProgressService();
            match ($action) {
                'pickup' => $progress->pickup($id),
                'start-route' => $progress->startRoute($id),
                'arrive' => $progress->arrive($id),
                'delivered' => $progress->complete($id),
                default => throw new RuntimeException('Ação inválida.'),
            };
            em_flash('ok', 'Pedido #' . $id . ' atualizado.');
        }
    } catch (Throwable $e) {
        em_flash('error', $e->getMessage());
    }
    em_go('delivery');
}

$sql = 'SELECT o.*,c.name customer_name,c.phone customer_phone,u.name delivery_name,
               dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at,
               l.latitude live_lat,l.longitude live_lng,l.accuracy_m live_accuracy,l.updated_at live_updated
        FROM orders o
        LEFT JOIN customers c ON c.id=o.customer_id
        LEFT JOIN users u ON u.id=o.assigned_delivery_user_id
        LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id
        LEFT JOIN delivery_live_locations l ON l.tenant_id=o.tenant_id AND l.order_id=o.id
        WHERE o.tenant_id=? AND o.unit_id=? AND o.channel="delivery" AND o.status IN ("ready","out_for_delivery","completed")';
$args = [$tenantId, $unitId];
if ($isDeliveryUser) {
    $sql .= ' AND o.assigned_delivery_user_id=?';
    $args[] = Auth::id();
}
$sql .= ' ORDER BY CASE WHEN o.status="out_for_delivery" THEN 0 WHEN o.status="ready" THEN 1 ELSE 2 END,o.id DESC LIMIT 100';
$s = $pdo->prepare($sql);
$s->execute($args);
$orders = $s->fetchAll();
$ready = count(array_filter($orders, static fn(array $o): bool => $o['status'] === 'ready'));
$onRoute = count(array_filter($orders, static fn(array $o): bool => $o['status'] === 'out_for_delivery'));
$done = count(array_filter($orders, static fn(array $o): bool => $o['status'] === 'completed'));

if (!$isDeliveryUser) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://unpkg.com; img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
}

em_header('Entregas', 'delivery');
?>
<section class="page-hero">
    <div>
        <span class="eyebrow">DELIVERY · <?= Security::e($unit['name']) ?></span>
        <h2><?= $isDeliveryUser ? 'Minhas entregas' : 'Central de entregas ao vivo' ?></h2>
        <p><?= $isDeliveryUser ? 'Registre cada etapa na ordem correta. O GPS em segundo plano é enviado pelo EventMenu GO durante a rota.' : 'Acompanhe entregadores, sinal do GPS e pedidos em rota sem recarregar a página.' ?></p>
    </div>
    <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=orders&channel=delivery')) ?>">Pedidos</a></div>
</section>

<section class="grid dashboard-metrics">
    <div class="card metric"><span class="muted">Prontos</span><strong><?= $ready ?></strong></div>
    <div class="card metric"><span class="muted">Em rota</span><strong><?= $onRoute ?></strong></div>
    <div class="card metric"><span class="muted">Concluídos</span><strong><?= $done ?></strong></div>
    <div class="card metric"><span class="muted">Unidade</span><strong style="font-size:20px"><?= Security::e($unit['name']) ?></strong></div>
</section>

<?php if (!$isDeliveryUser): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
.delivery-live-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,.8fr);gap:16px;margin-top:18px}.delivery-live-map{height:520px;border-radius:18px;background:#e9edf3;overflow:hidden}.delivery-live-side{max-height:520px;overflow:auto;display:grid;gap:9px;align-content:start}.gps-row{border:1px solid var(--border,#e4e7ec);border-radius:15px;padding:12px;background:var(--surface,#fff)}.gps-head{display:flex;justify-content:space-between;gap:8px;align-items:center}.gps-signal{font-size:11px;font-weight:800;border-radius:999px;padding:5px 8px}.gps-live{background:#e9f8ef;color:#087443}.gps-weak{background:#fff5d6;color:#8a5a00}.gps-offline,.gps-mock{background:#feeceb;color:#b42318}.gps-meta{font-size:12px;color:#667085;margin-top:5px}.manager-bike{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:#111827;color:#fff;border:3px solid #fff;box-shadow:0 5px 16px #0004;font-size:20px}.manager-bike.warn{background:#c47b00}.manager-bike.bad{background:#b42318}.manager-marker{background:none!important;border:none!important}@media(max-width:900px){.delivery-live-grid{grid-template-columns:1fr}.delivery-live-map{height:420px}.delivery-live-side{max-height:none}}
</style>
<section class="card" style="margin-top:18px;padding:18px">
    <div class="section-head"><div><span class="eyebrow">GPS EM TEMPO REAL</span><h2 style="margin-bottom:4px">Mapa das entregas</h2><p class="muted" style="margin:0">Todas as entregas ativas da empresa aparecem aqui.</p></div><div><span id="gpsSummary" class="badge">Conectando…</span></div></div>
    <div class="delivery-live-grid"><div id="deliveryLiveMap" class="delivery-live-map"></div><div id="deliveryLiveList" class="delivery-live-side"><div class="muted">Carregando posições…</div></div></div>
</section>
<?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-top:18px">
<?php foreach ($orders as $o):
    $picked = !empty($o['picked_up_at']);
    $routeStarted = !empty($o['route_started_at']) || $o['status'] === 'out_for_delivery';
    $arrived = !empty($o['arrived_at']);
?>
<article class="card">
    <div class="section-head"><div><span class="eyebrow"><?= Security::e(date('H:i', strtotime((string)$o['created_at']))) ?></span><h2>#<?= (int)$o['id'] ?></h2><strong><?= Security::e($o['customer_name'] ?? 'Cliente') ?></strong></div><span class="status-pill <?= $o['status'] === 'completed' ? 'active' : '' ?>"><?= Security::e($o['status']) ?></span></div>
    <p><?= Security::e($o['customer_phone'] ?? '') ?><br><strong>Endereço:</strong> <?= nl2br(Security::e($o['delivery_address'] ?? '')) ?></p>
    <p><strong>Total:</strong> <?= em_money($o['total_cents']) ?><br><strong>Pagamento:</strong> <span class="badge"><?= Security::e($o['payment_status']) ?></span><?php if (!$isDeliveryUser): ?><br><strong>Entregador:</strong> <?= Security::e($o['delivery_name'] ?? 'Não atribuído') ?><?php endif; ?></p>

    <?php if ($o['status'] === 'out_for_delivery'): ?>
        <div class="alert <?= $o['live_lat'] !== null ? 'ok' : '' ?>"><strong>GPS:</strong> <?= $o['live_lat'] !== null ? 'Última posição recebida · ' . Security::e((string)$o['live_updated']) : 'Aguardando primeiro sinal do APK' ?></div>
        <form method="post" style="margin-bottom:8px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="tracking"><button class="secondary" style="width:100%">Criar novo link privado do cliente</button></form>
    <?php endif; ?>

    <?php if ($o['notes']): ?><div class="alert">Obs.: <?= Security::e($o['notes']) ?></div><?php endif; ?>

    <?php if ($isDeliveryUser): ?>
        <?php if ($o['status'] === 'ready' && !$picked): ?>
            <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="pickup"><button class="primary" style="width:100%">Retirar pedido</button></form>
        <?php elseif ($o['status'] === 'ready' && $picked): ?>
            <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="start-route"><button class="primary" style="width:100%">Iniciar rota</button></form>
        <?php elseif ($o['status'] === 'out_for_delivery' && !$arrived): ?>
            <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="arrive"><button class="primary" style="width:100%">Cheguei ao cliente</button></form>
        <?php elseif ($o['status'] === 'out_for_delivery'): ?>
            <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="delivered"><button class="primary" style="width:100%"<?= $o['payment_status'] !== 'paid' ? ' disabled title="Pagamento precisa estar confirmado pelo servidor"' : '' ?>>Concluir entrega</button></form>
        <?php else: ?><div class="alert ok">Entrega concluída.</div><?php endif; ?>
    <?php elseif ($o['status'] === 'completed'): ?><div class="alert ok">Entrega concluída.</div><?php endif; ?>
</article>
<?php endforeach; ?>
</div>
<?php if (!$orders): ?><section class="card" style="margin-top:18px"><h2>Nenhuma entrega na fila</h2><p class="muted">Pedidos atribuídos aparecerão aqui quando estiverem prontos.</p></section><?php endif; ?>

<?php if (!$isDeliveryUser): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(()=>{const map=L.map('deliveryLiveMap',{zoomControl:true}).setView([-14.2,-51.9],4);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map);const markers=new Map(),list=document.getElementById('deliveryLiveList'),summary=document.getElementById('gpsSummary');let first=true;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function age(s){if(s==null||s>99999)return 'sem localização';if(s<10)return 'agora';if(s<60)return `há ${s}s`;return `há ${Math.floor(s/60)} min`}
function icon(status){const cls=status==='live'?'':status==='weak'?' warn':' bad';return L.divIcon({className:'manager-marker',html:`<div class="manager-bike${cls}">🛵</div>`,iconSize:[38,38],iconAnchor:[19,19]})}
async function refresh(){try{const r=await fetch('<?= Security::e(app_url('api-delivery-manager-live.php')) ?>',{cache:'no-store',headers:{Accept:'application/json'}}),j=await r.json();if(!r.ok||!j.ok)throw 0;const seen=new Set(),bounds=[];list.innerHTML='';summary.textContent=`${j.metrics.live} ao vivo · ${j.metrics.attention} atenção`;
if(!j.deliveries.length){list.innerHTML='<div class="muted">Nenhuma entrega em rota agora.</div>'}
j.deliveries.forEach(d=>{const id=Number(d.order_id),sig=d.signal||{status:'offline',label:'Sem sinal',age_seconds:999999};seen.add(id);const row=document.createElement('div');row.className='gps-row';const head=document.createElement('div');head.className='gps-head';const strong=document.createElement('strong');strong.textContent=`#${id} · ${d.delivery_name||'Entregador'}`;const pill=document.createElement('span');pill.className=`gps-signal gps-${sig.status}`;pill.textContent=sig.label;head.append(strong,pill);const meta=document.createElement('div');meta.className='gps-meta';meta.textContent=`${d.customer_name||'Cliente'} · ${age(sig.age_seconds)}${d.speed_kmh!=null?' · '+Math.round(d.speed_kmh)+' km/h':''}${d.battery_pct!=null?' · bateria '+d.battery_pct+'%':''}`;row.append(head,meta);list.appendChild(row);
if(d.latitude!=null&&d.longitude!=null){const ll=[Number(d.latitude),Number(d.longitude)];bounds.push(ll);let m=markers.get(id);if(!m){m=L.marker(ll,{icon:icon(sig.status)}).addTo(map);markers.set(id,m)}else{m.setLatLng(ll);m.setIcon(icon(sig.status))}const popup=document.createElement('div');popup.innerHTML=`<strong>Pedido #${id}</strong><br>${esc(d.delivery_name||'Entregador')}<br>${esc(d.customer_name||'Cliente')}<br><small>${esc(sig.label)} · ${esc(age(sig.age_seconds))}</small>`;m.bindPopup(popup);row.style.cursor='pointer';row.onclick=()=>{map.flyTo(ll,17,{duration:.6});m.openPopup()}}
});markers.forEach((m,id)=>{if(!seen.has(id)){map.removeLayer(m);markers.delete(id)}});if(first&&bounds.length){map.fitBounds(bounds,{padding:[45,45],maxZoom:16});first=false}}catch(e){summary.textContent='Reconectando ao GPS…'}}
refresh();setInterval(refresh,6000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh()});})();
</script>
<?php endif; ?>
<?php em_footer();
