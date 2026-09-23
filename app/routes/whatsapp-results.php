<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppCommerceAnalyticsService;

Auth::requirePermission('settings.manage');
$tenantId=(int)(Auth::tenantId()??0);
if($tenantId<1){http_response_code(403);exit('Selecione uma empresa para ver os resultados do WhatsApp.');}
$analytics=new WhatsAppCommerceAnalyticsService();
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    try{$saved=$analytics->saveSettings($pdo,$tenantId,['timezone'=>(string)($_POST['timezone']??'')]);Auth::audit('whatsapp.analytics_settings_saved','whatsapp_commerce_analytics_settings',(string)$tenantId,['timezone'=>$saved['timezone']]);em_flash('ok','Fuso horário dos resultados salvo.');}
    catch(Throwable$e){em_flash('error',$e->getMessage());}
    header('Location: '.app_url('whatsapp-results.php'));exit;
}
$period=strtolower(trim((string)($_GET['period']??'7d')));if(!in_array($period,['today','yesterday','7d','30d','month','custom'],true))$period='7d';
$customFrom=trim((string)($_GET['from']??''));$customTo=trim((string)($_GET['to']??''));
$report=$analytics->report($pdo,$tenantId,$period,$customFrom,$customTo);$settings=$analytics->settings($pdo,$tenantId);
$money=static fn(int$cents):string=>'R$ '.number_format($cents/100,2,',','.');
$num=static fn(int$value):string=>number_format($value,0,',','.');
$pct=static fn(float$value):string=>number_format($value,1,',','.').'%';
$duration=static function(?int$seconds):string{if($seconds===null)return'Indisponível';if($seconds<60)return$seconds.'s';if($seconds<3600)return(int)round($seconds/60).' min';return number_format($seconds/3600,1,',','.').' h';};
$funnel=$report['funnel'];$sales=$report['sales'];$recovery=$report['recovery'];$upsell=$report['upsell'];$messages=$report['messages'];$human=$report['human'];$comparison=$report['comparison'];
$funnelSteps=[
 ['Conversa',(int)$funnel['conversations'],100.0,'Conversa criada no período'],
 ['Carrinho',(int)$funnel['carts_started'],(float)($funnel['conversations']?round(((int)$funnel['carts_started']/(int)$funnel['conversations'])*100,1):0),'Pedido WhatsApp criado, inclusive rascunho'],
 ['Finalização',(int)$funnel['finalized_orders'],(float)$funnel['cart_to_order_pct'],'Pedido saiu de rascunho e não foi cancelado'],
 ['Pagamento',(int)$funnel['paid_orders'],(float)$funnel['order_to_paid_pct'],'Pagamento realmente confirmado no servidor'],
 ['Pedido concluído',(int)$funnel['completed_orders'],(float)$funnel['paid_to_completed_pct'],'Estado operacional final completed'],
];
$oldestPending=trim((string)($messages['oldest_pending']??''));
$periodLinks=['today'=>'Hoje','yesterday'=>'Ontem','7d'=>'7 dias','30d'=>'30 dias','month'=>'Este mês'];
em_header('Resultados do WhatsApp','whatsapp');
?>
<style>
.wa-result-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.wa-result-card{padding:18px;border:1px solid var(--line,#e8e5ef);border-radius:18px;background:var(--surface,#fff)}.wa-result-card span{display:block;color:#777;font-size:13px}.wa-result-card strong{display:block;font-size:26px;margin-top:5px}.wa-result-card small{display:block;margin-top:5px;color:#777}.wa-funnel{display:grid;gap:10px}.wa-funnel-row{display:grid;grid-template-columns:150px minmax(120px,1fr) 90px;gap:12px;align-items:center}.wa-funnel-track{height:13px;background:rgba(127,127,127,.15);border-radius:999px;overflow:hidden}.wa-funnel-fill{height:100%;background:currentColor;opacity:.6;border-radius:999px}.wa-split{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wa-health{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}.wa-health>div{padding:14px;border:1px solid var(--line,#e8e5ef);border-radius:14px}.wa-health strong{display:block;font-size:21px}.wa-chart{display:flex;gap:7px;align-items:flex-end;min-height:150px;padding-top:20px}.wa-chart-col{flex:1;min-width:18px;text-align:center}.wa-chart-bar{min-height:3px;background:currentColor;opacity:.5;border-radius:8px 8px 2px 2px}.wa-chart-col small{display:block;font-size:10px;margin-top:5px;color:#777;overflow:hidden}.wa-periods{display:flex;gap:7px;flex-wrap:wrap}.wa-custom{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.wa-custom label{min-width:150px}.wa-custom input,.wa-timezone select{width:100%}@media(max-width:900px){.wa-result-grid,.wa-health{grid-template-columns:repeat(2,minmax(0,1fr))}.wa-split{grid-template-columns:1fr}}@media(max-width:620px){.wa-result-grid,.wa-health{grid-template-columns:1fr}.wa-funnel-row{grid-template-columns:92px 1fr 68px}.wa-result-card strong{font-size:22px}}
</style>
<section class="page-hero">
 <div><span class="eyebrow">WHATSAPP COMMERCE</span><h2>Resultados</h2><p>Atendimento, conversão e faturamento calculados somente com dados reais desta empresa.</p></div>
 <div class="hero-actions"><a class="button primary" href="<?=Security::e(app_url('whatsapp.php'))?>">WhatsApp Commerce</a><a class="button secondary" href="<?=Security::e(app_url('support.php'))?>">Central de Atendimento</a></div>
</section>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">PERÍODO</span><h2><?=Security::e((string)$report['period']['label'])?></h2><p class="muted">Datas interpretadas em <?=Security::e((string)$report['period']['timezone'])?>. Pagamento pendente nunca entra como venda.</p></div></div>
 <div class="wa-periods"><?php foreach($periodLinks as$key=>$label):?><a class="button <?=$period===$key?'primary':'secondary'?> compact" href="<?=Security::e(app_url('whatsapp-results.php?period='.$key))?>"><?=Security::e($label)?></a><?php endforeach;?></div>
 <form method="get" class="wa-custom" style="margin-top:12px"><input type="hidden" name="period" value="custom"><label>De<input type="date" name="from" required value="<?=Security::e($customFrom)?>"></label><label>Até<input type="date" name="to" required value="<?=Security::e($customTo)?>"></label><button class="button secondary" type="submit">Aplicar período</button></form>
 <details style="margin-top:14px"><summary><strong>Fuso horário da empresa</strong></summary><form method="post" class="wa-timezone" style="max-width:440px;margin-top:12px"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><label>Fuso usado nos cortes de dia<select name="timezone"><?php foreach($settings['allowed_timezones'] as$tz):?><option value="<?=Security::e((string)$tz)?>"<?=((string)$settings['timezone']===(string)$tz?' selected':'')?>><?=Security::e((string)$tz)?></option><?php endforeach;?></select></label><button class="button primary" type="submit" style="margin-top:10px">Salvar fuso</button></form></details>
</section>
<section class="wa-result-grid">
 <div class="wa-result-card"><span>Vendas via WhatsApp</span><strong><?=$money((int)$sales['paid_sales_cents'])?></strong><small>Somente pagamentos confirmados</small></div>
 <div class="wa-result-card"><span>Pedidos pagos</span><strong><?=$num((int)$funnel['paid_orders'])?></strong><small><?=$num((int)$funnel['completed_orders'])?> já concluídos</small></div>
 <div class="wa-result-card"><span>Conversão conversa → pedido</span><strong><?=$pct((float)$funnel['conversation_to_order_pct'])?></strong><small><?=$num((int)$funnel['customers'])?> clientes atendidos</small></div>
 <div class="wa-result-card"><span>Ticket médio</span><strong><?=$money((int)$sales['ticket_average_cents'])?></strong><small>Sobre pedidos pagos</small></div>
</section>
<section class="card" style="margin-top:14px">
 <div class="section-head"><div><span class="eyebrow">COMPARAÇÃO</span><h2>Período anterior equivalente</h2></div></div>
 <?php if(empty($comparison['available'])):?><p class="muted">Ainda não há movimento suficiente no período anterior para uma comparação confiável.</p><?php else:?><div class="detail-grid"><div><span class="muted">Vendas anteriores</span><strong><?=$money((int)$comparison['paid_sales_cents'])?></strong></div><div><span class="muted">Pedidos pagos anteriores</span><strong><?=$num((int)$comparison['paid_orders'])?></strong></div><div><span class="muted">Conversas anteriores</span><strong><?=$num((int)$comparison['conversations'])?></strong></div><div><span class="muted">Variação das vendas</span><strong><?=$comparison['sales_change_pct']===null?'Indisponível':(($comparison['sales_change_pct']>0?'+':'').$pct((float)$comparison['sales_change_pct']))?></strong></div></div><?php endif;?>
</section>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">FUNIL</span><h2>Da conversa ao pedido concluído</h2><p class="muted">O percentual de cada linha compara a etapa com a etapa anterior. As definições abaixo são usadas em todos os cálculos.</p></div></div>
 <div class="wa-funnel"><?php foreach($funnelSteps as$i=>$step):$width=$i===0?100:max(2,min(100,(float)$step[2]));?><div class="wa-funnel-row" title="<?=Security::e($step[3])?>"><strong><?=Security::e($step[0])?></strong><div class="wa-funnel-track"><div class="wa-funnel-fill" style="width:<?=$width?>%"></div></div><div><strong><?=$num($step[1])?></strong><small class="muted"><?=$i===0?'base':$pct((float)$step[2])?></small></div></div><?php endforeach;?></div>
 <div class="alert" style="margin-top:16px"><strong>Definições:</strong> carrinho = pedido WhatsApp criado; finalização = pedido que saiu de rascunho e não foi cancelado; pagamento = <code>payment_status=paid</code>; concluído = <code>status=completed</code>.</div>
</section>
<div class="wa-split">
<section class="card">
 <div class="section-head"><div><span class="eyebrow">VENDER MAIS</span><h2>Carrinho abandonado</h2></div></div>
 <div class="detail-grid"><div><span class="muted">Carrinhos abandonados</span><strong><?=$num((int)$recovery['abandoned'])?></strong></div><div><span class="muted">Lembretes confirmados pelo Connect</span><strong><?=$num((int)$recovery['reminders_sent'])?></strong></div><div><span class="muted">Clientes que voltaram</span><strong><?=$num((int)$recovery['customers_returned'])?></strong></div><div><span class="muted">Carrinhos recuperados</span><strong><?=$num((int)$recovery['recovered'])?></strong><small><?=$pct((float)$recovery['recovery_pct'])?></small></div><div><span class="muted">Pedidos recuperados pagos</span><strong><?=$num((int)$recovery['recovered_paid_orders'])?></strong></div><div><span class="muted">Valor recuperado pago</span><strong><?=$money((int)$recovery['recovered_paid_sales_cents'])?></strong></div></div>
 <p class="muted">Atribuição objetiva: o mesmo <strong>order_id</strong> recebeu o lembrete e depois saiu de rascunho. Cada carrinho é contado uma única vez.</p>
</section>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">VENDER MAIS</span><h2>Repetição e upsell</h2></div></div>
 <div class="detail-grid"><div><span class="muted">Repetir pedido usado</span><strong><?=$num((int)$sales['repeat_started'])?></strong></div><div><span class="muted">Repetidos finalizados</span><strong><?=$num((int)$sales['repeat_finalized'])?></strong></div><div><span class="muted">Repetidos concluídos</span><strong><?=$num((int)$sales['repeat_completed'])?></strong></div><div><span class="muted">Vendas pagas por repetição</span><strong><?=$money((int)$sales['repeat_paid_sales_cents'])?></strong></div><div><span class="muted">Upsells oferecidos</span><strong><?=$num((int)$upsell['offers'])?></strong></div><div><span class="muted">Upsells aceitos</span><strong><?=$num((int)$upsell['accepted_items'])?></strong><small><?=$pct((float)$upsell['acceptance_pct'])?> por pedido ofertado</small></div><div><span class="muted">Valor extra pago</span><strong><?=$money((int)$upsell['paid_value_cents'])?></strong></div></div>
 <?php if(!empty($upsell['top_products'])):?><div class="table-wrap" style="margin-top:14px"><table class="table"><thead><tr><th>Produto de upsell</th><th>Aceites</th><th>Valor pago</th></tr></thead><tbody><?php foreach($upsell['top_products'] as$row):?><tr><td><?=Security::e((string)$row['product_name'])?></td><td><?=$num((int)$row['accepted'])?></td><td><?=$money((int)$row['paid_value_cents'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
</div>
<div class="wa-split">
<section class="card">
 <div class="section-head"><div><span class="eyebrow">ATENDIMENTO</span><h2>Automação e humano</h2></div></div>
 <div class="detail-grid"><div><span class="muted">Conversas iniciadas</span><strong><?=$num((int)$funnel['conversations'])?></strong></div><div><span class="muted">Mensagens recebidas</span><strong><?=$num((int)$messages['inbound'])?></strong></div><div><span class="muted">Transferidas para humano</span><strong><?=$num((int)$human['transferred'])?></strong></div><div><span class="muted">Atendimentos assumidos</span><strong><?=$num((int)$human['assumed'])?></strong></div><div><span class="muted">Mensagens manuais</span><strong><?=$num((int)$human['manual_messages'])?></strong></div><div><span class="muted">Tempo médio até assumir</span><strong><?=$duration($human['average_wait_seconds'])?></strong></div></div>
 <p class="muted">As métricas humanas usam as auditorias já existentes da Central de Atendimento e não alteram o bloqueio da automação quando um atendente assume.</p>
</section>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">SAÚDE DO WHATSAPP</span><h2>Fila de mensagens</h2></div></div>
 <div class="wa-health"><div><span class="muted">Aguardando</span><strong><?=$num((int)$messages['waiting'])?></strong></div><div><span class="muted">Processando</span><strong><?=$num((int)$messages['processing'])?></strong></div><div><span class="muted">Nova tentativa</span><strong><?=$num((int)$messages['retrying'])?></strong></div><div><span class="muted">Enviadas</span><strong><?=$num((int)$messages['sent'])?></strong></div><div><span class="muted">Falhas finais</span><strong><?=$num((int)$messages['failed'])?></strong></div></div>
 <div class="detail-grid" style="margin-top:14px"><div><span class="muted">Tentativas realizadas</span><strong><?=$num((int)$messages['attempts'])?></strong></div><div><span class="muted">Pendência mais antiga</span><strong><?=Security::e($oldestPending!==''?$oldestPending:'Nenhuma')?></strong></div><div><span class="muted">Atendimentos humanos abertos agora</span><strong><?=$num((int)$messages['human_open'])?></strong></div></div>
 <p class="muted">“Enviada” significa ACK de envio pelo EventMenu Connect. Não é apresentada como “entregue” ou “lida” pelo destinatário sem confirmação real do provedor.</p>
</section>
</div>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">EVOLUÇÃO</span><h2>Vendas pagas por dia</h2></div></div>
 <?php if(empty($report['daily'])):?><p class="muted">Sem movimento no período selecionado.</p><?php else:$maxDaily=1;foreach($report['daily'] as$r)$maxDaily=max($maxDaily,(int)$r['paid_sales_cents']);?><div class="wa-chart"><?php foreach($report['daily'] as$row):$height=max(3,(int)round(((int)$row['paid_sales_cents']/$maxDaily)*120));?><div class="wa-chart-col" title="<?=$money((int)$row['paid_sales_cents'])?> · <?=$num((int)$row['orders_count'])?> pedidos"><div class="wa-chart-bar" style="height:<?=$height?>px"></div><small><?=Security::e(substr((string)$row['day'],5))?></small></div><?php endforeach;?></div><?php endif;?>
</section>
<section class="card">
 <div class="section-head"><div><span class="eyebrow">PRODUTOS</span><h2>Mais vendidos pelo WhatsApp</h2></div></div>
 <?php if(empty($report['top_products'])):?><p class="muted">Ainda não há pedidos finalizados no período.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>Quantidade</th><th>Valor nos pedidos</th></tr></thead><tbody><?php foreach($report['top_products'] as$row):?><tr><td><?=Security::e((string)$row['name'])?></td><td><?=Security::e(number_format((float)$row['quantity'],2,',','.'))?></td><td><?=$money((int)$row['total_cents'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<?php if(!empty($report['analytics_since'])):?><p class="muted">Métricas dedicadas de repetição e upsell existem a partir de <?=Security::e((string)$report['analytics_since'])?>. O sistema não fabrica histórico anterior.</p><?php endif;?>
<?php em_footer(); ?>
