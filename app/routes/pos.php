<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\PaymentService;

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
        $manualMethod=(string)($_POST['manual_method']??'');
        if($manualMethod!==''&&Auth::can('payments.manage')){
            if(!in_array($manualMethod,['cash','card','pix','other'],true))throw new RuntimeException('Forma de recebimento inválida.');
            $payment=new PaymentService();
            $payment->create((int)$result['order_id'],'manual','pos-manual:'.$tenantId.':'.$result['order_id'].':'.bin2hex(random_bytes(8)));
            $payment->confirmVerified([
                'tenant_id'=>$tenantId,
                'order_id'=>(int)$result['order_id'],
                'provider'=>'manual',
                'provider_payment_id'=>'POS-'.strtoupper(bin2hex(random_bytes(10))),
                'amount_cents'=>(int)$result['total_cents'],
                'currency'=>'BRL',
                'account_reference'=>'manual',
                'manual_method'=>$manualMethod,
            ]);
            Auth::audit('pos.sale_paid','order',(string)$result['order_id'],['method'=>$manualMethod]);
            header('Location: /retirada.php?t='.rawurlencode((string)$result['fulfillment_token']).'&auto=1');exit;
        }
        em_flash('ok','Pedido de balcão #'.$result['order_id'].' criado. Estoque comprometido. Confirme o pagamento para liberar a retirada.');
        em_go('orders',['view'=>(int)$result['order_id']]);
    }catch(Throwable $e){
        if(isset($result['order_id'])){
            em_flash('error','Venda #'.$result['order_id'].' foi criada, mas o pagamento não foi confirmado: '.$e->getMessage());
            em_go('orders',['view'=>(int)$result['order_id']]);
        }
        em_flash('error',$e->getMessage());em_go('pos');
    }
}

$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=$c->fetchAll();
$p=$pdo->prepare('SELECT p.*,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? AND p.active=1 ORDER BY COALESCE(c.sort_order,9999),c.name,p.name');$p->execute([$tenantId]);$products=$p->fetchAll();
$grouped=[];foreach($products as$product)$grouped[$product['category_name']?:'Outros'][]=$product;

em_header('PDV / Balcão','pos');
?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><div class="grid" style="grid-template-columns:minmax(0,2fr) minmax(300px,1fr);align-items:start"><section><?php foreach($grouped as$category=>$rows):?><article class="card" style="margin-bottom:16px"><div class="section-head"><h2><?= Security::e($category) ?></h2><span class="muted"><?= count($rows) ?> itens</span></div><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))"><?php foreach($rows as$product):?><div style="padding:14px;border:1px solid var(--line);border-radius:16px"><strong><?= Security::e($product['name']) ?></strong><div class="price" style="margin:8px 0"><?= em_money($product['price_cents']) ?></div><?php if((int)$product['track_stock']):?><small class="muted">Estoque: <?= Security::e((string)$product['stock_qty']) ?></small><?php endif;?><label style="margin-top:10px">Quantidade<input type="number" name="qty[<?= (int)$product['id'] ?>]" min="0" max="50" value="0" inputmode="numeric"></label></div><?php endforeach;?></div></article><?php endforeach;?><?php if(!$products):?><section class="card"><h2>Sem produtos</h2><p class="muted">Cadastre produtos ativos antes de usar o PDV.</p></section><?php endif;?></section><aside class="card" style="position:sticky;top:18px"><h2>Nova venda</h2><p class="muted">Preço e estoque são recalculados no servidor. A quantidade total é baixada do estoque uma única vez na venda.</p><label>Cliente <input name="customer_name" maxlength="160" placeholder="Opcional"></label><label>Telefone <input name="customer_phone" maxlength="30" autocomplete="tel" placeholder="Opcional"></label><label>Observações<textarea name="notes" maxlength="1000" placeholder="Ex.: cliente vai retirar aos poucos"></textarea></label><?php if(Auth::can('payments.manage')):?><label>Recebimento<select name="manual_method"><option value="">Criar sem receber agora</option><option value="cash">Dinheiro</option><option value="card">Cartão / maquininha</option><option value="pix">Pix externo</option><option value="other">Outro</option></select></label><p class="muted">Ao confirmar uma forma de pagamento, o seu caixa precisa estar aberto. Depois do recebimento o comprovante de retirada abre automaticamente para impressão.</p><?php else:?><p class="muted">Seu perfil pode criar a venda, mas não confirmar pagamento. Um caixa/gerente deverá receber antes de liberar produtos.</p><?php endif;?><button class="primary" style="width:100%"<?= !$products?' disabled':'' ?>>Finalizar venda</button><p class="muted" style="margin-top:12px"><strong>Retirada parcial:</strong> se o cliente comprar 5 cervejas e pegar 1, o sistema registra saldo 4. Retirar depois não baixa estoque novamente.</p></aside></div></form><?php em_footer();
