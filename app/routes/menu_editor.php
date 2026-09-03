<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

Auth::requirePermission('catalog.manage');
$tenantId=em_require_tenant();

function menu_editor_color(string $value,string $fallback):string{
    $value=trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/',$value)?strtolower($value):$fallback;
}
function menu_editor_url(string $value):string{
    $value=trim($value);
    if($value==='')return'';
    if(!filter_var($value,FILTER_VALIDATE_URL)||!preg_match('#^https?://#i',$value))throw new RuntimeException('Logo e capa precisam usar uma URL http/https válida.');
    return mb_substr($value,0,1000);
}

$s=$pdo->prepare('SELECT * FROM tenants WHERE id=?');$s->execute([$tenantId]);$tenant=$s->fetch();
if(!$tenant){http_response_code(404);exit('Empresa não encontrada.');}
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    try{
        $action=(string)($_POST['action']??'save-design');
        if($action==='save-design'){
            $primary=menu_editor_color((string)($_POST['primary_color']??''),'#6d4aff');
            $secondary=menu_editor_color((string)($_POST['secondary_color']??''),'#8b5cf6');
            $background=menu_editor_color((string)($_POST['menu_background_color']??''),'#f5f6fb');
            $layout=(string)($_POST['menu_layout']??'classic');if(!in_array($layout,['classic','compact','cards'],true))$layout='classic';
            $categoryStyle=(string)($_POST['menu_category_style']??'chips');if(!in_array($categoryStyle,['chips','tabs','hidden'],true))$categoryStyle='chips';
            $settings=array_merge($settings,[
                'menu_logo_url'=>menu_editor_url((string)($_POST['menu_logo_url']??'')),
                'menu_banner_url'=>menu_editor_url((string)($_POST['menu_banner_url']??'')),
                'menu_headline'=>mb_substr(trim((string)($_POST['menu_headline']??'')),0,120),
                'menu_subtitle'=>mb_substr(trim((string)($_POST['menu_subtitle']??'')),0,220),
                'menu_background_color'=>$background,
                'menu_layout'=>$layout,
                'menu_category_style'=>$categoryStyle,
                'menu_search_enabled'=>isset($_POST['menu_search_enabled']),
                'menu_show_images'=>isset($_POST['menu_show_images']),
                'menu_show_descriptions'=>isset($_POST['menu_show_descriptions']),
                'menu_show_preparation'=>isset($_POST['menu_show_preparation']),
                'menu_show_badges'=>isset($_POST['menu_show_badges']),
                'menu_show_featured'=>isset($_POST['menu_show_featured']),
                'menu_show_eventmenu_brand'=>isset($_POST['menu_show_eventmenu_brand']),
                'menu_cart_title'=>mb_substr(trim((string)($_POST['menu_cart_title']??'Meu pedido')),0,60)?:'Meu pedido',
                'menu_add_button_label'=>mb_substr(trim((string)($_POST['menu_add_button_label']??'Adicionar')),0,40)?:'Adicionar',
            ]);
            $pdo->prepare('UPDATE tenants SET primary_color=?,secondary_color=?,settings=? WHERE id=?')->execute([$primary,$secondary,json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);
            Auth::audit('menu.design.updated','tenant',(string)$tenantId,['layout'=>$layout,'category_style'=>$categoryStyle]);
            em_flash('ok','Visual do cardápio salvo. A prévia pública já foi atualizada.');
        }elseif($action==='categories'){
            $sort=(array)($_POST['sort_order']??[]);$active=(array)($_POST['category_active']??[]);
            Database::transaction(function(PDO $db)use($tenantId,$sort,$active){
                $check=$db->prepare('SELECT id FROM categories WHERE tenant_id=?');$check->execute([$tenantId]);$valid=array_flip(array_map('intval',$check->fetchAll(PDO::FETCH_COLUMN)));
                $up=$db->prepare('UPDATE categories SET sort_order=?,active=? WHERE id=? AND tenant_id=?');
                foreach($sort as$id=>$order){$id=(int)$id;if(!isset($valid[$id]))continue;$up->execute([(int)$order,isset($active[$id])?1:0,$id,$tenantId]);}
            });
            Auth::audit('menu.categories.reordered','tenant',(string)$tenantId);
            em_flash('ok','Ordem e visibilidade das categorias atualizadas.');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());}
    em_go('menu-editor');
}

