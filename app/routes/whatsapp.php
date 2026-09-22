<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppIntegrationService;

Auth::requirePermission('settings.manage');
$tenantId=(int)(Auth::tenantId()??0);
if($tenantId<1){http_response_code(403);exit('Selecione uma empresa para configurar o WhatsApp.');}

$service=new WhatsAppIntegrationService();
$eventLabels=[
    'order_received'=>'Pedido recebido',
    'order_confirmed'=>'Pedido confirmado',
    'payment_confirmed'=>'Pagamento confirmado',
    'preparing'=>'Pedido em preparo',
    'ready'=>'Pedido pronto',
    'out_for_delivery'=>'Saiu para entrega',
    'delivered'=>'Pedido entregue',
    'cancelled'=>'Pedido cancelado',
];

if(($_GET['ajax']??'')==='status'){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    try{echo json_encode(['ok'=>true,'data'=>$service->status($pdo,$tenantId,false)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'Não foi possível consultar o EventMenu Connect agora.'],JSON_UNESCAPED_UNICODE);}exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='save'){
            $templates=[];
            foreach($eventLabels as$event=>$label)$templates[$event]=['enabled'=>isset($_POST['event_enabled'][$event]),'message'=>(string)($_POST['event_message'][$event]??'')];
            $service->saveConfiguration($pdo,$tenantId,isset($_POST['automation_enabled']),$templates);
            Auth::audit('whatsapp.settings_saved','whatsapp_connection',(string)$tenantId,['automation_enabled'=>isset($_POST['automation_enabled'])]);
            em_flash('ok','Configuração do WhatsApp salva.');
        }elseif($action==='save_commerce'){
            $service->ensureConnection($pdo,$tenantId);$enabled=isset($_POST['commerce_enabled']);
            $pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$enabled?1:0,$tenantId]);
            Auth::audit('whatsapp.commerce_toggled','whatsapp_connection',(string)$tenantId,['commerce_enabled'=>$enabled]);
            em_flash('ok',$enabled?'WhatsApp Commerce ativado.':'WhatsApp Commerce pausado. As mensagens recebidas continuam no histórico sem respostas automáticas.');
        }else{
            throw new RuntimeException('Ação de WhatsApp não permitida no servidor. Use o EventMenu Connect para parear, reconectar ou trocar o número.');
        }
    }catch(Throwable$e){em_flash('error',$e->getMessage());}
    header('Location: '.app_url('whatsapp.php'));exit;
}

