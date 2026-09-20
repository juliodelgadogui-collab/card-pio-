<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\TicketService;
use EventMenu\Support\OperationalDiagnostics;
use EventMenu\Support\UiVocabulary;

Auth::requirePermission('tickets.manage');
$tenantId=em_require_tenant();
$checkResult=null;
$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);
$eventsStmt=$pdo->prepare('SELECT id,name,starts_at,status FROM events WHERE tenant_id=? ORDER BY starts_at DESC');
$eventsStmt->execute([$tenantId]);
$events=$eventsStmt->fetchAll();
$selectedEvent=null;
foreach($events as$e)if((int)$e['id']===$eventId){$selectedEvent=$e;break;}
if($eventId>0&&!$selectedEvent)$eventId=0;

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    if($action==='checkin'){
        $token=trim((string)($_POST['token']??''));
        try{
            $checkResult=(new TicketService())->checkIn($token,$eventId);
            if($checkResult['ok'])em_flash('ok','Entrada confirmada neste evento.');
            elseif($checkResult['result']==='duplicate')em_flash('error','Este ingresso já foi utilizado.');
            elseif($checkResult['result']==='wrong_event')em_flash('error','Este ingresso pertence a outro evento. Entrada recusada.');
            elseif($checkResult['result']==='window_closed')em_flash('error',(string)($checkResult['message']??'Check-in fora do horário permitido.'));
            else em_flash('error','Este ingresso ainda não está liberado para entrada.');
        }catch(RuntimeException $e){
            em_flash('error',OperationalDiagnostics::friendly($e,'Não foi possível validar o ingresso. Tente novamente.'));
        }catch(Throwable $e){
            $error=OperationalDiagnostics::userFacing($e,'Não foi possível validar o ingresso. Tente novamente.','ticket.checkin',['query_length'=>mb_strlen($token),'event_id'=>$eventId]);
            em_flash('error',$error['message'].' Referência: '.$error['reference'].'.');
        }
        em_go('tickets',$eventId>0?['event_id'=>$eventId]:[]);
    }
    if($action==='release-expired'){
        try{$n=(new TicketService())->releaseExpired();Auth::audit('tickets.release_expired','ticket',null,['count'=>$n]);em_flash('ok',$n.' reservas expiradas foram liberadas.');}
        catch(Throwable $e){$error=OperationalDiagnostics::userFacing($e,'Não foi possível liberar as reservas expiradas. Tente novamente.','ticket.release_expired');em_flash('error',$error['message'].' Referência: '.$error['reference'].'.');}
        em_go('tickets',$eventId>0?['event_id'=>$eventId]:[]);
    }
}

$q=trim((string)($_GET['q']??''));
$sql='SELECT t.*,e.name event_name,b.name batch_name,c.name customer_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN customers c ON c.id=t.customer_id WHERE t.tenant_id=?';
$args=[$tenantId];
if($eventId>0){$sql.=' AND t.event_id=?';$args[]=$eventId;}
if($q!==''){$sql.=' AND (t.code=? OR c.name LIKE ?)';array_push($args,$q,'%'.$q.'%');}
$sql.=' ORDER BY t.id DESC LIMIT 150';
$s=$pdo->prepare($sql);$s->execute($args);$tickets=$s->fetchAll();
$stats=['paid'=>0,'checked'=>0,'reserved'=>0];
foreach(['paid'=>'paid','checked'=>'checked_in','reserved'=>'reserved']as$k=>$status){$sqls='SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status=?';$a=[$tenantId,$status];if($eventId>0){$sqls.=' AND event_id=?';$a[]=$eventId;}$x=$pdo->prepare($sqls);$x->execute($a);$stats[$k]=(int)$x->fetchColumn();}
$statusLabels=['draft'=>'Rascunho','published'=>'Publicado','closed'=>'Encerrado','cancelled'=>'Cancelado'];

em_header('Ingressos e entrada','tickets');
?>
<style>@media(max-width:760px){.ticket-layout{grid-template-columns:1fr!important}.ticket-event-selector{width:100%}.ticket-event-selector select{width:100%}}</style>
<section class="card" style="margin-bottom:16px"><div class="section-head"><div><span class="eyebrow">PORTARIA</span><h2>Selecione o evento</h2><p class="muted">O QR só é aceito se pertencer ao evento selecionado e estiver dentro da janela de check-in.</p></div></div><form method="get" class="actions ticket-event-selector"><input type="hidden" name="route" value="tickets"><select name="event_id" required onchange="this.form.submit()"><option value="0">Selecione um evento</option><?php foreach($events as$e):?><option value="<?= (int)$e['id'] ?>"<?= em_selected((string)$eventId,(string)$e['id']) ?>><?= Security::e($e['name'].' · '.($statusLabels[$e['status']]??$e['status']).' · '.date('d/m/Y H:i',strtotime((string)$e['starts_at']))) ?></option><?php endforeach;?></select><button class="secondary">Abrir evento</button></form></section>
<section class="grid"><div class="card metric"><span class="muted">Aguardando entrada</span><strong><?= $stats['paid'] ?></strong></div><div class="card metric"><span class="muted">Entradas realizadas</span><strong><?= $stats['checked'] ?></strong></div><div class="card metric"><span class="muted">Aguardando pagamento</span><strong><?= $stats['reserved'] ?></strong></div></section>
<div class="grid ticket-layout" style="grid-template-columns:minmax(280px,1fr) minmax(0,2fr);margin-top:18px"><section class="card"><h2><?= $selectedEvent?'Validar em '.Security::e($selectedEvent['name']):'Validação de entrada' ?></h2><?php if(!$selectedEvent):?><div class="alert">Selecione o evento acima antes de escanear ingressos.</div><?php else:?><p class="muted">Leia o QR Code ou digite o código. Ingressos de outro evento serão recusados.</p><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="checkin"><input type="hidden" name="event_id" value="<?= $eventId ?>"><label>Código / QR<input name="token" required autofocus autocomplete="off"></label><button class="primary" style="margin-top:10px">Confirmar entrada</button></form><?php endif;?><hr style="border-color:var(--line);margin:24px 0"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="release-expired"><input type="hidden" name="event_id" value="<?= $eventId ?>"><button class="secondary">Liberar reservas vencidas</button></form></section><section class="card"><form method="get" class="actions"><input type="hidden" name="route" value="tickets"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input name="q" value="<?= Security::e($q) ?>" placeholder="Código ou cliente"><button class="secondary">Buscar</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Ingresso</th><th>Evento / lote</th><th>Cliente</th><th>Situação</th><th>Entrada</th><th></th></tr></thead><tbody><?php foreach($tickets as$t):?><tr><td><strong><?= Security::e($t['code']) ?></strong></td><td><?= Security::e($t['event_name']) ?><br><span class="muted"><?= Security::e($t['batch_name']) ?></span></td><td><?= Security::e($t['customer_name']??'—') ?></td><td><span class="badge"><?= Security::e(UiVocabulary::ticketStatus((string)$t['status'])) ?></span><?php if($t['reserved_until']):?><br><span class="muted">reserva até <?= Security::e($t['reserved_until']) ?></span><?php endif;?></td><td><?= Security::e($t['checked_in_at']??'—') ?></td><td><?php if($t['qr_token']):?><a class="button secondary" target="_blank" rel="noopener" href="<?= Security::e(app_url('ingresso.php?t='.rawurlencode((string)$t['qr_token']))) ?>">Ver ingresso</a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section></div>
<?php em_footer();