$s=$pdo->prepare('SELECT * FROM tenants WHERE id=?');$s->execute([$tenantId]);$tenant=$s->fetch();
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];
$c=$pdo->prepare('SELECT id,name,sort_order,active FROM categories WHERE tenant_id=? ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=$c->fetchAll();
$publicUrl=em_url('/menu.php?empresa='.rawurlencode((string)$tenant['slug']));
$primary=menu_editor_color((string)($tenant['primary_color']??''),'#6d4aff');$secondary=menu_editor_color((string)($tenant['secondary_color']??''),'#8b5cf6');$background=menu_editor_color((string)($settings['menu_background_color']??''),'#f5f6fb');

em_header('Editar Cardápio Digital','menu-editor');
?>
<style>.menu-editor-layout{display:grid;grid-template-columns:minmax(360px,520px) minmax(360px,1fr);gap:18px;align-items:start}.menu-editor-preview{position:sticky;top:18px}.preview-frame{width:100%;height:760px;border:1px solid var(--line);border-radius:28px;background:#fff}.editor-section{padding:18px 0;border-top:1px solid var(--line)}.editor-section:first-of-type{border-top:0;padding-top:0}.color-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}.color-field{display:grid;grid-template-columns:52px 1fr;gap:8px;align-items:center}.color-field input[type=color]{width:52px;height:44px;padding:3px}.category-editor{display:grid;gap:8px}.category-editor-row{display:grid;grid-template-columns:1fr 90px 90px;gap:8px;align-items:center;padding:10px;border:1px solid var(--line);border-radius:12px}@media(max-width:1050px){.menu-editor-layout{grid-template-columns:1fr}.menu-editor-preview{position:static}.preview-frame{height:680px}}@media(max-width:650px){.color-row{grid-template-columns:1fr}.category-editor-row{grid-template-columns:1fr 80px}.category-editor-row label:last-child{grid-column:1/-1}}</style>
<div class="section-head"><div><h2 style="margin:0">Editor do Cardápio</h2><p class="muted">Personalize a experiência do cliente sem misturar aparência com cadastro de produtos.</p></div><div class="actions"><a class="button secondary" href="<?= Security::e(em_url('/?route=products')) ?>">Produtos</a><a class="button primary" target="_blank" href="<?= Security::e($publicUrl) ?>">Abrir cardápio público</a></div></div>
<div class="menu-editor-layout"><section class="card"><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save-design">
<div class="span-2 editor-section"><h3>Identidade</h3><p class="muted">Capa, marca e cores próprias do estabelecimento, como no sistema anterior.</p></div>
<label class="span-2">Título principal<input name="menu_headline" maxlength="120" value="<?= Security::e($settings['menu_headline']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label>
<label class="span-2">Subtítulo<input name="menu_subtitle" maxlength="220" value="<?= Security::e($settings['menu_subtitle']??'') ?>" placeholder="Cardápio digital · delivery · retirada"></label>
<label class="span-2">Logo (URL da imagem)<input type="url" name="menu_logo_url" value="<?= Security::e($settings['menu_logo_url']??'') ?>" placeholder="https://..."></label>
<label class="span-2">Capa / banner (URL da imagem)<input type="url" name="menu_banner_url" value="<?= Security::e($settings['menu_banner_url']??'') ?>" placeholder="https://..."></label>
<div class="span-2 color-row"><label>Cor principal<div class="color-field"><input type="color" name="primary_color" value="<?= Security::e($primary) ?>"><code><?= Security::e($primary) ?></code></div></label><label>Cor secundária<div class="color-field"><input type="color" name="secondary_color" value="<?= Security::e($secondary) ?>"><code><?= Security::e($secondary) ?></code></div></label><label>Fundo<div class="color-field"><input type="color" name="menu_background_color" value="<?= Security::e($background) ?>"><code><?= Security::e($background) ?></code></div></label></div>
<div class="span-2 editor-section"><h3>Layout</h3></div>
<label>Estilo dos produtos<select name="menu_layout"><option value="classic"<?= em_selected($settings['menu_layout']??'classic','classic') ?>>Clássico / lista</option><option value="compact"<?= em_selected($settings['menu_layout']??'classic','compact') ?>>Compacto</option><option value="cards"<?= em_selected($settings['menu_layout']??'classic','cards') ?>>Cards</option></select></label>
<label>Categorias<select name="menu_category_style"><option value="chips"<?= em_selected($settings['menu_category_style']??'chips','chips') ?>>Botões arredondados</option><option value="tabs"<?= em_selected($settings['menu_category_style']??'chips','tabs') ?>>Abas</option><option value="hidden"<?= em_selected($settings['menu_category_style']??'chips','hidden') ?>>Não mostrar</option></select></label>
<label class="checkbox span-2"><input type="checkbox" name="menu_search_enabled"<?= em_checked($settings['menu_search_enabled']??true) ?>> Mostrar busca de produtos</label>
<label class="checkbox"><input type="checkbox" name="menu_show_images"<?= em_checked($settings['menu_show_images']??true) ?>> Mostrar imagens</label><label class="checkbox"><input type="checkbox" name="menu_show_descriptions"<?= em_checked($settings['menu_show_descriptions']??true) ?>> Mostrar descrições</label><label class="checkbox"><input type="checkbox" name="menu_show_preparation"<?= em_checked($settings['menu_show_preparation']??true) ?>> Mostrar tempo de preparo</label><label class="checkbox"><input type="checkbox" name="menu_show_badges"<?= em_checked($settings['menu_show_badges']??true) ?>> Mostrar selos</label><label class="checkbox"><input type="checkbox" name="menu_show_featured"<?= em_checked($settings['menu_show_featured']??true) ?>> Destacar produtos marcados</label><label class="checkbox"><input type="checkbox" name="menu_show_eventmenu_brand"<?= em_checked($settings['menu_show_eventmenu_brand']??true) ?>> Mostrar “EventMenu Premium” na capa</label>
<label>Título do carrinho<input name="menu_cart_title" maxlength="60" value="<?= Security::e($settings['menu_cart_title']??'Meu pedido') ?>"></label><label>Texto do botão<input name="menu_add_button_label" maxlength="40" value="<?= Security::e($settings['menu_add_button_label']??'Adicionar') ?>"></label><button class="primary span-2">Salvar aparência</button></form>
<div class="editor-section"><h3>Categorias</h3><p class="muted">Defina a ordem e o que fica visível para o cliente.</p><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="categories"><div class="category-editor"><?php foreach($categories as$cat):?><div class="category-editor-row"><strong><?= Security::e($cat['name']) ?></strong><label>Ordem<input type="number" name="sort_order[<?= (int)$cat['id'] ?>]" value="<?= (int)$cat['sort_order'] ?>"></label><label class="checkbox"><input type="checkbox" name="category_active[<?= (int)$cat['id'] ?>]"<?= em_checked($cat['active']) ?>> Visível</label></div><?php endforeach;?></div><?php if(!$categories):?><p class="muted">Crie categorias em Produtos antes de organizá-las aqui.</p><?php else:?><button class="secondary" style="margin-top:12px">Salvar categorias</button><?php endif;?></form></div></section>
<section class="card menu-editor-preview"><div class="section-head"><div><h2>Prévia real</h2><p class="muted">Depois de salvar, esta é a mesma página vista pelo cliente.</p></div><button type="button" class="secondary" onclick="document.getElementById('menuPreview').src=document.getElementById('menuPreview').src">Atualizar</button></div><iframe id="menuPreview" class="preview-frame" src="<?= Security::e($publicUrl) ?>" title="Prévia do cardápio"></iframe></section></div>
<script>document.querySelectorAll('.color-field input[type=color]').forEach(el=>el.addEventListener('input',()=>{const code=el.parentElement.querySelector('code');if(code)code.textContent=el.value;}));</script>
<?php em_footer();
