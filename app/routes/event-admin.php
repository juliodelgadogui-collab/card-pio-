<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\EventProfessionalService;

Auth::requirePermission('events.manage');
$tenantId=em_require_tenant();
$eventId=(int)($_GET['id']??$_POST['event_id']??0);
if($eventId<1)em_go('events');
$service=new EventProfessionalService();
$section=(string)($_GET['section']??$_POST['section']??'overview');
if(!in_array($section,['overview','tickets','page','marketing','operation','audit'],true))$section='overview';

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='public-settings'){
            $service->savePublicSettings($eventId,$_POST);
            em_flash('ok','Página pública e capacidade atualizadas.');
            $section='page';
        }elseif($action==='type-save'){
            $service->saveTicketType($eventId,$_POST);
            em_flash('ok',(int)($_POST['id']??0)>0?'Tipo de ingresso atualizado.':'Tipo de ingresso criado.');
            $section='tickets';
        }elseif($action==='batch-save'){
            $editing=(int)($_POST['batch_id']??0)>0;
            $service->saveBatch($eventId,$_POST);
            em_flash('ok',$editing?'Lote atualizado com segurança.':'Lote criado.');
            $section='tickets';
        }elseif($action==='batch-toggle'){
            $active=(int)($_POST['active']??0)===1;
            $service->setBatchActive($eventId,(int)($_POST['batch_id']??0),$active);
            em_flash('ok',$active?'Lote ativado.':'Lote desativado. Ingressos já emitidos foram preservados.');
            $section='tickets';
        }elseif($action==='batch-duplicate'){
            $service->duplicateBatch($eventId,(int)($_POST['batch_id']??0));
            em_flash('ok','Lote duplicado como inativo. Revise os dados e ative quando estiver pronto.');
            $section='tickets';
        }elseif($action==='batch-delete'){
            $service->deleteBatch($eventId,(int)($_POST['batch_id']??0));
            em_flash('ok','Lote excluído.');
            $section='tickets';
        }elseif($action==='batch-type'){
            $service->setBatchType($eventId,(int)($_POST['batch_id']??0),(int)($_POST['ticket_type_id']??0)?:null);
            em_flash('ok','Tipo vinculado ao lote.');
            $section='tickets';
        }elseif($action==='promoter-link'){
            $raw=trim((string)($_POST['commission_percent']??''));
            $commission=$raw===''?null:(float)str_replace(',','.',$raw);
            $service->linkPromoter($eventId,(int)($_POST['promoter_id']??0),$commission,true);
            em_flash('ok','Promotor vinculado ao evento.');
            $section='marketing';
        }elseif($action==='coupon-link'){
            $service->linkCoupon($eventId,(int)($_POST['coupon_id']??0),true);
            em_flash('ok','Cupom vinculado ao evento.');
            $section='marketing';
        }else{
            throw new RuntimeException('Ação inválida.');
        }
    }catch(Throwable $e){
        em_flash('error',$e->getMessage());
    }
    em_go('event-admin',['id'=>$eventId,'section'=>$section]);
}

try{
    $d=$service->dashboard($eventId);
}catch(Throwable $e){
    em_flash('error',$e->getMessage());
    em_go('events');
}
$event=$d['event'];
$types=$d['types'];
$batches=$d['batches'];
$ticketStats=$d['ticket_stats'];
$sales=$d['sales_stats'];

$p=$pdo->prepare('SELECT id,name,code,commission_percent FROM promoters WHERE tenant_id=? AND active=1 ORDER BY name');
$p->execute([$tenantId]);
$allPromoters=$p->fetchAll();
$c=$pdo->prepare('SELECT id,code,type,value FROM coupons WHERE tenant_id=? AND active=1 ORDER BY code');
$c->execute([$tenantId]);
$allCoupons=$c->fetchAll();
$g=$pdo->prepare('SELECT COUNT(*) guests,COALESCE(SUM(plus_ones),0) plus_ones FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');
$g->execute([$tenantId,$eventId]);
$guestStats=$g->fetch()?:['guests'=>0,'plus_ones'=>0];
$guestSeats=(int)$guestStats['guests']+(int)$guestStats['plus_ones'];

