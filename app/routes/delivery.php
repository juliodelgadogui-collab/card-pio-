<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryProgressService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\WorkShiftService;
use EventMenu\Support\OperationalDiagnostics;
use EventMenu\Support\UiVocabulary;

Auth::requirePermission('orders.delivery');$tenantId=em_require_tenant();
try{$unit=(new OperatingUnitService())->requireCurrent();}catch(Throwable$e){$safe=OperationalDiagnostics::userFacing($e,'Não foi possível carregar as entregas desta unidade.','web.delivery.unit');em_header('Entregas','delivery');?><div class="alert error"><?= Security::e($safe['message']) ?></div><?php em_footer();return;}
$unitId=(int)$unit['id'];$shift=null;try{$shift=(new WorkShiftService())->current();}catch(Throwable$e){OperationalDiagnostics::capture($e,'web.delivery.shift');}
$isDeliveryUser=Auth::role()==='delivery';
if($isDeliveryUser&&$shift&&$shift['unit_id']!==null&&(int)$shift['unit_id']!==$unitId){em_header('Entregas','delivery');?><div class="alert error">Seu turno de entregas está aberto em outra unidade.</div><?php em_footer();return;}
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$id=(int)($_POST['id']??0);$action=(string)($_POST['action']??'');
    if(!$isDeliveryUser){em_flash('error','As etapas da rota devem ser registradas pelo entregador responsável.');em_go('delivery');}
    try{
        $progress=new DeliveryProgressService();
        match($action){
            'pickup'=>$progress->pickup($id),
            'start-route'=>$progress->startRoute($id),
            'arrive'=>$progress->arrive($id),
            'complete'=>$progress->complete($id),
            default=>throw new RuntimeException('Ação de entrega inválida.'),
        };
        $messages=['pickup'=>'Pedido retirado. Agora inicie a rota.','start-route'=>'Rota iniciada.','arrive'=>'Chegada confirmada.','complete'=>'Entrega concluída.'];
        em_flash('ok',$messages[$action]??'Entrega atualizada.');
    }catch(Throwable$e){$safe=OperationalDiagnostics::userFacing($e,'Não foi possível atualizar a entrega. Tente novamente.','web.delivery.action',['order'=>$id,'action'=>$action]);em_flash('error',$safe['message']);}
    em_go('delivery');
}
$sql='SELECT o.*,c.name customer_name,c.phone customer_phone,u.name delivery_name,dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.tenant_id=? AND o.unit_id=? AND o.channel="delivery" AND o.status IN ("ready","out_for_delivery","completed")';$args=[$tenantId,$unitId];if($isDeliveryUser){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}$sql.=' ORDER BY CASE WHEN o.status="out_for_delivery" THEN 0 WHEN o.status="ready" THEN 1 ELSE 2 END,o.id DESC LIMIT 100';$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();$ready=count(array_filter($orders,static fn(array$o):bool=>$o['status']==='ready'));$onRoute=count(array_filter($orders,static fn(array$o):bool=>$o['status']==='out_for_delivery'));$done=count(array_filter($orders,static fn(array$o):bool=>$o['status']==='completed'));
em_header('Entregas','delivery');
?>
<section class="page-hero"><div><span class="eyebrow">ENTREGAS · <?= Security::e($unit['name']) ?></span><h2>Fila de entregas</h2><p><?= $isDeliveryUser?'Aqui aparecem somente as entregas atribuídas a você. Siga as etapas na ordem para manter o acompanhamento correto.':'Acompanhe as entregas da unidade. As etapas da rota são confirmadas pelo entregador responsável.' ?></p></div><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=orders&channel=delivery')) ?>">Ver pedidos</a></div></section>
<section class="grid dashboard-metrics"><div class="card metric"><span class="muted">Prontos para sair</span><strong><?= $ready ?></strong></div><div class="card metric"><span class="muted">Em rota</span><strong><?= $onRoute ?></strong></div><div class="card metric"><span class="muted">Concluídos exibidos</span><strong><?= $done ?></strong></div><div class="card metric"><span class="muted">Unidade</span><strong style="font-size:20px"><?= Security::e($unit['name']) ?></strong></div></section>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-top:18px"><?php foreach($orders as$o):$picked=!empty($o['picked_up_at']);$route=!empty($o['route_started_at'])||$o['status']==='out_for_delivery';$arrived=!empty($o['arrived_at']);?><article class="card"><div class="section-head"><div><span class="eyebrow"><?= Security::e(date('H:i',strtotime((string)$o['created_at']))) ?></span><h2>#<?= (int)$o['id'] ?></h2><strong><?= Security::e($o['customer_name']??'Cliente') ?></strong></div><span class="status-pill <?= $o['status']==='completed'?'active':'' ?>"><?= Security::e(UiVocabulary::orderStatus((string)$o['status'])) ?></span></div><p><?= Security::e($o['customer_phone']??'') ?><br><strong>Endereço:</strong> <?= nl2br(Security::e($o['delivery_address']??'')) ?></p><p><strong>Total:</strong> <?= em_money($o['total_cents']) ?><br><strong>Pagamento:</strong> <span class="badge"><?= Security::e(UiVocabulary::paymentStatus((string)$o['payment_status'])) ?></span><?php if(!$isDeliveryUser):?><br><strong>Entregador:</strong> <?= Security::e($o['delivery_name']??'Não atribuído') ?><?php endif;?></p><?php if($o['notes']):?><div class="alert">Obs.: <?= Security::e($o['notes']) ?></div><?php endif;?><div class="muted" style="margin:10px 0"><strong>Andamento:</strong> <?= $picked?'Pedido retirado':'Aguardando retirada' ?> · <?= $route?'Rota iniciada':'Rota não iniciada' ?> · <?= $arrived?'Chegada confirmada':'Chegada pendente' ?></div><?php if($isDeliveryUser):?><?php if($o['status']==='ready'&&!$picked):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="pickup"><button class="primary" style="width:100%">Retirar pedido</button></form><?php elseif($o['status']==='ready'&&$picked):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="start-route"><button class="primary" style="width:100%">Iniciar rota</button></form><?php elseif($o['status']==='out_for_delivery'&&!$arrived):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="arrive"><button class="primary" style="width:100%">Cheguei ao cliente</button></form><?php elseif($o['status']==='out_for_delivery'&&$arrived&&$o['payment_status']==='paid'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="complete"><button class="primary" style="width:100%">Concluir entrega</button></form><?php elseif($o['status']==='out_for_delivery'&&$arrived):?><div class="alert">Aguardando confirmação do pagamento para concluir.</div><?php else:?><div class="alert ok">Entrega concluída.</div><?php endif;?><?php else:?><div class="alert">Acompanhamento somente. O entregador confirma retirada, rota, chegada e conclusão pelo EventMenu GO ou pelo acesso de entregas.</div><?php endif;?></article><?php endforeach;?></div><?php if(!$orders):?><section class="card" style="margin-top:18px"><h2>Nenhuma entrega na fila</h2><p class="muted">Pedidos atribuídos aparecerão aqui quando estiverem prontos.</p></section><?php endif;?>
<?php em_footer();