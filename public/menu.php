<?php

declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\PublicMenuService;

$pdo = Database::connection();
$slug = trim((string)($_GET['empresa'] ?? ''));
$stmt = $pdo->prepare('SELECT * FROM tenants WHERE slug=? AND status="active" LIMIT 1');
$stmt->execute([$slug]);
$tenant = $stmt->fetch();
if (!$tenant) { http_response_code(404); exit('Empresa não encontrada.'); }
$tenantId = (int)$tenant['id'];
if (!TenantFeatures::menu($tenantId)) { http_response_code(404); exit('Cardápio não habilitado para esta empresa.'); }
$settings = json_decode((string)($tenant['settings'] ?? '{}'), true) ?: [];

$tableToken = trim((string)($_GET['mesa'] ?? ''));
$table = null;
if ($tableToken !== '') {
    $s = $pdo->prepare('SELECT * FROM restaurant_tables WHERE tenant_id=? AND qr_token=? AND status<>"inactive" LIMIT 1');
    $s->execute([$tenantId,$tableToken]);
    $table = $s->fetch() ?: null;
    if (!$table) { http_response_code(404); exit('Mesa não encontrada ou inativa.'); }
}

$cartKey = 'public_cart_'.$tenantId;
if (!isset($_SESSION[$cartKey]) || !is_array($_SESSION[$cartKey])) $_SESSION[$cartKey] = [];
$cart =& $_SESSION[$cartKey];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'Sessão expirada. Atualize a página.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            $id = (int)($_POST['product_id'] ?? 0);
            $qty = max(1, min(99, (int)($_POST['qty'] ?? 1)));
            $s = $pdo->prepare('SELECT id,name,price_cents,track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? AND active=1');
            $s->execute([$id,$tenantId]);
            $p = $s->fetch();
            if (!$p) $error = 'Produto indisponível.';
            elseif ((int)$p['track_stock'] && (float)$p['stock_qty'] <= 0) $error = 'Produto sem estoque.';
            else $cart[$id] = ($cart[$id] ?? 0) + $qty;
        } elseif ($action === 'remove') {
            unset($cart[(int)($_POST['product_id'] ?? 0)]);
        } elseif ($action === 'clear') {
            $cart = [];
        } elseif ($action === 'checkout') {
            try {
                $rows = [];
                foreach ($cart as $productId => $qty) $rows[] = ['product_id'=>(int)$productId,'qty'=>(float)$qty];
                $order = (new PublicMenuService())->create(
                    $tenantId,
                    $rows,
                    (string)($_POST['name'] ?? ''),
                    (string)($_POST['phone'] ?? ''),
                    (string)($_POST['address'] ?? ''),
                    $table ? $tableToken : null
                );
                $cart = [];
                header('Location: '.app_url('pedido.php?t='.rawurlencode($order['public_token'])), true, 303);
                exit;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        if ($action !== 'checkout' && $error === null) {
            $query = ['empresa'=>$slug];
            if ($table) $query['mesa'] = $tableToken;
            header('Location: '.app_url('menu.php?'.http_build_query($query)), true, 303);
            exit;
        }
    }
}

$c = $pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');
$c->execute([$tenantId]);
$categories = $c->fetchAll();
$p = $pdo->prepare('SELECT * FROM products WHERE tenant_id=? AND active=1 ORDER BY category_id,name');
$p->execute([$tenantId]);
$products = $p->fetchAll();

$categoryMap = [];
$groups = [];
foreach ($categories as $cat) {
    $id = (int)$cat['id'];
    $categoryMap[$id] = true;
    $groups['cat-'.$id] = ['name'=>(string)$cat['name'],'products'=>[]];
}
$uncategorized = [];
foreach ($products as $prod) {
    $categoryId = (int)($prod['category_id'] ?? 0);
    if ($categoryId > 0 && isset($categoryMap[$categoryId])) $groups['cat-'.$categoryId]['products'][] = $prod;
    else $uncategorized[] = $prod;
}
$groups = array_filter($groups, static fn(array $g): bool => count($g['products']) > 0);
if ($uncategorized) $groups['outros'] = ['name'=>'Outros','products'=>$uncategorized];

$productMap = [];
foreach ($products as $prod) $productMap[(int)$prod['id']] = $prod;
$cartRows = [];
$cartTotal = 0;
$cartCount = 0;
foreach ($cart as $id => $qty) {
    $prod = $productMap[(int)$id] ?? null;
    if (!$prod) continue;
    $line = (int)$prod['price_cents'] * (int)$qty;
    $cartTotal += $line;
    $cartCount += (int)$qty;
    $cartRows[] = ['id'=>(int)$id,'name'=>$prod['name'],'qty'=>(int)$qty,'line'=>$line];
}