$used=(int)($ticketStats['sold']??0)+(int)($ticketStats['reserved']??0)+$guestSeats;
$capacity=$event['capacity_total']!==null?(int)$event['capacity_total']:null;
$remaining=$capacity!==null?max(0,$capacity-$used):null;
$sold=(int)($ticketStats['sold']??0);
$checkins=(int)($ticketStats['checkins']??0);
$checkinPct=$sold>0?(int)round($checkins*100/$sold):0;
$publicUrl=app_url('evento.php?empresa='.rawurlencode((string)$event['tenant_slug']).'&evento='.rawurlencode((string)$event['slug']));
$statusLabels=['draft'=>'Rascunho','published'=>'Publicado','closed'=>'Encerrado','cancelled'=>'Cancelado'];
$tabs=['overview'=>'Visão geral','tickets'=>'Ingressos e lotes','page'=>'Página pública','marketing'=>'Promoção e cupons','operation'=>'Operação e check-in','audit'=>'Auditoria'];

$editBatchId=max(0,(int)($_GET['edit_batch']??0));
$editBatch=null;
foreach($batches as $candidate)if((int)$candidate['id']===$editBatchId){$editBatch=$candidate;break;}
$editTypeId=max(0,(int)($_GET['edit_type']??0));
$editType=null;
foreach($types as $candidate)if((int)$candidate['id']===$editTypeId){$editType=$candidate;break;}
$dtValue=static fn(?string $value):string=>$value?date('Y-m-d\TH:i',strtotime($value)):'';

