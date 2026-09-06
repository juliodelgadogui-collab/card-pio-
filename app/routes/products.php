<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('catalog.manage');
$tenantId = em_require_tenant();

$tenantStmt = $pdo->prepare('SELECT id,name,slug,settings FROM tenants WHERE id=?');
$tenantStmt->execute([$tenantId]);
$tenant = $tenantStmt->fetch();
if (!$tenant) exit('Empresa não encontrada.');
$publicMenuUrl = app_url('menu.php?empresa='.rawurlencode((string)$tenant['slug']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'category-save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') exit('Categoria inválida.');
        if ($id) {
            $s = $pdo->prepare('UPDATE categories SET name=?,sort_order=?,active=? WHERE id=? AND tenant_id=?');
            $s->execute([$name,(int)($_POST['sort_order'] ?? 0),isset($_POST['active'])?1:0,$id,$tenantId]);
        } else {
            $s = $pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,?,1)');
            $s->execute([$tenantId,$name,(int)($_POST['sort_order'] ?? 0)]);
            $id = (int)$pdo->lastInsertId();
        }
        Auth::audit('category.saved','category',(string)$id);
        em_flash('ok','Categoria salva.');
        em_go('products');
    }
    if ($action === 'product-save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sku = trim((string)($_POST['sku'] ?? ''));
        $price = (int)round((float)str_replace(',','.',(string)($_POST['price'] ?? '0'))*100);
        $cat = (int)($_POST['category_id'] ?? 0);
        $track = isset($_POST['track_stock']) ? 1 : 0;
        $description = mb_substr(trim((string)($_POST['description'] ?? '')),0,1200);
        $imageRaw = trim((string)($_POST['image_url'] ?? ''));
        $image = null;
        if ($imageRaw !== '') {
            $valid = filter_var($imageRaw,FILTER_VALIDATE_URL);
            $scheme = strtolower((string)parse_url($imageRaw,PHP_URL_SCHEME));
            if (!$valid || !in_array($scheme,['http','https'],true)) exit('A foto precisa usar uma URL http ou https válida.');
            $image = mb_substr($imageRaw,0,700);
        }
        $active = isset($_POST['active']) ? 1 : 0;
        if ($name === '' || $price < 0) exit('Produto inválido.');
        if ($cat) {
            $c = $pdo->prepare('SELECT id FROM categories WHERE id=? AND tenant_id=?');
            $c->execute([$cat,$tenantId]);
            if (!$c->fetchColumn()) exit('Categoria inválida.');
        }
        if ($id) {
            $exists = $pdo->prepare('SELECT id,stock_qty,track_stock FROM products WHERE id=? AND tenant_id=?');
            $exists->execute([$id,$tenantId]);
            $current = $exists->fetch();
            if (!$current) exit('Produto não encontrado.');
            if ((int)$current['track_stock'] === 1 && $track === 0) {
                $r = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_reservations WHERE tenant_id=? AND product_id=? AND status="reserved"');
                $r->execute([$tenantId,$id]);
                if ((float)$r->fetchColumn() > 0) exit('Não é possível desativar o controle de estoque enquanto houver unidades reservadas em pedidos.');
            }
            $s = $pdo->prepare('UPDATE products SET category_id=?,name=?,description=?,sku=?,price_cents=?,track_stock=?,image_url=?,active=? WHERE id=? AND tenant_id=?');
            $s->execute([$cat?:null,$name,$description,$sku?:null,$price,$track,$image,$active,$id,$tenantId]);
        } else {
            $stock = (float)str_replace(',','.',(string)($_POST['stock_qty'] ?? '0'));
            if (!is_finite($stock) || $stock < 0) exit('Estoque inicial inválido.');
            $s = $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,sku,price_cents,stock_qty,track_stock,image_url,active) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$tenantId,$cat?:null,$name,$description,$sku?:null,$price,$stock,$track,$image,$active]);
            $id = (int)$pdo->lastInsertId();
            if ($track && $stock > 0) {
                $key = 'initial-stock:'.$tenantId.':'.$id;
                $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,NULL,"in",?,?)')->execute([$tenantId,$id,$stock,$key]);
            }
        }
        Auth::audit('product.saved','product',(string)$id);
        em_flash('ok','Produto salvo e o cardápio público foi atualizado.');
        em_go('products');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId) {
    $s = $pdo->prepare('SELECT p.*,COALESCE((SELECT SUM(sr.quantity) FROM stock_reservations sr WHERE sr.tenant_id=p.tenant_id AND sr.product_id=p.id AND sr.status="reserved"),0) reserved_qty FROM products p WHERE p.id=? AND p.tenant_id=?');
    $s->execute([$editId,$tenantId]);
    $edit = $s->fetch() ?: null;
}
$cats = $pdo->prepare('SELECT * FROM categories WHERE tenant_id=? ORDER BY sort_order,name');
$cats->execute([$tenantId]);
$categories = $cats->fetchAll();
$s = $pdo->prepare('SELECT p.*,c.name category_name,COALESCE((SELECT SUM(sr.quantity) FROM stock_reservations sr WHERE sr.tenant_id=p.tenant_id AND sr.product_id=p.id AND sr.status="reserved"),0) reserved_qty FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? ORDER BY p.active DESC,p.id DESC');
$s->execute([$tenantId]);
$products = $s->fetchAll();
$activeProducts = count(array_filter($products,static fn(array $p):bool=>(int)$p['active']===1));
$activeCategories = count(array_filter($categories,static fn(array $c):bool=>(int)$c['active']===1));

