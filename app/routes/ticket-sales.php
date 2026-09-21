<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\TicketCounterSaleService;
use EventMenu\Support\OperationalDiagnostics;

Auth::requirePermission('tickets.manage');
$tenantId=em_require_tenant();$sale=null;
$eventId=(int)($_GET['event_id']??$_POST['event_id']??0);
$events=$pdo->prepare('SELECT id,name,status,starts_at,capacity_total FROM events WHERE tenant_id=? AND status IN ("published","draft") ORDER BY starts_at DESC');$events->execute([$tenantId]);$events=$events->fetchAll();
$selected=null;foreach($events as$e)if((int)$e['id']===$eventId){$selected=$e;break;}
$batches=[];if($selected){$s=$pdo->prepare('SELECT b.id,b.name,b.price_cents,b.quantity_total,b.quantity_sold,b.quantity_reserved,tt.name ticket_type_name FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.event_id=? AND b.active=1 ORDER BY b.id');$s->execute([$eventId]);$batches=$s->fetchAll();}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    try{
        $sale=(new TicketCounterSaleService())->sell($eventId,(int)($_POST['batch_id']??0),(int)($_POST['quantity']??1),(string)($_POST['payment_method']??''),['name'=>$_POST['buyer_name']??'','email'=>$_POST['buyer_email']??'','phone'=>$_POST['buyer_phone']??'']);
        em_flash('ok','Venda registrada. '.count($sale['tickets']).' ingresso(s) individual(is) gerado(s).');
    }catch(Throwable $e){em_flash('error',OperationalDiagnostics::friendly($e,'Não foi possível concluir a venda presencial.'));}
}

em_header('Vender ingresso','tickets');
?>
<section class="card"><div class="section-head"><div><span class="eyebrow">BILHETERIA</span><h2>Venda presencial</h2><p class="muted">Venda avulsa não exige nome, CPF, telefone ou e-mail. Cada unidade recebe QR e código próprios.</p></div><a class="button secondary" href="<?= Security::e(app_url('?route=tickets'.($eventId?'&event_id='.$eventId:''))) ?>">Portaria / ingressos</a></div>
<form method="get" class="actions"><input type="hidden" name="route" value="ticket-sales"><select name="event_id" required onchange="this.form.submit()"><option value="">Escolha o evento</option><?php foreach($events as$e):?><option value="<?= (int)$e['id'] ?>"<?= $eventId===(int)$e['id']?' selected':'' ?>><?= Security::e($e['name'].' · '.date('d/m/Y H:i',strtotime((string)$e['starts_at']))) ?></option><?php endforeach;?></select><button class="secondary">Abrir</button></form></section>
<?php if($selected):?>
<section class="card" style="margin-top:16px"><h2>Nova venda · <?= Security::e($selected['name']) ?></h2><form method="post" class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr));align-items:end"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><label>Lote / tipo<select name="batch_id" required><?php foreach($batches as$b):$available=(int)$b['quantity_total']-(int)$b['quantity_sold']-(int)$b['quantity_reserved'];?><option value="<?= (int)$b['id'] ?>"><?= Security::e((($b['ticket_type_name']??'')?$b['ticket_type_name'].' · ':'').$b['name'].' · R$ '.number_format((int)$b['price_cents']/100,2,',','.').' · '.$available.' disponíveis') ?></option><?php endforeach;?></select></label><label>Quantidade<input type="number" name="quantity" min="1" max="20" value="1" required></label><label>Pagamento<select name="payment_method" required><option value="cash">Dinheiro</option><option value="pix">Pix</option><option value="card_pos">Cartão / POS</option><option value="nfc">NFC</option><?php if(Auth::can('events.manage')):?><option value="courtesy">Cortesia</option><?php endif;?></select></label><div></div><label>Nome <span class="muted">(opcional)</span><input name="buyer_name" autocomplete="name"></label><label>Telefone <span class="muted">(opcional)</span><input name="buyer_phone" autocomplete="tel"></label><label>E-mail <span class="muted">(opcional)</span><input name="buyer_email" type="email" autocomplete="email"></label><button class="primary">Concluir venda</button></form><p class="muted" style="margin-top:12px">Deixe os três campos do comprador vazios para venda avulsa. O sistema não cria cliente fictício.</p></section>
<?php endif;?>
<?php if($sale):?><section class="card" style="margin-top:16px"><div class="section-head"><div><span class="eyebrow">VENDA #<?= (int)$sale['order_id'] ?></span><h2><?= $sale['anonymous']?'Comprador não identificado':'Venda identificada' ?></h2><p class="muted">Pagamento: <?= Security::e($sale['payment_method']) ?> · situação <?= Security::e($sale['payment_status']) ?> · total R$ <?= number_format((int)$sale['total_cents']/100,2,',','.') ?></p></div><?php if($sale['payment_status']!=='paid'):?><a class="button primary" target="_blank" href="<?= Security::e(app_url('pedido.php?t='.rawurlencode($sale['public_token']))) ?>">Abrir pagamento</a><?php endif;?></div><div class="grid"><?php foreach($sale['tickets'] as$t):?><div class="card"><strong><?= Security::e($t['code']) ?></strong><div class="actions" style="margin-top:10px"><a class="button secondary" target="_blank" href="<?= Security::e(app_url('ingresso.php?t='.rawurlencode($t['qr_token']))) ?>">Ver / imprimir ingresso</a></div></div><?php endforeach;?></div></section><?php endif;?>
<?php em_footer();
