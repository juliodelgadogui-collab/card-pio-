<?php
declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppCommunicationService;

Auth::requirePermission('settings.manage');$tenantId=em_require_tenant();$service=new WhatsAppCommunicationService();
if($_SERVER['REQUEST_METHOD']==='POST'){em_post_csrf();try{$result=Database::transaction(fn(PDO $tx)=>$service->createAndQueue($tx,$tenantId,Auth::id(),$_POST));Auth::audit('whatsapp.communication_queued','whatsapp_communication',(string)$result['id'],['recipient_count'=>$result['recipient_count']]);em_flash('ok','Comunicação criada. '.$result['recipient_count'].' destinatário(s) colocado(s) na fila do EventMenu Connect.');}catch(Throwable $e){em_flash('error',$e->getMessage());}em_go('communications');}
$events=$service->events($pdo,$tenantId);$history=$service->list($pdo,$tenantId);em_header('Central de Comunicação','communications');?>
<section class="page-hero"><div><span class="eyebrow">WHATSAPP · EVENTMENU CONNECT</span><h2>Central de Comunicação</h2><p>Crie comunicados para números informados manualmente, clientes cadastrados ou compradores de um evento. O servidor apenas cria a fila; o EventMenu Connect realiza o envio.</p></div></section>
<section class="card" style="margin-bottom:16px"><div class="section-head"><div><span class="eyebrow">NOVA COMUNICAÇÃO</span><h2>Mensagem e destinatários</h2></div></div>
<form method="post" class="form-grid" id="communication-form"><input type="hidden" name="_csrf" value="<?=em_csrf()?>">
<label class="span-2">Título interno<input name="title" required maxlength="180" placeholder="Ex.: Aviso do evento de sábado"></label>
<label class="span-2">Mensagem<textarea name="message" required maxlength="4000" rows="6" placeholder="Digite a mensagem que será enviada no WhatsApp"></textarea></label>
<label>Público<select name="audience_type" id="audience-type"><option value="manual">Números informados manualmente</option><option value="customers">Todos os clientes com telefone</option><option value="event_buyers">Compradores de um evento</option></select></label>
<label id="event-field" style="display:none">Evento<select name="event_id"><option value="">Selecione</option><?php foreach($events as$event):?><option value="<?=(int)$event['id']?>"><?=Security::e($event['name'])?> · <?=Security::e((string)$event['starts_at'])?></option><?php endforeach;?></select></label>
<label class="span-2" id="manual-field">Telefones<textarea name="manual_recipients" rows="4" placeholder="Um número por linha, com DDD"></textarea><small class="muted">Números repetidos são removidos automaticamente.</small></label>
<div class="span-2"><h3>Anexo opcional</h3><p class="muted">Use uma URL HTTPS pública para imagem ou PDF. O EventMenu Connect baixa e envia o arquivo pelo WhatsApp.</p></div>
<label>Tipo<select name="media_type"><option value="">Sem anexo</option><option value="image">Imagem</option><option value="document">PDF / documento</option></select></label>
<label>URL HTTPS<input type="url" name="media_url" placeholder="https://..."></label><label>Nome do arquivo<input name="media_filename" maxlength="255" placeholder="comunicado.pdf"></label><label>MIME<input name="media_mime" maxlength="120" placeholder="application/pdf ou image/png"></label>
<div class="span-2 alert"><strong>Confirmação de envio:</strong> ao enviar, os destinatários são gravados e cada mensagem recebe uma chave única. Reabrir a página não duplica a comunicação já criada.</div>
<div class="span-2"><button class="primary" type="submit" onclick="return confirm('Colocar esta comunicação na fila do EventMenu Connect?')">Criar e enviar</button></div></form></section>
<section class="card"><div class="section-head"><div><span class="eyebrow">HISTÓRICO</span><h2>Comunicações recentes</h2></div></div><?php if(!$history):?><p class="muted">Nenhuma comunicação criada.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>ID</th><th>Comunicação</th><th>Público</th><th>Destinatários</th><th>Status</th><th>Criada</th></tr></thead><tbody><?php foreach($history as$row):?><tr><td>#<?=(int)$row['id']?></td><td><strong><?=Security::e($row['title'])?></strong><br><span class="muted"><?=Security::e(mb_strimwidth((string)$row['message_text'],0,100,'…'))?></span></td><td><?=Security::e(match($row['audience_type']){'customers'=>'Clientes','event_buyers'=>'Compradores do evento',default=>'Manual'})?></td><td><?=(int)$row['recipient_count']?></td><td><?=Security::e(strtoupper((string)$row['status']))?></td><td><?=Security::e((string)$row['created_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script>(()=>{const a=document.getElementById('audience-type'),m=document.getElementById('manual-field'),e=document.getElementById('event-field');const sync=()=>{m.style.display=a.value==='manual'?'':'none';e.style.display=a.value==='event_buyers'?'':'none'};a.addEventListener('change',sync);sync()})();</script>
<?php em_footer();
