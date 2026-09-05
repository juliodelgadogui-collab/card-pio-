<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\CashService;

Auth::requirePermission('cash.manage');
$tenantId=em_require_tenant();
$service=new CashService();
$toCents=static function(string $value):int{
    $value=trim($value);
    if($value==='')return 0;
    $value=str_replace(['R$',' '],'',$value);
    if(str_contains($value,',')&&str_contains($value,'.'))$value=str_replace('.','',$value);
    $value=str_replace(',','.',$value);
    if(!is_numeric($value))throw new RuntimeException('Valor inválido.');
    return (int)round(((float)$value)*100);
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='open'){
            $service->open($toCents((string)($_POST['opening_cash']??'0')),(string)($_POST['notes']??''));
            em_flash('ok','Caixa aberto com sucesso.');
        }elseif($action==='supply'){
            $service->addManualMovement('supply',$toCents((string)($_POST['amount']??'0')),(string)($_POST['notes']??''));
            em_flash('ok','Suprimento registrado.');
        }elseif($action==='withdrawal'){
            $service->addManualMovement('withdrawal',$toCents((string)($_POST['amount']??'0')),(string)($_POST['notes']??''));
            em_flash('ok','Sangria registrada.');
        }elseif($action==='adjustment'){
            $service->addManualMovement('adjustment',$toCents((string)($_POST['amount']??'0')),(string)($_POST['notes']??''),(string)($_POST['direction']??'in'));
            em_flash('ok','Ajuste registrado.');
        }elseif($action==='close'){
            $closed=$service->close($toCents((string)($_POST['counted_cash']??'0')),(string)($_POST['notes']??''));
            $difference=(int)$closed['difference_cents'];
            em_flash($difference===0?'ok':'error','Caixa fechado. Diferença: '.em_money($difference));
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());}
    em_go('cash');
}

$current=$service->currentSession();
$viewId=(int)($_GET['view']??0);
$data=$service->summary($viewId?:($current?(int)$current['id']:null));
$session=$data['session'];
$ownOpen=$session&&$session['status']==='open'&&(int)$session['user_id']===(int)Auth::id();

$sql='SELECT cs.*,u.name user_name FROM cash_sessions cs JOIN users u ON u.id=cs.user_id WHERE cs.tenant_id=?';$args=[$tenantId];
if(!in_array(Auth::role(),['admin','manager','super_admin'],true)){$sql.=' AND cs.user_id=?';$args[]=Auth::id();}
$sql.=' ORDER BY cs.id DESC LIMIT 40';$h=$pdo->prepare($sql);$h->execute($args);$history=$h->fetchAll();

em_header('Turno de caixa','cash');
?>
<?php if(!$current):?>
<section class="card" style="max-width:640px"><h2>Abrir caixa</h2><p class="muted">Informe apenas o dinheiro físico existente na gaveta no início do turno.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="open"><label>Fundo inicial (R$)<input name="opening_cash" inputmode="decimal" value="0,00" required></label><label class="span-2">Observação<textarea name="notes" maxlength="500"></textarea></label><button class="primary span-2">Abrir meu caixa</button></form></section>
<?php endif;?>

<?php if($session):?>
<section class="grid" style="margin-top:18px"><div class="card metric"><span class="muted">Operador</span><strong><?= Security::e($session['user_name']??Auth::name()) ?></strong><small><?= Security::e($session['status']==='open'?'Aberto':'Fechado') ?></small></div><div class="card metric"><span class="muted">Fundo inicial</span><strong><?= em_money($session['opening_cash_cents']) ?></strong></div><div class="card metric"><span class="muted">Dinheiro esperado</span><strong><?= em_money($data['expected_cash_cents']) ?></strong></div><?php if($session['status']==='closed'):?><div class="card metric"><span class="muted">Dinheiro contado</span><strong><?= em_money($session['closing_cash_cents']??0) ?></strong><small>Diferença <?= em_money($session['difference_cents']??0) ?></small></div><?php endif;?></section>

