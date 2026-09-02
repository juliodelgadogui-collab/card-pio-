<?php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;

$pdo = Database::connection();
$slug = trim((string)($_GET['empresa'] ?? ''));
$stmt = $pdo->prepare('SELECT * FROM tenants WHERE slug=? AND status="active" LIMIT 1');
$stmt->execute([$slug]);
$tenant = $stmt->fetch();
if (!$tenant) { http_response_code(404); exit('Empresa não encontrada.'); }
$tenantId = (int)$tenant['id'];

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) exit('CSRF inválido.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $id=(int)($_POST['product_id']??0); $s=$pdo->prepare('SELECT id,name,price_cents FROM products WHERE id=? AND tenant_id=? AND active=1');$s->execute([$id,$tenantId]);$p=$s->fetch(); if($p){$key=$tenantId.':'.$id;if(!isset($_SESSION['cart'][$key]))$_SESSION['cart'][$key]=['product_id'=>$id,'tenant_id'=>$tenantId,'name'=>$p['name'],'price_cents'=>(int)$p['price_cents'],'qty'=>0];$_SESSION['cart'][$key]['qty']++;}
        header('Location: ?empresa='.urlencode($slug));exit;
    }
    if ($action === 'checkout') {
        $items=array_values(array_filter($_SESSION['cart'],fn($i)=>($i['tenant_id']??0)===$tenantId)); if(!$items) exit('Carrinho vazio.');
        $name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));$address=trim((string)($_POST['address']??'')); if($name===''||$phone==='') exit('Nome e telefone são obrigatórios.');
        $pdo->beginTransaction(); try{$s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)');$s->execute([$tenantId,$name,$phone]);$customerId=(int)$pdo->lastInsertId();$subtotal=0;foreach($items as $i)$subtotal+=(int)$i['price_cents']*(int)$i['qty'];$s=$pdo->prepare('INSERT INTO orders (tenant_id,customer_id,channel,status,payment_status,subtotal_cents,total_cents,delivery_address) VALUES (?,? ,"delivery","pending","unpaid",?,?,?)');$s->execute([$tenantId,$customerId,$subtotal,$subtotal,$address]);$orderId=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');foreach($items as $i)$ins->execute([$orderId,$i['product_id'],$i['name'],$i['price_cents'],$i['qty'],$i['price_cents']*$i['qty']]);$pdo->commit();foreach(array_keys($_SESSION['cart']) as $key)if(str_starts_with($key,$tenantId.':'))unset($_SESSION['cart'][$key]);header('Location: ?empresa='.urlencode($slug).'&pedido='.$orderId);exit;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=$c->fetchAll();
$p=$pdo->prepare('SELECT * FROM products WHERE tenant_id=? AND active=1 ORDER BY category_id,name');$p->execute([$tenantId]);$products=$p->fetchAll();
$cart=array_values(array_filter($_SESSION['cart'],fn($i)=>($i['tenant_id']??0)===$tenantId));$cartTotal=0;foreach($cart as $i)$cartTotal+=$i['price_cents']*$i['qty'];
function m(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= Security::e($tenant['name']) ?> — Cardápio</title><link rel="stylesheet" href="assets/app.css"><style>.menu-shell{max-width:1100px;margin:auto;padding:28px 18px}.hero{padding:30px;border:1px solid var(--line);border-radius:24px;background:linear-gradient(135deg,#181e2a,#11151d);margin-bottom:22px}.menu-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px}.product{display:flex;justify-content:space-between;gap:16px;align-items:center}.product+.product{border-top:1px solid var(--line);margin-top:14px;padding-top:14px}.cart{position:sticky;top:18px}.price{font-weight:800;color:var(--accent)}@media(max-width:800px){.menu-grid{grid-template-columns:1fr}.cart{position:static}}</style></head><body><main class="menu-shell"><section class="hero"><div class="brand">EventMenu <span>Premium</span></div><h1><?= Security::e($tenant['name']) ?></h1><p class="muted">Cardápio digital • pedido online • operação integrada</p></section><?php if(isset($_GET['pedido'])):?><div class="alert ok">Pedido #<?= (int)$_GET['pedido'] ?> criado. O pagamento ainda não foi confirmado; a confirmação é feita somente pelo fluxo seguro do gateway.</div><?php endif;?><div class="menu-grid"><section><?php foreach($categories as $cat):?><div class="card" style="margin-bottom:16px"><div class="section-head"><h2><?= Security::e($cat['name']) ?></h2></div><?php foreach($products as $prod):if((int)$prod['category_id']!==(int)$cat['id'])continue;?><div class="product"><div><strong><?= Security::e($prod['name']) ?></strong><div class="muted"><?= Security::e($prod['description']) ?></div><div class="price"><?= m((int)$prod['price_cents']) ?></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>"><button class="primary">Adicionar</button></form></div><?php endforeach;?></div><?php endforeach;?></section><aside class="card cart"><h2>Seu pedido</h2><?php if(!$cart):?><p class="muted">Seu carrinho está vazio.</p><?php else:?><?php foreach($cart as $i):?><p><?= (int)$i['qty'] ?>× <?= Security::e($i['name']) ?> <strong style="float:right"><?= m($i['price_cents']*$i['qty']) ?></strong></p><?php endforeach;?><hr style="border-color:var(--line)"><p><strong>Total <span style="float:right"><?= m($cartTotal) ?></span></strong></p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="checkout"><label class="span-2">Nome<input name="name" required></label><label class="span-2">Telefone<input name="phone" required></label><label class="span-2">Endereço<textarea name="address" required></textarea></label><button class="primary span-2">Enviar pedido</button></form><?php endif;?></aside></div></main></body></html>
