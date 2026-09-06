<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('dashboard');$tenantId=em_require_tenant();
$stats=[];
$queries=[
 'orders'=>'SELECT COUNT(*) FROM orders WHERE tenant_id=? AND DATE(created_at)=DATE(CURRENT_TIMESTAMP)',
 'sales'=>'SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE tenant_id=? AND payment_status="paid" AND DATE(created_at)=DATE(CURRENT_TIMESTAMP)',
 'paid_orders'=>'SELECT COUNT(*) FROM orders WHERE tenant_id=? AND payment_status="paid" AND DATE(created_at)=DATE(CURRENT_TIMESTAMP)',
 'active_orders'=>'SELECT COUNT(*) FROM orders WHERE tenant_id=? AND status IN ("confirmed","preparing","ready","out_for_delivery")',
 'open_tabs'=>'SELECT COUNT(*) FROM tabs WHERE tenant_id=? AND status="open"',
 'tickets'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status IN ("paid","checked_in")',
 'low_stock'=>'SELECT COUNT(*) FROM products WHERE tenant_id=? AND active=1 AND track_stock=1 AND stock_qty<=5',
 'pending_payments'=>'SELECT COUNT(*) FROM payments WHERE tenant_id=? AND status IN ("created","pending","authorized")',
 'occupied_tables'=>'SELECT COUNT(*) FROM restaurant_tables WHERE tenant_id=? AND status="occupied"',
];
foreach($queries as $k=>$sql){$s=$pdo->prepare($sql);$s->execute([$tenantId]);$stats[$k]=$s->fetchColumn();}
$ticketAverage=(int)$stats['paid_orders']>0?(int)round((int)$stats['sales']/(int)$stats['paid_orders']):0;

$hourly=array_fill(0,8,0);$hourLabels=['0h','3h','6h','9h','12h','15h','18h','21h'];
$h=$pdo->prepare('SELECT created_at,total_cents FROM orders WHERE tenant_id=? AND payment_status="paid" AND DATE(created_at)=DATE(CURRENT_TIMESTAMP)');$h->execute([$tenantId]);foreach($h->fetchAll() as$row){$hour=(int)date('G',strtotime((string)$row['created_at']));$bucket=min(7,intdiv($hour,3));$hourly[$bucket]+=(int)$row['total_cents'];}
$maxHourly=max(1,max($hourly));

$channelCounts=['table'=>0,'delivery'=>0,'pickup'=>0,'counter'=>0,'event'=>0,'event_bar'=>0];
$ch=$pdo->prepare('SELECT channel,COUNT(*) qty FROM orders WHERE tenant_id=? AND DATE(created_at)=DATE(CURRENT_TIMESTAMP) GROUP BY channel');$ch->execute([$tenantId]);foreach($ch->fetchAll() as$row)$channelCounts[(string)$row['channel']]=(int)$row['qty'];$channelTotal=max(1,array_sum($channelCounts));
$channelLabels=['table'=>'Salão','delivery'=>'Delivery','pickup'=>'Retirada','counter'=>'Balcão','event'=>'Ingressos','event_bar'=>'Evento / Bar'];

$sql='SELECT o.id,o.status,o.payment_status,o.total_cents,o.channel,o.created_at,c.name customer_name,rt.name table_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id WHERE o.tenant_id=?';$args=[$tenantId];if(Auth::role()==='delivery'){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}$sql.=' ORDER BY o.id DESC LIMIT 8';$s=$pdo->prepare($sql);$s->execute($args);$recent=$s->fetchAll();
$tenantSlug=(string)$pdo->query('SELECT slug FROM tenants WHERE id='.(int)$tenantId)->fetchColumn();

em_header('Visão geral','dashboard');
?>
<section class="page-hero"><div><span class="eyebrow">EVENTMENU</span><h2>Olá, <?= Security::e(explode(' ',trim(Auth::name()))[0]??Auth::name()) ?>!</h2><p>Acompanhe vendas, pedidos e pontos que precisam da sua atenção hoje.</p></div><div class="hero-actions"><a class="button primary" href="<?= Security::e(app_url('?route=pos')) ?>">+ Novo pedido</a><a class="button secondary" href="<?= Security::e(app_url('?route=reports')) ?>">Ver relatórios</a></div></section>

