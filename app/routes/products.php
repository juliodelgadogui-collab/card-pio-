<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('catalog.manage');$tenantId=em_require_tenant();
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    if($action==='category-save'){
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));if($name==='')exit('Categoria inválida.');
        if($id){$s=$pdo->prepare('UPDATE categories SET name=?,sort_order=?,active=? WHERE id=? AND tenant_id=?');$s->execute([$name,(int)($_POST['sort_order']??0),isset($_POST['active'])?1:0,$id,$tenantId]);}
        else{$s=$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,?,1)');$s->execute([$tenantId,$name,(int)($_POST['sort_order']??0)]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('category.saved','category',(string)$id);em_flash('ok','Categoria salva.');em_go('products');
    }
    if($action==='product-save'){
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$sku=trim((string)($_POST['sku']??''));$price=(int)round((float)str_replace(',','.',(string)($_POST['price']??'0'))*100);$cat=(int)($_POST['category_id']??0);$stock=(float)str_replace(',','.',(string)($_POST['stock_qty']??'0'));$track=isset($_POST['track_stock'])?1:0;
        if($name===''||$price<0)exit('Produto inválido.');
        if($cat){$c=$pdo->prepare('SELECT id FROM categories WHERE id=? AND tenant_id=?');$c->execute([$cat,$tenantId]);if(!$c->fetchColumn())exit('Categoria inválida.');}
        $args=[$cat?:null,$name,trim((string)($_POST['description']??'')),$sku?:null,$price,$stock,$track,trim((string)($_POST['image_url']??''))?:null,isset($_POST['active'])?1:0];
        if($id){$s=$pdo->prepare('UPDATE products SET category_id=?,name=?,description=?,sku=?,price_cents=?,stock_qty=?,track_stock=?,image_url=?,active=? WHERE id=? AND tenant_id=?');$s->execute([...$args,$id,$tenantId]);}
        else{$s=$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,sku,price_cents,stock_qty,track_stock,image_url,active) VALUES (?,?,?,?,?,?,?,?,?,?)');$s->execute([$tenantId,...$args]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('product.saved','product',(string)$id);em_flash('ok','Produto salvo com sucesso.');em_go('products');
    }
}
$editId=(int)($_GET['edit']??0);$edit=null;if($editId){$s=$pdo->prepare('SELECT * FROM products WHERE id=? AND tenant_id=?');$s->execute([$editId,$tenantId]);$edit=$s->fetch()?:null;}
$cats=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? ORDER BY sort_order,name');$cats->execute([$tenantId]);$categories=$cats->fetchAll();
$s=$pdo->prepare('SELECT p.*,c.name category_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? ORDER BY p.active DESC,p.id DESC');$s->execute([$tenantId]);$products=$s->fetchAll();
em_header('Cardápio e produtos','products');
?><div class="grid" style="grid-template-columns:minmax(290px,1fr) minmax(0,2fr)"><section class="card"><h2><?= $edit?'Editar produto':'Novo produto' ?></h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="product-save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome<input name="name" required value="<?= Security::e($edit['name']??'') ?>"></label><label>Preço<input name="price" required inputmode="decimal" value="<?= $edit?Security::e(number_format(((int)$edit['price_cents'])/100,2,',','')):'' ?>"></label><label>SKU<input name="sku" value="<?= Security::e($edit['sku']??'') ?>"></label><label class="span-2">Categoria<select name="category_id"><option value="0">Sem categoria</option><?php foreach($categories as $c):?><option value="<?= (int)$c['id'] ?>"<?= em_selected($edit['category_id']??0,$c['id']) ?>><?= Security::e($c['name']) ?></option><?php endforeach;?></select></label><label>Estoque<input name="stock_qty" inputmode="decimal" value="<?= Security::e((string)($edit['stock_qty']??'0')) ?>"></label><label class="checkbox"><input type="checkbox" name="track_stock"<?= em_checked($edit['track_stock']??0) ?>> Controlar estoque</label><label class="span-2">Imagem (URL)<input type="url" name="image_url" value="<?= Security::e($edit['image_url']??'') ?>"></label><label class="span-2">Descrição<textarea name="description"><?= Security::e($edit['description']??'') ?></textarea></label><label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($edit?($edit['active']??0):1) ?>> Produto ativo</label><button class="primary span-2">Salvar produto</button><?php if($edit):?><a class="button secondary span-2" href="/?route=products">Cancelar edição</a><?php endif;?></form><hr style="border-color:var(--line);margin:24px 0"><h3>Nova categoria</h3><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="category-save"><label>Nome<input name="name" required></label><label>Ordem<input type="number" name="sort_order" value="0"></label><button class="secondary" style="margin-top:10px">Criar categoria</button></form></section><section class="card"><div class="section-head"><h2>Produtos</h2><span class="muted"><?= count($products) ?> cadastrados</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Produto</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($products as $p):?><tr><td><strong><?= Security::e($p['name']) ?></strong><br><span class="muted"><?= Security::e($p['sku']??'') ?></span></td><td><?= Security::e($p['category_name']??'—') ?></td><td><?= em_money($p['price_cents']) ?></td><td><?= $p['track_stock']?Security::e((string)$p['stock_qty']):'Livre' ?></td><td><span class="badge"><?= $p['active']?'Ativo':'Inativo' ?></span></td><td><a class="button secondary" href="/?route=products&edit=<?= (int)$p['id'] ?>">Editar</a></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();
