<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\CashService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\OrderCancellationService;
use EventMenu\Services\OrderFulfillmentService;
use EventMenu\Services\OrderService;
use EventMenu\Services\PaymentService;

Auth::requirePermission('orders.view');
$tenantId=em_require_tenant();
try{$unit=(new OperatingUnitService())->requireCurrent();}
catch(Throwable$e){em_header('Pedidos','orders');?><div class="alert error"><?= Security::e($e->getMessage()) ?></div><?php em_footer();return;}
$unitId=(int)$unit['id'];

function orders_qty(float|int|string$v):string{$q=(float)$v;return abs($q-round($q))<.0005?(string)(int)round($q):rtrim(rtrim(number_format($q,3,',','.'),'0'),',');}
function orders_channel(string$c):string{return match($c){'counter'=>'Balcão / PDV','pickup'=>'Retirada','delivery'=>'Delivery','table'=>'Mesa / comanda','event_bar'=>'Evento / Bar',default=>$c};}
function orders_status_label(string$s):string{return match($s){'pending'=>'Novo','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','served'=>'Servido','out_for_delivery'=>'Em entrega','completed'=>'Concluído','cancelled'=>'Cancelado',default=>$s};}
function orders_payment_label(string$s):string{return match($s){'unpaid'=>'Não pago','failed'=>'Falhou','created'=>'Iniciado','pending'=>'Processando','authorized'=>'Autorizado','paid'=>'Pago','refunded'=>'Estornado','partially_refunded'=>'Estorno parcial',default=>$s};}
function orders_cancel_status(string$s):string{return match($s){'pending'=>'Aguardando decisão','approved'=>'Aprovado','rejected'=>'Recusado','cancelled'=>'Substituído / encerrado',default=>$s};}
function orders_next_status(string$status,string$channel):?string{return match($status){'pending'=>'confirmed','confirmed'=>'preparing','preparing'=>'ready','ready'=>$channel==='delivery'?'out_for_delivery':($channel==='table'?'served':'completed'),'served'=>'completed','out_for_delivery'=>'completed',default=>null};}
function orders_cancel_reason(array$post):string{
    $preset=trim((string)($post['reason']??''));$details=mb_substr(trim((string)($post['details']??'')),0,350);
    $allowed=['Cliente desistiu','Pedido duplicado','Item indisponível','Erro no pedido','Outro'];
    if(!in_array($preset,$allowed,true))$preset='Outro';
    if($preset==='Outro'&&$details==='')throw new RuntimeException('Informe o motivo do cancelamento.');
    return mb_substr($details!==''?$preset.': '.$details:$preset,0,500);
}

