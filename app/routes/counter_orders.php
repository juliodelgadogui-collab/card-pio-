<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('counter.orders');
$tenantId=em_require_tenant();$unitId=Auth::unitId();
$userId=(int)Auth::id();

$sql='SELECT o.id,o.status,o.payment_status,o.fulfillment_status,o.total_cents,o.created_at,c.name customer_name,(SELECT GROUP_CONCAT(CONCAT(oi.quantity,"× ",oi.name_snapshot) ORDER BY oi.id SEPARATOR " · ") FROM order_items oi WHERE oi.order_id=o.id) items FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.channel="counter" AND o.created_by=?'.($unitId?' AND o.unit_id=?':'').' ORDER BY o.id DESC LIMIT 80';
$args=[$tenantId,$userId];if($unitId)$args[]=$unitId;$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();

em_header('Minhas vendas','counter-orders');
?><section class="card"><div class="section-head"><div><h2>Vendas do seu usuário</h2><div class="muted">Somente pedidos criados por você<?= $unitId?' na unidade selecionada':'' ?>.</div></div><a class="button primary" href="<?= Security::e(em_url('/?route=pos')) ?>">Nova venda</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Venda</th><th>Cliente</th><th>Itens</th><th>Pagamento</th><th>Retirada</th><th>Total</th><th>Data</th></tr></thead><tbody><?php foreach($orders as$o):?><tr><td><strong>#<?= (int)$o['id'] ?></strong></td><td><?= Security::e($o['customer_name']??'Consumidor') ?></td><td><?= Security::e($o['items']??'') ?></td><td><span class="badge"><?= Security::e(em_status_label($o['payment_status'])) ?></span></td><td><span class="badge"><?= Security::e(em_status_label($o['fulfillment_status'])) ?></span></td><td><strong><?= em_money($o['total_cents']) ?></strong></td><td><?= Security::e($o['created_at']) ?></td></tr><?php endforeach;?><?php if(!$orders):?><tr><td colspan="7" class="muted">Você ainda não realizou vendas nesta unidade.</td></tr><?php endif;?></tbody></table></div></section><?php em_footer();
