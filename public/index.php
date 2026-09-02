<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

$route = (string)($_GET['route'] ?? 'dashboard');
$pdo = null;
try { $pdo = Database::connection(); } catch (Throwable $e) {
    http_response_code(503);
    exit('<h1>EventMenu Premium</h1><p>Banco ainda não configurado. Copie <code>.env.example</code> para <code>.env</code>, configure o MySQL e acesse <a href="/install.php">/install.php</a>.</p>');
}

if ($route === 'login') {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::validateCsrf($_POST['_csrf'] ?? null)) $error = 'Sessão expirada.';
        elseif (Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) { header('Location: /'); exit; }
        else $error = 'E-mail ou senha inválidos.';
    }
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar — EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Entrar no painel</h1><?php if ($error): ?><div class="alert error"><?= Security::e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus></label><label>Senha<input type="password" name="password" required></label><button class="primary">Entrar</button></form></main></body></html><?php exit;
}

if ($route === 'logout') { Auth::logout(); header('Location: /?route=login'); exit; }
if (!Auth::check()) { header('Location: /?route=login'); exit; }

$tenantId = Auth::tenantId();
if (!$tenantId && Auth::role() !== 'super_admin') { http_response_code(403); exit('Usuário sem empresa.'); }

function money(int|float|string $cents): string { return 'R$ ' . number_format(((int)$cents)/100, 2, ',', '.'); }
function post_csrf(): void { if (!Security::validateCsrf($_POST['_csrf'] ?? null)) { http_response_code(419); exit('CSRF inválido.'); } }
function go(string $route): never { header('Location: /?route=' . urlencode($route)); exit; }

// Mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    post_csrf();
    if ($route === 'product-save') {
        Auth::requirePermission('catalog.manage');
        $id = (int)($_POST['id'] ?? 0); $name = trim((string)($_POST['name'] ?? '')); $price = (int)round(((float)str_replace(',', '.', (string)($_POST['price'] ?? '0'))) * 100); $cat = (int)($_POST['category_id'] ?? 0); $desc = trim((string)($_POST['description'] ?? ''));
        if ($name === '' || $price < 0) exit('Produto inválido.');
        if ($id) { $s=$pdo->prepare('UPDATE products SET name=?,description=?,category_id=NULLIF(?,0),price_cents=?,active=? WHERE id=? AND tenant_id=?'); $s->execute([$name,$desc,$cat,$price,isset($_POST['active'])?1:0,$id,$tenantId]); Auth::audit('product.updated','product',(string)$id); }
        else { $s=$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,price_cents,active) VALUES (?,NULLIF(?,0),?,?,?,1)'); $s->execute([$tenantId,$cat,$name,$desc,$price]); Auth::audit('product.created','product',(string)$pdo->lastInsertId()); }
        go('products');
    }
    if ($route === 'category-save') {
        Auth::requirePermission('catalog.manage'); $name=trim((string)($_POST['name']??'')); if($name==='') exit('Categoria inválida.'); $s=$pdo->prepare('INSERT INTO categories (tenant_id,name,active) VALUES (?,?,1)'); $s->execute([$tenantId,$name]); go('products');
    }
    if ($route === 'event-save') {
        Auth::requirePermission('events.manage'); $name=trim((string)($_POST['name']??'')); $slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-', $name)??'','-')); $starts=(string)($_POST['starts_at']??''); if($name===''||$starts==='') exit('Evento inválido.'); $s=$pdo->prepare('INSERT INTO events (tenant_id,name,slug,description,venue,address,starts_at,status) VALUES (?,?,?,?,?,?,?,?)'); $s->execute([$tenantId,$name,$slug.'-'.substr(bin2hex(random_bytes(3)),0,5),(string)($_POST['description']??''),(string)($_POST['venue']??''),(string)($_POST['address']??''),str_replace('T',' ',$starts),(string)($_POST['status']??'draft')]); go('events');
    }
    if ($route === 'order-status') {
        Auth::requirePermission('orders.manage'); $id=(int)($_POST['id']??0); $status=(string)($_POST['status']??'pending'); $allowed=['pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled']; if(!in_array($status,$allowed,true)) exit('Status inválido.'); $s=$pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?'); $s->execute([$status,$id,$tenantId]); Auth::audit('order.status','order',(string)$id,['status'=>$status]); go('orders');
    }
}

