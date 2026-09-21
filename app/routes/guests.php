<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\GuestService;

Auth::requirePermission('guests.manage');
$tenantId=em_require_tenant();
$ownPromoterId=null;
if(Auth::role()==='promoter'){
    $x=$pdo->prepare('SELECT id FROM promoters WHERE tenant_id=? AND user_id=? AND active=1');$x->execute([$tenantId,Auth::id()]);$ownPromoterId=$x->fetchColumn();if(!$ownPromoterId){http_response_code(403);exit('Promotor sem cadastro vinculado.');}
}
$selectedEventId=(int)($_GET['event_id']??$_POST['event_id']??0);

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    if($action==='save'){
        try{
            $eventId=(int)($_POST['event_id']??0);
            $name=mb_substr(trim((string)($_POST['name']??'')),0,180);
            if($name==='')throw new RuntimeException('Informe o nome do convidado.');
            $plusOnes=(int)($_POST['plus_ones']??0);
            if($plusOnes<0||$plusOnes>20)throw new RuntimeException('Acompanhantes deve ficar entre 0 e 20.');
            $promoterId=$ownPromoterId?:((int)($_POST['promoter_id']??0)?:null);
            $id=Database::transaction(function(PDO $tx)use($tenantId,$eventId,$name,$plusOnes,$promoterId):int{
                $e=$tx->prepare(Database::portableSql($tx,'SELECT * FROM events WHERE id=? AND tenant_id=? AND status IN ("draft","published") FOR UPDATE'));
                $e->execute([$eventId,$tenantId]);$event=$e->fetch();if(!$event)throw new RuntimeException('Evento inválido ou encerrado.');
                if($promoterId){$p=$tx->prepare('SELECT id FROM promoters WHERE id=? AND tenant_id=? AND active=1');$p->execute([$promoterId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Promotor inválido.');}
                if($event['capacity_total']!==null){
                    $t=$tx->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');$t->execute([$tenantId,$eventId]);$used=(int)$t->fetchColumn();
                    $g=$tx->prepare('SELECT COALESCE(SUM(1+COALESCE(plus_ones,0)),0) FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');$g->execute([$tenantId,$eventId]);$used+=(int)$g->fetchColumn();
                    if($used+1+$plusOnes>(int)$event['capacity_total'])throw new RuntimeException('Não há capacidade disponível para este convidado e seus acompanhantes.');
                }
                $code=sprintf('%s-%s',str_pad((string)$eventId,4,'0',STR_PAD_LEFT),strtoupper(bin2hex(random_bytes(6))));
                $s=$tx->prepare('INSERT INTO event_guests (tenant_id,event_id,promoter_id,name,document,phone,plus_ones,status,checkin_code) VALUES (?,?,?,?,?,?,?,"invited",?)');
                $s->execute([$tenantId,$eventId,$promoterId,$name,trim((string)($_POST['document']??''))?:null,trim((string)($_POST['phone']??''))?:null,$plusOnes,$code]);
                $id=(int)$tx->lastInsertId();
                try{$tx->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,Auth::id(),'guest.created','guest',(string)$id,json_encode(['plus_ones'=>$plusOnes,'promoter_id'=>$promoterId],JSON_UNESCAPED_UNICODE)]);}catch(Throwable){}
                return $id;
            });
            Auth::audit('guest.created','guest',(string)$id,['event_id'=>$eventId,'plus_ones'=>$plusOnes]);
            em_flash('ok','Convidado adicionado.');
            em_go('guests',['event_id'=>$eventId]);
        }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('guests',$selectedEventId>0?['event_id'=>$selectedEventId]:[]);}
    }
    if($action==='checkin'){
        try{$g=(new GuestService())->checkIn((string)($_POST['code']??''),$selectedEventId);em_flash('ok','Entrada liberada para '.$g['name'].' + '.(int)($g['plus_ones']??0).' acompanhante(s).');}
        catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('guests',$selectedEventId>0?['event_id'=>$selectedEventId]:[]);
    }
}