em_header('Cardápio','products');
?>
<section class="page-hero catalog-publish">
  <div><span class="eyebrow">CARDÁPIO PÚBLICO</span><h2>Seu cardápio está online</h2><span class="public-url"><?= Security::e($publicMenuUrl) ?></span><div class="catalog-stats"><span class="catalog-stat"><?= $activeProducts ?> produtos publicados</span><span class="catalog-stat"><?= $activeCategories ?> categorias ativas</span></div></div>
  <div class="actions"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir cardápio</a><button type="button" class="secondary" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Link copiado ✓'">Copiar link</button><a class="button secondary" href="<?= Security::e(app_url('?route=settings#cardapio')) ?>">Personalizar aparência</a></div>
</section>

<div class="grid product-admin-grid">
<section class="card"><div class="section-head"><div><span class="eyebrow"><?= $edit?'EDIÇÃO':'NOVO ITEM' ?></span><h2><?= $edit?'Editar produto':'Adicionar produto' ?></h2></div></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="product-save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome do produto<input name="name" required value="<?= Security::e($edit['name']??'') ?>" placeholder="Ex.: X-Bacon Especial"></label><label>Preço<input name="price" required inputmode="decimal" value="<?= $edit?Security::e(number_format(((int)$edit['price_cents'])/100,2,',','')):'' ?>" placeholder="24,90"></label><label>Código interno / SKU<input name="sku" value="<?= Security::e($edit['sku']??'') ?>" placeholder="Opcional"></label><label class="span-2">Categoria<select name="category_id"><option value="0">Outros / sem categoria</option><?php foreach($categories as $c):?><option value="<?= (int)$c['id'] ?>"<?= em_selected($edit['category_id']??0,$c['id']) ?>><?= Security::e($c['name']) ?><?= !$c['active']?' (inativa)':'' ?></option><?php endforeach;?></select><small>Produtos sem categoria também aparecem no cardápio, na seção “Outros”.</small></label><?php if($edit):?><label>Saldo disponível<input value="<?= Security::e((string)($edit['stock_qty']??'0')) ?>" readonly></label><label>Reservado em pedidos<input value="<?= Security::e((string)($edit['reserved_qty']??'0')) ?>" readonly></label><?php else:?><label>Estoque inicial<input name="stock_qty" inputmode="decimal" value="0"></label><div class="muted">Depois do cadastro, o saldo é alterado no módulo Estoque.</div><?php endif;?><label class="checkbox span-2"><input type="checkbox" name="track_stock"<?= em_checked($edit['track_stock']??0) ?>> Controlar disponibilidade pelo estoque</label><?php if($edit&&(float)($edit['reserved_qty']??0)>0):?><div class="alert span-2">Há <?= Security::e((string)$edit['reserved_qty']) ?> unidade(s) reservada(s). O controle de estoque não pode ser desativado agora.</div><?php endif;?><label class="span-2">Foto do produto (URL)<input type="url" name="image_url" value="<?= Security::e($edit['image_url']??'') ?>" placeholder="https://..."><small>Opcional. A foto pode ser ocultada na personalização do cardápio.</small></label><label class="span-2">Descrição<textarea name="description" placeholder="Conte os ingredientes ou destaque o diferencial do produto."><?= Security::e($edit['description']??'') ?></textarea></label><label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($edit?($edit['active']??0):1) ?>> Publicado no cardápio</label><button class="primary span-2"><?= $edit?'Salvar alterações':'Publicar produto' ?></button><?php if($edit):?><a class="button secondary span-2" href="<?= Security::e(app_url('?route=products')) ?>">Cancelar edição</a><?php endif;?></form>
<hr style="border-color:var(--line);margin:24px 0"><span class="eyebrow">ORGANIZAÇÃO</span><h3>Nova categoria</h3><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="category-save"><label class="span-2">Nome<input name="name" required placeholder="Ex.: Hambúrgueres"></label><label>Ordem de exibição<input type="number" name="sort_order" value="0"></label><button class="secondary">Criar categoria</button></form></section>

<section class="card"><div class="section-head"><div><span class="eyebrow">CATÁLOGO</span><h2>Produtos</h2></div><span class="muted"><?= count($products) ?> cadastrados</span></div><?php if(!$products):?><div class="alert">Você ainda não cadastrou produtos. Adicione o primeiro item ao lado; ele aparecerá imediatamente no link público.</div><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><div class="product-admin-item"><?php if(!empty($p['image_url'])):?><img class="product-thumb" src="<?= Security::e($p['image_url']) ?>" alt="" loading="lazy"><?php else:?><span class="product-thumb"></span><?php endif;?><div><strong><?= Security::e($p['name']) ?></strong><?php if(!empty($p['sku'])):?><br><span class="muted"><?= Security::e($p['sku']) ?></span><?php endif;?></div></div></td><td><?= Security::e($p['category_name']??'Outros') ?></td><td><strong><?= em_money($p['price_cents']) ?></strong></td><td><?php if($p['track_stock']):?><?= Security::e((string)$p['stock_qty']) ?> disponível<?php if((float)$p['reserved_qty']>0):?><br><span class="muted"><?= Security::e((string)$p['reserved_qty']) ?> reservado</span><?php endif;?><?php else:?>Sem controle<?php endif;?></td><td><span class="status-pill <?= $p['active']?'active':'cancelled' ?>"><?= $p['active']?'Publicado':'Oculto' ?></span></td><td><div class="actions"><a class="button secondary compact" href="<?= Security::e(app_url('?route=products&edit='.(int)$p['id'])) ?>">Editar</a><?php if($p['track_stock']&&Auth::can('inventory.manage')):?><a class="button secondary compact" href="<?= Security::e(app_url('?route=inventory&product_id='.(int)$p['id'])) ?>">Estoque</a><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
</div>
<?php em_footer();
