<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppCommerceAssistantService;
use EventMenu\Services\WhatsAppCommerceConversionService;
use EventMenu\Services\WhatsAppIntegrationService;

Auth::requirePermission('settings.manage');
$tenantId=(int)(Auth::tenantId()??0);
if($tenantId<1){http_response_code(403);exit('Selecione uma empresa para configurar o WhatsApp.');}

$service=new WhatsAppIntegrationService();
$assistantService=new WhatsAppCommerceAssistantService();
$conversionService=new WhatsAppCommerceConversionService();
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
    em_post_csrf();$action=(string)($_POST['action']??'');$anchor='';
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
        }elseif($action==='save_conversion'){
            $saved=$conversionService->saveSettings($pdo,$tenantId,[
                'repeat_last_order_enabled'=>isset($_POST['repeat_last_order_enabled']),
                'upsell_enabled'=>isset($_POST['upsell_enabled']),
                'upsell_max_suggestions'=>(int)($_POST['upsell_max_suggestions']??3),
                'abandoned_cart_enabled'=>isset($_POST['abandoned_cart_enabled']),
                'abandoned_delay_minutes'=>(int)($_POST['abandoned_delay_minutes']??60),
            ]);
            Auth::audit('whatsapp.conversion_settings_saved','whatsapp_commerce_conversion_settings',(string)$tenantId,[
                'repeat_last_order_enabled'=>(int)($saved['repeat_last_order_enabled']??0)===1,
                'upsell_enabled'=>(int)($saved['upsell_enabled']??0)===1,
                'upsell_max_suggestions'=>(int)($saved['upsell_max_suggestions']??3),
                'abandoned_cart_enabled'=>(int)($saved['abandoned_cart_enabled']??0)===1,
                'abandoned_delay_minutes'=>(int)($saved['abandoned_delay_minutes']??60),
            ]);
            em_flash('ok','Recursos de venda do WhatsApp Commerce salvos.');$anchor='#whatsapp-sales';
        }elseif($action==='save_assistant'){
            $saved=$assistantService->saveSettings($pdo,$tenantId,[
                'enabled'=>isset($_POST['assistant_enabled']),
                'knowledge_enabled'=>isset($_POST['knowledge_enabled']),
                'assistant_name'=>(string)($_POST['assistant_name']??''),
                'tone'=>(string)($_POST['tone']??'friendly'),
                'greeting_message'=>(string)($_POST['greeting_message']??''),
                'unknown_behavior'=>(string)($_POST['unknown_behavior']??'menu'),
                'unknown_message'=>(string)($_POST['unknown_message']??''),
                'handoff_message'=>(string)($_POST['handoff_message']??''),
                'handoff_keywords'=>(string)($_POST['handoff_keywords']??''),
            ]);
            Auth::audit('whatsapp.assistant_settings_saved','whatsapp_assistant_settings',(string)$tenantId,['enabled'=>(int)($saved['enabled']??0)===1,'knowledge_enabled'=>(int)($saved['knowledge_enabled']??0)===1,'unknown_behavior'=>(string)($saved['unknown_behavior']??'menu')]);
            em_flash('ok','Configurações do Assistente IA salvas.');$anchor='#whatsapp-ai';
        }elseif($action==='save_knowledge'){
            $knowledgeId=$assistantService->saveKnowledge($pdo,$tenantId,[
                'id'=>(int)($_POST['knowledge_id']??0),
                'title'=>(string)($_POST['knowledge_title']??''),
                'answer'=>(string)($_POST['knowledge_answer']??''),
                'keywords'=>(string)($_POST['knowledge_keywords']??''),
                'enabled'=>isset($_POST['knowledge_item_enabled']),
                'sort_order'=>(int)($_POST['knowledge_sort_order']??0),
            ]);
            Auth::audit('whatsapp.assistant_knowledge_saved','whatsapp_assistant_knowledge',(string)$knowledgeId,[]);em_flash('ok','Informação salva na Base da IA.');$anchor='#knowledge-base';
        }elseif($action==='delete_knowledge'){
            $knowledgeId=(int)($_POST['knowledge_id']??0);$assistantService->deleteKnowledge($pdo,$tenantId,$knowledgeId);Auth::audit('whatsapp.assistant_knowledge_deleted','whatsapp_assistant_knowledge',(string)$knowledgeId,[]);em_flash('ok','Informação removida da Base da IA.');$anchor='#knowledge-base';
        }else{
            throw new RuntimeException('Ação de WhatsApp não permitida no servidor. Use o EventMenu Connect para parear, reconectar ou trocar o número.');
        }
    }catch(Throwable$e){em_flash('error',$e->getMessage());if($action==='save_conversion')$anchor='#whatsapp-sales';elseif(in_array($action,['save_assistant','save_knowledge','delete_knowledge'],true))$anchor='#whatsapp-ai';}
    header('Location: '.app_url('whatsapp.php').$anchor);exit;
}

