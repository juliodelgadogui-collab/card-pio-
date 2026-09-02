<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\RefundService;

Auth::requirePermission('payments.manage');
$tenantId=em_require_tenant();

function em_parse_brl_to_cents(string $value): ?int
{
    $value=trim($value);
    if($value==='')return null;
    $value=str_replace(['R$',' '],'',$value);
    if(str_contains($value,',')){$value=str_replace('.','',$value);$value=str_replace(',','.',$value);}
    if(!is_numeric($value))throw new RuntimeException('Valor de reembolso inválido.');
    $cents=(int)round(((float)$value)*100);
    if($cents<=0)throw new RuntimeException('O reembolso deve ser maior que zero.');
    return $cents;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    if($action==='refund'){
        try{
            $paymentId=(int)($_POST['payment_id']??0);
            $amount=em_parse_brl_to_cents((string)($_POST['amount']??''));
            $restoreStock=!empty($_POST['restore_stock']);
            $key=(string)($_POST['idempotency_key']??'');
            $refund=(new RefundService())->request($paymentId,$amount,$key,$restoreStock);
            $message=$refund['status']==='succeeded'?'Reembolso confirmado.':'Reembolso enviado e aguardando confirmação do provedor.';
            em_flash('ok',$message);
        }catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('payments');
    }
}

$provider=trim((string)($_GET['provider']??''));
$status=trim((string)($_GET['status']??''));
$sql='SELECT p.*,o.status order_status,o.payment_status order_payment_status,o.channel,c.name customer_name,
 (SELECT COALESCE(SUM(r.amount_cents),0) FROM refunds r WHERE r.payment_id=p.id AND r.tenant_id=p.tenant_id AND r.status="succeeded") refunded_cents,
 (SELECT COALESCE(SUM(r.amount_cents),0) FROM refunds r WHERE r.payment_id=p.id AND r.tenant_id=p.tenant_id AND r.status="processing") refund_processing_cents
 FROM payments p JOIN orders o ON o.id=p.order_id LEFT JOIN customers c ON c.id=o.customer_id WHERE p.tenant_id=?';
$args=[$tenantId];
if($provider!==''){$sql.=' AND p.provider=?';$args[]=$provider;}
if($status!==''){$sql.=' AND p.status=?';$args[]=$status;}
$sql.=' ORDER BY p.id DESC LIMIT 250';
$s=$pdo->prepare($sql);$s->execute($args);$payments=$s->fetchAll();

$w=$pdo->prepare('SELECT * FROM webhook_events WHERE tenant_id=? ORDER BY id DESC LIMIT 100');$w->execute([$tenantId]);$webhooks=$w->fetchAll();
$r=$pdo->prepare('SELECT r.*,p.provider_payment_id,u.name requested_by_name FROM refunds r JOIN payments p ON p.id=r.payment_id LEFT JOIN users u ON u.id=r.requested_by WHERE r.tenant_id=? ORDER BY r.id DESC LIMIT 100');$r->execute([$tenantId]);$refunds=$r->fetchAll();
$stats=[];
foreach([
    'paid'=>'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND status IN ("paid","partially_refunded","refunded")',
    'refunded'=>'SELECT COALESCE(SUM(amount_cents),0) FROM refunds WHERE tenant_id=? AND status="succeeded"',
    'pending'=>'SELECT COUNT(*) FROM payments WHERE tenant_id=? AND status IN ("created","pending","authorized")',
    'failed'=>'SELECT COUNT(*) FROM payments WHERE tenant_id=? AND status="failed"',
] as $k=>$q){$x=$pdo->prepare($q);$x->execute([$tenantId]);$stats[$k]=$x->fetchColumn();}

em_header('Pagamentos','payments');
?>
<section class="grid">
 <div class="card metric"><span class="muted">Recebido bruto</span><strong><?= em_money($stats['paid']) ?></strong></div>
 <div class="card metric"><span class="muted">Reembolsado</span><strong><?= em_money($stats['refunded']) ?></strong></div>
 <div class="card metric"><span class="muted">Cobranças pendentes</span><strong><?= (int)$stats['pending'] ?></strong></div>
 <div class="card metric"><span class="muted">Falharam</span><strong><?= (int)$stats['failed'] ?></strong></div>
</section>