$connection=$service->status($pdo,$tenantId,false);
$templates=$service->templates($pdo,$tenantId);$templatesByEvent=[];foreach($templates as$row)$templatesByEvent[(string)$row['event_type']]=$row;$defaults=$service->defaultTemplates();
$status=(string)($connection['status']??'disconnected');$agent=is_array($connection['agent']??null)?$connection['agent']:[];
$commerceStmt=$pdo->prepare('SELECT commerce_enabled FROM whatsapp_connections WHERE tenant_id=? LIMIT 1');$commerceStmt->execute([$tenantId]);$commerceEnabled=(int)($commerceStmt->fetchColumn()?:0)===1;
$statusLabel=match($status){'connected'=>'Conectado','starting'=>'Iniciando','qr'=>'Aguardando pareamento no Connect','reconnecting'=>'Reconectando','error'=>'Precisa de atenção',default=>'Desconectado'};
$statusTone=match($status){'connected'=>'success','starting','qr','reconnecting'=>'warning','error'=>'danger',default=>'muted'};
$pollUrl=json_encode(app_url('whatsapp.php?ajax=status'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

em_header('WhatsApp','whatsapp');
?>
<section class="page-hero">
  <div><span class="eyebrow">EVENTMENU CONNECT</span><h2>WhatsApp da empresa</h2><p>O servidor organiza conversas e filas. Pareamento, sessão, reconexão e envio real ficam exclusivamente no aplicativo EventMenu Connect.</p></div>
  <div class="hero-actions"><a class="button primary" href="<?=Security::e(app_url('support.php?list=1'))?>">Atender / iniciar conversa</a><a class="button secondary" href="<?=Security::e(app_url('support.php'))?>">Central de Atendimento</a><a class="button secondary" href="<?=Security::e(app_url('?route=settings'))?>">Voltar</a></div>
</section>

<div class="settings-layout"><div class="settings-main">
<section class="card" data-wa-panel>
  <div class="section-head"><div><span class="eyebrow">CONEXÃO</span><h2>Status do EventMenu Connect</h2></div><span class="badge status-<?=$statusTone?>" data-wa-status><?=Security::e($statusLabel)?></span></div>
  <div class="detail-grid">
    <div><span class="muted">Número conectado</span><strong data-wa-phone><?=Security::e((string)($connection['phone_number']?:'—'))?></strong></div>
    <div><span class="muted">Último contato</span><strong data-wa-last><?=Security::e((string)($connection['last_seen_at']?:'—'))?></strong></div>
    <div><span class="muted">Dispositivo</span><strong data-wa-device><?=Security::e((string)($agent['device_label']??'—'))?></strong></div>
    <div><span class="muted">Mensagens aguardando</span><strong data-wa-pending><?=(int)($connection['pending']??0)?></strong></div>
  </div>
  <p class="muted" data-wa-error><?=Security::e((string)($connection['last_error']??$agent['last_error']??''))?></p>
  <div class="alert"><strong>Conexão no aparelho:</strong> para parear, reconectar, trocar número ou encerrar a sessão, abra o EventMenu Connect. Este painel não gera QR/código e não mantém credenciais do WhatsApp na hospedagem.</div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">WHATSAPP COMMERCE</span><h2>Pedidos e conversas automáticas</h2></div><span class="badge status-<?=$commerceEnabled?'success':'muted'?>"><?=$commerceEnabled?'Ativo':'Pausado'?></span></div>
  <p class="muted">Quando ativo, mensagens recebidas pelo Baileys embarcado no EventMenu Connect entram no servidor, seguem o fluxo de cardápio/pedido e as respostas voltam pela mesma fila segura.</p>
  <form method="post" class="form-grid" id="commerceForm"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save_commerce"><label class="checkbox span-2"><input type="checkbox" name="commerce_enabled"<?=em_checked($commerceEnabled)?>> Ativar WhatsApp Commerce nesta empresa</label><div class="span-2"><span class="badge status-warning" id="commerceUnsaved" hidden>Alteração não salva</span></div><div class="span-2 actions"><button class="primary" type="submit">Salvar WhatsApp Commerce</button></div></form>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">AUTOMAÇÕES</span><h2>Mensagens por etapa do pedido</h2><p class="muted">Todas as mensagens abaixo são apenas enfileiradas no servidor e enviadas pelo EventMenu Connect.</p></div></div>
  <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save"><label class="checkbox span-2"><input type="checkbox" name="automation_enabled"<?=em_checked($connection['automation_enabled']??false)?>> Ativar mensagens automáticas desta empresa</label><div class="span-2"><p class="muted">Variáveis: <code>{nome}</code>, <code>{pedido}</code>, <code>{valor}</code>, <code>{status}</code>, <code>{previsao}</code>, <code>{link}</code>, <code>{cliente}</code>, <code>{total}</code>, <code>{restaurante}</code>.</p></div>
  <?php foreach($eventLabels as$event=>$label):$row=$templatesByEvent[$event]??[];$message=(string)($row['message_template']??$defaults[$event]??'');?><section class="span-2" style="padding:14px;border:1px solid #ddd;border-radius:16px"><label class="checkbox"><input type="checkbox" name="event_enabled[<?=Security::e($event)?>]"<?=em_checked($row['enabled']??false)?>> <strong><?=Security::e($label)?></strong></label><label style="display:block;margin-top:10px">Mensagem<textarea name="event_message[<?=Security::e($event)?>]" rows="3" maxlength="1500" required><?=Security::e($message)?></textarea></label></section><?php endforeach;?><div class="span-2 actions"><button class="primary" type="submit">Salvar automações</button></div></form>
</section>
</div>

<aside class="settings-side">
  <section class="card"><span class="eyebrow">ARQUITETURA</span><h3>Baileys somente no Connect</h3><p class="muted">A hospedagem não executa Node.js, WPPConnect ou Baileys. O navegador também nunca conversa diretamente com o motor do WhatsApp.</p></section>
  <section class="card"><span class="eyebrow">ATENDIMENTO</span><h3>Inbox completo</h3><p class="muted">Use a Central de Atendimento para assumir conversas, responder manualmente e devolver o cliente ao fluxo automático.</p><a class="button secondary compact" href="<?=Security::e(app_url('support.php'))?>">Abrir Central</a></section>
  <section class="card"><span class="eyebrow">FILA SEGURA</span><h3>ACK e novas tentativas</h3><p class="muted">O Connect reivindica mensagens com lease, confirma o envio por ACK e o servidor controla novas tentativas sem duplicar mensagens.</p></section>
</aside></div>

<script>(()=>{const commerce=document.getElementById('commerceForm'),dirty=document.getElementById('commerceUnsaved');commerce?.querySelector('input[name="commerce_enabled"]')?.addEventListener('change',()=>{if(dirty)dirty.hidden=false});})();</script>
<script>(()=>{const endpoint=<?=$pollUrl?>,root=document.querySelector('[data-wa-panel]');if(!root)return;const label={connected:'Conectado',starting:'Iniciando',qr:'Aguardando pareamento no Connect',reconnecting:'Reconectando',error:'Precisa de atenção',disconnected:'Desconectado'},tone={connected:'success',starting:'warning',qr:'warning',reconnecting:'warning',error:'danger',disconnected:'muted'};async function refresh(){try{const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),p=await r.json();if(!r.ok||!p.ok)return;const d=p.data||{},s=d.status||'disconnected',badge=root.querySelector('[data-wa-status]');if(badge){badge.textContent=label[s]||'Desconectado';badge.className='badge status-'+(tone[s]||'muted')}const phone=root.querySelector('[data-wa-phone]');if(phone)phone.textContent=d.phone_number||'—';const last=root.querySelector('[data-wa-last]');if(last)last.textContent=d.last_seen_at||'—';const pending=root.querySelector('[data-wa-pending]');if(pending)pending.textContent=String(d.pending||0);const device=root.querySelector('[data-wa-device]');if(device)device.textContent=(d.agent&&d.agent.device_label)||'—';const err=root.querySelector('[data-wa-error]');if(err)err.textContent=d.last_error||(d.agent&&d.agent.last_error)||'';}catch(e){}}refresh();setInterval(refresh,10000);})();</script>
<?php em_footer(); ?>