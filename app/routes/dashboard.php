<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('dashboard');
$tenantId=em_require_tenant();
$unitId=Auth::unitId();

$tenantStmt=$pdo->prepare('SELECT slug,settings FROM tenants WHERE id=? LIMIT 1');
$tenantStmt->execute([$tenantId]);
$tenantRow=$tenantStmt->fetch()?:[];
$tenantSlug=(string)($tenantRow['slug']??'');
$tenantSettings=json_decode((string)($tenantRow['settings']??'{}'),true);
if(!is_array($tenantSettings))$tenantSettings=[];
try{$tz=new DateTimeZone((string)($tenantSettings['timezone']??'America/Sao_Paulo'));}catch(Throwable){$tz=new DateTimeZone('America/Sao_Paulo');}
$utc=new DateTimeZone('UTC');
$nowLocal=new DateTimeImmutable('now',$tz);
$monthStartLocal=$nowLocal->modify('first day of this month')->setTime(0,0);
$nextMonthLocal=$monthStartLocal->modify('+1 month');
$monthStart=$monthStartLocal->setTimezone($utc)->format('Y-m-d H:i:s');
$nextMonth=$nextMonthLocal->setTimezone($utc)->format('Y-m-d H:i:s');

$scope=$unitId?' AND unit_id=?':'';
$scopeArgs=$unitId?[$tenantId,$unitId]:[$tenantId];

$count=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=?'.$scope.' AND created_at>=? AND created_at<?');
$count->execute([...$scopeArgs,$monthStart,$nextMonth]);
$ordersMonth=(int)$count->fetchColumn();

$sales=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0),COUNT(*) FROM orders WHERE tenant_id=?'.$scope.' AND payment_status="paid" AND created_at>=? AND created_at<?');
$sales->execute([...$scopeArgs,$monthStart,$nextMonth]);
[$revenueMonth,$paidOrders]=$sales->fetch(PDO::FETCH_NUM)?:[0,0];
$revenueMonth=(int)$revenueMonth;$paidOrders=(int)$paidOrders;
$avgTicket=$paidOrders>0?(int)round($revenueMonth/$paidOrders):0;

$ticketSql='SELECT COUNT(*) FROM tickets t JOIN events e ON e.id=t.event_id WHERE t.tenant_id=? AND t.status IN ("paid","checked_in") AND t.created_at>=? AND t.created_at<?';
$ticketArgs=[$tenantId,$monthStart,$nextMonth];
if($unitId){$ticketSql.=' AND (e.unit_id IS NULL OR e.unit_id=?)';$ticketArgs[]=$unitId;}
$tickets=$pdo->prepare($ticketSql);$tickets->execute($ticketArgs);$ticketsMonth=(int)$tickets->fetchColumn();

$days=[];
for($i=6;$i>=0;$i--){$d=$nowLocal->modify('-'.$i.' days');$days[$d->format('Y-m-d')]=['label'=>$d->format('d/m'),'value'=>0];}
$sevenStart=$nowLocal->modify('-6 days')->setTime(0,0)->setTimezone($utc)->format('Y-m-d H:i:s');
$sevenEnd=$nowLocal->modify('+1 day')->setTime(0,0)->setTimezone($utc)->format('Y-m-d H:i:s');
$seven=$pdo->prepare('SELECT total_cents,created_at FROM orders WHERE tenant_id=?'.$scope.' AND payment_status="paid" AND created_at>=? AND created_at<?');
$seven->execute([...$scopeArgs,$sevenStart,$sevenEnd]);
foreach($seven->fetchAll() as$row){try{$day=(new DateTimeImmutable((string)$row['created_at'],$utc))->setTimezone($tz)->format('Y-m-d');if(isset($days[$day]))$days[$day]['value']+=(int)$row['total_cents'];}catch(Throwable){}}
$maxDay=max(1,...array_map(fn($d)=>(int)$d['value'],$days));

$types=['event'=>0,'menu'=>0];
$typeRows=$pdo->prepare('SELECT channel,total_cents FROM orders WHERE tenant_id=?'.$scope.' AND payment_status="paid" AND created_at>=? AND created_at<?');
$typeRows->execute([...$scopeArgs,$monthStart,$nextMonth]);
foreach($typeRows->fetchAll() as$row){$key=(string)$row['channel']==='event'?'event':'menu';$types[$key]+=(int)$row['total_cents'];}
$typeTotal=max(1,$types['event']+$types['menu']);
$eventPct=(int)round($types['event']*100/$typeTotal);$menuPct=100-$eventPct;

