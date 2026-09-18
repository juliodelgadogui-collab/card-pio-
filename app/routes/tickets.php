<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\TicketService;
use EventMenu\Support\OperationalDiagnostics;
use EventMenu\Support\UiVocabulary;

Auth::requirePermission('tickets.manage');$tenantId=em_require_tenant();$checkResult=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    if($action==='checkin'){
        $token=trim((string)($_POST['token']??''));
        try{
            $checkResult=(new TicketService())->checkIn($token);
            if($checkResult['ok'])em_flash('ok','Entrada confirmada.');
            else em_flash('error',$checkResult['result']==='duplicate'?'Este ingresso já foi utilizado.':'Este ingresso ainda não está liberado para entrada.');
        }catch(RuntimeException $e){
            em_flash('error',OperationalDiagnostics::friendly($e,'Não foi possível validar o ingresso. Tente novamente.'));
        }catch(Throwable $e){
            $error=OperationalDiagnostics::userFacing($e,'Não foi possível validar o ingresso. Tente novamente.','ticket.checkin',['query_length'=>mb_strlen($token)]);
            em_flash('error',$error['message'].' Referência: '.$error['reference'].'.');
        }
        em_go('tickets',['q'=>$token]);
    }
    if($action==='release-expired'){
        try{
            $n=(new TicketService())->releaseExpired();Auth::audit('tickets.release_expired','ticket',null,['count'=>$n]);em_flash('ok',$n.' reservas expiradas foram liberadas.');
        }catch(Throwable $e){
            $error=OperationalDiagnostics::userFacing($e,'Não foi possível liberar as reservas expiradas. Tente novamente.','ticket.release_expired');
            em_flash('error',$error['message'].' Referência: '.$error['reference'].'.');
        }
        em_go('tickets');
    }
}
$q=trim((string)($_GET['q']??''));$sql='SELECT t.*,e.name event_name,b.name batch_name,c.name customer_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN customers c ON c.id=t.customer_id WHERE t.tenant_id=?';$args=[$tenantId];if($q!==''){$sql.=' AND (t.code=? OR t.qr_token=? OR c.name LIKE ?)';array_push($args,$q,$q,'%'.$q.'%');}$sql.=' ORDER BY t.id DESC LIMIT 150';$s=$pdo->prepare($sql);$s->execute($args);$tickets=$s->fetchAll();
$stats=[];foreach(['paid'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="paid"','checked'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="checked_in"','reserved'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="reserved"'] as $k=>$sqls){$x=$pdo->prepare($sqls);$x->execute([$tenantId]);$stats[$k]=$x->fetchColumn();}
em_header('Ingressos e entrada','tickets');
?><section class="grid"><div class="card metric"><span class="muted">Aguardando entrada</span><strong><?= (int)$stats['paid'] ?></strong></div><div class="card metric"><span class="muted">Entradas realizadas</span><strong><?= (int)$stats['checked'] ?></strong></div><div class="card metric"><span class="muted">Aguardando pagamento</span><strong><?= (int)$stats['reserved'] ?></strong></div></section><div class="grid" style="grid-template-columns:minmax(280px,1fr) minmax(0,2fr);margin-top:18px"><section class="card"><h2>Validar ingresso</h2><p class="muted">Leia o QR Code ou digite o código do ingresso. Somente ingressos pagos são liberados.</p><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="checkin"><label>Código / QR<input name="token" required autofocus autocomplete="off"></label><button class="primary" style="margin-top:10px">Confirmar entrada</button></form><hr style="border-color:var(--line);margin:24px 0"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="release-expired"><button class="secondary">Liberar reservas vencidas</button></form></section><section class="card"><form method="get" class="actions"><input type="hidden" name="route" value="tickets"><input name="q" value="<?= Security::e($q) ?>" placeholder="Código, QR ou cliente"><button class="secondary">Buscar</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Ingresso</th><th>Evento / lote</th><th>Cliente</th><th>Situação</th><th>Entrada</th><th></th></tr></thead><tbody><?php foreach($tickets as $t):?><tr><td><strong><?= Security::e($t['code']) ?></strong></td><td><?= Security::e($t['event_name']) ?><br><span class="muted"><?= Security::e($t['batch_name']) ?></span></td><td><?= Security::e($t['customer_name']??'—') ?></td><td><span class="badge"><?= Security::e(UiVocabulary::ticketStatus((string)$t['status'])) ?></span><?php if($t['reserved_until']):?><br><span class="muted">reserva até <?= Security::e($t['reserved_until']) ?></span><?php endif;?></td><td><?= Security::e($t['checked_in_at']??'—') ?></td><td><?php if($t['qr_token']):?><a class="button secondary" target="_blank" href="/ingresso.php?t=<?= urlencode($t['qr_token']) ?>">Ver ingresso</a><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();