em_header('Evento · '.$event['name'],'events');
?>
<style>
.event-ticket-layout{display:grid;grid-template-columns:minmax(300px,.8fr) minmax(0,1.2fr);gap:16px}.batch-cards{display:none}.batch-actions{display:flex;gap:6px;flex-wrap:wrap}.batch-actions form{margin:0}.batch-actions .button,.batch-actions button{white-space:nowrap}.batch-inactive{opacity:.72}.event-admin-note{padding:12px 14px;border-radius:12px;background:#f6f3ff;color:#4d3a7b;margin-bottom:14px;font-size:13px}.danger-compact{border:1px solid #e6b9bf!important;color:#9a3844!important;background:#fff7f8!important}
@media(max-width:820px){.event-ticket-layout{grid-template-columns:1fr}.event-ticket-layout>.card{min-width:0}.batch-table{display:none}.batch-cards{display:grid;gap:10px}.batch-card{border:1px solid rgba(80,70,110,.15);border-radius:15px;padding:14px;background:#fff}.batch-card-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.batch-card h3{margin:0 0 4px;font-size:17px}.batch-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:7px;margin:12px 0}.batch-meta div{padding:9px;border-radius:10px;background:#f7f6fa;min-width:0}.batch-meta small{display:block;color:#777184;font-size:10px}.batch-meta strong{display:block;margin-top:2px;font-size:13px}.subtabs{overflow-x:auto;white-space:nowrap}.page-hero{align-items:flex-start}.page-hero .hero-actions{flex-wrap:wrap}.form-grid{grid-template-columns:1fr!important}.form-grid .span-2{grid-column:auto!important}}
</style>
<section class="page-hero"><div><span class="eyebrow">GESTÃO DO EVENTO</span><h2><?= Security::e($event['name']) ?></h2><p><?= Security::e(date('d/m/Y · H:i',strtotime((string)$event['starts_at']))) ?><?= $event['venue']?' · '.Security::e($event['venue']):'' ?> · <strong><?= Security::e($statusLabels[$event['status']]??$event['status']) ?></strong></p></div><div class="hero-actions"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicUrl) ?>">Ver página pública</a><a class="button secondary" href="<?= Security::e(app_url('?route=events&edit='.$eventId)) ?>">Editar dados básicos</a><a class="button secondary" href="<?= Security::e(app_url('?route=events')) ?>">Todos os eventos</a></div></section>
<nav class="subtabs" style="margin-bottom:18px"><?php foreach($tabs as$key=>$label):?><a class="<?= $section===$key?'active':'' ?>" href="<?= Security::e(app_url('?route=event-admin&id='.$eventId.'&section='.$key)) ?>"><?= Security::e($label) ?></a><?php endforeach;?></nav>

<?php if($section==='overview'):?>
<section class="metric-grid"><div class="metric-card"><span>Receita confirmada</span><strong><?= em_money((int)($sales['revenue']??0)) ?></strong><small><?= (int)($sales['orders_count']??0) ?> compras pagas</small></div><div class="metric-card"><span>Ingressos vendidos</span><strong><?= $sold ?></strong><small><?= (int)($ticketStats['reserved']??0) ?> reservados</small></div><div class="metric-card"><span>Check-ins</span><strong><?= $checkins ?></strong><small><?= $checkinPct ?>% dos pagos</small></div><div class="metric-card"><span>Capacidade restante</span><strong><?= $remaining===null?'∞':$remaining ?></strong><small><?= $capacity===null?'sem limite definido':'inclui '.$guestSeats.' vaga(s) de convidados/acompanhantes' ?></small></div></section>
<div class="grid" style="grid-template-columns:minmax(0,1.35fr) minmax(280px,.65fr);gap:16px;margin-top:18px"><section class="card"><div class="section-head"><div><span class="eyebrow">PRÓXIMOS PASSOS</span><h2>Preparação do evento</h2></div></div><?php $checks=[['Página publicada',$event['status']==='published','page'],['Tipos de ingresso cadastrados',count($types)>0,'tickets'],['Lotes de venda cadastrados',count($batches)>0,'tickets'],['Capacidade definida',$capacity!==null,'page'],['Promotores configurados',count($d['promoters'])>0,'marketing'],['Cupons do evento configurados',count($d['coupons'])>0,'marketing']];foreach($checks as[$label,$ok,$target]):?><div class="list-row"><div><strong><?= $ok?'✓':'○' ?> <?= Security::e($label) ?></strong></div><a class="button secondary compact" href="<?= Security::e(app_url('?route=event-admin&id='.$eventId.'&section='.$target)) ?>"><?= $ok?'Revisar':'Configurar' ?></a></div><?php endforeach;?></section><aside class="card"><span class="eyebrow">ATALHOS</span><h2>Operação</h2><div class="actions" style="display:grid;margin-top:14px"><a class="button primary" href="<?= Security::e(app_url('?route=tickets&event_id='.$eventId)) ?>">Abrir check-in</a><a class="button secondary" href="<?= Security::e(app_url('?route=guests&event_id='.$eventId)) ?>">Lista de convidados</a><a class="button secondary" href="<?= Security::e(app_url('?route=reports')) ?>">Relatórios</a><a class="button secondary" href="<?= Security::e(app_url('?route=payments')) ?>">Pagamentos</a></div></aside></div>
<?php endif;?>

<?php if($section==='tickets'):?>
<div class="event-ticket-layout">
<section class="card"><span class="eyebrow">TIPOS DE INGRESSO</span><h2><?= $editType?'Editar tipo':'Pista, VIP, camarote...' ?></h2><p class="muted">O tipo define área e capacidade. O lote define preço e período de venda.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="type-save"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="tickets"><input type="hidden" name="id" value="<?= (int)($editType['id']??0) ?>"><label class="span-2">Nome<input name="name" required placeholder="Ex.: Pista Premium" value="<?= Security::e($editType['name']??'') ?>"></label><label>Área de acesso<input name="access_area" placeholder="Setor/portão" value="<?= Security::e($editType['access_area']??'') ?>"></label><label>Capacidade<input type="number" name="capacity_total" min="1" placeholder="Opcional" value="<?= Security::e((string)($editType['capacity_total']??'')) ?>"></label><label>Ordem<input type="number" name="sort_order" value="<?= (int)($editType['sort_order']??0) ?>"></label><label class="checkbox"><input type="checkbox" name="active"<?= em_checked($editType['active']??1) ?>> Ativo</label><label class="span-2">Descrição<textarea name="description"><?= Security::e($editType['description']??'') ?></textarea></label><div class="actions span-2"><button class="primary"><?= $editType?'Salvar tipo':'Criar tipo' ?></button><?php if($editType):?><a class="button secondary" href="<?= Security::e(app_url('?route=event-admin&id='.$eventId.'&section=tickets')) ?>">Cancelar edição</a><?php endif;?></div></form><?php if($types):?><div style="margin-top:18px"><?php foreach($types as$t):?><div class="list-row"><div><strong><?= Security::e($t['name']) ?></strong><small><?= Security::e($t['access_area']??'Sem área') ?> · <?= (int)$t['used_count'] ?> usado(s)<?= $t['capacity_total']!==null?' de '.(int)$t['capacity_total']:'' ?></small></div><div class="actions"><span class="status-pill <?= $t['active']?'active':'cancelled' ?>"><?= $t['active']?'Ativo':'Inativo' ?></span><a class="button secondary compact" href="<?= Security::e(app_url('?route=event-admin&id='.$eventId.'&section=tickets&edit_type='.(int)$t['id'])) ?>">Editar</a></div></div><?php endforeach;?></div><?php endif;?></section>

<section class="card"><span class="eyebrow">LOTES DE VENDA</span><h2><?= $editBatch?'Editar lote':'Preço, quantidade e período' ?></h2><div class="event-admin-note">Alterar preço afeta somente novas compras. Não é permitido reduzir a quantidade abaixo de vendidos + reservados. Desativar um lote não cancela ingressos já emitidos.</div><form method="post" class="form-grid" style="margin-bottom:18px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="batch-save"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="tickets"><input type="hidden" name="batch_id" value="<?= (int)($editBatch['id']??0) ?>"><label>Tipo<select name="ticket_type_id"><option value="0">Sem tipo específico</option><?php foreach($types as$t):?><option value="<?= (int)$t['id'] ?>"<?= em_selected((string)($editBatch['ticket_type_id']??0),(string)$t['id']) ?>><?= Security::e($t['name']) ?><?= !$t['active']?' (inativo)':'' ?></option><?php endforeach;?></select></label><label>Nome do lote<input name="name" required placeholder="1º lote" value="<?= Security::e($editBatch['name']??'') ?>"></label><label>Preço (R$)<input name="price" inputmode="decimal" required placeholder="50,00" value="<?= $editBatch?Security::e(number_format((int)$editBatch['price_cents']/100,2,',','.')):'' ?>"></label><label>Quantidade<input type="number" name="quantity_total" min="1" required value="<?= Security::e((string)($editBatch['quantity_total']??'')) ?>"></label><label>Início da venda<input type="datetime-local" name="sales_start" value="<?= Security::e($dtValue($editBatch['sales_start']??null)) ?>"></label><label>Fim da venda<input type="datetime-local" name="sales_end" value="<?= Security::e($dtValue($editBatch['sales_end']??null)) ?>"></label><label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($editBatch['active']??1) ?>> Lote ativo para novas vendas</label><div class="actions span-2"><button class="primary"><?= $editBatch?'Salvar alterações':'Criar lote' ?></button><?php if($editBatch):?><a class="button secondary" href="<?= Security::e(app_url('?route=event-admin&id='.$eventId.'&section=tickets')) ?>">Cancelar edição</a><?php endif;?></div></form>
<?php if(!$batches):?><div class="alert">Nenhum lote criado. A venda só começa quando existir um lote ativo.</div><?php else:?>
<div class="table-wrap batch-table"><table class="table"><thead><tr><th>Lote</th><th>Tipo</th><th>Preço</th><th>Vendido</th><th>Reservado</th><th>Disponível</th><th>Status</th><th>Ações</th></tr></thead><tbody><?php foreach($batches as$b):$available=max(0,(int)$b['quantity_total']-(int)$b['quantity_sold']-(int)$b['quantity_reserved']);?><tr class="<?= !$b['active']?'batch-inactive':'' ?>"><td><strong><?= Security::e($b['name']) ?></strong><br><small><?= $b['sales_start']?Security::e(date('d/m/Y H:i',strtotime($b['sales_start']))):'início livre' ?> → <?= $b['sales_end']?Security::e(date('d/m/Y H:i',strtotime($b['sales_end']))):'sem fim' ?></small></td><td><?= Security::e($b['ticket_type_name']??'—') ?></td><td><?= em_money((int)$b['price_cents']) ?></td><td><?= (int)$b['quantity_sold'] ?></td><td><?= (int)$b['quantity_reserved'] ?></td><td><strong><?= $available ?></strong></td><td><span class="status-pill <?= $b['active']?'active':'cancelled' ?>"><?= $b['active']?'Ativo':'Inativo' ?></span></td><td><?php render_batch_actions($b,$eventId); ?></td></tr><?php endforeach;?></tbody></table></div>
<div class="batch-cards"><?php foreach($batches as$b):$available=max(0,(int)$b['quantity_total']-(int)$b['quantity_sold']-(int)$b['quantity_reserved']);?><article class="batch-card <?= !$b['active']?'batch-inactive':'' ?>"><div class="batch-card-head"><div><h3><?= Security::e($b['name']) ?></h3><small><?= Security::e($b['ticket_type_name']??'Sem tipo') ?> · <?= em_money((int)$b['price_cents']) ?></small></div><span class="status-pill <?= $b['active']?'active':'cancelled' ?>"><?= $b['active']?'Ativo':'Inativo' ?></span></div><div class="batch-meta"><div><small>Vendidos</small><strong><?= (int)$b['quantity_sold'] ?></strong></div><div><small>Reservados</small><strong><?= (int)$b['quantity_reserved'] ?></strong></div><div><small>Disponíveis</small><strong><?= $available ?></strong></div></div><small><?= $b['sales_start']?Security::e(date('d/m/Y H:i',strtotime($b['sales_start']))):'Início livre' ?> → <?= $b['sales_end']?Security::e(date('d/m/Y H:i',strtotime($b['sales_end']))):'Sem fim definido' ?></small><div style="margin-top:12px"><?php render_batch_actions($b,$eventId); ?></div></article><?php endforeach;?></div>
<?php endif;?></section></div>
<?php endif;?>

<?php if($section==='page'):?>
<div class="grid" style="grid-template-columns:minmax(0,1.2fr) minmax(280px,.8fr);gap:16px"><section class="card"><span class="eyebrow">PÁGINA PÚBLICA</span><h2>Identidade, capacidade e venda</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="public-settings"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="page"><label class="span-2">Subtítulo público<input name="public_subtitle" value="<?= Security::e($event['public_subtitle']??'') ?>" placeholder="Uma noite inesquecível..."></label><label>Tipo do evento<input name="event_type" value="<?= Security::e($event['event_type']??'general') ?>" placeholder="show, festa, congresso..."></label><label>Capacidade total<input type="number" name="capacity_total" min="1" value="<?= Security::e((string)($event['capacity_total']??'')) ?>" placeholder="Sem limite"></label><label>Cor principal<input type="color" name="primary_color" value="<?= Security::e($event['primary_color']??'#6236df') ?>"></label><label>Cor secundária<input type="color" name="secondary_color" value="<?= Security::e($event['secondary_color']??'#20134f') ?>"></label><label>Cor do texto<input type="color" name="text_color" value="<?= Security::e($event['text_color']??'#252137') ?>"></label><label class="span-2">Link do mapa<input type="url" name="map_url" value="<?= Security::e($event['map_url']??'') ?>" placeholder="https://maps.google.com/..."></label><label>Latitude<input name="latitude" value="<?= Security::e((string)($event['latitude']??'')) ?>"></label><label>Longitude<input name="longitude" value="<?= Security::e((string)($event['longitude']??'')) ?>"></label><label class="checkbox"><input type="checkbox" name="sales_enabled"<?= em_checked($event['sales_enabled']??1) ?>> Venda online habilitada</label><label class="checkbox"><input type="checkbox" name="bar_enabled"<?= em_checked($event['bar_enabled']??1) ?>> Venda de produtos/bar habilitada</label><button class="primary span-2">Salvar página pública</button></form></section><aside class="card"><span class="eyebrow">PUBLICAÇÃO</span><h2>Link do evento</h2><p class="muted">Esse endereço identifica a empresa e o evento para impedir conflito entre estabelecimentos.</p><div class="public-link-box"><strong><?= Security::e($publicUrl) ?></strong></div><div class="actions" style="margin-top:12px"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicUrl) ?>">Abrir página</a><button type="button" class="secondary" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicUrl)) ?>);this.textContent='Copiado ✓'">Copiar link</button></div></aside></div>
<?php endif;?>

<?php if($section==='marketing'):?>
<div class="grid" style="grid-template-columns:1fr 1fr;gap:16px"><section class="card"><span class="eyebrow">PROMOTORES / AFILIADOS</span><h2>Comissão por evento</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="promoter-link"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="marketing"><label class="span-2">Promotor<select name="promoter_id" required><option value="">Selecione</option><?php foreach($allPromoters as$p):?><option value="<?= (int)$p['id'] ?>"><?= Security::e($p['name'].' · '.$p['code']) ?></option><?php endforeach;?></select></label><label class="span-2">Comissão específica %<input name="commission_percent" inputmode="decimal" placeholder="Vazio = comissão padrão"></label><button class="primary span-2">Vincular promotor</button></form><?php foreach($d['promoters'] as$p):?><div class="list-row"><div><strong><?= Security::e($p['name']) ?></strong><small>Código <?= Security::e($p['code']) ?></small></div><strong><?= Security::e((string)($p['commission_percent']??$p['default_commission'])) ?>%</strong></div><?php endforeach;?></section><section class="card"><span class="eyebrow">CUPONS</span><h2>Descontos do evento</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="coupon-link"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="marketing"><label class="span-2">Cupom<select name="coupon_id" required><option value="">Selecione</option><?php foreach($allCoupons as$c):?><option value="<?= (int)$c['id'] ?>"><?= Security::e($c['code']) ?></option><?php endforeach;?></select></label><button class="primary span-2">Vincular cupom</button></form><?php foreach($d['coupons'] as$c):?><div class="list-row"><div><strong><?= Security::e($c['code']) ?></strong><small><?= Security::e($c['type'].' · '.$c['value']) ?></small></div><span><?= (int)$c['uses_count'] ?> usos</span></div><?php endforeach;?></section></div>
<?php endif;?>

<?php if($section==='operation'):?>
<section class="metric-grid"><div class="metric-card"><span>Ingressos pagos</span><strong><?= $sold ?></strong></div><div class="metric-card"><span>Entradas validadas</span><strong><?= $checkins ?></strong></div><div class="metric-card"><span>Taxa de entrada</span><strong><?= $checkinPct ?>%</strong></div><div class="metric-card"><span>Convidados + acompanhantes</span><strong><?= $guestSeats ?></strong></div></section><div class="grid" style="grid-template-columns:repeat(3,1fr);gap:16px;margin-top:18px"><section class="card"><span class="eyebrow">PORTARIA</span><h2>Check-in</h2><p class="muted">Abra a operação já vinculada a este evento.</p><a class="button primary" href="<?= Security::e(app_url('?route=tickets&event_id='.$eventId)) ?>">Abrir check-in</a></section><section class="card"><span class="eyebrow">CONVIDADOS</span><h2>Lista de convidados</h2><p class="muted">Controle nomes, acompanhantes e presença.</p><a class="button secondary" href="<?= Security::e(app_url('?route=guests&event_id='.$eventId)) ?>">Gerenciar convidados</a></section><section class="card"><span class="eyebrow">BAR / PRODUTOS</span><h2>Venda no evento</h2><p class="muted"><?= (int)($event['bar_enabled']??1)?'Habilitada para a operação do evento.':'Desabilitada na configuração pública.' ?></p><a class="button secondary" href="<?= Security::e(app_url('?route=pos')) ?>">Abrir PDV</a></section></div>
<?php endif;?>

<?php if($section==='audit'):?><section class="card"><div class="section-head"><div><span class="eyebrow">AUDITORIA</span><h2>Histórico do evento</h2></div><span class="muted"><?= count($d['audit']) ?> registros</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Ação</th><th>Usuário</th><th>Entidade</th><th>Dados</th></tr></thead><tbody><?php foreach($d['audit'] as$a):?><tr><td><?= Security::e($a['created_at']) ?></td><td><strong><?= Security::e($a['action']) ?></strong></td><td><?= Security::e($a['user_name']??'Sistema/cliente') ?></td><td><?= Security::e(($a['entity_type']??'').' '.($a['entity_id']??'')) ?></td><td><small><?= Security::e(mb_substr((string)($a['metadata']??''),0,180)) ?></small></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php
function render_batch_actions(array $b,int $eventId): void
{
    $editUrl=app_url('?route=event-admin&id='.$eventId.'&section=tickets&edit_batch='.(int)$b['id']);
    ?>
    <div class="batch-actions">
        <a class="button secondary compact" href="<?= Security::e($editUrl) ?>">Editar</a>
        <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="batch-toggle"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="tickets"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="active" value="<?= $b['active']?0:1 ?>"><button class="button secondary compact" type="submit"><?= $b['active']?'Desativar':'Ativar' ?></button></form>
        <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="batch-duplicate"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="tickets"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><button class="button secondary compact" type="submit">Duplicar</button></form>
        <?php if((int)$b['quantity_sold']===0&&(int)$b['quantity_reserved']===0):?><form method="post" onsubmit="return confirm('Excluir este lote? Esta ação só funciona se não houver histórico de ingressos.')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="batch-delete"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="section" value="tickets"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>"><button class="button secondary compact danger-compact" type="submit">Excluir</button></form><?php endif;?>
    </div>
    <?php
}
em_footer();