$recentSql='SELECT o.id,o.status,o.payment_status,o.total_cents,o.channel,o.created_at,c.name customer_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=?';
$recentArgs=[$tenantId];
if($unitId){$recentSql.=' AND o.unit_id=?';$recentArgs[]=$unitId;}
$recentSql.=' ORDER BY o.id DESC LIMIT 8';
$recentStmt=$pdo->prepare($recentSql);$recentStmt->execute($recentArgs);$recent=$recentStmt->fetchAll();

function dashboard_channel_label(string$channel):string{return match($channel){'event'=>'Evento','delivery'=>'Delivery','pickup'=>'Retirada','counter'=>'Balcão','table'=>'Mesa',default=>ucfirst($channel)};}

em_header('Dashboard','dashboard');
?>
<section class="legacy-dashboard">
  <div class="legacy-page-intro">
    <div><h2>Visão geral do seu negócio</h2><p>Acompanhe vendas, pedidos e operação em um só lugar.</p></div>
    <div class="actions"><a class="button secondary" target="_blank" href="<?= Security::e(em_url('/menu.php?empresa='.rawurlencode($tenantSlug))) ?>">Ver cardápio</a><a class="button primary" href="<?= Security::e(em_url('/?route=pos')) ?>">+ Nova venda</a></div>
  </div>

  <div class="legacy-metrics">
    <article class="legacy-metric"><span>Faturamento (Mês)</span><strong><?= em_money($revenueMonth) ?></strong><small>Pedidos pagos no mês atual</small></article>
    <article class="legacy-metric"><span>Pedidos (Mês)</span><strong><?= $ordersMonth ?></strong><small>Todos os canais de venda</small></article>
    <article class="legacy-metric"><span>Ingressos Vendidos</span><strong><?= $ticketsMonth ?></strong><small>Pagos ou já utilizados</small></article>
    <article class="legacy-metric"><span>Ticket Médio</span><strong><?= em_money($avgTicket) ?></strong><small>Média das vendas pagas</small></article>
  </div>

  <div class="legacy-analytics">
    <article class="card legacy-chart-card">
      <div class="section-head"><div><h2>Faturamento</h2><p class="muted">Últimos 7 dias</p></div><span class="badge">7 dias</span></div>
      <div class="legacy-bars" aria-label="Faturamento dos últimos sete dias">
        <?php foreach($days as$d):$height=max(7,(int)round(((int)$d['value']/$maxDay)*100));?>
          <div class="legacy-bar-col" title="<?= Security::e($d['label'].' · '.em_money($d['value'])) ?>"><div class="legacy-bar-value"><?= $d['value']?Security::e(em_money($d['value'])):'' ?></div><div class="legacy-bar-track"><div class="legacy-bar-fill" style="height:<?= $height ?>%"></div></div><span><?= Security::e($d['label']) ?></span></div>
        <?php endforeach;?>
      </div>
    </article>
    <article class="card legacy-chart-card">
      <div class="section-head"><div><h2>Vendas por Tipo</h2><p class="muted">Distribuição do mês</p></div></div>
      <div class="legacy-sales-split">
        <div class="legacy-donut" style="--event-pct:<?= $eventPct ?>%"><div><strong><?= $paidOrders ?></strong><span>vendas pagas</span></div></div>
        <div class="legacy-legend"><div><i class="event"></i><span>Eventos</span><strong><?= $eventPct ?>%</strong><small><?= em_money($types['event']) ?></small></div><div><i class="menu"></i><span>Cardápio Digital</span><strong><?= $menuPct ?>%</strong><small><?= em_money($types['menu']) ?></small></div></div>
      </div>
    </article>
  </div>

  <article class="card legacy-recent">
    <div class="section-head"><div><h2>Pedidos Recentes</h2><p class="muted">Últimas movimentações da operação</p></div><a class="button secondary" href="<?= Security::e(em_url('/?route=orders')) ?>">Ver todos</a></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Tipo</th><th>Cliente</th><th>Valor</th><th>Status</th><th>Data</th></tr></thead><tbody>
      <?php foreach($recent as$o):?><tr><td><strong>#<?= (int)$o['id'] ?></strong></td><td><?= Security::e(dashboard_channel_label((string)$o['channel'])) ?></td><td><?= Security::e($o['customer_name']??'Consumidor') ?></td><td><strong><?= em_money($o['total_cents']) ?></strong></td><td><span class="badge <?= $o['payment_status']==='paid'?'ok':'' ?>"><?= Security::e($o['payment_status']==='paid'?'Pago':em_status_label((string)$o['status'])) ?></span></td><td><?= Security::e((new DateTimeImmutable((string)$o['created_at'],$utc))->setTimezone($tz)->format('d/m/Y H:i')) ?></td></tr><?php endforeach;?>
      <?php if(!$recent):?><tr><td colspan="6" class="muted">Nenhum pedido encontrado.</td></tr><?php endif;?>
    </tbody></table></div>
  </article>
