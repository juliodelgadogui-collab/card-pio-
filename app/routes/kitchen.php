<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OrderWorkflowService;

Auth::requirePermission('orders.kitchen');$tenantId=em_require_tenant();
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
    if(!in_array($status,['preparing','ready'],true))exit('Status inválido.');
    try{(new OrderWorkflowService())->transition($tenantId,$id,$status);em_flash('ok','Pedido #'.$id.' atualizado.');}catch(Throwable $e){em_flash('error',$e->getMessage());}em_go('kitchen');
}
$s=$pdo->prepare('SELECT o.id,o.channel,o.status,o.created_at,o.notes,rt.name table_name,c.name customer_name,(SELECT GROUP_CONCAT(CONCAT(oi.quantity,"× ",oi.name_snapshot) ORDER BY oi.id SEPARATOR " | ") FROM order_items oi WHERE oi.order_id=o.id) items FROM orders o LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.status IN ("confirmed","preparing","ready") ORDER BY FIELD(o.status,"preparing","confirmed","ready"),o.created_at');$s->execute([$tenantId]);$orders=$s->fetchAll();
em_header('Cozinha / KDS','kitchen');
?><meta http-equiv="refresh" content="15"><section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))"><?php foreach($orders as$o):?><article class="card" style="border-width:2px"><div class="section-head"><div><h2>#<?= (int)$o['id'] ?></h2><span class="muted"><?= Security::e($o['table_name']??$o['customer_name']??$o['channel']) ?></span></div><span class="badge"><?= Security::e($o['status']) ?></span></div><p style="font-size:1.05rem;line-height:1.55"><strong><?= Security::e($o['items']??'') ?></strong></p><?php if(!empty($o['notes'])):?><div class="alert"><?= nl2br(Security::e($o['notes'])) ?></div><?php endif;?><p class="muted">Entrada: <?= Security::e(date('H:i',strtotime($o['created_at']))) ?></p><?php if($o['status']==='confirmed'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="preparing"><button class="primary" style="width:100%">Iniciar preparo</button></form><?php elseif($o['status']==='preparing'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="ready"><button class="primary" style="width:100%">Marcar pronto</button></form><?php else:?><div class="alert ok">Pronto para retirada/entrega</div><?php endif;?></article><?php endforeach;?><?php if(!$orders):?><article class="card"><h2>Fila vazia</h2><p class="muted">Nenhum pedido aguardando preparo.</p></article><?php endif;?></section><?php em_footer();
