<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\TicketService;

Auth::requirePermission('tickets.manage');
$tenantId=em_require_tenant();
$service=new TicketService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='checkin'){
            $token=trim((string)($_POST['token']??''));
            $r=$service->checkIn($token);
            em_flash($r['ok']?'ok':'error',$r['ok']?'Check-in realizado com sucesso.':($r['result']==='duplicate'?'Ingresso já utilizado.':'Ingresso não está liberado para entrada.'));
            em_go('tickets',['q'=>$token]);
        }
        if($action==='release-expired'){
            $n=$service->releaseExpired();
            Auth::audit('tickets.release_expired','ticket',null,['count'=>$n]);
            em_flash('ok',$n.' reserva(s) expirada(s) liberada(s).');
            em_go('tickets');
        }
        if($action==='transfer'){
            $r=$service->transfer((int)($_POST['ticket_id']??0),['name'=>$_POST['name']??'','email'=>$_POST['email']??'','phone'=>$_POST['phone']??'']);
            em_flash('ok','Ingresso transferido. O QR antigo foi invalidado e um novo ingresso foi gerado para '.$r['name'].'.');
            em_go('tickets',['q'=>$r['code']]);
        }
        if($action==='queue-email'){
            $n=$service->queuePaidTicketsForDelivery($tenantId,300);
            em_flash('ok',$n.' ingresso(s) enviados/verificados na fila de e-mail.');
            em_go('tickets');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('tickets');}
}

