<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PaymentService;

Auth::requirePermission('orders.view');
$tenantId=em_require_tenant();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['id']??0);
    try{
        if($action==='status'){
            Auth::requirePermission('orders.manage');
            $status=(string)($_POST['status']??'');
            (new OrderWorkflowService())->transition($tenantId,$id,$status);
            em_flash('ok','Status atualizado.');
            em_go('orders');
        }
        if($action==='delivery-status'){
            Auth::requirePermission('orders.delivery');
            $status=(string)($_POST['status']??'');
            if(!in_array($status,['out_for_delivery','completed'],true))throw new RuntimeException('Status de entrega inválido.');
            $x=$pdo->prepare('SELECT assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? AND channel="delivery"');
            $x->execute([$id,$tenantId]);
            if((int)$x->fetchColumn()!==(int)Auth::id())throw new RuntimeException('Este pedido não está atribuído a você.');
            (new OrderWorkflowService())->transition($tenantId,$id,$status);
            em_flash('ok',$status==='completed'?'Entrega concluída.':'Pedido marcado como saiu para entrega.');
            em_go('orders');
        }
        if($action==='assign-delivery'){
            Auth::requirePermission('delivery.assign');
            $userId=(int)($_POST['delivery_user_id']??0);
            (new DeliveryService())->assign($tenantId,$id,$userId>0?$userId:null);
            em_flash('ok',$userId>0?'Entregador atribuído.':'Entregador removido.');
            em_go('orders');
        }
        if($action==='manual-pay'){
            Auth::requirePermission('payments.manage');
            $s=$pdo->prepare('SELECT total_cents,status,payment_status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $s->execute([$id,$tenantId]);
            $o=$s->fetch();
            if(!$o)throw new RuntimeException('Pedido não encontrado.');
            if(!in_array($o['payment_status'],['unpaid','failed'],true))throw new RuntimeException('Este pedido não pode receber confirmação manual neste estado de pagamento.');
            $open=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND provider<>"manual" AND status IN ("created","pending","authorized")');
            $open->execute([$tenantId,$id]);
            if((int)$open->fetchColumn()>0)throw new RuntimeException('Existe cobrança online em andamento. Aguarde/cancele a tentativa antes de confirmar pagamento manual.');
            $service=new PaymentService();
            $service->create($id,'manual','manual:'.$tenantId.':'.$id.':'.bin2hex(random_bytes(8)));
            $service->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>$id,'provider'=>'manual','provider_payment_id'=>'MANUAL-'.strtoupper(bin2hex(random_bytes(8))),'amount_cents'=>(int)$o['total_cents'],'currency'=>'BRL','account_reference'=>'manual']);
            Auth::audit('payment.manual','order',(string)$id);
            em_flash('ok','Pagamento manual confirmado.');
            em_go('orders');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('orders');}
}

$sql='SELECT o.*,c.name customer_name,u.name delivery_name,rt.name table_name,t.label tab_label,dz.name delivery_zone_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN tabs t ON t.id=o.tab_id LEFT JOIN delivery_zones dz ON dz.id=o.delivery_zone_id WHERE o.tenant_id=?';
$args=[$tenantId];
if(Auth::role()==='delivery'){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}
$sql.=' ORDER BY o.id DESC LIMIT 150';
$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();
$d=$pdo->prepare('SELECT id,name FROM users WHERE tenant_id=? AND role="delivery" AND status="active" ORDER BY name');$d->execute([$tenantId]);$deliveries=$d->fetchAll();

$viewId=(int)($_GET['view']??0);
$items=[];$deliveryEvents=[];
if($viewId){
    $scope='SELECT id FROM orders WHERE id=? AND tenant_id=?'.(Auth::role()==='delivery'?' AND assigned_delivery_user_id=?':'');
    $scopeArgs=[$viewId,$tenantId];if(Auth::role()==='delivery')$scopeArgs[]=Auth::id();
    $scopeStmt=$pdo->prepare($scope);$scopeStmt->execute($scopeArgs);
    if($scopeStmt->fetchColumn()){
        $q=$pdo->prepare('SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.order_id=? AND o.tenant_id=?');$q->execute([$viewId,$tenantId]);$items=$q->fetchAll();
        try{$ev=$pdo->prepare('SELECT de.*,u.name user_name FROM delivery_events de LEFT JOIN users u ON u.id=de.user_id WHERE de.tenant_id=? AND de.order_id=? ORDER BY de.id DESC');$ev->execute([$tenantId,$viewId]);$deliveryEvents=$ev->fetchAll();}catch(Throwable){$deliveryEvents=[];}
    }
}

