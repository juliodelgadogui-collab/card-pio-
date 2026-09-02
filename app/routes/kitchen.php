<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OrderWorkflowService;

Auth::requirePermission('orders.kitchen');
$tenantId=em_require_tenant();
$settingsStmt=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$settingsStmt->execute([$tenantId]);$settings=json_decode((string)($settingsStmt->fetchColumn()?:'{}'),true);if(!is_array($settings))$settings=[];
$lead=max(0,min(1440,(int)($settings['scheduled_kds_lead_minutes']??45)));
try{$tz=new DateTimeZone((string)($settings['timezone']??'America/Sao_Paulo'));}catch(Throwable){$tz=new DateTimeZone('America/Sao_Paulo');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
    if(!in_array($status,['preparing','ready'],true))exit('Status inválido.');
    try{(new OrderWorkflowService())->transition($tenantId,$id,$status);em_flash('ok','Pedido #'.$id.' atualizado.');}catch(Throwable $e){em_flash('error',$e->getMessage());}em_go('kitchen');
}

$sql='SELECT o.id,o.channel,o.status,o.created_at,o.notes,o.scheduled_for,rt.name table_name,c.name customer_name,(SELECT GROUP_CONCAT(CONCAT(oi.quantity,"× ",oi.name_snapshot) ORDER BY oi.id SEPARATOR " | ") FROM order_items oi WHERE oi.order_id=o.id) items FROM orders o LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.status IN ("confirmed","preparing","ready") AND (o.status IN ("preparing","ready") OR o.scheduled_for IS NULL OR o.scheduled_for<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL '.$lead.' MINUTE)) ORDER BY FIELD(o.status,"preparing","confirmed","ready"),COALESCE(o.scheduled_for,o.created_at),o.id';
$s=$pdo->prepare($sql);$s->execute([$tenantId]);$orders=$s->fetchAll();
function kds_schedule(?string $value,DateTimeZone $tz):?string{if(!$value)return null;try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->setTimezone($tz)->format('d/m H:i');}catch(Throwable){return null;}}

$confirmed=count(array_filter($orders,fn($o)=>$o['status']==='confirmed'));$preparing=count(array_filter($orders,fn($o)=>$o['status']==='preparing'));$ready=count(array_filter($orders,fn($o)=>$o['status']==='ready'));
em_header('Tela da cozinha','kitchen');
?><meta http-equiv="refresh" content="15"><div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px"><div class="card metric"><span class="muted">Aguardando preparo</span><strong><?= $confirmed ?></strong></div><div class="card metric"><span class="muted">Em preparo</span><strong><?= $preparing ?></strong></div><div class="card metric"><span class="muted">Prontos</span><strong><?= $ready ?></strong></div></div><section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))"><?php foreach($orders as$o):$scheduled=kds_schedule($o['scheduled_for']??null,$tz);?><article class="card" style="border-width:2px;border-top:4px solid <?= $o['status']==='preparing'?'#f59e0b':($o['status']==='ready'?'#16a34a':'#6d4aff') ?>"><div class="section-head"><div><span class="muted">Pedido</span><h2 style="margin:3px 0">#<?= (int)$o['id'] ?></h2><span class="muted"><?= Security::e($o['table_name']??$o['customer_name']??$o['channel']) ?></span></div><span class="badge <?= $o['status']==='ready'?'active':'' ?>"><?= Security::e(em_status_label($o['status'])) ?></span></div><?php if($scheduled):?><div class="alert"><strong>Agendado: <?= Security::e($scheduled) ?></strong></div><?php endif;?><p style="font-size:1.12rem;line-height:1.6"><strong><?= Security::e($o['items']??'') ?></strong></p><?php if(!empty($o['notes'])):?><div class="alert"><strong>Observação</strong><br><?= nl2br(Security::e($o['notes'])) ?></div><?php endif;?><p class="muted">Entrada: <?= Security::e(date('H:i',strtotime($o['created_at']))) ?><?= $scheduled?' · liberado '.$lead.' min antes':'' ?></p><?php if($o['status']==='confirmed'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="preparing"><button class="primary" style="width:100%;padding:15px">Iniciar preparo</button></form><?php elseif($o['status']==='preparing'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="status" value="ready"><button class="primary" style="width:100%;padding:15px">Marcar como pronto</button></form><?php else:?><div class="alert ok"><strong>Pronto para retirada ou entrega</strong></div><?php endif;?></article><?php endforeach;?><?php if(!$orders):?><article class="card"><h2>Fila vazia</h2><p class="muted">Nenhum pedido aguardando preparo nesta janela.</p></article><?php endif;?></section><?php em_footer();
