<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\WhatsAppCommerceAnalyticsService;

Auth::requirePermission('settings.manage');
$tenantId=(int)(Auth::tenantId()??0);
if($tenantId<1){http_response_code(403);exit('Selecione uma empresa para ver os resultados do WhatsApp.');}

$days=(int)($_GET['days']??7);if(!in_array($days,[1,7,30,90],true))$days=7;
$analytics=new WhatsAppCommerceAnalyticsService();$report=$analytics->report($pdo,$tenantId,$days);
$money=static fn(int$cents):string=>'R$ '.number_format($cents/100,2,',','.');
$num=static fn(int$value):string=>number_format($value,0,',','.');
$pct=static fn(float$value):string=>number_format($value,1,',','.').'%';
$funnel=$report['funnel'];$sales=$report['sales'];$recovery=$report['recovery'];$upsell=$report['upsell'];$messages=$report['messages'];
$funnelMax=max(1,(int)$funnel['conversations'],(int)$funnel['carts_started'],(int)$funnel['orders_placed'],(int)$funnel['paid_orders']);
$periodLabel=match($days){1=>'Últimas 24 horas',7=>'Últimos 7 dias',30=>'Últimos 30 dias',90=>'Últimos 90 dias',default=>'Últimos 7 dias'};

em_header('Resultados do WhatsApp','whatsapp');
?>
<section class="page-hero">
  <div><span class="eyebrow">WHATSAPP COMMERCE</span><h2>Resultados e conversão</h2><p>Acompanhe pedidos, vendas confirmadas, recuperação de carrinhos, repetição de pedidos, sugestões aceitas e saúde da fila do WhatsApp.</p></div>
  <div class="hero-actions"><a class="button primary" href="<?=Security::e(app_url('whatsapp.php'))?>">Configurar WhatsApp</a><a class="button secondary" href="<?=Security::e(app_url('support.php'))?>">Central de Atendimento</a></div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">PERÍODO</span><h2><?=Security::e($periodLabel)?></h2><p class="muted">As vendas exibidas abaixo só consideram pagamento realmente confirmado no EventMenu.</p></div></div>
  <div class="actions">
    <?php foreach([1=>'24h',7=>'7 dias',30=>'30 dias',90=>'90 dias'] as$d=>$label):?><a class="button <?=$days===$d?'primary':'secondary'?> compact" href="<?=Security::e(app_url('whatsapp-results.php?days='.$d))?>"><?=Security::e($label)?></a><?php endforeach;?>
  </div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">RESUMO</span><h2>Quanto o WhatsApp está vendendo</h2></div></div>
  <div class="detail-grid">
    <div><span class="muted">Vendas pagas</span><strong><?=$money((int)$sales['paid_sales_cents'])?></strong></div>
    <div><span class="muted">Pedidos pagos</span><strong><?=$num((int)$funnel['paid_orders'])?></strong></div>
    <div><span class="muted">Ticket médio</span><strong><?=$money((int)$sales['ticket_average_cents'])?></strong></div>
    <div><span class="muted">Conversão conversa → pedido</span><strong><?=$pct((float)$funnel['conversation_to_order_pct'])?></strong></div>
  </div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">FUNIL</span><h2>Da conversa até o pagamento</h2><p class="muted">O funil usa dados reais de conversas e pedidos com origem WhatsApp.</p></div></div>
  <?php foreach([
    ['Conversas iniciadas',(int)$funnel['conversations']],
    ['Carrinhos iniciados',(int)$funnel['carts_started']],
    ['Pedidos finalizados',(int)$funnel['orders_placed']],
    ['Pedidos pagos',(int)$funnel['paid_orders']],
  ] as$step):$width=max(3,(int)round(($step[1]/$funnelMax)*100));?>
    <div style="margin:14px 0"><div class="section-head" style="margin-bottom:6px"><strong><?=Security::e($step[0])?></strong><strong><?=$num($step[1])?></strong></div><div style="height:10px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden"><div style="height:100%;width:<?=$width?>%;background:currentColor;opacity:.55"></div></div></div>
  <?php endforeach;?>
  <div class="detail-grid" style="margin-top:18px">
    <div><span class="muted">Carrinho → pedido</span><strong><?=$pct((float)$funnel['cart_to_order_pct'])?></strong></div>
    <div><span class="muted">Pedido → pagamento</span><strong><?=$pct((float)$funnel['order_to_paid_pct'])?></strong></div>
  </div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">VENDER MAIS</span><h2>Recuperação, repetição e sugestões</h2></div></div>
  <div class="detail-grid">
    <div><span class="muted">Lembretes de carrinho</span><strong><?=$num((int)$recovery['reminders'])?></strong></div>
    <div><span class="muted">Carrinhos recuperados</span><strong><?=$num((int)$recovery['recovered'])?></strong><small class="muted"><?=$pct((float)$recovery['recovery_pct'])?> de recuperação</small></div>
    <div><span class="muted">Carrinhos ainda abertos</span><strong><?=$num((int)$recovery['open_drafts'])?></strong></div>
    <div><span class="muted">Pedidos repetidos iniciados</span><strong><?=$num((int)$sales['repeat_started'])?></strong><small class="muted"><?=$num((int)$sales['repeat_finalized'])?> chegaram à finalização</small></div>
    <div><span class="muted">Pedidos com sugestão aceita</span><strong><?=$num((int)$upsell['orders_with_upsell'])?></strong><small class="muted"><?=$pct((float)$upsell['acceptance_pct'])?> dos pedidos que receberam sugestão</small></div>
    <div><span class="muted">Valor extra confirmado por sugestões</span><strong><?=$money((int)$upsell['paid_value_cents'])?></strong><small class="muted">Somente pedidos pagos</small></div>
  </div>
  <div class="alert" style="margin-top:16px"><strong>Medição precisa:</strong> repetição e valor de itens sugeridos passam a ser registrados de forma dedicada a partir desta versão. Não há estimativa retroativa para não inventar resultado.</div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">MENSAGENS</span><h2>Saúde do canal</h2></div></div>
  <div class="detail-grid">
    <div><span class="muted">Mensagens recebidas</span><strong><?=$num((int)$messages['inbound'])?></strong></div>
    <div><span class="muted">Mensagens enviadas</span><strong><?=$num((int)$messages['sent'])?></strong></div>
    <div><span class="muted">Aguardando envio</span><strong><?=$num((int)$messages['pending'])?></strong></div>
    <div><span class="muted">Falhas após todas as tentativas</span><strong><?=$num((int)$messages['failed'])?></strong></div>
    <div><span class="muted">Atendimentos humanos abertos agora</span><strong><?=$num((int)$messages['human_open'])?></strong></div>
  </div>
  <p class="muted" style="margin-top:12px">“Enviada” significa que o EventMenu Connect confirmou o envio ao WhatsApp. O painel não afirma leitura pelo destinatário sem confirmação real do provedor.</p>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">PRODUTOS</span><h2>Mais vendidos pelo WhatsApp</h2></div></div>
  <?php if(empty($report['top_products'])):?><p class="muted">Ainda não há pedidos finalizados no período selecionado.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>Quantidade</th><th>Valor nos pedidos</th></tr></thead><tbody><?php foreach($report['top_products'] as$row):?><tr><td><?=Security::e((string)$row['name'])?></td><td><?=Security::e(number_format((float)$row['quantity'],2,',','.'))?></td><td><?=$money((int)$row['total_cents'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">EVOLUÇÃO</span><h2>Pedidos recentes</h2><p class="muted">Até 14 dias com movimento dentro do período selecionado.</p></div></div>
  <?php if(empty($report['daily'])):?><p class="muted">Sem movimento suficiente para exibir evolução.</p><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Dia</th><th>Pedidos</th><th>Vendas pagas</th></tr></thead><tbody><?php foreach($report['daily'] as$row):?><tr><td><?=Security::e((string)$row['day'])?></td><td><?=$num((int)$row['orders_count'])?></td><td><?=$money((int)$row['paid_sales_cents'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<?php em_footer(); ?>