$connection=$service->status($pdo,$tenantId,false);
$templates=$service->templates($pdo,$tenantId);$templatesByEvent=[];foreach($templates as$row)$templatesByEvent[(string)$row['event_type']]=$row;$defaults=$service->defaultTemplates();
$status=(string)($connection['status']??'disconnected');$agent=is_array($connection['agent']??null)?$connection['agent']:[];
$commerceStmt=$pdo->prepare('SELECT commerce_enabled FROM whatsapp_connections WHERE tenant_id=? LIMIT 1');$commerceStmt->execute([$tenantId]);$commerceEnabled=(int)($commerceStmt->fetchColumn()?:0)===1;
$conversionSettings=$conversionService->settings($pdo,$tenantId);
$assistantSettings=$assistantService->settings($pdo,$tenantId);$knowledgeItems=$assistantService->knowledge($pdo,$tenantId,false);
$statusLabel=match($status){'connected'=>'Conectado','starting'=>'Iniciando','qr'=>'Aguardando pareamento no Connect','reconnecting'=>'Reconectando','error'=>'Precisa de atenção',default=>'Desconectado'};
$statusTone=match($status){'connected'=>'success','starting','qr','reconnecting'=>'warning','error'=>'danger',default=>'muted'};
$pollUrl=json_encode(app_url('whatsapp.php?ajax=status'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

em_header('WhatsApp','whatsapp');
?>
<section class="page-hero">
  <div><span class="eyebrow">EVENTMENU CONNECT</span><h2>WhatsApp da empresa</h2><p>O servidor organiza conversas, pedidos, respostas e a Base da IA. Pareamento, sessão, reconexão e envio real ficam exclusivamente no aplicativo EventMenu Connect.</p></div>
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
  <p class="muted">Quando ativo, mensagens recebidas pelo EventMenu Connect entram no servidor, seguem o fluxo de cardápio/pedido e as respostas voltam pela mesma fila segura.</p>
  <form method="post" class="form-grid" id="commerceForm"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save_commerce"><label class="checkbox span-2"><input type="checkbox" name="commerce_enabled"<?=em_checked($commerceEnabled)?>> Ativar WhatsApp Commerce nesta empresa</label><div class="span-2"><span class="badge status-warning" id="commerceUnsaved" hidden>Alteração não salva</span></div><div class="span-2 actions"><button class="primary" type="submit">Salvar WhatsApp Commerce</button></div></form>
</section>

<section class="card" id="whatsapp-sales">
  <div class="section-head"><div><span class="eyebrow">VENDER MAIS</span><h2>Recursos para aumentar os pedidos</h2><p class="muted">Ative só o que fizer sentido para sua operação. Esses recursos usam o catálogo, os preços e o histórico real do EventMenu.</p></div></div>
  <?php if(!$commerceEnabled):?><div class="alert"><strong>WhatsApp Commerce está pausado.</strong> Estas preferências ficam salvas, mas só entram em ação quando o WhatsApp Commerce estiver ativo.</div><?php endif;?>
  <form method="post" class="form-grid" style="margin-top:16px"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save_conversion">
    <section class="span-2" style="padding:14px;border:1px solid #ddd;border-radius:16px">
      <label class="checkbox"><input type="checkbox" name="repeat_last_order_enabled"<?=em_checked((int)($conversionSettings['repeat_last_order_enabled']??0)===1)?>> <strong>Permitir repetir o último pedido</strong></label>
      <p class="muted" style="margin:8px 0 0">O cliente pode escolher “Repetir último pedido”. O carrinho é recriado com os preços atuais e itens indisponíveis não são forçados.</p>
    </section>
    <section class="span-2" style="padding:14px;border:1px solid #ddd;border-radius:16px">
      <label class="checkbox"><input type="checkbox" name="upsell_enabled"<?=em_checked((int)($conversionSettings['upsell_enabled']??0)===1)?>> <strong>Sugerir itens antes de finalizar</strong></label>
      <p class="muted" style="margin:8px 0 12px">Antes de escolher entrega ou retirada, o EventMenu pode sugerir produtos que combinam com o carrinho usando o histórico real de pedidos.</p>
      <label style="max-width:280px">Máximo de sugestões<input type="number" name="upsell_max_suggestions" min="1" max="5" value="<?=max(1,min(5,(int)($conversionSettings['upsell_max_suggestions']??3)))?>"></label>
    </section>
    <section class="span-2" style="padding:14px;border:1px solid #ddd;border-radius:16px">
      <label class="checkbox"><input type="checkbox" name="abandoned_cart_enabled"<?=em_checked((int)($conversionSettings['abandoned_cart_enabled']??0)===1)?>> <strong>Lembrar o cliente do carrinho não finalizado</strong></label>
      <p class="muted" style="margin:8px 0 12px">Se o cliente parar no meio do pedido, o EventMenu pode enviar um único lembrete para ele continuar de onde parou.</p>
      <label style="max-width:280px">Esperar quantos minutos<input type="number" name="abandoned_delay_minutes" min="15" max="1440" value="<?=max(15,min(1440,(int)($conversionSettings['abandoned_delay_minutes']??60)))?>"><small class="muted">Mínimo de 15 minutos. O lembrete só é usado em carrinhos recentes.</small></label>
    </section>
    <div class="span-2 alert"><strong>Segurança dos pedidos:</strong> preços e disponibilidade são verificados novamente antes de adicionar itens. Estes recursos não confirmam pagamento e não alteram o PIX.</div>
    <div class="span-2 actions"><button class="primary" type="submit">Salvar recursos de venda</button></div>
  </form>
</section>

<section class="card" id="whatsapp-ai">
  <div class="section-head"><div><span class="eyebrow">ASSISTENTE IA</span><h2>Como o atendimento deve conversar</h2><p class="muted">O assistente usa o fluxo real do EventMenu para produtos, preços, pedido e pagamento e usa a Base da IA abaixo para informações da empresa. Se não encontrar informação confiável, ele não inventa.</p></div><span class="badge status-<?=(int)($assistantSettings['enabled']??0)===1?'success':'muted'?>"><?=(int)($assistantSettings['enabled']??0)===1?'Ativo':'Pausado'?></span></div>
  <div class="alert"><strong>Segurança:</strong> a Base da IA serve para conversa e dúvidas. Preços, estoque, total do pedido, PIX e confirmação de pagamento continuam vindo exclusivamente dos serviços reais do EventMenu.</div>
  <form method="post" class="form-grid" style="margin-top:16px"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save_assistant">
    <label class="checkbox span-2"><input type="checkbox" name="assistant_enabled"<?=em_checked((int)($assistantSettings['enabled']??0)===1)?>> Ativar Assistente IA no WhatsApp Commerce</label>
    <label class="checkbox span-2"><input type="checkbox" name="knowledge_enabled"<?=em_checked((int)($assistantSettings['knowledge_enabled']??0)===1)?>> Permitir respostas usando a Base da IA</label>
    <label>Nome do assistente<input type="text" name="assistant_name" maxlength="80" value="<?=Security::e((string)($assistantSettings['assistant_name']??'Assistente EventMenu'))?>" placeholder="Ex.: Bia"></label>
    <label>Estilo do atendimento<select name="tone"><option value="friendly"<?=((string)($assistantSettings['tone']??''))==='friendly'?' selected':''?>>Amigável</option><option value="professional"<?=((string)($assistantSettings['tone']??''))==='professional'?' selected':''?>>Profissional</option><option value="direct"<?=((string)($assistantSettings['tone']??''))==='direct'?' selected':''?>>Direto</option><option value="casual"<?=((string)($assistantSettings['tone']??''))==='casual'?' selected':''?>>Descontraído</option></select></label>
    <label class="span-2">Mensagem de boas-vindas<textarea name="greeting_message" rows="3" maxlength="1500"><?=Security::e((string)($assistantSettings['greeting_message']??''))?></textarea><small class="muted">Variáveis: <code>{cliente}</code>, <code>{empresa}</code> e <code>{assistente}</code>. As opções de pedido são acrescentadas pelo sistema.</small></label>
    <label>Quando não souber responder<select name="unknown_behavior"><option value="menu"<?=((string)($assistantSettings['unknown_behavior']??'menu'))==='menu'?' selected':''?>>Não inventar e mostrar o menu</option><option value="human"<?=((string)($assistantSettings['unknown_behavior']??''))==='human'?' selected':''?>>Transferir para atendimento humano</option></select></label>
    <label>Palavras para chamar atendente<input type="text" name="handoff_keywords" maxlength="1500" value="<?=Security::e((string)($assistantSettings['handoff_keywords']??''))?>" placeholder="atendente, humano, falar com gerente"></label>
    <label class="span-2">Mensagem quando não houver informação segura<textarea name="unknown_message" rows="3" maxlength="1500"><?=Security::e((string)($assistantSettings['unknown_message']??''))?></textarea></label>
    <label class="span-2">Mensagem ao transferir para uma pessoa<textarea name="handoff_message" rows="3" maxlength="1200"><?=Security::e((string)($assistantSettings['handoff_message']??''))?></textarea></label>
    <div class="span-2 actions"><button class="primary" type="submit">Salvar Assistente IA</button></div>
  </form>
</section>

<section class="card" id="knowledge-base">
  <div class="section-head"><div><span class="eyebrow">BASE DA IA</span><h2>Conhecimento da empresa</h2><p class="muted">Cadastre fatos e respostas que não estão no cardápio ou no pedido: entrega em bairros, estacionamento, encomendas, políticas, horários especiais e outras dúvidas frequentes.</p></div><span class="badge status-muted"><?=count($knowledgeItems)?> item(ns)</span></div>
  <section style="padding:14px;border:1px solid #ddd;border-radius:16px;margin-bottom:16px"><h3>Adicionar informação</h3><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save_knowledge"><input type="hidden" name="knowledge_id" value="0"><label class="span-2">Pergunta ou assunto<input type="text" name="knowledge_title" maxlength="180" required placeholder="Ex.: Vocês têm estacionamento?"></label><label class="span-2">Resposta<textarea name="knowledge_answer" rows="4" maxlength="3000" required placeholder="Ex.: Sim. Temos estacionamento gratuito ao lado da entrada principal."></textarea></label><label>Palavras-chave<input type="text" name="knowledge_keywords" maxlength="1500" placeholder="estacionamento, carro, vaga"></label><label>Prioridade<input type="number" name="knowledge_sort_order" min="0" max="9999" value="0"></label><label class="checkbox span-2"><input type="checkbox" name="knowledge_item_enabled" checked> Informação ativa</label><div class="span-2 actions"><button class="primary" type="submit">Adicionar à Base da IA</button></div></form></section>
  <?php if(!$knowledgeItems):?><p class="muted">A Base da IA ainda está vazia. O WhatsApp Commerce continua usando normalmente os dados reais do EventMenu e não inventará informações que não conhece.</p><?php endif;?>
  <?php foreach($knowledgeItems as$item):?>
    <section style="padding:14px;border:1px solid #ddd;border-radius:16px;margin-top:12px"><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="knowledge_id" value="<?=(int)$item['id']?>"><label class="span-2">Pergunta ou assunto<input type="text" name="knowledge_title" maxlength="180" required value="<?=Security::e((string)$item['title'])?>"></label><label class="span-2">Resposta<textarea name="knowledge_answer" rows="4" maxlength="3000" required><?=Security::e((string)$item['answer'])?></textarea></label><label>Palavras-chave<input type="text" name="knowledge_keywords" maxlength="1500" value="<?=Security::e((string)($item['keywords']??''))?>"></label><label>Prioridade<input type="number" name="knowledge_sort_order" min="0" max="9999" value="<?=(int)($item['sort_order']??0)?>"></label><label class="checkbox span-2"><input type="checkbox" name="knowledge_item_enabled"<?=em_checked((int)($item['enabled']??0)===1)?>> Informação ativa</label><div class="span-2 actions"><button class="primary" type="submit" name="action" value="save_knowledge">Salvar</button><button class="button secondary" type="submit" name="action" value="delete_knowledge" onclick="return confirm('Remover esta informação da Base da IA?')">Excluir</button></div></form></section>
  <?php endforeach;?>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">AUTOMAÇÕES</span><h2>Mensagens por etapa do pedido</h2><p class="muted">Todas as mensagens abaixo são apenas enfileiradas no servidor e enviadas pelo EventMenu Connect.</p></div></div>
  <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save"><label class="checkbox span-2"><input type="checkbox" name="automation_enabled"<?=em_checked($connection['automation_enabled']??false)?>> Ativar mensagens automáticas desta empresa</label><div class="span-2"><p class="muted">Variáveis: <code>{nome}</code>, <code>{pedido}</code>, <code>{valor}</code>, <code>{status}</code>, <code>{previsao}</code>, <code>{link}</code>, <code>{cliente}</code>, <code>{total}</code>, <code>{restaurante}</code>.</p></div>
  <?php foreach($eventLabels as$event=>$label):$row=$templatesByEvent[$event]??[];$message=(string)($row['message_template']??$defaults[$event]??'');?><section class="span-2" style="padding:14px;border:1px solid #ddd;border-radius:16px"><label class="checkbox"><input type="checkbox" name="event_enabled[<?=Security::e($event)?>]"<?=em_checked($row['enabled']??false)?>> <strong><?=Security::e($label)?></strong></label><label style="display:block;margin-top:10px">Mensagem<textarea name="event_message[<?=Security::e($event)?>]" rows="3" maxlength="1500" required><?=Security::e($message)?></textarea></label></section><?php endforeach;?><div class="span-2 actions"><button class="primary" type="submit">Salvar automações</button></div></form>
</section>
</div>

<aside class="settings-side">
  <section class="card"><span class="eyebrow">VENDAS</span><h3>Conversão no WhatsApp</h3><p class="muted">Controle repetição de pedidos, sugestões de itens e lembrete de carrinho não finalizado.</p><a class="button secondary compact" href="#whatsapp-sales">Configurar vendas</a></section>
  <section class="card"><span class="eyebrow">ASSISTENTE</span><h3>IA sem inventar dados</h3><p class="muted">Perguntas da empresa usam a Base da IA. Pedido, catálogo, valores, estoque e pagamentos continuam consultando o EventMenu como fonte da verdade.</p><a class="button secondary compact" href="#whatsapp-ai">Configurar IA</a></section>
  <section class="card"><span class="eyebrow">ATENDIMENTO</span><h3>Inbox completo</h3><p class="muted">Use a Central de Atendimento para assumir conversas, responder manualmente e devolver o cliente ao fluxo automático.</p><a class="button secondary compact" href="<?=Security::e(app_url('support.php'))?>">Abrir Central</a></section>
  <section class="card"><span class="eyebrow">ARQUITETURA</span><h3>Connect só transporta</h3><p class="muted">O aplicativo mantém a sessão do WhatsApp. Configurações, conhecimento, pedidos e regras ficam centralizados no EventMenu Server.</p></section>
</aside></div>

<script>(()=>{const commerce=document.getElementById('commerceForm'),dirty=document.getElementById('commerceUnsaved');commerce?.querySelector('input[name="commerce_enabled"]')?.addEventListener('change',()=>{if(dirty)dirty.hidden=false});})();</script>
<script>(()=>{const endpoint=<?=$pollUrl?>,root=document.querySelector('[data-wa-panel]');if(!root)return;const label={connected:'Conectado',starting:'Iniciando',qr:'Aguardando pareamento no Connect',reconnecting:'Reconectando',error:'Precisa de atenção',disconnected:'Desconectado'},tone={connected:'success',starting:'warning',qr:'warning',reconnecting:'warning',error:'danger',disconnected:'muted'};async function refresh(){try{const r=await fetch(endpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),p=await r.json();if(!r.ok||!p.ok)return;const d=p.data||{},s=d.status||'disconnected',badge=root.querySelector('[data-wa-status]');if(badge){badge.textContent=label[s]||'Desconectado';badge.className='badge status-'+(tone[s]||'muted')}const phone=root.querySelector('[data-wa-phone]');if(phone)phone.textContent=d.phone_number||'—';const last=root.querySelector('[data-wa-last]');if(last)last.textContent=d.last_seen_at||'—';const pending=root.querySelector('[data-wa-pending]');if(pending)pending.textContent=String(d.pending||0);const device=root.querySelector('[data-wa-device]');if(device)device.textContent=(d.agent&&d.agent.device_label)||'—';const err=root.querySelector('[data-wa-error]');if(err)err.textContent=d.last_error||(d.agent&&d.agent.last_error)||'';}catch(e){}}refresh();setInterval(refresh,10000);})();</script>
<?php em_footer(); ?>