$tab=(string)($_GET['tab']??'all');
$tabs=['all'=>'Todos','valid'=>'Válidos','used'=>'Utilizados','reserved'=>'Reservados'];
if(!isset($tabs[$tab]))$tab='all';
$q=trim((string)($_GET['q']??''));
$sql='SELECT t.*,e.name event_name,b.name batch_name,c.name customer_name,c.email customer_email,c.phone customer_phone,fc.name previous_customer_name FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN customers fc ON fc.id=t.transferred_from_customer_id WHERE t.tenant_id=?';
$args=[$tenantId];
if($tab==='valid')$sql.=' AND t.status="paid"';
elseif($tab==='used')$sql.=' AND t.status="checked_in"';
elseif($tab==='reserved')$sql.=' AND t.status="reserved"';
if($q!==''){$sql.=' AND (t.code=? OR t.qr_token=? OR c.name LIKE ? OR c.email LIKE ?)';array_push($args,$q,$q,'%'.$q.'%','%'.$q.'%');}
$sql.=' ORDER BY t.id DESC LIMIT 250';
$s=$pdo->prepare($sql);$s->execute($args);$tickets=$s->fetchAll();
$stats=[];
foreach(['paid'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="paid"','checked'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="checked_in"','reserved'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND status="reserved"','total'=>'SELECT COUNT(*) FROM tickets WHERE tenant_id=?'] as$k=>$sqls){$x=$pdo->prepare($sqls);$x->execute([$tenantId]);$stats[$k]=(int)$x->fetchColumn();}
$transferId=(int)($_GET['transfer']??0);$transfer=null;if($transferId){foreach($tickets as$t)if((int)$t['id']===$transferId)$transfer=$t;}

em_header('Ingressos','tickets');
?>
<style>
.ticket-hero{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.6fr);gap:16px;align-items:start}.ticket-scan{background:linear-gradient(145deg,#6d4aff,#4d2fd1);color:#fff;border:0}.ticket-scan h2,.ticket-scan p{color:#fff}.ticket-scan input{background:#fff;color:#222;border:0}.ticket-scan .secondary{background:#ffffff1b;color:#fff;border-color:#ffffff33}.ticket-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.ticket-stat{padding:16px;background:#fff;border:1px solid var(--line);border-radius:14px}.ticket-stat span{display:block;color:var(--muted);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}.ticket-stat strong{display:block;margin-top:5px;font-size:24px}.ticket-tabs{display:flex;gap:4px;overflow:auto;border-bottom:1px solid var(--line);margin:18px 0 12px}.ticket-tabs a{padding:10px 13px;font-size:11px;font-weight:850;color:#7b7b8d;white-space:nowrap;border-bottom:2px solid transparent}.ticket-tabs a.active{color:var(--accent);border-color:var(--accent)}.ticket-list{display:grid;gap:8px}.ticket-row{display:grid;grid-template-columns:150px minmax(190px,1.2fr) minmax(150px,1fr) 125px 120px;gap:12px;align-items:center;padding:13px 14px;background:#fff;border:1px solid var(--line);border-radius:13px}.ticket-code{font-weight:900;font-size:12px}.ticket-row small{display:block;color:var(--muted);margin-top:3px}.ticket-row .actions{justify-content:flex-end}.scanner-stage{display:none;margin-top:12px;border-radius:14px;overflow:hidden;background:#111;position:relative}.scanner-stage.active{display:block}.scanner-stage video{display:block;width:100%;max-height:330px;object-fit:cover}.scanner-hint{position:absolute;left:12px;right:12px;bottom:12px;background:#000a;color:#fff;padding:9px 11px;border-radius:10px;font-size:11px}.transfer-panel{margin-top:16px}.ticket-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.ticket-tools form.search{display:flex;gap:7px;flex:1;min-width:240px}.ticket-tools form.search input{min-width:0}@media(max-width:950px){.ticket-hero{grid-template-columns:1fr}.ticket-stats{grid-template-columns:repeat(2,1fr)}.ticket-row{grid-template-columns:120px 1fr 120px}.ticket-row .ticket-holder,.ticket-row .ticket-transfer{display:none}}@media(max-width:600px){.ticket-stats{grid-template-columns:1fr 1fr}.ticket-row{grid-template-columns:90px 1fr auto;padding:11px}.ticket-row .ticket-event small{display:none}.ticket-row .actions .button{padding:8px;font-size:10px}.ticket-tools{align-items:stretch}.ticket-tools form.search{width:100%}}
</style>
<section class="ticket-hero"><article class="card ticket-scan"><span style="font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;opacity:.8">Entrada do evento</span><h2 style="font-size:25px;margin:7px 0">Check-in rápido</h2><p style="opacity:.85">Leia o QR do ingresso ou informe o código manualmente.</p><form method="post" id="checkinForm"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="checkin"><label style="color:#fff">Código / QR<input id="ticketToken" name="token" required autofocus autocomplete="off" placeholder="Aponte o leitor ou digite o código"></label><div class="actions" style="margin-top:10px"><button class="primary" style="background:#fff;color:#5b3fe0;box-shadow:none">Realizar Check-in</button><button class="secondary" type="button" id="cameraButton">Abrir câmera</button></div></form><div class="scanner-stage" id="scannerStage"><video id="scannerVideo" playsinline muted></video><div class="scanner-hint">Aponte a câmera para o QR Code do ingresso.</div></div></article><div><div class="ticket-stats"><div class="ticket-stat"><span>Total</span><strong><?= $stats['total'] ?></strong></div><div class="ticket-stat"><span>Válidos</span><strong><?= $stats['paid'] ?></strong></div><div class="ticket-stat"><span>Utilizados</span><strong><?= $stats['checked'] ?></strong></div><div class="ticket-stat"><span>Reservados</span><strong><?= $stats['reserved'] ?></strong></div></div><article class="card" style="margin-top:10px"><strong>Operação de ingressos</strong><p class="muted">QR individual, check-in, transferência de titularidade, reenvio por e-mail e liberação automática de reservas expiradas.</p><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="release-expired"><button class="secondary">Liberar expirados</button></form><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="queue-email"><button class="secondary">Reenviar ingressos</button></form><?php if(Auth::can('exports.view')):?><a class="button secondary" href="<?= Security::e(em_url('/?route=export&kind=tickets')) ?>">Exportar CSV</a><?php endif;?></div></article></div></section>
<?php if($transfer):?><section class="card transfer-panel"><div class="section-head"><div><h2>Transferir ingresso</h2><p class="muted"><?= Security::e($transfer['event_name']) ?> · <?= Security::e($transfer['code']) ?> · titular atual: <?= Security::e($transfer['customer_name']??'—') ?></p></div><a class="button secondary" href="<?= Security::e(em_url('/?route=tickets&tab='.$tab)) ?>">Cancelar</a></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="transfer"><input type="hidden" name="ticket_id" value="<?= $transferId ?>"><label class="span-2">Novo titular<input name="name" required></label><label>E-mail<input type="email" name="email"></label><label>Telefone<input name="phone"></label><div class="alert span-2">Ao confirmar, o QR atual será invalidado e um novo QR será gerado.</div><button class="primary span-2">Confirmar transferência</button></form></section><?php endif;?>
<nav class="ticket-tabs"><?php foreach($tabs as$key=>$label):?><a class="<?= $tab===$key?'active':'' ?>" href="<?= Security::e(em_url('/?route=tickets&tab='.$key)) ?>"><?= Security::e($label) ?></a><?php endforeach;?></nav>
<section class="card"><div class="ticket-tools"><form method="get" class="search"><input type="hidden" name="route" value="tickets"><input type="hidden" name="tab" value="<?= Security::e($tab) ?>"><input name="q" value="<?= Security::e($q) ?>" placeholder="Buscar código, titular ou e-mail"><button class="secondary">Buscar</button></form><span class="muted"><?= count($tickets) ?> ingresso(s)</span></div><div class="ticket-list" style="margin-top:13px"><?php foreach($tickets as$t):?><div class="ticket-row"><div><span class="ticket-code"><?= Security::e($t['code']) ?></span><small>#<?= (int)$t['id'] ?></small></div><div class="ticket-event"><strong><?= Security::e($t['event_name']) ?></strong><small><?= Security::e($t['batch_name']) ?></small></div><div class="ticket-holder"><strong><?= Security::e($t['customer_name']??'—') ?></strong><small><?= Security::e($t['customer_email']??'') ?></small></div><div><span class="badge <?= $t['status']==='paid'?'active':'' ?>"><?= Security::e(em_status_label($t['status'])) ?></span><?php if($t['checked_in_at']):?><small><?= Security::e($t['checked_in_at']) ?></small><?php endif;?></div><div class="actions"><?php if($t['qr_token']):?><a class="button secondary" target="_blank" href="<?= Security::e(em_url('/ingresso.php?t='.urlencode($t['qr_token']))) ?>">Abrir</a><?php endif;?><?php if($t['status']==='paid'&&!$t['checked_in_at']):?><a class="button secondary" href="<?= Security::e(em_url('/?route=tickets&tab='.$tab.'&transfer='.(int)$t['id'])) ?>">Transferir</a><?php endif;?></div></div><?php endforeach;?><?php if(!$tickets):?><div class="alert">Nenhum ingresso encontrado nesta visão.</div><?php endif;?></div></section>
<script>
(()=>{const btn=document.getElementById('cameraButton'),stage=document.getElementById('scannerStage'),video=document.getElementById('scannerVideo'),input=document.getElementById('ticketToken'),form=document.getElementById('checkinForm');let stream=null,reading=false;const stop=()=>{reading=false;if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}stage?.classList.remove('active');if(video)video.srcObject=null;};btn?.addEventListener('click',async()=>{if(stream){stop();btn.textContent='Abrir câmera';return;}if(!navigator.mediaDevices?.getUserMedia){alert('A câmera não está disponível neste navegador. Use o código manual.');return;}try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});video.srcObject=stream;await video.play();stage.classList.add('active');btn.textContent='Fechar câmera';if(!('BarcodeDetector'in window)){alert('Leitura automática de QR não é suportada neste navegador. Você pode usar um leitor externo ou digitar o código.');return;}const detector=new BarcodeDetector({formats:['qr_code']});reading=true;const scan=async()=>{if(!reading)return;try{const codes=await detector.detect(video);if(codes.length){input.value=codes[0].rawValue||'';stop();form.requestSubmit();return;}}catch(e){}requestAnimationFrame(scan);};scan();}catch(e){alert('Não foi possível acessar a câmera. Verifique a permissão do navegador.');stop();}});window.addEventListener('pagehide',stop);})();
</script>
<?php em_footer();
