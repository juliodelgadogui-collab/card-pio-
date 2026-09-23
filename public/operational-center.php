<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/admin_helpers.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\BackgroundJobService;

Auth::enforceCurrentUser();
if(!Auth::isSuperAdmin()){http_response_code(403);exit('Acesso restrito ao Super ADM.');}

$pdo=Database::connection();
$jobs=new BackgroundJobService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    $id=(int)($_POST['id']??0);
    try{
        if($action==='retry-job'){$jobs->retryJob($id);em_flash('ok','Tarefa reenfileirada para processamento imediato.');}
        elseif($action==='discard-job'){$jobs->discardJob($id);em_flash('ok','Tarefa descartada da fila ativa.');}
        elseif($action==='retry-whatsapp'){
            $s=$pdo->prepare('UPDATE whatsapp_outbox SET status="queued",available_at=CURRENT_TIMESTAMP,locked_at=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND status IN ("failed","error","retry")');$s->execute([$id]);
            if($s->rowCount()<1)throw new RuntimeException('A mensagem não pode ser reenfileirada no estado atual.');
            em_flash('ok','Mensagem do WhatsApp reenfileirada.');
        }
        elseif($action==='retry-print'){
            $s=$pdo->prepare('UPDATE production_print_queue SET status="pending",failed_at=NULL WHERE id=? AND status="failed"');$s->execute([$id]);
            if($s->rowCount()<1)throw new RuntimeException('A impressão não pode ser reenfileirada no estado atual.');
            em_flash('ok','Impressão reenfileirada.');
        }
        elseif($action==='process-jobs'){$r=$jobs->runBatch(100,'operational-center');em_flash('ok','Fila processada: '.(int)$r['completed'].' concluída(s), '.(int)$r['retried'].' reagendada(s), '.(int)$r['failed'].' falha(s).');}
        else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){em_flash('error','Não foi possível concluir: '.$e->getMessage());}
    header('Location: '.app_url('operational-center.php'));exit;
}

$jobStats=$jobs->stats();
$jobFailures=$jobs->recentFailures(40);
$whatsAppCounts=[];$whatsAppFailures=[];$connections=[];$printCounts=[];$printFailures=[];$runtime=[];
try{$rows=$pdo->query('SELECT status,COUNT(*) total FROM whatsapp_outbox GROUP BY status')->fetchAll();foreach($rows as$r)$whatsAppCounts[(string)$r['status']]=(int)$r['total'];$q=$pdo->query('SELECT w.id,w.tenant_id,t.name tenant_name,w.recipient,w.event_type,w.status,w.attempt_count,w.max_attempts,w.last_error,w.updated_at FROM whatsapp_outbox w LEFT JOIN tenants t ON t.id=w.tenant_id WHERE w.status IN ("failed","error","retry") ORDER BY w.updated_at DESC,w.id DESC LIMIT 40');$whatsAppFailures=$q->fetchAll();$connections=$pdo->query('SELECT w.tenant_id,t.name tenant_name,w.status,w.phone_number,w.last_seen_at,w.last_error,w.updated_at FROM whatsapp_connections w LEFT JOIN tenants t ON t.id=w.tenant_id ORDER BY COALESCE(w.last_seen_at,w.updated_at) ASC LIMIT 100')->fetchAll();}catch(Throwable){}
try{$rows=$pdo->query('SELECT status,COUNT(*) total FROM production_print_queue GROUP BY status')->fetchAll();foreach($rows as$r)$printCounts[(string)$r['status']]=(int)$r['total'];$q=$pdo->query('SELECT p.id,p.tenant_id,t.name tenant_name,p.station_id,p.order_id,p.status,p.reason,p.created_at,p.failed_at FROM production_print_queue p LEFT JOIN tenants t ON t.id=p.tenant_id WHERE p.status="failed" ORDER BY COALESCE(p.failed_at,p.created_at) DESC,p.id DESC LIMIT 40');$printFailures=$q->fetchAll();}catch(Throwable){}
try{$q=$pdo->query("SELECT status_key,state,message,metadata,checked_at FROM system_runtime_status WHERE status_key IN ('backup.last_verified','backup.last_mirror','queue.worker.last_run') ORDER BY status_key");foreach($q->fetchAll()as$r)$runtime[(string)$r['status_key']]=$r;}catch(Throwable){}

$now=time();
$staleConnections=array_values(array_filter($connections,static function(array $r)use($now):bool{$state=strtolower((string)($r['status']??''));$last=strtotime((string)($r['last_seen_at']??$r['updated_at']??''));return $state!=='connected'||$last===false||$last<$now-300;}));
$jobProblems=(int)$jobStats['failed']+(int)$jobStats['retry']+(int)$jobStats['stale_processing'];
$waProblems=count($whatsAppFailures)+count($staleConnections);
$printProblems=count($printFailures);
$totalProblems=$jobProblems+$waProblems+$printProblems;

em_header('Central Operacional','system-health');
?>
<section class="page-hero"><div><span class="eyebrow">RECUPERAÇÃO E INCIDENTES</span><h2>Central Operacional</h2><p>Filas, WhatsApp, impressão e backups em uma visão única, com ações seguras de recuperação.</p></div><div class="hero-actions"><a class="button secondary" href="<?=Security::e(app_url('?route=system-health'))?>">Saúde do sistema</a><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="process-jobs"><button class="primary">Processar fila agora</button></form></div></section>

