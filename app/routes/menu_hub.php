<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('catalog.manage');$tenantId=em_require_tenant();
$t=$pdo->prepare('SELECT name,slug,settings FROM tenants WHERE id=?');$t->execute([$tenantId]);$tenant=$t->fetch()?:[];$publicUrl=em_url('/menu.php?empresa='.rawurlencode((string)($tenant['slug']??'')));
$links=[
 ['menu-editor','Editar aparência','Capa, logo, cores, categorias e visual do cardápio.','catalog.manage','🎨'],
 ['products','Produtos e categorias','Produtos, preços, estoque, promoções, adicionais e combos.','catalog.manage','🍔'],
 ['pos','PDV / Balcão','Venda presencial com carrinho rápido e retirada parcial.','orders.create','🧾'],
 ['fulfillment','Retiradas','Leia o QR ou venda e controle saldos de retirada.','fulfillment.manage','📦'],
 ['delivery','Delivery','Zonas, taxas, pedidos e atribuição de entregadores.','delivery.assign','🛵'],
 ['restaurant','Mesas e comandas','Salão, comandas, QR de mesa e chamadas de garçom.','tables.manage','🍽️'],
 ['kitchen','Cozinha / KDS','Fila visual de preparo para a cozinha.','orders.kitchen','👨‍🍳'],
 ['coupons','Cupons','Descontos, validade e limites de uso.','coupons.manage','🎟️'],
];
em_header('Cardápio Digital','menu-hub');
?>
<style>.menu-hub-hero{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center;background:linear-gradient(135deg,#24173a,#6d4aff);color:#fff;border-radius:18px;padding:26px;margin-bottom:16px}.menu-hub-hero h2{margin:0 0 7px;color:#fff;font-size:27px}.menu-hub-hero p{margin:0;color:#ddd4ff}.menu-hub-actions{display:flex;gap:8px}.menu-hub-actions a{background:#fff;color:#5f43d2;border:0}.menu-hub-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.menu-hub-card{display:block;border-radius:14px;text-decoration:none;color:inherit;transition:.15s}.menu-hub-card:hover{transform:translateY(-2px);box-shadow:0 10px 24px #2c204314}.menu-hub-icon{width:43px;height:43px;border-radius:12px;background:#f0ecff;display:grid;place-items:center;font-size:20px;margin-bottom:12px}.menu-hub-card h3{margin:0 0 6px}.menu-hub-card p{margin:0;min-height:48px}.menu-hub-preview{margin-top:16px}.menu-hub-preview iframe{width:100%;height:600px;border:1px solid var(--line);border-radius:16px;background:#fff}@media(max-width:1050px){.menu-hub-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:650px){.menu-hub-hero{grid-template-columns:1fr}.menu-hub-actions{width:100%}.menu-hub-actions a{flex:1;text-align:center}.menu-hub-grid{grid-template-columns:1fr 1fr;gap:8px}.menu-hub-card{padding:13px}.menu-hub-card p{font-size:10px;min-height:42px}.menu-hub-preview iframe{height:520px}}@media(max-width:420px){.menu-hub-grid{grid-template-columns:1fr}}</style>
<section class="menu-hub-hero"><div><h2><?= Security::e($tenant['name']??'Cardápio Digital') ?></h2><p>Personalize, cadastre produtos e controle toda a operação do cardápio em um só módulo.</p></div><div class="menu-hub-actions"><a class="button" href="<?= Security::e(em_url('/?route=menu-editor')) ?>">Editar cardápio</a><a class="button" target="_blank" href="<?= Security::e($publicUrl) ?>">Ver como cliente</a></div></section><section class="menu-hub-grid"><?php foreach($links as[$route,$title,$desc,$permission,$icon]):if(!Auth::can($permission))continue;?><a class="card menu-hub-card" href="<?= Security::e(em_url('/?route='.$route)) ?>"><div class="menu-hub-icon"><?= $icon ?></div><h3><?= Security::e($title) ?></h3><p class="muted"><?= Security::e($desc) ?></p></a><?php endforeach;?></section><section class="card menu-hub-preview"><div class="section-head"><div><h2>Prévia do Cardápio</h2><p class="muted">A mesma experiência que seu cliente verá.</p></div><a class="button secondary" target="_blank" href="<?= Security::e($publicUrl) ?>">Abrir em nova aba</a></div><iframe src="<?= Security::e($publicUrl) ?>" title="Prévia do cardápio"></iframe></section>
<?php em_footer();