$events=$pdo->prepare('SELECT id,name,starts_at,status FROM events WHERE tenant_id=? AND status IN ("draft","published") ORDER BY starts_at DESC');$events->execute([$tenantId]);$events=$events->fetchAll();
$selectedEvent=null;foreach($events as$e)if((int)$e['id']===$selectedEventId){$selectedEvent=$e;break;}if($selectedEventId>0&&!$selectedEvent)$selectedEventId=0;
$promoters=$pdo->prepare('SELECT id,name,code FROM promoters WHERE tenant_id=? AND active=1 ORDER BY name');$promoters->execute([$tenantId]);$promoters=$promoters->fetchAll();
$sql='SELECT g.*,e.name event_name,e.status event_status,p.name promoter_name FROM event_guests g JOIN events e ON e.id=g.event_id LEFT JOIN promoters p ON p.id=g.promoter_id WHERE g.tenant_id=?';$args=[$tenantId];if($ownPromoterId){$sql.=' AND g.promoter_id=?';$args[]=$ownPromoterId;}if($selectedEventId>0){$sql.=' AND g.event_id=?';$args[]=$selectedEventId;}$sql.=' ORDER BY g.id DESC LIMIT 300';$s=$pdo->prepare($sql);$s->execute($args);$guests=$s->fetchAll();
$statusLabels=['draft'=>'Rascunho','published'=>'Publicado','invited'=>'Convidado','checked_in'=>'Entrada realizada','cancelled'=>'Cancelado'];
em_header('Lista de convidados','guests');
?>
<style>.guest-cards{display:none}@media(max-width:820px){.guest-layout{grid-template-columns:1fr!important}.guest-table{display:none}.guest-cards{display:grid;gap:10px}.guest-card{border:1px solid var(--line);border-radius:14px;padding:14px}.guest-card-head{display:flex;justify-content:space-between;gap:10px}.guest-card small{color:var(--muted)}.guest-card code{display:block;margin-top:8px;overflow-wrap:anywhere}.form-grid{grid-template-columns:1fr!important}.form-grid .span-2{grid-column:auto!important}}</style>
<section class="card" style="margin-bottom:16px"><div class="section-head"><div><span class="eyebrow">EVENTO DA OPERAÇÃO</span><h2><?= $selectedEvent?Security::e($selectedEvent['name']):'Selecione um evento' ?></h2><p class="muted">Convidados e check-in ficam vinculados ao evento selecionado.</p></div></div><form method="get" class="actions"><input type="hidden" name="route" value="guests"><select name="event_id" onchange="this.form.submit()"><option value="0">Todos / selecione</option><?php foreach($events as$e):?><option value="<?= (int)$e['id'] ?>"<?= em_selected((string)$selectedEventId,(string)$e['id']) ?>><?= Security::e($e['name'].' · '.($statusLabels[$e['status']]??$e['status'])) ?></option><?php endforeach;?></select><button class="secondary">Abrir</button></form></section>
<div class="grid guest-layout" style="grid-template-columns:minmax(280px,1fr) minmax(0,2fr)"><section class="card"><h2>Adicionar convidado</h2><?php if(!$events):?><div class="alert">Crie ou reabra um evento antes de adicionar convidados.</div><?php else:?><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save"><label class="span-2">Evento<select name="event_id" required><?php foreach($events as$e):?><option value="<?= (int)$e['id'] ?>"<?= em_selected((string)$selectedEventId,(string)$e['id']) ?>><?= Security::e($e['name'].' · '.($statusLabels[$e['status']]??$e['status'])) ?></option><?php endforeach;?></select></label><label class="span-2">Nome<input name="name" required></label><label>Telefone<input name="phone"></label><label>Documento<input name="document"></label><label>Acompanhantes<input type="number" min="0" max="20" name="plus_ones" value="0"></label><?php if(!$ownPromoterId):?><label>Promotor<select name="promoter_id"><option value="0">Sem promotor</option><?php foreach($promoters as$p):?><option value="<?= (int)$p['id'] ?>"><?= Security::e($p['name'].' · '.$p['code']) ?></option><?php endforeach;?></select></label><?php endif;?><button class="primary span-2">Adicionar</button></form><?php endif;?><hr style="border-color:var(--line);margin:24px 0"><h2>Check-in convidado</h2><?php if(!$selectedEvent):?><div class="alert">Selecione o evento acima antes de validar um convite.</div><?php else:?><p class="muted">Convites de outro evento serão recusados.</p><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="checkin"><input type="hidden" name="event_id" value="<?= $selectedEventId ?>"><label>Código<input name="code" required autocomplete="off"></label><button class="secondary" style="margin-top:8px">Validar entrada</button></form><?php endif;?></section><section class="card"><div class="table-wrap guest-table"><table class="table"><thead><tr><th>Convidado</th><th>Evento</th><th>Promotor</th><th>Acomp.</th><th>Status</th><th>Código</th></tr></thead><tbody><?php foreach($guests as$g):?><tr><td><strong><?= Security::e($g['name']) ?></strong><br><span class="muted"><?= Security::e($g['phone']??'') ?></span></td><td><?= Security::e($g['event_name']) ?><br><small class="muted"><?= Security::e($statusLabels[$g['event_status']]??$g['event_status']) ?></small></td><td><?= Security::e($g['promoter_name']??'—') ?></td><td><?= (int)$g['plus_ones'] ?></td><td><span class="badge"><?= Security::e($statusLabels[$g['status']]??$g['status']) ?></span></td><td><code><?= Security::e($g['checkin_code']) ?></code></td></tr><?php endforeach;?></tbody></table></div><div class="guest-cards"><?php foreach($guests as$g):?><article class="guest-card"><div class="guest-card-head"><div><strong><?= Security::e($g['name']) ?></strong><br><small><?= Security::e($g['event_name']) ?></small></div><span class="badge"><?= Security::e($statusLabels[$g['status']]??$g['status']) ?></span></div><small>Promotor: <?= Security::e($g['promoter_name']??'—') ?> · Acompanhantes: <?= (int)$g['plus_ones'] ?></small><code><?= Security::e($g['checkin_code']) ?></code></article><?php endforeach;?></div></section></div>
<?php em_footer();