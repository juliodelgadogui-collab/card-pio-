<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\InventoryService;

Auth::requirePermission('inventory.manage');$tenantId=em_require_tenant();$service=new InventoryService();
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');$productId=(int)($_POST['product_id']??0);$reason=trim((string)($_POST['reason']??''));
    try{
        if($action==='move'){$type=(string)($_POST['type']??'');$qty=(float)str_replace(',','.',(string)($_POST['quantity']??'0'));$service->move($productId,$type,$qty,$reason);em_flash('ok','Movimentação registrada.');}
        elseif($action==='adjust'){$qty=(float)str_replace(',','.',(string)($_POST['new_quantity']??'0'));$service->setStock($productId,$qty,$reason);em_flash('ok','Saldo ajustado.');}
        else throw new RuntimeException('Ação inválida.');
    }catch(Throwable $e){em_flash('error',$e->getMessage());}
    em_go('inventory');
}

$p=$pdo->prepare('SELECT id,name,sku,stock_qty,active FROM products WHERE tenant_id=? AND track_stock=1 ORDER BY active DESC,name');$p->execute([$tenantId]);$products=$p->fetchAll();
$low=[];foreach($products as $row){if((float)$row['stock_qty']<=5)$low[]=$row;}
$m=$pdo->prepare('SELECT sm.*,p.name product_name,p.sku,o.id linked_order FROM stock_movements sm JOIN products p ON p.id=sm.product_id LEFT JOIN orders o ON o.id=sm.order_id WHERE sm.tenant_id=? ORDER BY sm.id DESC LIMIT 200');$m->execute([$tenantId]);$movements=$m->fetchAll();
$selected=(int)($_GET['product_id']??0);
em_header('Estoque','inventory');
?><section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr))"><div class="card metric"><span class="muted">Produtos controlados</span><strong><?= count($products) ?></strong></div><div class="card metric"><span class="muted">Estoque baixo / zerado</span><strong><?= count($low) ?></strong></div></section>
<div class="grid" style="grid-template-columns:minmax(300px,1fr) minmax(0,2fr);margin-top:18px"><section class="card"><h2>Movimentar estoque</h2><?php if(!$products):?><p class="muted">Nenhum produto com controle de estoque ativo.</p><?php else:?><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="move"><label class="span-2">Produto<select name="product_id" required><?php foreach($products as $p):?><option value="<?= (int)$p['id'] ?>"<?= em_selected($selected,$p['id']) ?>><?= Security::e($p['name'].($p['sku']?' · '.$p['sku']:'').' · saldo '.$p['stock_qty']) ?></option><?php endforeach;?></select></label><label>Tipo<select name="type"><option value="in">Entrada</option><option value="out">Saída</option></select></label><label>Quantidade<input name="quantity" inputmode="decimal" required></label><label class="span-2">Motivo<textarea name="reason" required maxlength="500" placeholder="Compra de fornecedor, perda, consumo interno..."></textarea></label><button class="primary span-2">Registrar movimento</button></form><hr style="border-color:var(--line);margin:24px 0"><h3>Ajuste de inventário</h3><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="adjust"><label class="span-2">Produto<select name="product_id" required><?php foreach($products as $p):?><option value="<?= (int)$p['id'] ?>"<?= em_selected($selected,$p['id']) ?>><?= Security::e($p['name'].' · saldo '.$p['stock_qty']) ?></option><?php endforeach;?></select></label><label class="span-2">Novo saldo contado<input name="new_quantity" inputmode="decimal" required></label><label class="span-2">Motivo do ajuste<textarea name="reason" required maxlength="500"></textarea></label><button class="secondary span-2">Ajustar saldo</button></form><?php endif;?></section><section class="card"><div class="section-head"><h2>Saldos</h2><span class="muted">alerta em 5 unidades ou menos</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>SKU</th><th>Saldo</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($products as $p):$qty=(float)$p['stock_qty'];?><tr><td><?= Security::e($p['name']) ?></td><td><?= Security::e($p['sku']??'—') ?></td><td><strong><?= Security::e((string)$p['stock_qty']) ?></strong></td><td><span class="badge"><?= $qty<=0?'Zerado':($qty<=5?'Baixo':'OK') ?></span></td><td><a class="button secondary" href="<?= Security::e(app_url('?route=inventory&product_id='.(int)$p['id'])) ?>">Movimentar</a></td></tr><?php endforeach;?></tbody></table></div></section></div>
<section class="card" style="margin-top:18px"><div class="section-head"><h2>Últimas movimentações</h2><span class="muted">vendas, devoluções e ajustes</span></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Produto</th><th>Tipo</th><th>Qtd.</th><th>Pedido</th><th>Data</th></tr></thead><tbody><?php foreach($movements as $m):?><tr><td>#<?= (int)$m['id'] ?></td><td><?= Security::e($m['product_name']) ?></td><td><span class="badge"><?= Security::e($m['type']) ?></span></td><td><?= Security::e((string)$m['quantity']) ?></td><td><?= $m['linked_order']?'#'.(int)$m['linked_order']:'Manual' ?></td><td><?= Security::e($m['created_at']??'') ?></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();