$nav = [
 'dashboard'=>['Painel','dashboard'], 'products'=>['Cardápio','catalog.manage'], 'orders'=>['Pedidos','dashboard'], 'events'=>['Eventos','dashboard'], 'payments'=>['Pagamentos','payments.manage']
];

function page_header(string $title, string $active, array $nav): void {
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= Security::e($title) ?> — EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"></head><body><div class="layout"><aside class="sidebar"><div class="brand">EventMenu <span>Premium</span></div><nav class="nav"><?php foreach($nav as $key=>$item): if(!Auth::can($item[1]) && $item[1]!=='dashboard') continue; ?><a class="<?= $active===$key?'active':'' ?>" href="/?route=<?= Security::e($key) ?>"><?= Security::e($item[0]) ?></a><?php endforeach; ?></nav><div class="sidebar-foot"><strong><?= Security::e(Auth::name()) ?></strong><br><span><?= Security::e((string)Auth::role()) ?></span><br><br><a href="/?route=logout">Sair</a></div></aside><main class="content"><header class="topbar"><div><h1><?= Security::e($title) ?></h1><div class="muted">Operação multiempresa protegida por permissões</div></div></header><?php
}
function page_footer(): void { echo '</main></div></body></html>'; }

if ($route === 'dashboard') {
    Auth::requirePermission('dashboard');
    $stats=[]; foreach(['orders'=>'SELECT COUNT(*) FROM orders WHERE tenant_id=? AND DATE(created_at)=CURDATE()','sales'=>'SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE tenant_id=? AND payment_status="paid" AND DATE(created_at)=CURDATE()','products'=>'SELECT COUNT(*) FROM products WHERE tenant_id=? AND active=1','events'=>'SELECT COUNT(*) FROM events WHERE tenant_id=? AND status="published"'] as $k=>$sql){$s=$pdo->prepare($sql);$s->execute([$tenantId]);$stats[$k]=$s->fetchColumn();}
    $s=$pdo->prepare('SELECT id,status,payment_status,total_cents,channel,created_at FROM orders WHERE tenant_id=? ORDER BY id DESC LIMIT 8');$s->execute([$tenantId]);$recent=$s->fetchAll();
    page_header('Visão geral','dashboard',$nav); ?><section class="grid"><div class="card metric"><span class="muted">Pedidos hoje</span><strong><?= (int)$stats['orders'] ?></strong></div><div class="card metric"><span class="muted">Recebido hoje</span><strong><?= money($stats['sales']) ?></strong></div><div class="card metric"><span class="muted">Produtos ativos</span><strong><?= (int)$stats['products'] ?></strong></div><div class="card metric"><span class="muted">Eventos publicados</span><strong><?= (int)$stats['events'] ?></strong></div></section><section class="card" style="margin-top:18px"><div class="section-head"><h2>Pedidos recentes</h2><a class="button secondary" href="/?route=orders">Ver pedidos</a></div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Canal</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Data</th></tr></thead><tbody><?php foreach($recent as $o): ?><tr><td>#<?= (int)$o['id'] ?></td><td><?= Security::e($o['channel']) ?></td><td><span class="badge"><?= Security::e($o['status']) ?></span></td><td><?= Security::e($o['payment_status']) ?></td><td><?= money($o['total_cents']) ?></td><td><?= Security::e($o['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php page_footer(); exit;
}

if ($route === 'products') {
    Auth::requirePermission('catalog.manage'); $s=$pdo->prepare('SELECT p.*,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? ORDER BY p.id DESC');$s->execute([$tenantId]);$products=$s->fetchAll();$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$cats=$c->fetchAll();
    page_header('Cardápio e produtos','products',$nav); ?><div class="grid" style="grid-template-columns:1fr 2fr"><section class="card"><h2>Novo produto</h2><form method="post" action="/?route=product-save" class="form-grid"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label class="span-2">Nome<input name="name" required></label><label>Preço<input name="price" inputmode="decimal" placeholder="29,90" required></label><label>Categoria<select name="category_id"><option value="0">Sem categoria</option><?php foreach($cats as $cat):?><option value="<?= (int)$cat['id'] ?>"><?= Security::e($cat['name']) ?></option><?php endforeach;?></select></label><label class="span-2">Descrição<textarea name="description"></textarea></label><button class="primary span-2">Adicionar produto</button></form><hr style="border-color:var(--line);margin:24px 0"><form method="post" action="/?route=category-save"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>Nova categoria<input name="name" required></label><button class="secondary" style="margin-top:10px">Criar categoria</button></form></section><section class="card"><div class="section-head"><h2>Produtos</h2><span class="muted"><?= count($products) ?> cadastrados</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Status</th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><?= Security::e($p['name']) ?></td><td><?= Security::e($p['category_name']??'—') ?></td><td><?= money($p['price_cents']) ?></td><td><span class="badge"><?= $p['active']?'Ativo':'Inativo' ?></span></td></tr><?php endforeach;?></tbody></table></div></section></div><?php page_footer(); exit;
}

