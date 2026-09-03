<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('dashboard');$tenantId=em_require_tenant();
$stats=[];
$queries=[
 'orders'=>'SELECT COUNT(*) FROM orders WHERE tenant_id=? AND DATE(created_at)=CURDATE()',
 'sales'=>'SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE tenant_id=? AND payment_status="paid" AND DATE(created_at)=CURDATE()',
 'open_tabs'=>'SELECT COUNT(*) FROM tabs WHERE tenant_id=? AND status="open"',
 'tickets'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status IN ("paid","checked_in")',
];
foreach($queries as $k=>$sql){$s=$pdo->prepare($sql);$s->execute([$tenantId]);$stats[$k]=$s->fetchColumn();}
$sql='SELECT o.id,o.status,o.payment_status,o.total_cents,o.channel,o.created_at,o.assigned_delivery_user_id FROM orders o WHERE o.tenant_id=?';$args=[$tenantId];if(Auth::role()==='delivery'){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}$sql.=' ORDER BY o.id DESC LIMIT 12';$s=$pdo->prepare($sql);$s->execute($args);$recent=$s->fetchAll();
$tenantSlug=(string)$pdo->query('SELECT slug FROM tenants WHERE id='.(int)$tenantId)->fetchColumn();
em_header('Visão geral','dashboard');
?><section class="grid"><div class="card metric"><span class="muted">Pedidos hoje</span><strong><?= (int)$stats['orders'] ?></strong></div><div class="card metric"><span class="muted">Recebido hoje</span><strong><?= em_money($stats['sales']) ?></strong></div><div class="card metric"><span class="muted">Comandas abertas</span><strong><?= (int)$stats['open_tabs'] ?></strong></div><div class="card metric"><span class="muted">Ingressos pagos</span><strong><?= (int)$stats['tickets'] ?></strong></div></section><section class="card" style="margin-top:18px"><div class="section-head"><h2>Pedidos recentes</h2><a class="button secondary" href="<?= Security::e(em_url('/?route=orders')) ?>">Ver operação</a></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Canal</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Data</th></tr></thead><tbody><?php foreach($recent as $o):?><tr><td>#<?= (int)$o['id'] ?></td><td><?= Security::e($o['channel']) ?></td><td><span class="badge"><?= Security::e($o['status']) ?></span></td><td><?= Security::e($o['payment_status']) ?></td><td><?= em_money($o['total_cents']) ?></td><td><?= Security::e($o['created_at']) ?></td></tr><?php endforeach;?></tbody></table></div></section><section class="grid" style="margin-top:18px"><a class="card" href="<?= Security::e(em_url('/menu.php?empresa='.rawurlencode($tenantSlug))) ?>"><h2>Cardápio público</h2><p class="muted">Visualizar experiência do cliente.</p></a><a class="card" href="<?= Security::e(em_url('/?route=tickets')) ?>"><h2>Check-in</h2><p class="muted">Validar ingresso por código ou QR.</p></a><a class="card" href="<?= Security::e(em_url('/?route=restaurant')) ?>"><h2>Salão</h2><p class="muted">Mesas e comandas abertas.</p></a></section><?php em_footer();