<section class="card" style="margin-top:18px">
 <form method="get" class="actions">
  <input type="hidden" name="route" value="payments">
  <select name="provider"><option value="">Todos provedores</option><?php foreach(['stripe','pagbank','mercadopago','manual'] as $p):?><option value="<?= $p ?>"<?= em_selected($provider,$p) ?>><?= $p ?></option><?php endforeach;?></select>
  <select name="status"><option value="">Todos status</option><?php foreach(['created','pending','authorized','paid','failed','cancelled','refunded','partially_refunded'] as $st):?><option value="<?= $st ?>"<?= em_selected($status,$st) ?>><?= $st ?></option><?php endforeach;?></select>
  <button class="secondary">Filtrar</button>
 </form>
 <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Pedido</th><th>Cliente</th><th>Provedor</th><th>Status</th><th>Valor</th><th>Reembolso</th><th>ID externo</th><th>Verificado</th></tr></thead><tbody>
 <?php foreach($payments as $p):
    $reservedRefund=(int)$p['refunded_cents']+(int)$p['refund_processing_cents'];
    $remaining=max(0,(int)$p['amount_cents']-$reservedRefund);
    $canRefund=in_array($p['status'],['paid','partially_refunded'],true)&&$remaining>0;
 ?>
 <tr>
  <td>#<?= (int)$p['id'] ?></td>
  <td><a href="/?route=orders&view=<?= (int)$p['order_id'] ?>">#<?= (int)$p['order_id'] ?></a><br><span class="muted"><?= Security::e($p['channel']) ?></span></td>
  <td><?= Security::e($p['customer_name']??'—') ?></td>
  <td><?= Security::e($p['provider']) ?></td>
  <td><span class="badge"><?= Security::e($p['status']) ?></span></td>
  <td><strong><?= em_money($p['amount_cents']) ?></strong><?php if((int)$p['refunded_cents']>0):?><br><span class="muted">devolvido <?= em_money($p['refunded_cents']) ?></span><?php endif;?></td>
  <td>
   <?php if((int)$p['refund_processing_cents']>0):?><div class="badge">Processando <?= em_money($p['refund_processing_cents']) ?></div><?php endif;?>
   <?php if($canRefund):?>
    <form method="post" style="min-width:250px;margin-top:7px" onsubmit="return confirm('Solicitar este reembolso? A operação financeira pode ser irreversível no provedor.')">
     <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
     <input type="hidden" name="action" value="refund">
     <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
     <input type="hidden" name="idempotency_key" value="refund:<?= (int)$tenantId ?>:<?= (int)$p['id'] ?>:<?= bin2hex(random_bytes(12)) ?>">
     <label>Valor <input name="amount" inputmode="decimal" placeholder="vazio = <?= em_money($remaining) ?>"<?= $p['channel']==='event'?' readonly':'' ?>></label>
     <?php if($p['channel']!=='event'):?><label style="display:block;margin:6px 0"><input type="checkbox" name="restore_stock" value="1"> devolver estoque se o reembolso quitar 100%</label><?php else:?><div class="muted">Ingresso: somente reembolso integral e sem check-in.</div><?php endif;?>
     <button class="secondary" type="submit">Reembolsar</button>
    </form>
   <?php elseif($remaining<=0):?><span class="muted">Sem saldo</span><?php endif;?>
  </td>
  <td><code><?= Security::e($p['provider_payment_id']??'—') ?></code></td>
  <td><?= Security::e($p['verified_at']??'—') ?></td>
 </tr>
 <?php endforeach;?></tbody></table></div>
</section>

<section class="card" style="margin-top:18px">
 <div class="section-head"><h2>Reembolsos</h2><span class="muted">Idempotência + conciliação</span></div>
 <div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Pagamento</th><th>Pedido</th><th>Provedor</th><th>Valor</th><th>Status</th><th>ID provedor</th><th>Solicitado por</th><th>Erro</th></tr></thead><tbody>
 <?php foreach($refunds as $rf):?><tr><td>#<?= (int)$rf['id'] ?></td><td>#<?= (int)$rf['payment_id'] ?></td><td>#<?= (int)$rf['order_id'] ?></td><td><?= Security::e($rf['provider']) ?></td><td><?= em_money($rf['amount_cents']) ?></td><td><span class="badge"><?= Security::e($rf['status']) ?></span></td><td><code><?= Security::e($rf['provider_refund_id']??'—') ?></code></td><td><?= Security::e($rf['requested_by_name']??'sistema') ?></td><td><?= Security::e($rf['error_message']??'—') ?></td></tr><?php endforeach;?>
 </tbody></table></div>
</section>

<?php if(Auth::can('audit.view')):?>
<section class="card" style="margin-top:18px"><div class="section-head"><h2>Últimos webhooks</h2><span class="muted">Assinatura + idempotência</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Provedor</th><th>ID evento</th><th>Assinatura</th><th>Status</th><th>Processado</th></tr></thead><tbody><?php foreach($webhooks as $w):?><tr><td><?= Security::e($w['provider']) ?></td><td><code><?= Security::e($w['external_event_id']) ?></code></td><td><?= $w['signature_valid']?'Válida':'Inválida' ?></td><td><?= Security::e($w['status']) ?></td><td><?= Security::e($w['processed_at']??'—') ?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php em_footer();
