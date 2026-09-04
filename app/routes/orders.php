<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PaymentService;

Auth::requirePermission('orders.view');
$tenantId=em_require_tenant();$unitId=Auth::unitId();
$tenantSettingsStmt=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$tenantSettingsStmt->execute([$tenantId]);$tenantSettings=json_decode((string)($tenantSettingsStmt->fetchColumn()?:'{}'),true);if(!is_array($tenantSettings))$tenantSettings=[];
try{$ordersTz=new DateTimeZone((string)($tenantSettings['timezone']??'America/Sao_Paulo'));}catch(Throwable){$ordersTz=new DateTimeZone('America/Sao_Paulo');}
function order_channel_label(string $channel):string{return match($channel){'counter'=>'Balcão','table'=>'Mesa','delivery'=>'Delivery','pickup'=>'Retirada','event'=>'Evento',default=>$channel};}
function order_schedule_label(?string $utc,DateTimeZone $tz):?string{if(!$utc)return null;try{return(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone($tz)->format('d/m H:i');}catch(Throwable){return null;}}
$assertOrderUnit=function(int $id)use($pdo,$tenantId,$unitId):void{if(!$unitId)return;$s=$pdo->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND unit_id=? LIMIT 1');$s->execute([$id,$tenantId,$unitId]);if(!$s->fetchColumn())throw new RuntimeException('Este pedido pertence a outra unidade.');};

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['id']??0);
    try{
        $assertOrderUnit($id);
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
            $sql='SELECT assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? AND channel="delivery"'.($unitId?' AND unit_id=?':'');$args=[$id,$tenantId];if($unitId)$args[]=$unitId;
            $x=$pdo->prepare($sql);$x->execute($args);
            if((int)$x->fetchColumn()!==(int)Auth::id())throw new RuntimeException('Este pedido não está atribuído a você nesta unidade.');
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
            $manualMethod=(string)($_POST['manual_method']??'');
            if(!in_array($manualMethod,['cash','card','pix','other'],true))throw new RuntimeException('Escolha a forma de recebimento manual.');
            $sql='SELECT total_cents,status,payment_status FROM orders WHERE id=? AND tenant_id=?'.($unitId?' AND unit_id=?':'');$args=[$id,$tenantId];if($unitId)$args[]=$unitId;
            $s=$pdo->prepare($sql);$s->execute($args);$o=$s->fetch();
            if(!$o)throw new RuntimeException('Pedido não encontrado nesta unidade.');
            if(!in_array($o['payment_status'],['unpaid','failed'],true))throw new RuntimeException('Este pedido não pode receber confirmação manual neste estado de pagamento.');
            $open=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND provider<>"manual" AND status IN ("created","pending","authorized")');
            $open->execute([$tenantId,$id]);
            if((int)$open->fetchColumn()>0)throw new RuntimeException('Existe cobrança online em andamento. Aguarde/cancele a tentativa antes de confirmar pagamento manual.');
            $service=new PaymentService();
            $service->create($id,'manual','manual:'.$tenantId.':'.$id.':'.bin2hex(random_bytes(8)));
            $service->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>$id,'provider'=>'manual','provider_payment_id'=>'MANUAL-'.strtoupper(bin2hex(random_bytes(8))),'amount_cents'=>(int)$o['total_cents'],'currency'=>'BRL','account_reference'=>'manual','manual_method'=>$manualMethod]);
            Auth::audit('payment.manual','order',(string)$id,['method'=>$manualMethod,'unit_id'=>$unitId]);
            em_flash('ok','Pagamento manual confirmado e lançado no caixa.');
            em_go('orders');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('orders');}
}