$cancellationService=new OrderCancellationService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');$id=(int)($_POST['id']??0);

    if($action==='status'){
        Auth::requirePermission('orders.manage');
        try{(new OrderService())->changeStatus($id,(string)($_POST['status']??''),'panel');em_flash('ok','Status atualizado.');}
        catch(Throwable$e){em_flash('error',$e->getMessage());}
        em_go('orders',['view'=>$id]);
    }

    if($action==='assign-delivery'){
        Auth::requirePermission('delivery.assign');$deliveryUserId=(int)($_POST['delivery_user_id']??0);
        try{
            Database::transaction(function(PDO$tx)use($tenantId,$unitId,$id,$deliveryUserId):void{
                $o=$tx->prepare(Database::portableSql($tx,'SELECT id,unit_id,channel,status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$o->execute([$id,$tenantId]);$order=$o->fetch();
                if(!$order||(int)$order['unit_id']!==$unitId)throw new RuntimeException('Pedido não encontrado nesta unidade.');
                if($order['channel']!=='delivery')throw new RuntimeException('Somente delivery pode receber entregador.');
                if(in_array($order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido já encerrado.');
                if($deliveryUserId>0){$u=$tx->prepare('SELECT u.id FROM users u WHERE u.id=? AND u.tenant_id=? AND u.role="delivery" AND u.status="active" AND (NOT EXISTS (SELECT 1 FROM user_unit_access a WHERE a.tenant_id=u.tenant_id AND a.user_id=u.id) OR EXISTS (SELECT 1 FROM user_unit_access a WHERE a.tenant_id=u.tenant_id AND a.user_id=u.id AND a.unit_id=?))');$u->execute([$deliveryUserId,$tenantId,$unitId]);if(!$u->fetchColumn())throw new RuntimeException('Entregador não possui acesso a esta unidade.');}
                $tx->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=? AND unit_id=?')->execute([$deliveryUserId?:null,$id,$tenantId,$unitId]);
            });
            Auth::audit('order.delivery_assigned','order',(string)$id,['delivery_user_id'=>$deliveryUserId?:null,'unit_id'=>$unitId]);em_flash('ok','Entregador atualizado.');
        }catch(Throwable$e){em_flash('error',$e->getMessage());}
        em_go('orders',['view'=>$id]);
    }

    if($action==='manual-pay'){
        Auth::requirePermission('payments.manage');Auth::requirePermission('cash.manage');
        $s=$pdo->prepare('SELECT total_cents,status,payment_status FROM orders WHERE id=? AND tenant_id=? AND unit_id=?');$s->execute([$id,$tenantId,$unitId]);$o=$s->fetch();
        if(!$o){em_flash('error','Pedido não encontrado nesta unidade.');em_go('orders');}
        if(in_array($o['status'],['completed','cancelled'],true)){em_flash('error','Pedido encerrado não pode receber novo pagamento.');em_go('orders',['view'=>$id]);}
        if(!in_array($o['payment_status'],['unpaid','failed'],true)){em_flash('error','Este pedido possui pagamento em andamento ou já processado.');em_go('orders',['view'=>$id]);}
        try{
            Database::transaction(function()use($id,$tenantId,$unitId,$o):void{
                $cash=new CashService();$session=$cash->currentSession();if(!$session)throw new RuntimeException('Abra seu turno de caixa antes de receber dinheiro.');
                if($session['unit_id']!==null&&(int)$session['unit_id']!==$unitId)throw new RuntimeException('O caixa aberto pertence a outra unidade.');
                $service=new PaymentService();$payment=$service->create($id,'manual','manual:'.$tenantId.':'.$unitId.':'.$id.':'.bin2hex(random_bytes(8)));
                if(($payment['provider']??'manual')!=='manual'&&isset($payment['provider']))throw new RuntimeException('Existe uma cobrança de outro provedor para este pedido.');
                $service->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>$id,'provider'=>'manual','provider_payment_id'=>'CASH-'.strtoupper(bin2hex(random_bytes(8))),'amount_cents'=>(int)$o['total_cents'],'currency'=>'BRL','account_reference'=>'manual']);
                $cash->recordPaidPayment((int)$payment['id'],'cash');Auth::audit('payment.cash','order',(string)$id,['cash_session'=>true,'unit_id'=>$unitId]);
            });
            em_flash('ok','Pagamento em dinheiro confirmado e conciliado no caixa desta unidade.');
        }catch(Throwable$e){em_flash('error',$e->getMessage());}
        em_go('orders',['view'=>$id]);
    }

    if($action==='cancel-request'){
        Auth::requirePermission('cancellations.request');
        try{$reason=orders_cancel_reason($_POST);$request=$cancellationService->requestFromPanel($id,$reason,$unitId);em_flash('ok','Cancelamento enviado ao gerente para aprovação. Solicitação #'.(int)$request['id'].'.');}
        catch(Throwable$e){em_flash('error',$e->getMessage());}
        em_go('orders',['view'=>$id]);
    }

    if($action==='cancel-approve'){
        Auth::requirePermission('cancellations.approve');$requestId=(int)($_POST['request_id']??0);
        try{$result=$cancellationService->approveFromPanel($requestId,$unitId);em_flash('ok','Cancelamento aprovado. O pedido #'.(int)$result['order_id'].' foi cancelado.');em_go('orders',['view'=>(int)$result['order_id']]);}
        catch(Throwable$e){em_flash('error',$e->getMessage());em_go('orders',['view'=>$id?:null]);}
    }

    if($action==='cancel-reject'){
        Auth::requirePermission('cancellations.approve');$requestId=(int)($_POST['request_id']??0);$reason=mb_substr(trim((string)($_POST['reject_reason']??'')),0,500);
        try{$result=$cancellationService->rejectFromPanel($requestId,$reason,$unitId);em_flash('ok','Solicitação de cancelamento recusada.');em_go('orders',['view'=>(int)$result['order_id']]);}
        catch(Throwable$e){em_flash('error',$e->getMessage());em_go('orders',['view'=>$id?:null]);}
    }
}

$q=trim((string)($_GET['q']??''));$statusFilter=(string)($_GET['status']??'');$channelFilter=(string)($_GET['channel']??'');
$validStatuses=['pending','confirmed','preparing','ready','served','out_for_delivery','completed','cancelled'];$validChannels=['counter','pickup','delivery','table','event_bar'];
$sql='SELECT o.*,c.name customer_name,c.phone customer_phone,u.name delivery_name,rt.name table_name,t.label tab_label FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN tabs t ON t.id=o.tab_id WHERE o.tenant_id=? AND o.unit_id=?';$args=[$tenantId,$unitId];
if(Auth::role()==='delivery'){$sql.=' AND o.assigned_delivery_user_id=?';$args[]=Auth::id();}
if(in_array($statusFilter,$validStatuses,true)){$sql.=' AND o.status=?';$args[]=$statusFilter;}
if(in_array($channelFilter,$validChannels,true)){$sql.=' AND o.channel=?';$args[]=$channelFilter;}
if($q!==''){$like='%'.$q.'%';if(ctype_digit(ltrim($q,'#'))){$sql.=' AND o.id=?';$args[]=(int)ltrim($q,'#');}else{$sql.=' AND (c.name LIKE ? OR c.phone LIKE ? OR o.delivery_address LIKE ? OR rt.name LIKE ?)';array_push($args,$like,$like,$like,$like);}}
$sql.=' ORDER BY o.id DESC LIMIT 200';$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();

$d=$pdo->prepare('SELECT u.id,u.name FROM users u WHERE u.tenant_id=? AND u.role="delivery" AND u.status="active" AND (NOT EXISTS (SELECT 1 FROM user_unit_access a WHERE a.tenant_id=u.tenant_id AND a.user_id=u.id) OR EXISTS (SELECT 1 FROM user_unit_access a WHERE a.tenant_id=u.tenant_id AND a.user_id=u.id AND a.unit_id=?)) ORDER BY u.name');$d->execute([$tenantId,$unitId]);$deliveries=$d->fetchAll();

$pendingCancellations=[];
if(Auth::can('cancellations.approve')){try{$pendingCancellations=$cancellationService->pendingForUnit($unitId);}catch(Throwable$e){em_flash('error','Não foi possível carregar cancelamentos pendentes: '.$e->getMessage());}}

$cashSession=null;$cashReady=false;
if(Auth::can('payments.manage')&&Auth::can('cash.manage')){try{$cashSession=(new CashService())->currentSession();$cashReady=$cashSession&&($cashSession['unit_id']===null||(int)$cashSession['unit_id']===$unitId);}catch(Throwable){}}

$viewId=(int)($_GET['view']??0);$items=[];$viewOrder=null;$fulfillmentDetails=null;$orderQr=null;$history=[];$cancellationRequest=null;
if($viewId){
    $v=$pdo->prepare('SELECT o.*,c.name customer_name,c.phone customer_phone,rt.name table_name,u.name delivery_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id WHERE o.id=? AND o.tenant_id=? AND o.unit_id=? LIMIT 1');$v->execute([$viewId,$tenantId,$unitId]);$viewOrder=$v->fetch()?:null;
    if($viewOrder){
        $i=$pdo->prepare('SELECT oi.*,GROUP_CONCAT(oim.option_name_snapshot, ", ") modifier_names FROM order_items oi LEFT JOIN order_item_modifiers oim ON oim.order_item_id=oi.id AND oim.tenant_id=? WHERE oi.order_id=? GROUP BY oi.id ORDER BY oi.id');$i->execute([$tenantId,$viewId]);$items=$i->fetchAll();
        try{$h=$pdo->prepare('SELECT osh.*,u.name user_name FROM order_status_history osh LEFT JOIN users u ON u.id=osh.user_id WHERE osh.tenant_id=? AND osh.order_id=? ORDER BY osh.id DESC LIMIT 40');$h->execute([$tenantId,$viewId]);$history=$h->fetchAll();}catch(Throwable){}
        try{$cancellationRequest=$cancellationService->forOrder($viewId);}catch(Throwable){}
        $fulfillment=new OrderFulfillmentService();if($fulfillment->supportsChannel((string)$viewOrder['channel'])&&!empty($viewOrder['public_token'])){try{$fulfillmentDetails=$fulfillment->detailsByToken((string)$viewOrder['public_token']);$orderQr=$fulfillment->qrDataUri((string)$viewOrder['public_token'],280);}catch(Throwable){}}
    }else em_flash('error','Pedido não encontrado na unidade '.$unit['name'].'.');
}

$kanban=['new'=>[],'preparing'=>[],'ready'=>[],'delivery'=>[]];
foreach($orders as$o){$status=(string)$o['status'];if(in_array($status,['pending','confirmed'],true))$kanban['new'][]=$o;elseif($status==='preparing')$kanban['preparing'][]=$o;elseif(in_array($status,['ready','served'],true))$kanban['ready'][]=$o;elseif($status==='out_for_delivery')$kanban['delivery'][]=$o;}
$kanbanMeta=['new'=>['Novos','new'],'preparing'=>['Preparando','preparing'],'ready'=>['Prontos / servir','ready'],'delivery'=>['Em entrega','delivery']];

em_header('Pedidos','orders');
?>
<style>
.order-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:16px 0}.order-action-box{border:1px solid var(--line);border-radius:16px;padding:16px;background:var(--surface)}.order-action-box h3{margin:4px 0 8px}.order-action-box .big-status{font-size:20px;font-weight:800;margin:6px 0}.cancel-review-list{display:grid;gap:10px}.cancel-review{border:1px solid var(--line);border-radius:14px;padding:14px}.cancel-review-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.cancel-review-actions form{margin:0}.payment-ok{color:var(--success,#157a4b)}.payment-warn{color:var(--danger,#a33)}
@media(max-width:760px){.order-action-grid{grid-template-columns:1fr}.cancel-review-actions{display:grid;grid-template-columns:1fr}.cancel-review-actions form,.cancel-review-actions .button,.cancel-review-actions button{width:100%}.order-action-box button,.order-action-box .button{width:100%}.order-detail-table{font-size:14px}}
</style>
<section class="page-hero"><div><span class="eyebrow">OPERAÇÃO · <?= Security::e($unit['name']) ?></span><h2>Pedidos da unidade</h2><p>Somente pedidos de <strong><?= Security::e($unit['name']) ?></strong> aparecem e podem ser operados nesta tela.</p></div><div class="hero-actions"><a class="button primary" href="<?= Security::e(app_url('?route=pos')) ?>">+ Novo pedido</a><a class="button secondary" href="<?= Security::e(app_url('?route=kitchen')) ?>">Abrir KDS</a></div></section>

<?php if($pendingCancellations):?>
<section class="card" style="margin-bottom:14px;border-color:#e4b560">
    <div class="section-head"><div><span class="eyebrow">ATENÇÃO DA GERÊNCIA</span><h2>Cancelamentos para analisar</h2><p class="muted">A notificação de cancelamento aparece aqui para você aprovar ou recusar.</p></div><span class="badge"><?= count($pendingCancellations) ?> pendente(s)</span></div>
    <div class="cancel-review-list">
    <?php foreach($pendingCancellations as$cr):?>
        <article class="cancel-review">
            <div class="section-head"><div><strong>Pedido #<?= (int)$cr['order_id'] ?></strong><div class="muted"><?= Security::e($cr['customer_name']?:'Consumidor') ?> · <?= Security::e(orders_channel((string)$cr['channel'])) ?> · <?= em_money($cr['total_cents']) ?></div></div><span class="badge"><?= Security::e(orders_payment_label((string)$cr['payment_status'])) ?></span></div>
            <p><strong>Motivo:</strong> <?= Security::e($cr['reason']) ?><br><span class="muted">Solicitado por <?= Security::e($cr['requester_name']?:'Funcionário') ?></span></p>
            <div class="cancel-review-actions">
                <a class="button secondary" href="<?= Security::e(app_url('?route=orders&view='.(int)$cr['order_id'])) ?>">Abrir pedido</a>
                <form method="post" onsubmit="return confirm('Autorizar o cancelamento deste pedido?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="cancel-approve"><input type="hidden" name="id" value="<?= (int)$cr['order_id'] ?>"><input type="hidden" name="request_id" value="<?= (int)$cr['id'] ?>"><button class="primary">Aprovar cancelamento</button></form>
                <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="cancel-reject"><input type="hidden" name="id" value="<?= (int)$cr['order_id'] ?>"><input type="hidden" name="request_id" value="<?= (int)$cr['id'] ?>"><input name="reject_reason" maxlength="500" placeholder="Motivo da recusa"><button class="secondary">Recusar</button></form>
            </div>
        </article>
    <?php endforeach;?>
    </div>
</section>
<?php endif;?>

<form method="get" class="card form-grid" style="margin-bottom:14px"><input type="hidden" name="route" value="orders"><label class="span-2">Buscar pedido, cliente, telefone, mesa ou endereço<input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="#123, Maria, telefone..."></label><label>Status<select name="status"><option value="">Todos</option><?php foreach($validStatuses as$st):?><option value="<?= $st ?>"<?= em_selected($statusFilter,$st) ?>><?= Security::e(orders_status_label($st)) ?></option><?php endforeach;?></select></label><label>Canal<select name="channel"><option value="">Todos</option><?php foreach($validChannels as$ch):?><option value="<?= $ch ?>"<?= em_selected($channelFilter,$ch) ?>><?= Security::e(orders_channel($ch)) ?></option><?php endforeach;?></select></label><div class="actions span-2"><button class="primary">Filtrar</button><a class="button secondary" href="<?= Security::e(app_url('?route=orders')) ?>">Limpar</a></div></form>

<?php if($viewOrder):?>
<section class="card" style="margin-bottom:14px">
    <div class="section-head"><div><span class="eyebrow"><?= Security::e(orders_channel((string)$viewOrder['channel'])) ?> · <?= Security::e($unit['name']) ?></span><h2>Pedido #<?= $viewId ?></h2><div class="muted"><?= Security::e($viewOrder['customer_name']??$viewOrder['table_name']??'Consumidor') ?> · <?= Security::e(orders_status_label((string)$viewOrder['status'])) ?> · <?= Security::e(orders_payment_label((string)$viewOrder['payment_status'])) ?></div></div><a class="button secondary" href="<?= Security::e(app_url('?route=orders')) ?>">Fechar detalhes</a></div>

    <div class="order-action-grid">
        <div class="order-action-box">
            <span class="eyebrow">PAGAMENTO</span><h3>Recebimento do pedido</h3>
            <div class="big-status <?= $viewOrder['payment_status']==='paid'?'payment-ok':'payment-warn' ?>"><?= Security::e(orders_payment_label((string)$viewOrder['payment_status'])) ?> · <?= em_money($viewOrder['total_cents']) ?></div>
            <?php if($viewOrder['payment_status']==='paid'):?>
                <p class="muted">Pagamento confirmado pelo servidor.</p>
                <?php if(Auth::can('payments.manage')):?><a class="button secondary" href="<?= Security::e(app_url('?route=payments&q=%23'.$viewId)) ?>">Ver pagamento / estorno</a><?php endif;?>
            <?php elseif(in_array($viewOrder['payment_status'],['unpaid','failed'],true)&&!in_array($viewOrder['status'],['completed','cancelled'],true)):?>
                <?php if(Auth::can('payments.manage')&&Auth::can('cash.manage')):?>
                    <?php if($cashReady):?>
                    <form method="post" onsubmit="return confirm('Confirmar recebimento de <?= Security::e(em_money($viewOrder['total_cents'])) ?> em dinheiro?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="manual-pay"><input type="hidden" name="id" value="<?= $viewId ?>"><button class="primary">Receber <?= em_money($viewOrder['total_cents']) ?> em dinheiro</button></form>
                    <?php else:?>
                    <p class="muted">Para receber em dinheiro, abra primeiro o turno de caixa desta unidade.</p><a class="button primary" href="<?= Security::e(app_url('?route=cash')) ?>">Abrir caixa</a>
                    <?php endif;?>
                    <p class="muted" style="margin-top:10px">Pix e cartão não são “marcados como pagos” manualmente: o sistema confirma quando o provedor aprova.</p>
                <?php else:?>
                    <p class="muted">Somente Caixa, Gerente ou Administrador autorizado pode confirmar recebimento.</p>
                <?php endif;?>
            <?php else:?>
                <p class="muted">Existe uma cobrança em processamento. Aguarde a confirmação do provedor antes de concluir ou cancelar o pedido.</p><?php if(Auth::can('payments.manage')):?><a class="button secondary" href="<?= Security::e(app_url('?route=payments&q=%23'.$viewId)) ?>">Abrir pagamentos</a><?php endif;?>
            <?php endif;?>
        </div>

        <div class="order-action-box">
            <span class="eyebrow">CANCELAMENTO</span><h3>Cancelar pedido</h3>
            <?php if($viewOrder['status']==='cancelled'):?>
                <div class="big-status">Pedido cancelado</div><p class="muted">O pedido já foi encerrado por cancelamento.</p>
            <?php elseif($viewOrder['status']==='completed'):?>
                <p class="muted">Pedido concluído não aceita nova solicitação de cancelamento.</p>
            <?php elseif($cancellationRequest&&$cancellationRequest['status']==='pending'):?>
                <div class="big-status">Aguardando gerente</div><p><strong>Motivo:</strong> <?= Security::e($cancellationRequest['reason']) ?><br><span class="muted">Solicitado por <?= Security::e($cancellationRequest['requester_name']?:'Funcionário') ?></span></p>
                <?php if(Auth::can('cancellations.approve')):?><div class="cancel-review-actions"><form method="post" onsubmit="return confirm('Autorizar o cancelamento deste pedido?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="cancel-approve"><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="request_id" value="<?= (int)$cancellationRequest['id'] ?>"><button class="primary">Aprovar cancelamento</button></form><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="cancel-reject"><input type="hidden" name="id" value="<?= $viewId ?>"><input type="hidden" name="request_id" value="<?= (int)$cancellationRequest['id'] ?>"><input name="reject_reason" maxlength="500" placeholder="Motivo da recusa"><button class="secondary">Recusar</button></form></div><?php endif;?>
            <?php elseif($viewOrder['payment_status']==='paid'):?>
                <p class="muted">Este pedido possui valor recebido. Faça o estorno primeiro; o sistema não permite simplesmente apagar um pedido pago.</p><?php if(Auth::can('payments.manage')):?><a class="button secondary" href="<?= Security::e(app_url('?route=payments&q=%23'.$viewId)) ?>">Ir para pagamento / estorno</a><?php endif;?>
            <?php elseif(Auth::can('cancellations.request')):?>
                <?php if($cancellationRequest):?><p class="muted">Última solicitação: <?= Security::e(orders_cancel_status((string)$cancellationRequest['status'])) ?><?= $cancellationRequest['decider_name']?' por '.Security::e($cancellationRequest['decider_name']):'' ?>.</p><?php endif;?>
                <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="cancel-request"><input type="hidden" name="id" value="<?= $viewId ?>"><label>Motivo<select name="reason" required><option value="Cliente desistiu">Cliente desistiu</option><option value="Pedido duplicado">Pedido duplicado</option><option value="Item indisponível">Item indisponível</option><option value="Erro no pedido">Erro no pedido</option><option value="Outro">Outro</option></select></label><label>Detalhes (opcional)<input name="details" maxlength="350" placeholder="Explique se necessário"></label><button class="secondary" style="margin-top:8px">Solicitar cancelamento</button></form>
            <?php else:?>
                <p class="muted">Sua função não pode solicitar cancelamento.</p>
            <?php endif;?>
        </div>
    </div>

    <div class="table-wrap order-detail-table"><table class="table"><thead><tr><th>Item</th><th>Qtd.</th><th>Unitário</th><th>Total</th><th>Adicionais</th><th>Obs.</th></tr></thead><tbody><?php foreach($items as$i):?><tr><td><strong><?= Security::e($i['name_snapshot']) ?></strong></td><td><?= orders_qty($i['quantity']) ?></td><td><?= em_money($i['unit_price_cents']) ?></td><td><?= em_money($i['total_cents']) ?></td><td><?= Security::e($i['modifier_names']??'—') ?></td><td><?= Security::e($i['notes']??'') ?></td></tr><?php endforeach;?></tbody></table></div>

    <?php if($fulfillmentDetails&&$orderQr):$progress=$fulfillmentDetails['progress'];?><div style="display:grid;grid-template-columns:minmax(180px,230px) minmax(0,1fr);gap:20px;align-items:center;margin-top:18px;padding-top:18px;border-top:1px solid var(--line)"><div style="background:#fff;border:1px solid var(--line);border-radius:14px;padding:10px"><img src="<?= Security::e($orderQr) ?>" alt="QR do pedido" style="display:block;width:100%;height:auto"></div><div><span class="eyebrow">RETIRADA</span><h2 style="margin:4px 0 8px">Progresso da entrega</h2><div class="metric-grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin:12px 0"><div class="metric-card"><span>Pedido</span><strong><?= orders_qty($progress['ordered_quantity']) ?></strong></div><div class="metric-card"><span>Entregue</span><strong><?= orders_qty($progress['fulfilled_quantity']) ?></strong></div><div class="metric-card"><span>Falta</span><strong><?= orders_qty($progress['remaining_quantity']) ?></strong></div></div><?php if(Auth::can('orders.fulfill')):?><a class="button primary" href="<?= Security::e(app_url('?route=pickup&token='.rawurlencode((string)$viewOrder['public_token']))) ?>">Abrir retirada</a><?php endif;?></div></div><?php endif;?>
    <?php if($history):?><details class="technical-details" style="margin-top:16px"><summary>Histórico do pedido</summary><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>De</th><th>Para</th><th>Origem</th><th>Usuário</th><th>Observação</th></tr></thead><tbody><?php foreach($history as$h):?><tr><td><?= Security::e($h['created_at']) ?></td><td><?= Security::e(orders_status_label((string)($h['from_status']??'—'))) ?></td><td><?= Security::e(orders_status_label((string)($h['to_status']??'—'))) ?></td><td><?= Security::e($h['source']??'—') ?></td><td><?= Security::e($h['user_name']??'Sistema') ?></td><td><?= Security::e($h['notes']??$h['note']??'—') ?></td></tr><?php endforeach;?></tbody></table></div></details><?php endif;?>
</section>
<?php endif;?>

<section class="kanban" aria-label="Quadro de pedidos"><?php foreach($kanbanMeta as$key=>[$label,$tone]):?><div class="kanban-column" data-tone="<?= Security::e($tone) ?>"><div class="kanban-head"><span><?= Security::e($label) ?></span><span class="kanban-count"><?= count($kanban[$key]) ?></span></div><?php if(!$kanban[$key]):?><div class="muted" style="font-size:11px;padding:12px">Nenhum pedido.</div><?php endif;?><?php foreach($kanban[$key]as$o):$next=orders_next_status((string)$o['status'],(string)$o['channel']);$needsPayment=$next==='completed'&&$o['payment_status']!=='paid';?><article class="order-card"><div class="order-card-top"><strong>#<?= (int)$o['id'] ?></strong><span class="order-total"><?= em_money($o['total_cents']) ?></span></div><p><b><?= Security::e($o['customer_name']??$o['table_name']??'Consumidor') ?></b><br><?= Security::e(orders_channel((string)$o['channel'])) ?> · <?= Security::e(date('H:i',strtotime((string)$o['created_at']))) ?><br><?= Security::e(orders_payment_label((string)$o['payment_status'])) ?><?= $o['delivery_name']?' · '.Security::e($o['delivery_name']):'' ?></p><div class="actions"><a class="button secondary" href="<?= Security::e(app_url('?route=orders&view='.(int)$o['id'])) ?>"><?= $needsPayment?'Receber pagamento':'Detalhes' ?></a><?php if($next&&Auth::can('orders.manage')&&!$needsPayment):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="<?= Security::e($next) ?>"><button class="primary"><?= Security::e(match($next){'confirmed'=>'Confirmar','preparing'=>'Preparar','ready'=>'Pronto','served'=>'Servido','out_for_delivery'=>'Saiu','completed'=>'Concluir',default=>'Avançar'}) ?></button></form><?php endif;?><?php if(Auth::can('orders.fulfill')&&in_array($o['channel'],['counter','pickup'],true)&&!empty($o['public_token'])):?><a class="button secondary" href="<?= Security::e(app_url('?route=pickup&token='.rawurlencode((string)$o['public_token']))) ?>">Retirada</a><?php endif;?></div></article><?php endforeach;?></div><?php endforeach;?></section>

<section class="card" style="margin-top:14px"><div class="section-head"><div><span class="eyebrow">HISTÓRICO · <?= Security::e($unit['name']) ?></span><h2>Pedidos recentes</h2></div><span class="muted"><?= count($orders) ?> registros</span></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Cliente / origem</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Entrega</th><th>Ações</th></tr></thead><tbody><?php foreach($orders as$o):?><tr><td><a href="<?= Security::e(app_url('?route=orders&view='.(int)$o['id'])) ?>"><strong>#<?= (int)$o['id'] ?></strong></a><br><span class="muted"><?= Security::e(orders_channel((string)$o['channel'])) ?></span></td><td><?= Security::e($o['customer_name']??$o['table_name']??'Consumidor') ?><br><span class="muted"><?= Security::e($o['delivery_address']??'') ?></span></td><td><span class="badge"><?= Security::e(orders_status_label((string)$o['status'])) ?></span></td><td><?= Security::e(orders_payment_label((string)$o['payment_status'])) ?><?php if(Auth::can('payments.manage')&&Auth::can('cash.manage')&&$cashReady&&in_array($o['payment_status'],['unpaid','failed'],true)&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" style="margin-top:6px" onsubmit="return confirm('Confirmar recebimento em dinheiro neste caixa?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="manual-pay"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="secondary compact">Receber em dinheiro</button></form><?php endif;?></td><td><strong><?= em_money($o['total_cents']) ?></strong></td><td><?= $o['channel']==='delivery'?Security::e($o['delivery_name']??'Sem entregador'):'—' ?><?php if($o['channel']==='delivery'&&Auth::can('delivery.assign')&&!in_array($o['status'],['completed','cancelled'],true)):?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="assign-delivery"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="delivery_user_id"><option value="0">Sem entregador</option><?php foreach($deliveries as$du):?><option value="<?= (int)$du['id'] ?>"<?= em_selected($o['assigned_delivery_user_id']??0,$du['id']) ?>><?= Security::e($du['name']) ?></option><?php endforeach;?></select><button class="secondary compact">Atribuir</button></form><?php endif;?></td><td><div class="actions"><a class="button secondary compact" href="<?= Security::e(app_url('?route=orders&view='.(int)$o['id'])) ?>">Detalhes</a><?php if(Auth::can('orders.fulfill')&&in_array($o['channel'],['counter','pickup'],true)&&!empty($o['public_token'])):?><a class="button secondary compact" href="<?= Security::e(app_url('?route=pickup&token='.rawurlencode((string)$o['public_token']))) ?>">Retirada</a><?php endif;?></div></td></tr><?php endforeach;?><?php if(!$orders):?><tr><td colspan="7">Nenhum pedido encontrado nesta unidade.</td></tr><?php endif;?></tbody></table></div></section>
<?php em_footer();