function m(int $c): string { return 'R$ '.number_format($c/100,2,',','.'); }
function menu_color(mixed $value, string $fallback): string {
    $v = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : $fallback;
}

$deliveryFee = max(0,(int)($settings['delivery_fee_cents'] ?? 0));
$minimum = max(0,(int)($settings['min_delivery_order_cents'] ?? 0));
$title = trim((string)($settings['menu_public_title'] ?? '')) ?: (string)$tenant['name'];
$subtitle = trim((string)($settings['menu_subtitle'] ?? '')) ?: 'Peça do seu jeito. Rápido e fácil.';
$primary = menu_color($settings['menu_primary_color'] ?? '', '#f4b942');
$background = menu_color($settings['menu_background_color'] ?? '', '#0b0d12');
$surface = menu_color($settings['menu_surface_color'] ?? '', '#141821');
$text = menu_color($settings['menu_text_color'] ?? '', '#f5f7fb');
$layout = in_array(($settings['menu_layout'] ?? 'cards'), ['cards','compact'], true) ? (string)$settings['menu_layout'] : 'cards';
$headerStyle = in_array(($settings['menu_header_style'] ?? 'gradient'), ['gradient','solid','minimal'], true) ? (string)$settings['menu_header_style'] : 'gradient';
$showImages = (bool)($settings['menu_show_images'] ?? true);
$showSearch = (bool)($settings['menu_show_search'] ?? true);
$showBranding = (bool)($settings['menu_show_branding'] ?? true);
$logoUrl = trim((string)($settings['menu_logo_url'] ?? ''));
$coverUrl = trim((string)($settings['menu_cover_url'] ?? ''));
$whatsapp = preg_replace('/\D+/', '', (string)($settings['whatsapp'] ?? '')) ?: '';
$canonical = app_url('menu.php?empresa='.rawurlencode($slug));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($background) ?>">
<title><?= Security::e($title) ?> — Cardápio</title>
<meta name="description" content="<?= Security::e($subtitle) ?>">
<link rel="canonical" href="<?= Security::e($canonical) ?>">
<style>
:root{--menu-bg:<?= Security::e($background) ?>;--menu-surface:<?= Security::e($surface) ?>;--menu-text:<?= Security::e($text) ?>;--menu-primary:<?= Security::e($primary) ?>;--muted:color-mix(in srgb,var(--menu-text) 62%,transparent);--line:color-mix(in srgb,var(--menu-text) 14%,transparent)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--menu-bg);color:var(--menu-text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh}button,input,textarea{font:inherit}button{cursor:pointer}a{text-decoration:none;color:inherit}.menu-app{width:min(1180px,100%);margin:0 auto;padding:18px 18px 110px}.menu-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:8px 2px 18px}.store-brand{display:flex;align-items:center;gap:11px;min-width:0}.store-logo{width:46px;height:46px;border-radius:15px;object-fit:cover;background:var(--menu-surface);border:1px solid var(--line)}.store-monogram{display:grid;place-items:center;font-weight:900;color:#111;background:var(--menu-primary)}.store-brand strong{display:block;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.store-brand small,.muted{color:var(--muted)}.top-actions{display:flex;gap:8px}.soft-button,.icon-button{border:1px solid var(--line);background:color-mix(in srgb,var(--menu-surface) 92%,transparent);color:var(--menu-text);border-radius:999px;padding:10px 14px;font-weight:700}.hero{position:relative;overflow:hidden;border-radius:30px;border:1px solid var(--line);background:var(--menu-surface);min-height:250px;margin-bottom:20px}.hero.gradient{background:linear-gradient(135deg,color-mix(in srgb,var(--menu-primary) 24%,var(--menu-surface)),var(--menu-surface) 60%)}.hero.minimal{border:0;border-radius:0;background:transparent;min-height:190px}.hero-cover{width:100%;height:170px;object-fit:cover;display:block;opacity:.8}.hero-content{padding:28px;position:relative}.hero.has-cover .hero-content{margin-top:-68px;background:linear-gradient(180deg,transparent,var(--menu-surface) 44%);padding-top:82px}.hero h1{margin:7px 0 8px;font-size:clamp(30px,6vw,50px);letter-spacing:-1.6px;line-height:1}.hero p{margin:0;max-width:650px;color:var(--muted);font-size:16px;line-height:1.55}.eyebrow{font-size:11px;letter-spacing:.15em;font-weight:900;color:var(--menu-primary)}.hero-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.pill{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border-radius:999px;background:color-mix(in srgb,var(--menu-surface) 82%,var(--menu-primary) 18%);border:1px solid var(--line);font-size:12px;font-weight:700}.notice{margin-top:16px;padding:13px 15px;border-radius:15px;background:color-mix(in srgb,var(--menu-primary) 12%,transparent);border:1px solid color-mix(in srgb,var(--menu-primary) 28%,transparent);font-size:14px}.alert{padding:13px 15px;border-radius:15px;margin:0 0 18px;background:#481f28;color:#ffd8de}.browse-tools{position:sticky;top:0;z-index:15;background:color-mix(in srgb,var(--menu-bg) 92%,transparent);backdrop-filter:blur(18px);padding:10px 0 12px;margin-bottom:8px}.search-box{display:flex;align-items:center;gap:10px;background:var(--menu-surface);border:1px solid var(--line);border-radius:17px;padding:0 14px;height:52px}.search-box input{border:0;outline:0;background:transparent;color:var(--menu-text);width:100%;font-size:15px}.search-box input::placeholder{color:var(--muted)}.category-strip{display:flex;gap:8px;overflow-x:auto;padding:10px 0 2px;scrollbar-width:none}.category-strip::-webkit-scrollbar{display:none}.category-chip{white-space:nowrap;border:1px solid var(--line);background:var(--menu-surface);border-radius:999px;padding:9px 13px;font-size:13px;font-weight:750}.category-chip:hover{border-color:var(--menu-primary)}.menu-grid{display:grid;grid-template-columns:minmax(0,1.75fr) minmax(310px,.75fr);gap:22px;align-items:start}.catalog-section{scroll-margin-top:110px;margin-bottom:28px}.catalog-head{display:flex;justify-content:space-between;align-items:end;margin-bottom:12px}.catalog-head h2{margin:0;font-size:23px;letter-spacing:-.5px}.catalog-head small{color:var(--muted)}.products-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.products-grid.compact{grid-template-columns:1fr}.product-card{display:grid;grid-template-columns:112px minmax(0,1fr);gap:14px;background:var(--menu-surface);border:1px solid var(--line);border-radius:21px;padding:12px;min-height:138px;transition:transform .18s ease,border-color .18s ease}.product-card:hover{transform:translateY(-2px);border-color:color-mix(in srgb,var(--menu-primary) 52%,var(--line))}.product-card.no-image{grid-template-columns:1fr}.products-grid.compact .product-card{grid-template-columns:82px minmax(0,1fr);min-height:104px}.product-photo{width:112px;height:112px;border-radius:16px;object-fit:cover;background:color-mix(in srgb,var(--menu-text) 7%,transparent)}.products-grid.compact .product-photo{width:82px;height:82px}.product-info{display:flex;flex-direction:column;min-width:0;padding:3px 2px}.product-info h3{margin:0;font-size:16px;line-height:1.25}.description{font-size:13px;line-height:1.4;color:var(--muted);margin:5px 0 10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.product-bottom{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:auto}.price{font-weight:900;color:var(--menu-primary);font-size:16px}.add-form{display:flex;align-items:center;gap:7px}.qty{width:48px!important;min-width:48px;border:1px solid var(--line);background:transparent;color:var(--menu-text);border-radius:11px;padding:8px;text-align:center}.add-button{border:0;background:var(--menu-primary);color:#111;border-radius:12px;padding:9px 12px;font-weight:900}.add-button:disabled{opacity:.45}.soldout{font-size:11px;padding:5px 7px;border-radius:999px;background:color-mix(in srgb,#ff6464 18%,transparent);color:#ffb7b7}.cart{position:sticky;top:94px;background:var(--menu-surface);border:1px solid var(--line);border-radius:24px;padding:19px}.cart-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}.cart h2{margin:0;font-size:19px}.clear-button,.remove-button{border:1px solid var(--line);background:transparent;color:var(--menu-text);border-radius:10px;padding:7px 10px}.cart-empty{border:1px dashed var(--line);padding:22px;border-radius:16px;text-align:center;color:var(--muted)}.cart-line{display:flex;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--line)}.cart-line strong{display:block}.cart-line small{color:var(--muted)}.cart-total{display:flex;justify-content:space-between;align-items:center;font-size:18px;padding:17px 0}.checkout-form{display:grid;gap:10px}.checkout-form label{display:grid;gap:6px;color:var(--muted);font-size:12px;font-weight:700}.checkout-form input,.checkout-form textarea{border:1px solid var(--line);background:var(--menu-bg);color:var(--menu-text);border-radius:13px;padding:12px 13px;outline:0}.checkout-form textarea{min-height:78px;resize:vertical}.checkout-button{border:0;background:var(--menu-primary);color:#111;border-radius:14px;padding:14px;font-weight:950;font-size:15px}.empty-menu{background:var(--menu-surface);border:1px solid var(--line);border-radius:23px;padding:42px 24px;text-align:center}.empty-menu strong{display:block;font-size:20px;margin-bottom:6px}.mobile-cart{display:none}.powered{text-align:center;color:var(--muted);font-size:12px;padding:34px 0 4px}.powered b{color:var(--menu-primary)}
@media(max-width:900px){.menu-app{padding:12px 12px 105px}.menu-grid{grid-template-columns:1fr}.cart{position:static}.products-grid{grid-template-columns:1fr}.hero{border-radius:23px}.hero-content{padding:23px}.hero-cover{height:150px}.mobile-cart{position:fixed;left:12px;right:12px;bottom:max(12px,env(safe-area-inset-bottom));z-index:30;display:flex;align-items:center;justify-content:space-between;background:var(--menu-primary);color:#111;padding:13px 15px;border-radius:17px;box-shadow:0 18px 50px #0008;font-weight:900}.browse-tools{top:0}.top-actions .soft-button{padding:9px 11px}.products-grid.compact .product-card,.product-card{grid-template-columns:92px minmax(0,1fr);min-height:116px}.product-photo,.products-grid.compact .product-photo{width:92px;height:92px}.product-card.no-image{grid-template-columns:1fr}.add-form .qty{display:none}}
@media(max-width:430px){.hero h1{font-size:34px}.hero-content{padding:20px}.hero.has-cover .hero-content{padding-top:75px}.product-card{grid-template-columns:82px minmax(0,1fr);gap:11px}.product-photo{width:82px;height:82px}.product-bottom{align-items:end}.add-button{padding:8px 10px}.menu-top{padding-top:3px}.store-brand small{display:none}}
</style>
</head>
<body>
<main class="menu-app">
<header class="menu-top"><div class="store-brand"><?php if($logoUrl!==''):?><img class="store-logo" src="<?= Security::e($logoUrl) ?>" alt="Logo <?= Security::e($title) ?>"><?php else:?><div class="store-logo store-monogram"><?= Security::e(mb_strtoupper(mb_substr($title,0,1))) ?></div><?php endif;?><div><strong><?= Security::e($title) ?></strong><small><?= $table?'Pedido na mesa':'Cardápio digital' ?></small></div></div><div class="top-actions"><?php if($whatsapp!==''):?><a class="soft-button" target="_blank" rel="noopener" href="https://wa.me/<?= Security::e($whatsapp) ?>">WhatsApp</a><?php endif;?></div></header>

<section class="hero <?= Security::e($headerStyle) ?><?= $coverUrl!==''?' has-cover':'' ?>"><?php if($coverUrl!==''):?><img class="hero-cover" src="<?= Security::e($coverUrl) ?>" alt=""><?php endif;?><div class="hero-content"><span class="eyebrow"><?= $table?'PEDIDO NA MESA':'CARDÁPIO ONLINE' ?></span><h1><?= Security::e($title) ?></h1><p><?= Security::e($subtitle) ?></p><div class="hero-meta"><?php if($table):?><span class="pill">Mesa · <?= Security::e($table['name']) ?></span><span class="pill">Pedido vinculado automaticamente</span><?php else:?><span class="pill">Delivery</span><?php if($minimum>0):?><span class="pill">Pedido mínimo <?= m($minimum) ?></span><?php endif;?><?php if($deliveryFee>0):?><span class="pill">Entrega <?= m($deliveryFee) ?></span><?php endif;?><?php endif;?></div><?php if(!empty($settings['menu_message'])):?><div class="notice"><?= Security::e((string)$settings['menu_message']) ?></div><?php endif;?></div></section>

<?php if($error):?><div class="alert"><?= Security::e($error) ?></div><?php endif;?>

<?php if($groups):?><div class="browse-tools"><?php if($showSearch):?><label class="search-box"><span>⌕</span><input id="menu-search" type="search" placeholder="Buscar produto no cardápio" autocomplete="off"></label><?php endif;?><nav class="category-strip"><?php foreach($groups as $key=>$group):?><a class="category-chip" href="#<?= Security::e($key) ?>"><?= Security::e($group['name']) ?></a><?php endforeach;?></nav></div><?php endif;?>

<div class="menu-grid"><section id="catalog"><?php if(!$groups):?><div class="empty-menu"><strong>Cardápio em preparação</strong><span class="muted">Ainda não há produtos publicados. Volte em breve.</span></div><?php else:?><?php foreach($groups as $key=>$group):?><section class="catalog-section" id="<?= Security::e($key) ?>"><div class="catalog-head"><h2><?= Security::e($group['name']) ?></h2><small><?= count($group['products']) ?> <?= count($group['products'])===1?'item':'itens' ?></small></div><div class="products-grid <?= Security::e($layout) ?>"><?php foreach($group['products'] as $prod):$out=(int)$prod['track_stock']&&(float)$prod['stock_qty']<=0;$hasImage=$showImages&&!empty($prod['image_url']);$searchText=mb_strtolower(trim((string)$prod['name'].' '.(string)($prod['description']??'').' '.(string)$group['name']));?><article class="product-card<?= $hasImage?'':' no-image' ?>" data-menu-product data-search="<?= Security::e($searchText) ?>"><?php if($hasImage):?><img class="product-photo" src="<?= Security::e($prod['image_url']) ?>" alt="<?= Security::e($prod['name']) ?>" loading="lazy"><?php endif;?><div class="product-info"><h3><?= Security::e($prod['name']) ?></h3><?php if(trim((string)($prod['description']??''))!==''):?><p class="description"><?= Security::e($prod['description']) ?></p><?php endif;?><div class="product-bottom"><div><span class="price"><?= m((int)$prod['price_cents']) ?></span><?php if($out):?> <span class="soldout">Esgotado</span><?php endif;?></div><form method="post" class="add-form"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>"><input class="qty" type="number" name="qty" min="1" max="99" value="1"<?= $out?' disabled':'' ?>><button class="add-button"<?= $out?' disabled':'' ?>>Adicionar</button></form></div></div></article><?php endforeach;?></div></section><?php endforeach;?><?php endif;?></section>

<aside class="cart" id="cart"><div class="cart-head"><div><span class="eyebrow"><?= $table?'MESA':'SEU PEDIDO' ?></span><h2><?= $cartCount>0?$cartCount.' '.($cartCount===1?'item':'itens'):'Seu pedido' ?></h2></div><?php if($cartRows):?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="clear"><button class="clear-button">Limpar</button></form><?php endif;?></div><?php if(!$cartRows):?><div class="cart-empty">Adicione produtos para começar seu pedido.</div><?php else:?><?php foreach($cartRows as $item):?><div class="cart-line"><div><strong><?= (int)$item['qty'] ?>× <?= Security::e($item['name']) ?></strong><small><?= m($item['line']) ?></small></div><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= (int)$item['id'] ?>"><button class="remove-button" aria-label="Remover item">×</button></form></div><?php endforeach;?><div class="cart-total"><span>Subtotal</span><strong><?= m($cartTotal) ?></strong></div><?php if(!$table&&$deliveryFee>0):?><p class="muted">Taxa padrão de entrega: <?= m($deliveryFee) ?></p><?php endif;?><form method="post" class="checkout-form"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="checkout"><label>Seu nome<input name="name" required placeholder="Como podemos te chamar?"></label><label>Telefone<input name="phone"<?= $table?'':' required' ?> inputmode="tel" placeholder="(22) 99999-9999"></label><?php if(!$table):?><label>Endereço de entrega<textarea name="address" required placeholder="Rua, número, bairro e referência"></textarea></label><?php else:?><div class="notice">Este pedido será enviado para <?= Security::e($table['name']) ?>.</div><?php endif;?><button class="checkout-button">Continuar para pagamento</button></form><?php endif;?></aside></div>
<?php if($showBranding):?><footer class="powered">Powered by <b>EventMenu</b></footer><?php endif;?>
</main>
<?php if($cartRows):?><a class="mobile-cart" href="#cart"><span>Ver pedido · <?= $cartCount ?> <?= $cartCount===1?'item':'itens' ?></span><strong><?= m($cartTotal) ?></strong></a><?php endif;?>
<script>
const search=document.getElementById('menu-search');
if(search){search.addEventListener('input',()=>{const q=search.value.trim().toLocaleLowerCase('pt-BR');document.querySelectorAll('[data-menu-product]').forEach(card=>{card.hidden=q!==''&&!card.dataset.search.includes(q)});document.querySelectorAll('.catalog-section').forEach(section=>{const visible=[...section.querySelectorAll('[data-menu-product]')].some(card=>!card.hidden);section.hidden=!visible})})}
</script>
</body></html>