em_header('Pedidos','orders');
?><?php if($viewId):?><section class="card" style="margin-bottom:18px"><div class="section-head"><h2>Itens do pedido #<?= $viewId ?></h2><a class="button secondary" href="/?route=orders">Fechar</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Qtd.</th><th>Unitário</th><th>Total</th><th>Obs.</th></tr></thead><tbody><?php foreach($items as$i):?><tr><td><?= Security::e($i['name_snapshot']) ?></td><td><?= Security::e((string)$i['quantity']) ?></td><td><?= em_money($i['unit_price_cents']) ?></td><td><?= em_money($i['total_cents']) ?></td><td><?= Security::e($i['notes']??'') ?></td></tr><?php endforeach;?></tbody></table></div><?php if($deliveryEvents):?><div class="section-head" style="margin-top:20px"><h3>Linha do tempo da entrega</h3></div><div class="table-wrap"><table class="table"><thead><tr><th>Evento</th><th>Operador</th><th>Data</th></tr></thead><tbody><?php foreach($deliveryEvents as$ev):?><tr><td><span class="badge"><?= Security::e($ev['event_type']) ?></span></td><td><?= Security::e($ev['user_name']??'Sistema') ?></td><td><?= Security::e($ev['created_at']) ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endif;?><section class="card"><div class="section-head"><h2>Operação</h2><span class="muted"><?= count($orders) ?> pedidos recentes</span></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Cliente / origem</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Entrega</th><th>Ações</th></tr></thead><tbody><?php foreach($orders as$o):?><tr><td><a href="/?route=orders&view=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a><br><span class="muted"><?= Security::e($o['channel']) ?></span></td><td><?= Security::e($o['customer_name']??'Consumidor') ?><br><span class="muted"><?= Security::e($o['table_name']??$o['delivery_address']??'') ?></span><?php if($o['channel']==='delivery'&&!empty($o['delivery_zone_name'])):?><br><small class="muted">Zona: <?= Security::e($o['delivery_zone_name']) ?></small><?php endif;?></td><td><span class="badge"><?= Security::e($o['status']) ?></span><?php if(Auth::can('orders.manage')):?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="status"><?php foreach(['pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled']as$st):?><option value="<?= $st ?>"<?= em_selected($o['status'],$st) ?>><?= $st ?></option><?php endforeach;?></select><button class="secondary">Salvar</button></form><?php elseif(Auth::can('orders.delivery')):?><div class="actions" style="margin-top:6px"><?php if($o['status']==='ready'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="delivery-status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="out_for_delivery"><button class="primary">Saiu para entrega</button></form><?php elseif($o['status']==='out_for_delivery'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="delivery-status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="completed"><button class="primary">Entregue</button></form><?php endif;?></div><?php endif;?></td><td><?= Security::e($o['payment_status']) ?><?php if(Auth::can('payments.manage')&&in_array($o['payment_status'],['unpaid','failed'],true)&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" style="margin-top:6px" onsubmit="return confirm('Confirmar recebimento manual deste pedido?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="manual-pay"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="secondary">Confirmar dinheiro/maquininha</button></form><?php endif;?></td><td><strong><?= em_money($o['total_cents']) ?></strong><?php if($o['channel']==='delivery'):?><br><small class="muted">Frete <?= em_money($o['delivery_fee_cents']) ?></small><?php endif;?></td><td><?= Security::e($o['delivery_name']??'—') ?><?php if($o['channel']==='delivery'):?><br><small class="muted"><?= (int)($o['delivery_eta_min_minutes']??0) ?>–<?= (int)($o['delivery_eta_max_minutes']??0) ?> min</small><?php endif;?><?php if(Auth::can('delivery.assign')&&$o['channel']==='delivery'&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="assign-delivery"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="delivery_user_id"><option value="0">Sem entregador</option><?php foreach($deliveries as$du):?><option value="<?= (int)$du['id'] ?>"<?= em_selected($o['assigned_delivery_user_id']??0,$du['id']) ?>><?= Security::e($du['name']) ?></option><?php endforeach;?></select><button class="secondary">Atribuir</button></form><?php endif;?></td><td><a class="button secondary" href="/?route=orders&view=<?= (int)$o['id'] ?>">Itens</a></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();