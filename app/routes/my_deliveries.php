<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OrderWorkflowService;

Auth::requirePermission('orders.delivery');
$tenantId=em_require_tenant();
$userId=(int)Auth::id();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
    try{
        if(!in_array($status,['out_for_delivery','completed'],true))throw new RuntimeException('Ação de entrega inválida.');
        $s=$pdo->prepare('SELECT id,status,payment_status FROM orders WHERE id=? AND tenant_id=? AND channel="delivery" AND assigned_delivery_user_id=? FOR UPDATE');
        $s->execute([$id,$tenantId,$userId]);$order=$s->fetch();
        if(!$order)throw new RuntimeException('Esta entrega não está atribuída ao seu usuário.');
        (new OrderWorkflowService())->transition($tenantId,$id,$status);
        Auth::audit('delivery.worker_status','order',(string)$id,['status'=>$status]);
        em_flash('ok',$status==='completed'?'Entrega concluída com sucesso.':'Boa rota! Pedido marcado como saiu para entrega.');
    }catch(Throwable $e){em_flash('error',$e->getMessage());}
    em_go('my-deliveries');
}

$s=$pdo->prepare('SELECT o.id,o.status,o.payment_status,o.total_cents,o.delivery_fee_cents,o.delivery_address,o.delivery_neighborhood,o.delivery_city,o.delivery_postal_code,o.delivery_eta_min_minutes,o.delivery_eta_max_minutes,o.created_at,c.name customer_name,c.phone customer_phone,(SELECT GROUP_CONCAT(CONCAT(oi.quantity,"× ",oi.name_snapshot) ORDER BY oi.id SEPARATOR " · ") FROM order_items oi WHERE oi.order_id=o.id) items FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery") ORDER BY FIELD(o.status,"out_for_delivery","ready"),o.created_at');
$s->execute([$tenantId,$userId]);$active=$s->fetchAll();
$r=$pdo->prepare('SELECT o.id,o.total_cents,o.delivered_at,c.name customer_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status="completed" AND o.delivered_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) ORDER BY o.delivered_at DESC LIMIT 20');
$r->execute([$tenantId,$userId]);$recent=$r->fetchAll();

em_header('Minhas entregas','my-deliveries');
?><div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px"><div class="card metric"><span class="muted">Aguardando saída</span><strong><?= count(array_filter($active,fn($o)=>$o['status']==='ready')) ?></strong></div><div class="card metric"><span class="muted">Em rota</span><strong><?= count(array_filter($active,fn($o)=>$o['status']==='out_for_delivery')) ?></strong></div><div class="card metric"><span class="muted">Entregues 24h</span><strong><?= count($recent) ?></strong></div></div>
<section class="grid delivery-worker-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">
<?php foreach($active as$o):$address=trim(implode(', ',array_filter([$o['delivery_address']??null,$o['delivery_neighborhood']??null,$o['delivery_city']??null,$o['delivery_postal_code']??null])));?>
<article class="card" style="border-top:4px solid <?= $o['status']==='out_for_delivery'?'#16a34a':'#6d4aff' ?>">
 <div class="section-head"><div><span class="muted">Pedido</span><h2 style="margin:3px 0">#<?= (int)$o['id'] ?></h2></div><span class="badge <?= $o['status']==='out_for_delivery'?'active':'' ?>"><?= Security::e(em_status_label($o['status'])) ?></span></div>
 <div style="font-size:1.08rem;margin-bottom:12px"><strong><?= Security::e($o['customer_name']??'Cliente') ?></strong><?php if(!empty($o['customer_phone'])):?><br><a class="muted" href="tel:<?= Security::e(preg_replace('/[^0-9+]/','',(string)$o['customer_phone'])) ?>"><?= Security::e($o['customer_phone']) ?></a><?php endif;?></div>
 <div class="alert"><strong>Endereço</strong><br><?= Security::e($address?:'Endereço não informado') ?></div>
 <p><strong>Itens:</strong><br><?= Security::e($o['items']??'') ?></p>
 <div class="actions" style="justify-content:space-between;align-items:center;margin:16px 0"><span><small class="muted">Pedido</small><br><strong><?= em_money($o['total_cents']) ?></strong></span><span><small class="muted">Pagamento</small><br><strong><?= Security::e(em_status_label($o['payment_status'])) ?></strong></span><span><small class="muted">Prazo</small><br><strong><?= (int)$o['delivery_eta_min_minutes'] ?>–<?= (int)$o['delivery_eta_max_minutes'] ?> min</strong></span></div>
 <?php if($o['status']==='ready'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="out_for_delivery"><button class="primary" style="width:100%;padding:15px">Sair para entrega</button></form><?php else:?><form method="post" onsubmit="return confirm('Confirmar que o pedido foi entregue ao cliente?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="completed"><button class="primary" style="width:100%;padding:15px">Marcar como entregue</button></form><?php endif;?>
</article>
<?php endforeach;?>
<?php if(!$active):?><article class="card"><h2>Nenhuma entrega agora</h2><p class="muted">Quando um gerente atribuir um pedido a você, ele aparecerá aqui automaticamente.</p></article><?php endif;?>
</section>
<?php if($recent):?><section class="card" style="margin-top:18px"><div class="section-head"><h2>Entregas concluídas</h2><span class="muted">Últimas 24 horas</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Valor</th><th>Entregue em</th></tr></thead><tbody><?php foreach($recent as$o):?><tr><td>#<?= (int)$o['id'] ?></td><td><?= Security::e($o['customer_name']??'Cliente') ?></td><td><?= em_money($o['total_cents']) ?></td><td><?= Security::e($o['delivered_at']??'') ?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php em_footer();