$sql='SELECT o.*,c.name customer_name,u.name delivery_name,rt.name table_name,t.label tab_label,dz.name delivery_zone_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN tabs t ON t.id=o.tab_id LEFT JOIN delivery_zones dz ON dz.id=o.delivery_zone_id WHERE o.tenant_id=?';
$args=[$tenantId];
if($unitId){$sql.=' AND o.unit_id=?';$args[]=$unitId;}
if(Auth::role()==='delivery'){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}
$sql.=' ORDER BY COALESCE(o.scheduled_for,o.created_at) DESC,o.id DESC LIMIT 150';
$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();
if($unitId){$d=$pdo->prepare('SELECT DISTINCT u.id,u.name FROM users u WHERE u.tenant_id=? AND u.role="delivery" AND u.status="active" AND (NOT EXISTS (SELECT 1 FROM user_units ux WHERE ux.user_id=u.id) OR EXISTS (SELECT 1 FROM user_units uu WHERE uu.user_id=u.id AND uu.unit_id=?)) ORDER BY u.name');$d->execute([$tenantId,$unitId]);}else{$d=$pdo->prepare('SELECT id,name FROM users WHERE tenant_id=? AND role="delivery" AND status="active" ORDER BY name');$d->execute([$tenantId]);}$deliveries=$d->fetchAll();

$viewId=(int)($_GET['view']??0);
$items=[];$deliveryEvents=[];
if($viewId){
    $scope='SELECT id FROM orders WHERE id=? AND tenant_id=?'.($unitId?' AND unit_id=?':'').(Auth::role()==='delivery'?' AND assigned_delivery_user_id=?':'');
    $scopeArgs=[$viewId,$tenantId];if($unitId)$scopeArgs[]=$unitId;if(Auth::role()==='delivery')$scopeArgs[]=Auth::id();
    $scopeStmt=$pdo->prepare($scope);$scopeStmt->execute($scopeArgs);
    if($scopeStmt->fetchColumn()){
        $q=$pdo->prepare('SELECT oi.* FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.order_id=? AND o.tenant_id=?'.($unitId?' AND o.unit_id=?':''));$qArgs=[$viewId,$tenantId];if($unitId)$qArgs[]=$unitId;$q->execute($qArgs);$items=$q->fetchAll();
        try{$ev=$pdo->prepare('SELECT de.*,u.name user_name FROM delivery_events de LEFT JOIN users u ON u.id=de.user_id JOIN orders o ON o.id=de.order_id WHERE de.tenant_id=? AND de.order_id=?'.($unitId?' AND o.unit_id=?':'').' ORDER BY de.id DESC');$evArgs=[$tenantId,$viewId];if($unitId)$evArgs[]=$unitId;$ev->execute($evArgs);$deliveryEvents=$ev->fetchAll();}catch(Throwable){$deliveryEvents=[];}
    }
}