if ($route === 'orders') {
    Auth::requirePermission('dashboard'); $sql='SELECT * FROM orders WHERE tenant_id=?';$args=[$tenantId]; if(Auth::role()==='delivery'){$sql.=' AND assigned_delivery_user_id=?';$args[]=Auth::id();}$sql.=' ORDER BY id DESC LIMIT 100';$s=$pdo->prepare($sql);$s->execute($args);$orders=$s->fetchAll();
    page_header('Pedidos','orders',$nav); ?><section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Canal</th><th>Status</th><th>Pagamento</th><th>Total</th><th>Ação</th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td>#<?= (int)$o['id'] ?></td><td><?= Security::e($o['channel']) ?></td><td><?= Security::e($o['status']) ?></td><td><?= Security::e($o['payment_status']) ?></td><td><?= money($o['total_cents']) ?></td><td><?php if(Auth::can('orders.manage')):?><form method="post" action="/?route=order-status" class="actions"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><select name="status"><?php foreach(['pending','confirmed','preparing','ready','out_for_delivery','completed','cancelled'] as $st):?><option <?= $st===$o['status']?'selected':'' ?>><?= $st ?></option><?php endforeach;?></select><button class="secondary">Atualizar</button></form><?php else:?><span class="muted">Somente leitura</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section><?php page_footer(); exit;
}

if ($route === 'events') {
    Auth::requirePermission('dashboard'); $s=$pdo->prepare('SELECT * FROM events WHERE tenant_id=? ORDER BY starts_at DESC');$s->execute([$tenantId]);$events=$s->fetchAll();
    page_header('Eventos','events',$nav); ?><div class="grid" style="grid-template-columns:1fr 2fr"><section class="card"><?php if(Auth::can('events.manage')):?><h2>Novo evento</h2><form method="post" action="/?route=event-save" class="form-grid"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label class="span-2">Nome<input name="name" required></label><label>Início<input type="datetime-local" name="starts_at" required></label><label>Status<select name="status"><option value="draft">Rascunho</option><option value="published">Publicado</option></select></label><label class="span-2">Local<input name="venue"></label><label class="span-2">Endereço<input name="address"></label><label class="span-2">Descrição<textarea name="description"></textarea></label><button class="primary span-2">Criar evento</button></form><?php else:?><p class="muted">Seu perfil possui acesso de consulta.</p><?php endif;?></section><section class="card"><h2>Eventos cadastrados</h2><div class="table-wrap"><table class="table"><thead><tr><th>Evento</th><th>Data</th><th>Local</th><th>Status</th></tr></thead><tbody><?php foreach($events as $e):?><tr><td><?= Security::e($e['name']) ?></td><td><?= Security::e($e['starts_at']) ?></td><td><?= Security::e($e['venue']) ?></td><td><span class="badge"><?= Security::e($e['status']) ?></span></td></tr><?php endforeach;?></tbody></table></div></section></div><?php page_footer(); exit;
}

if ($route === 'payments') {
    Auth::requirePermission('payments.manage'); $s=$pdo->prepare('SELECT p.*,o.status order_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.tenant_id=? ORDER BY p.id DESC LIMIT 100');$s->execute([$tenantId]);$payments=$s->fetchAll();
    page_header('Pagamentos','payments',$nav); ?><section class="card"><div class="alert ok">Pagamentos só devem virar <strong>paid</strong> após verificação servidor-a-servidor do gateway/webhook válido.</div><div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>Pedido</th><th>Gateway</th><th>Valor</th><th>Status</th><th>Verificado</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td>#<?= (int)$p['id'] ?></td><td>#<?= (int)$p['order_id'] ?></td><td><?= Security::e($p['provider']) ?></td><td><?= money($p['amount_cents']) ?></td><td><span class="badge"><?= Security::e($p['status']) ?></span></td><td><?= Security::e($p['verified_at']??'—') ?></td></tr><?php endforeach;?></tbody></table></div></section><?php page_footer(); exit;
}

http_response_code(404); echo 'Página não encontrada.';
