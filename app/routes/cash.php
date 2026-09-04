<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\CashRegisterService;

Auth::requirePermission('payments.manage');
$tenantId=em_require_tenant();
$service=new CashRegisterService();

function cash_cents(string $value):int{
    $value=trim(str_replace(['R$',' '],'',$value));if($value==='')return 0;
    if(str_contains($value,','))$value=str_replace(['.',','],['','.'],$value);
    if(!is_numeric($value))throw new RuntimeException('Valor inválido.');
    return max(0,(int)round((float)$value*100));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='open'){$service->open(cash_cents((string)($_POST['opening_balance']??'0')),(string)($_POST['notes']??''));em_flash('ok','Caixa aberto.');em_go('cash');}
        if($action==='deposit'){$service->addMovement('deposit',cash_cents((string)($_POST['amount']??'0')),(string)($_POST['notes']??''));em_flash('ok','Suprimento registrado.');em_go('cash');}
        if($action==='withdrawal'){$service->addMovement('withdrawal',cash_cents((string)($_POST['amount']??'0')),(string)($_POST['notes']??''));em_flash('ok','Sangria registrada.');em_go('cash');}
        if($action==='close'){$closed=$service->close(cash_cents((string)($_POST['declared_cash']??'0')),(string)($_POST['notes']??''));em_flash('ok','Caixa fechado. Diferença: '.em_money((int)$closed['difference_cents']).'.');em_go('cash');}
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('cash');}
}

$current=$service->current();
$movements=$current?$service->movements((int)$current['id']):[];
$recent=$pdo->prepare('SELECT cs.*,u.name user_name FROM cash_sessions cs JOIN users u ON u.id=cs.user_id WHERE cs.tenant_id=? ORDER BY cs.id DESC LIMIT 20');$recent->execute([$tenantId]);$recent=$recent->fetchAll();

em_header('Caixa','cash');
?><?php if(!$current):?><section class="card" style="max-width:620px"><h2>Abrir caixa</h2><p class="muted">Pagamentos manuais só podem ser confirmados por um operador com caixa aberto. Pagamentos online continuam conciliados pelos gateways.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="open"><label class="span-2">Saldo inicial em dinheiro (R$)<input name="opening_balance" inputmode="decimal" value="0,00"></label><label class="span-2">Observação<textarea name="notes" maxlength="500" placeholder="Opcional"></textarea></label><button class="primary span-2">Abrir meu caixa</button></form></section><?php else:?><section class="grid"><div class="card metric"><span class="muted">Saldo esperado em dinheiro</span><strong><?= em_money($current['expected_live_cents']) ?></strong></div><div class="card metric"><span class="muted">Vendas dinheiro</span><strong><?= em_money($current['totals']['cash']) ?></strong></div><div class="card metric"><span class="muted">Cartão / maquininha</span><strong><?= em_money($current['totals']['card']) ?></strong></div><div class="card metric"><span class="muted">Pix externo</span><strong><?= em_money($current['totals']['pix']) ?></strong></div></section><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));margin-top:18px"><section class="card"><h2>Suprimento</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="deposit"><label class="span-2">Valor (R$)<input name="amount" required inputmode="decimal"></label><label class="span-2">Motivo<input name="notes" maxlength="500" required></label><button class="secondary span-2">Registrar entrada</button></form></section><section class="card"><h2>Sangria</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="withdrawal"><label class="span-2">Valor (R$)<input name="amount" required inputmode="decimal"></label><label class="span-2">Motivo<input name="notes" maxlength="500" required></label><button class="secondary span-2">Registrar retirada</button></form></section><section class="card"><h2>Fechar caixa</h2><p class="muted">Aberto em <?= Security::e($current['opened_at']) ?> UTC · saldo inicial <?= em_money($current['opening_balance_cents']) ?></p><form method="post" class="form-grid" onsubmit="return confirm('Fechar este caixa? Depois do fechamento os novos recebimentos exigirão outra abertura.')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="close"><label class="span-2">Dinheiro contado (R$)<input name="declared_cash" required inputmode="decimal"></label><label class="span-2">Observação<textarea name="notes" maxlength="500"></textarea></label><button class="primary span-2">Conferir e fechar</button></form></section></div><section class="card" style="margin-top:18px"><div class="section-head"><h2>Movimentos do turno</h2><span class="muted"><?= count($movements) ?> registros</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Forma</th><th>Pedido</th><th>Valor</th><th>Operador</th><th>Data UTC</th></tr></thead><tbody><?php foreach($movements as$m):?><tr><td><span class="badge"><?= Security::e($m['type']) ?></span></td><td><?= Security::e($m['method']) ?></td><td><?= $m['order_id']?'#'.(int)$m['order_id']:'—' ?></td><td><strong><?= em_money($m['amount_cents']) ?></strong></td><td><?= Security::e($m['user_name']??'') ?></td><td><?= Security::e($m['created_at']) ?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?><section class="card" style="margin-top:18px"><div class="section-head"><h2>Últimos caixas</h2><span class="muted">Histórico da empresa</span></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Operador</th><th>Status</th><th>Abertura</th><th>Esperado</th><th>Declarado</th><th>Diferença</th></tr></thead><tbody><?php foreach($recent as$r):?><tr><td>#<?= (int)$r['id'] ?></td><td><?= Security::e($r['user_name']) ?></td><td><span class="badge"><?= Security::e($r['status']) ?></span></td><td><?= Security::e($r['opened_at']) ?></td><td><?= $r['expected_cash_cents']!==null?em_money($r['expected_cash_cents']):'—' ?></td><td><?= $r['declared_cash_cents']!==null?em_money($r['declared_cash_cents']):'—' ?></td><td><?= $r['difference_cents']!==null?em_money($r['difference_cents']):'—' ?></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();