</section>
<style>
.legacy-dashboard{display:grid;gap:18px}.legacy-page-intro{display:flex;align-items:center;justify-content:space-between;gap:18px}.legacy-page-intro h2{font-size:22px;margin:0;color:var(--text-strong)}.legacy-page-intro p{margin:6px 0 0;color:var(--muted)}.legacy-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.legacy-metric{background:#fff;border:1px solid var(--line);border-radius:16px;padding:20px;box-shadow:0 4px 18px rgba(30,27,65,.035);position:relative;overflow:hidden}.legacy-metric:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent)}.legacy-metric span{font-size:12px;color:var(--muted);font-weight:700}.legacy-metric strong{display:block;font-size:27px;letter-spacing:-.8px;color:var(--text-strong);margin:8px 0 5px}.legacy-metric small{color:#9999aa}.legacy-analytics{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.8fr);gap:16px}.legacy-chart-card{min-height:310px}.legacy-chart-card .section-head p{margin:4px 0 0}.legacy-bars{height:218px;display:grid;grid-template-columns:repeat(7,1fr);gap:10px;align-items:end;padding-top:18px}.legacy-bar-col{height:100%;display:grid;grid-template-rows:24px 1fr 22px;gap:5px;text-align:center;min-width:0}.legacy-bar-value{font-size:9px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.legacy-bar-track{height:100%;background:#f1effa;border-radius:10px;display:flex;align-items:end;overflow:hidden}.legacy-bar-fill{width:100%;min-height:7px;border-radius:10px;background:linear-gradient(180deg,#8b6cff,var(--accent));box-shadow:0 5px 16px rgba(109,74,255,.22)}.legacy-bar-col>span{font-size:10px;color:var(--muted)}.legacy-sales-split{display:grid;grid-template-columns:155px 1fr;align-items:center;gap:22px;padding-top:22px}.legacy-donut{width:150px;height:150px;border-radius:50%;background:conic-gradient(var(--accent) 0 var(--event-pct),#d8d0ff var(--event-pct) 100%);display:grid;place-items:center;position:relative}.legacy-donut:after{content:"";position:absolute;inset:22px;border-radius:50%;background:#fff}.legacy-donut>div{position:relative;z-index:1;text-align:center}.legacy-donut strong{display:block;font-size:25px}.legacy-donut span{font-size:10px;color:var(--muted)}.legacy-legend{display:grid;gap:15px}.legacy-legend>div{display:grid;grid-template-columns:10px 1fr auto;gap:8px;align-items:center}.legacy-legend i{width:9px;height:9px;border-radius:50%;background:var(--accent)}.legacy-legend i.menu{background:#d8d0ff}.legacy-legend strong{font-size:13px}.legacy-legend small{grid-column:2/4;color:var(--muted)}@media(max-width:1050px){.legacy-metrics{grid-template-columns:repeat(2,1fr)}.legacy-analytics{grid-template-columns:1fr}}@media(max-width:640px){.legacy-page-intro{align-items:flex-start;flex-direction:column}.legacy-page-intro .actions{width:100%}.legacy-page-intro .actions a{flex:1}.legacy-metrics{grid-template-columns:1fr 1fr;gap:10px}.legacy-metric{padding:15px}.legacy-metric strong{font-size:21px}.legacy-metric small{font-size:10px}.legacy-chart-card{min-height:auto}.legacy-bars{height:185px;gap:6px}.legacy-sales-split{grid-template-columns:120px 1fr;gap:14px}.legacy-donut{width:118px;height:118px}.legacy-donut:after{inset:18px}}
</style>
<?php em_footer();