<?php if($ownOpen):?>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr));margin-top:18px">
<section class="card"><h2>Suprimento</h2><p class="muted">Entrada física de dinheiro na gaveta.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="supply"><label>Valor (R$)<input name="amount" inputmode="decimal" required></label><label>Observação<input name="notes" maxlength="500"></label><button class="secondary">Registrar suprimento</button></form></section>
<section class="card"><h2>Sangria</h2><p class="muted">Retirada física de dinheiro da gaveta.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="withdrawal"><label>Valor (R$)<input name="amount" inputmode="decimal" required></label><label>Motivo<input name="notes" maxlength="500" required></label><button class="secondary">Registrar sangria</button></form></section>
<section class="card"><h2>Ajuste</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="adjustment"><label>Direção<select name="direction"><option value="in">Entrada</option><option value="out">Saída</option></select></label><label>Valor (R$)<input name="amount" inputmode="decimal" required></label><label class="span-2">Motivo<input name="notes" maxlength="500" required></label><button class="secondary span-2">Registrar ajuste</button></form></section>
<section class="card"><h2>Fechar caixa</h2><p class="muted">Conte somente o dinheiro físico. Pix e cartão são conciliados separadamente.</p><form method="post" class="form-grid" onsubmit="return confirm('Confirmar fechamento deste caixa?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="close"><label>Dinheiro contado (R$)<input name="counted_cash" inputmode="decimal" required></label><label>Observação<input name="notes" maxlength="500"></label><button class="primary">Fechar caixa</button></form></section>
</div>
<?php endif;?>

<div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(280px,1fr);margin-top:18px"><section class="card"><div class="section-head"><h2>Movimentos do turno</h2><span class="muted"><?= count($data['movements']) ?> lançamentos</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Hora</th><th>Tipo</th><th>Forma</th><th>Direção</th><th>Valor</th><th>Observação</th></tr></thead><tbody><?php foreach($data['movements'] as $m):?><tr><td><?= Security::e((string)$m['created_at']) ?></td><td><?= Security::e((string)$m['type']) ?></td><td><?= Security::e((string)$m['method']) ?></td><td><?= $m['direction']==='in'?'Entrada':'Saída' ?></td><td><?= em_money($m['amount_cents']) ?></td><td><?= Security::e((string)($m['notes']??'')) ?></td></tr><?php endforeach;?><?php if(!$data['movements']):?><tr><td colspan="6" class="muted">Nenhum movimento registrado.</td></tr><?php endif;?></tbody></table></div></section><section class="card"><h2>Conciliação digital</h2><p class="muted">Pagamentos confirmados pelo servidor em pedidos criados por este operador durante o turno.</p><?php foreach($data['digital'] as $d):?><div class="section-head"><span><?= Security::e((string)$d['provider']) ?><br><small class="muted"><?= (int)$d['qty'] ?> pagamento(s)</small></span><strong><?= em_money($d['total_cents']) ?></strong></div><?php endforeach;?><?php if(!$data['digital']):?><p class="muted">Nenhum pagamento digital confirmado no período.</p><?php endif;?></section></div>
<?php endif;?>

<section class="card" style="margin-top:18px"><div class="section-head"><h2>Histórico</h2><span class="muted">Últimos turnos</span></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Operador</th><th>Abertura</th><th>Fechamento</th><th>Inicial</th><th>Esperado</th><th>Diferença</th><th></th></tr></thead><tbody><?php foreach($history as $r):?><tr><td>#<?= (int)$r['id'] ?></td><td><?= Security::e($r['user_name']) ?></td><td><?= Security::e($r['opened_at']) ?></td><td><?= Security::e($r['closed_at']??'Em aberto') ?></td><td><?= em_money($r['opening_cash_cents']) ?></td><td><?= $r['expected_cash_cents']!==null?em_money($r['expected_cash_cents']):'—' ?></td><td><?= $r['difference_cents']!==null?em_money($r['difference_cents']):'—' ?></td><td><a class="button secondary" href="<?= Security::e(app_url('?route=cash&view='.(int)$r['id'])) ?>">Ver</a></td></tr><?php endforeach;?></tbody></table></div></section>
<?php em_footer();
