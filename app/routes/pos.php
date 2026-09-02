<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\CounterOrderService;

Auth::requirePermission('orders.create');
$tenantId=em_require_tenant();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    try{
        $result=(new CounterOrderService())->create(
            is_array($_POST['qty']??null)?$_POST['qty']:[],
            ['name'=>(string)($_POST['customer_name']??''),'phone'=>(string)($_POST['customer_phone']??'')],
            (string)($_POST['notes']??'')
        );
        em_flash('ok','Pedido de balcão #'.$result['order_id'].' criado. Estoque comprometido e pedido enviado para a operação.');
        em_go('orders',['view'=>(int)$result['order_id']]);
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('pos');}
}

$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=$c->fetchAll();
$p=$pdo->prepare('SELECT p.*,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? AND p.active=1 ORDER BY COALESCE(c.sort_order,9999),c.name,p.name');$p->execute([$tenantId]);$products=$p->fetchAll();
$grouped=[];foreach($products as$product)$grouped[$product['category_name']?:'Outros'][]=$product;

em_header('PDV / Balcão','pos');
?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(300px,1fr);align-items:start"><section><?php foreach($grouped as$category=>$rows):?><article class="card" style="margin-bottom:16px"><div class="section-head"><h2><?= Security::e($category) ?></h2><span class="muted"><?= count($rows) ?> itens</span></div><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))"><?php foreach($rows as$product):?><div style="padding:14px;border:1px solid var(--line);border-radius:16px"><strong><?= Security::e($product['name']) ?></strong><div class="price" style="margin:8px 0"><?= em_money($product['price_cents']) ?></div><?php if((int)$product['track_stock']):?><small class="muted">Estoque: <?= Security::e((string)$product['stock_qty']) ?></small><?php endif;?><label style="margin-top:10px">Quantidade<input type="number" name="qty[<?= (int)$product['id'] ?>]" min="0" max="50" value="0" inputmode="numeric"></label></div><?php endforeach;?></div></article><?php endforeach;?><?php if(!$products):?><section class="card"><h2>Sem produtos</h2><p class="muted">Cadastre produtos ativos antes de usar o PDV.</p></section><?php endif;?></section><aside class="card" style="position:sticky;top:18px"><h2>Novo pedido</h2><p class="muted">O servidor recalcula preço e estoque no momento da criação. O PDV não pode alterar o preço enviado pelo catálogo.</p><label>Cliente <input name="customer_name" maxlength="160" placeholder="Opcional"></label><label>Telefone <input name="customer_phone" maxlength="30" autocomplete="tel" placeholder="Opcional"></label><label>Observações<textarea name="notes" maxlength="1000" placeholder="Ex.: sem cebola, viagem"></textarea></label><button class="primary" style="width:100%"<?= !$products?' disabled':'' ?>>Criar pedido e enviar à cozinha</button><p class="muted" style="margin-top:12px">O pedido nasce como <strong>confirmado e não pago</strong>. Quem possui permissão financeira pode confirmar dinheiro/maquininha na tela de Pedidos. Garçom não ganha permissão de pagamento por usar o PDV.</p></aside></div></form><?php em_footer();