em_header('Pedidos','orders');
?><?php if($unitId):?><div class="alert"><strong>Pedidos da unidade selecionada.</strong> Ações administrativas também estão limitadas a esta filial.</div><?php endif;?><?php if($viewId):?><section class="card" style="margin-bottom:18px"><div class="section-head"><h2>Itens do pedido #<?= $viewId ?></h2><a class="button secondary" href="/?route=orders">Fechar</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Qtd.</th><th>Unitário</th><th>Total</th><th>Obs.</th></tr></thead><tbody><?php foreach($items as$i):?><tr><td><?= Security::e($i['name_snapshot']) ?></td><td><?= Security::e((string)$i['quantity']) ?></td><td><?= em_money($i['unit_price_cents']) ?></td><td><?= em_money($i['total_cents']) ?></td><td><?= Security::e($i['notes']??'') ?></td></tr><?php endforeach;?></tbody></table></div><?php if($deliveryEvents):?><div class="section-head" style="margin-top:20px"><h3>Linha do tempo da entrega</h3></div><div class="table-wrap"><table class="table"><thead><tr><th>Evento</th><th>Operador</th><th>Data UTC</th></tr></thead><tbody><?php foreach($deliveryEvents as$ev):?><tr><td><span class="badge"><?= Security::e($ev['event_type']) ?></span></td><td><?= Security::e($ev['user_name']??'Sistema') ?></td><td><?= Security::e($ev['created_at']) ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endif;?><section class="card"><div class="section-head"><h2>Operação</h2><span class="muted"><?= count($orders) ?> pedidos recentes</span></div><div class="table-wrap"><table class="table"><thead><tr><th># / horário</th><th>Cliente / origem</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Entrega</th><th>Ações</th></tr></thead><tbody><?php foreach($orders as$o):$scheduled=order_schedule_label($o['scheduled_for']??null,$ordersTz);?><tr><td><a href="/?route=orders&view=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a><br><span class="muted"><?= Security::e(order_channel_label((string)$o['channel'])) ?></span><?php if($scheduled):?><br><strong>Agendado <?= Security::e($scheduled) ?></strong><?php endif;?></td><td><?= Security::e($o['customer_name']??'Consumidor') ?><br><span class="muted"><?php if($o['channel']==='pickup'):?>Retirada no estabelecimento<?php else:?><?= Security::e($o['table_name']??$o['delivery_address']??'') ?><?php endif;?></span><?php if($o['channel']==='delivery'&&!empty($o['delivery_zone_name'])):?><br><small class="muted">Zona: <?= Security::e($o['delivery_zone_name']) ?></small><?php endif;?></td><td><span class="badge"><?= Security::e($o['status']) ?></span><?php if(Auth::can('orders.manage')):?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="status"><?php foreach(['pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled']as$st):?><option value="<?= $st ?>"<?= em_selected($o['status'],$st) ?>><?= $st ?></option><?php endforeach;?></select><button class="secondary">Salvar</button></form><?php elseif(Auth::can('orders.delivery')):?><div class="actions" style="margin-top:6px"><?php if($o['status']==='ready'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="delivery-status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="out_for_delivery"><button class="primary">Saiu para entrega</button></form><?php elseif($o['status']==='out_for_delivery'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="delivery-status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="completed"><button class="primary">Entregue</button></form><?php endif;?></div><?php endif;?></td><td><?= Security::e($o['payment_status']) ?><?php if(Auth::can('payments.manage')&&in_array($o['payment_status'],['unpaid','failed'],true)&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" class="actions" style="margin-top:6px" onsubmit="return confirm('Confirmar recebimento manual deste pedido? O lançamento será vinculado ao seu caixa aberto.')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="manual-pay"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="manual_method" required><option value="cash">Dinheiro</option><option value="card">Cartão / maquininha</option><option value="pix">Pix externo</option><option value="other">Outro</option></select><button class="secondary">Confirmar recebimento</button></form><?php endif;?></td><td><strong><?= em_money($o['total_cents']) ?></strong><?php if($o['channel']==='delivery'):?><br><small class="muted">Frete <?= em_money($o['delivery_fee_cents']) ?></small><?php endif;?></td><td><?php if($o['channel']==='delivery'):?><?= Security::e($o['delivery_name']??'—') ?><br><small class="muted"><?= (int)($o['delivery_eta_min_minutes']??0) ?>–<?= (int)($o['delivery_eta_max_minutes']??0) ?> min</small><?php elseif($o['channel']==='pickup'):?><span class="badge">Retirada</span><?php else:?>—<?php endif;?><?php if(Auth::can('delivery.assign')&&$o['channel']==='delivery'&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="assign-delivery"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="delivery_user_id"><option value="0">Sem entregador</option><?php foreach($deliveries as$du):?><option value="<?= (int)$du['id'] ?>"<?= em_selected($o['assigned_delivery_user_id']??0,$du['id']) ?>><?= Security::e($du['name']) ?></option><?php endforeach;?></select><button class="secondary">Atribuir</button></form><?php endif;?></td><td><a class="button secondary" href="/?route=orders&view=<?= (int)$o['id'] ?>">Itens</a></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();