<section class="grid dashboard-metrics">
  <div class="card metric dashboard-metric"><div class="metric-icon">R$</div><span class="muted">Vendas do dia</span><strong><?= em_money($stats['sales']) ?></strong><small><?= (int)$stats['paid_orders'] ?> pedidos pagos</small></div>
  <div class="card metric dashboard-metric"><div class="metric-icon">#</div><span class="muted">Pedidos</span><strong><?= (int)$stats['orders'] ?></strong><small>criados hoje</small></div>
  <div class="card metric dashboard-metric"><div class="metric-icon">↗</div><span class="muted">Ticket médio</span><strong><?= em_money($ticketAverage) ?></strong><small>média dos pedidos pagos</small></div>
  <div class="card metric dashboard-metric"><div class="metric-icon">●</div><span class="muted">Pedidos agora</span><strong><?= (int)$stats['active_orders'] ?></strong><small>em operação</small></div>
</section>

<section class="dashboard-grid">
  <article class="card dashboard-chart"><div class="section-head"><div><span class="eyebrow">VENDAS</span><h2>Vendas nas últimas horas</h2></div><span class="badge">Hoje</span></div><div class="chart-bars"><?php foreach($hourly as$i=>$value):$height=max(6,(int)round(($value/$maxHourly)*100));?><div class="chart-bar" style="height:<?= $height ?>%" title="<?= Security::e($hourLabels[$i].' · '.em_money($value)) ?>"><span><?= Security::e($hourLabels[$i]) ?></span></div><?php endforeach;?></div></article>
  <article class="card"><div class="section-head"><div><span class="eyebrow">CANAIS</span><h2>Pedidos por canal</h2></div></div><div class="channel-list"><?php foreach($channelLabels as$key=>$label):$qty=(int)($channelCounts[$key]??0);if($qty===0&&$key==='event_bar')continue;$pct=(int)round($qty*100/$channelTotal);?><div class="channel-row"><span><?= Security::e($label) ?></span><div class="channel-track"><div class="channel-fill" style="width:<?= $pct ?>%"></div></div><strong><?= $pct ?>%</strong></div><?php endforeach;?></div></article>
</section>

<section class="quick-grid">
  <a class="quick-card" href="<?= Security::e(app_url('?route=inventory')) ?>"><strong><?= (int)$stats['low_stock'] ?> produtos com estoque baixo</strong><span>Itens com 5 unidades ou menos disponíveis.</span></a>
  <a class="quick-card" href="<?= Security::e(app_url('?route=payments&status=pending')) ?>"><strong><?= (int)$stats['pending_payments'] ?> pagamentos aguardando</strong><span>Cobranças criadas, pendentes ou autorizadas.</span></a>
  <a class="quick-card" href="<?= Security::e(app_url('?route=restaurant')) ?>"><strong><?= (int)$stats['occupied_tables'] ?> mesas ocupadas</strong><span><?= (int)$stats['open_tabs'] ?> comandas abertas neste momento.</span></a>
</section>

<section class="card" style="margin-top:14px"><div class="section-head"><div><span class="eyebrow">OPERAÇÃO</span><h2>Pedidos recentes</h2></div><a class="button secondary" href="<?= Security::e(app_url('?route=orders')) ?>">Ver todos os pedidos</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Cliente / origem</th><th>Canal</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Horário</th></tr></thead><tbody><?php foreach($recent as$o):?><tr><td><a href="<?= Security::e(app_url('?route=orders&view='.(int)$o['id'])) ?>"><strong>#<?= (int)$o['id'] ?></strong></a></td><td><?= Security::e($o['customer_name']??$o['table_name']??'Consumidor') ?></td><td><?= Security::e($channelLabels[$o['channel']]??$o['channel']) ?></td><td><span class="badge"><?= Security::e($o['status']) ?></span></td><td><?= Security::e($o['payment_status']) ?></td><td><strong><?= em_money($o['total_cents']) ?></strong></td><td><?= Security::e(date('H:i',strtotime((string)$o['created_at']))) ?></td></tr><?php endforeach;?></tbody></table></div></section>

<section class="quick-grid">
  <a class="quick-card" href="<?= Security::e(app_url('menu.php?empresa='.urlencode($tenantSlug))) ?>" target="_blank"><strong>Cardápio público</strong><span>Abra a experiência que o cliente visualiza.</span></a>
  <a class="quick-card" href="<?= Security::e(app_url('?route=tickets')) ?>"><strong>Ingressos e check-in</strong><span>Validar ingressos e acompanhar entradas.</span></a>
  <a class="quick-card" href="<?= Security::e(app_url('?route=kitchen')) ?>"><strong>Cozinha / KDS</strong><span>Acompanhar estações, preparo e atrasos.</span></a>
</section>
<?php em_footer();