<section class="metric-grid" style="margin-bottom:18px">
 <div class="metric-card"><span>Incidentes ativos</span><strong><?=$totalProblems?></strong><small><?=$totalProblems?'precisam de revisão':'nenhum problema detectado'?></small></div>
 <div class="metric-card"><span>Jobs</span><strong><?=$jobProblems?></strong><small><?= (int)$jobStats['pending'] ?> pendente(s) · <?= (int)$jobStats['processing'] ?> processando</small></div>
 <div class="metric-card"><span>WhatsApp</span><strong><?=$waProblems?></strong><small><?=count($staleConnections)?> conexão(ões) offline/desatualizadas</small></div>
 <div class="metric-card"><span>Impressão</span><strong><?=$printProblems?></strong><small><?= (int)($printCounts['pending']??0) ?> pendente(s)</small></div>
</section>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;margin-bottom:18px">
<section class="card"><span class="eyebrow">BACKUP LOCAL</span><h2><?=Security::e((string)($runtime['backup.last_verified']['state']??'Sem dados'))?></h2><p><?=Security::e((string)($runtime['backup.last_verified']['message']??'Ainda não há confirmação de backup verificado.'))?></p><small class="muted"><?=Security::e((string)($runtime['backup.last_verified']['checked_at']??''))?></small></section>
<section class="card"><span class="eyebrow">BACKUP SECUNDÁRIO</span><h2><?=Security::e((string)($runtime['backup.last_mirror']['state']??'Não configurado'))?></h2><p><?=Security::e((string)($runtime['backup.last_mirror']['message']??'Configure BACKUP_MIRROR_PATH para manter uma segunda cópia verificada fora da pasta principal de backups.'))?></p><small class="muted"><?=Security::e((string)($runtime['backup.last_mirror']['checked_at']??''))?></small></section>
</div>

<section class="card" style="margin-bottom:18px"><div class="section-head"><div><span class="eyebrow">FILA ASSÍNCRONA</span><h2>Tarefas com pendência</h2></div><span class="badge status-<?=$jobProblems?'warning':'success'?>"><?=$jobProblems?'Revisar':'Normal'?></span></div>
<?php if(!$jobFailures):?><p class="muted">Nenhuma tarefa falha ou aguardando retry.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Tenant</th><th>Tipo</th><th>Estado</th><th>Tentativas</th><th>Erro</th><th>Ações</th></tr></thead><tbody><?php foreach($jobFailures as$j):?><tr><td>#<?=(int)$j['id']?></td><td><?=Security::e((string)($j['tenant_id']??'Global'))?></td><td><?=Security::e((string)$j['type'])?></td><td><?=Security::e((string)$j['status'])?></td><td><?=(int)$j['attempts']?> / <?=(int)$j['max_attempts']?></td><td><?=Security::e(mb_substr((string)($j['last_error']??''),0,180))?></td><td><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="retry-job"><input type="hidden" name="id" value="<?=(int)$j['id']?>"><button class="secondary compact">Reenviar</button></form><form method="post" onsubmit="return confirm('Descartar esta tarefa da fila ativa?')"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="discard-job"><input type="hidden" name="id" value="<?=(int)$j['id']?>"><button class="secondary compact">Descartar</button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>

<section class="card" style="margin-bottom:18px"><div class="section-head"><div><span class="eyebrow">EVENTMENU CONNECT / WHATSAPP</span><h2>Conexões e mensagens</h2></div><span class="badge status-<?=$waProblems?'warning':'success'?>"><?=$waProblems?'Revisar':'Normal'?></span></div>
<?php if($staleConnections):?><div class="alert warning"><strong>Conexões offline ou sem heartbeat recente:</strong> <?php foreach(array_slice($staleConnections,0,8)as$c):?><?=Security::e((string)($c['tenant_name']??('#'.$c['tenant_id'])))?> (<?=Security::e((string)$c['status'])?>) · <?php endforeach;?></div><?php endif;?>
<?php if(!$whatsAppFailures):?><p class="muted">Nenhuma mensagem falha na outbox.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Empresa</th><th>Evento</th><th>Destinatário</th><th>Tentativas</th><th>Erro</th><th>Ação</th></tr></thead><tbody><?php foreach($whatsAppFailures as$w):?><tr><td>#<?=(int)$w['id']?></td><td><?=Security::e((string)($w['tenant_name']??$w['tenant_id']))?></td><td><?=Security::e((string)$w['event_type'])?></td><td><?=Security::e((string)$w['recipient'])?></td><td><?=(int)$w['attempt_count']?> / <?=(int)$w['max_attempts']?></td><td><?=Security::e(mb_substr((string)($w['last_error']??''),0,180))?></td><td><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="retry-whatsapp"><input type="hidden" name="id" value="<?=(int)$w['id']?>"><button class="secondary compact">Reenviar</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>

<section class="card"><div class="section-head"><div><span class="eyebrow">IMPRESSÃO DE PRODUÇÃO</span><h2>Falhas de impressão</h2></div><span class="badge status-<?=$printProblems?'warning':'success'?>"><?=$printProblems?'Revisar':'Normal'?></span></div>
<?php if(!$printFailures):?><p class="muted">Nenhuma impressão marcada como falha.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Empresa</th><th>Pedido</th><th>Estação</th><th>Motivo</th><th>Ação</th></tr></thead><tbody><?php foreach($printFailures as$p):?><tr><td>#<?=(int)$p['id']?></td><td><?=Security::e((string)($p['tenant_name']??$p['tenant_id']))?></td><td>#<?=(int)$p['order_id']?></td><td>#<?=(int)$p['station_id']?></td><td><?=Security::e(mb_substr((string)($p['reason']??''),0,180))?></td><td><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="retry-print"><input type="hidden" name="id" value="<?=(int)$p['id']?>"><button class="secondary compact">Reimprimir</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<?php em_footer();
