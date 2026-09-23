<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\ConfiguredOrderService;
use EventMenu\Services\PublicMenuService;
use EventMenu\Services\PublicUnitService;

$pdo=Database::connection();
$slug=trim((string)($_GET['empresa']??''));
$stmt=$pdo->prepare('SELECT * FROM tenants WHERE slug=? AND status="active" LIMIT 1');
$stmt->execute([$slug]);
$tenant=$stmt->fetch();
if(!$tenant){http_response_code(404);exit('Empresa não encontrada.');}
$tenantId=(int)$tenant['id'];
if(!TenantFeatures::menu($tenantId)){http_response_code(404);exit('Cardápio não habilitado para esta empresa.');}

$settings=json_decode((string)($tenant['settings']??'{}'),true)?:[];
$publicUnits=new PublicUnitService();
$units=$publicUnits->units($tenantId);
if(!$units){http_response_code(503);exit('Nenhuma unidade disponível para pedidos.');}

$tableToken=trim((string)($_GET['mesa']??''));
$table=null;$selectedUnit=null;
if($tableToken!==''){
    $s=$pdo->prepare('SELECT * FROM restaurant_tables WHERE tenant_id=? AND qr_token=? AND status<>"inactive" LIMIT 1');
    $s->execute([$tenantId,$tableToken]);
    $table=$s->fetch()?:null;
    if(!$table){http_response_code(404);exit('Mesa não encontrada ou inativa.');}
    $tableUnitId=(int)($table['unit_id']??0);
    if($tableUnitId<1){http_response_code(503);exit('Esta mesa ainda não está vinculada a uma unidade operacional.');}
    try{$selectedUnit=$publicUnits->selectById($tenantId,$tableUnitId);}catch(Throwable){http_response_code(503);exit('A unidade desta mesa está indisponível.');}
}else{
    $requestedCode=trim((string)($_GET['unidade']??''));
    if($requestedCode!==''){
        try{$selectedUnit=$publicUnits->selectByCode($tenantId,$requestedCode);}catch(Throwable){http_response_code(404);exit('Unidade não encontrada ou indisponível.');}
    }elseif(count($units)>1){
        header('Location: '.app_url('loja.php?empresa='.rawurlencode($slug)),true,302);exit;
    }else{
        $selectedUnit=$publicUnits->selectById($tenantId,(int)$units[0]['id']);
    }
}
$publicUnitId=(int)$selectedUnit['id'];
$unitCode=(string)$selectedUnit['code'];

$cartKey=$table?'public_cart_'.$tenantId.'_table_'.(int)$table['id']:'public_cart_'.$tenantId.'_unit_'.$publicUnitId;
if(!isset($_SESSION[$cartKey])||!is_array($_SESSION[$cartKey]))$_SESSION[$cartKey]=[];
$cart=&$_SESSION[$cartKey];
$error=null;
$configured=new ConfiguredOrderService();

$legacy=false;
foreach($cart as$value){if(!is_array($value)){$legacy=true;break;}}
if($legacy){
    $old=$cart;$cart=[];
    foreach($old as$productId=>$qty){
        $productId=(int)$productId;$qty=(float)$qty;
        if($productId<1||$qty<=0)continue;
        $key=hash('sha256',$productId.':');
        $cart[$key]=['product_id'=>$productId,'qty'=>$qty,'option_ids'=>[]];
    }
}

function flatten_options(mixed$value):array{
    $out=[];
    $walk=function(mixed$v)use(&$out,&$walk):void{
        if(is_array($v)){foreach($v as$x)$walk($x);return;}
        $id=(int)$v;if($id>0)$out[]=$id;
    };
    $walk($value);$out=array_values(array_unique($out));sort($out);return$out;
}
function menu_money(int$cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
function menu_color(mixed$value,string$fallback):string{$v=strtolower(trim((string)$value));return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$fallback;}
function menu_modifier_rule(int$min,int$max):string{
    if($min>0&&$min===$max)return $min===1?'Escolha 1 opção':'Escolha '.$min.' opções';
    if($min>0)return 'Escolha de '.$min.' a '.$max.' opções';
    return $max===1?'Escolha até 1 opção':'Escolha até '.$max.' opções';
}
function menu_friendly_error(Throwable$e):string{
    $message=trim($e->getMessage());
    if($message==='')return'Não foi possível concluir a operação. Tente novamente.';
    $technical=['sqlstate','select ','insert ','update ','delete ','http 4','http 5','json','payload','endpoint','webhook','provider','stack trace','exception','undefined','nullpointer','pdoexception'];
    $lower=mb_strtolower($message);
    foreach($technical as$term)if(str_contains($lower,$term))return'Não foi possível concluir a operação agora. Tente novamente.';
    return mb_substr($message,0,220);
}
function menu_stock_row(PDO$pdo,int$unitId,int$productId,int$tenantId):array{
    $q=$pdo->prepare('SELECT p.id,p.track_stock,EXISTS(SELECT 1 FROM product_recipes r WHERE r.tenant_id=p.tenant_id AND r.product_id=p.id) has_recipe,COALESCE(ui.stock_qty,0) unit_stock FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.id=? AND p.tenant_id=? AND p.active=1');
    $q->execute([$unitId,$productId,$tenantId]);
    return $q->fetch()?:[];
}

$selectedFulfillment=in_array((string)($_POST['fulfillment']??'pickup'),['pickup','delivery'],true)?(string)($_POST['fulfillment']??'pickup'):'pickup';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sua sessão expirou. Atualize a página e tente novamente.';
    else{
        $action=(string)($_POST['action']??'');
        if($action==='add'){
            try{
                $id=(int)($_POST['product_id']??0);
                $qty=max(1,min(99,(int)($_POST['qty']??1)));
                $optionIds=flatten_options($_POST['option_ids']??[]);
                $stock=menu_stock_row($pdo,$publicUnitId,$id,$tenantId);
                if(!$stock)throw new RuntimeException('Produto indisponível.');
                if((int)$stock['track_stock']&&!(int)$stock['has_recipe']&&(float)$stock['unit_stock']<=0)throw new RuntimeException('Produto esgotado nesta unidade.');
                $resolved=$configured->resolveLine($pdo,$tenantId,['product_id'=>$id,'qty'=>$qty,'option_ids'=>$optionIds],false);
                $lineKey=hash('sha256',$id.':'.implode(',',$resolved['option_ids']));
                if(isset($cart[$lineKey]))$cart[$lineKey]['qty']=min(99,(float)$cart[$lineKey]['qty']+$qty);
                else$cart[$lineKey]=['product_id'=>$id,'qty'=>$qty,'option_ids'=>$resolved['option_ids']];
            }catch(Throwable$e){$error=menu_friendly_error($e);}
        }elseif($action==='restore'){
            try{
                $decoded=json_decode((string)($_POST['cart_backup']??''),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($decoded)||count($decoded)>60)throw new RuntimeException('Não foi possível recuperar este carrinho.');
                $restored=[];
                foreach($decoded as$line){
                    if(!is_array($line))continue;
                    $id=(int)($line['product_id']??0);
                    $qty=max(1,min(99,(int)($line['qty']??1)));
                    $optionIds=flatten_options($line['option_ids']??[]);
                    $stock=menu_stock_row($pdo,$publicUnitId,$id,$tenantId);
                    if(!$stock)continue;
                    if((int)$stock['track_stock']&&!(int)$stock['has_recipe']&&(float)$stock['unit_stock']<=0)continue;
                    $resolved=$configured->resolveLine($pdo,$tenantId,['product_id'=>$id,'qty'=>$qty,'option_ids'=>$optionIds],false);
                    $lineKey=hash('sha256',$id.':'.implode(',',$resolved['option_ids']));
                    if(isset($restored[$lineKey]))$restored[$lineKey]['qty']=min(99,(float)$restored[$lineKey]['qty']+$qty);
                    else$restored[$lineKey]=['product_id'=>$id,'qty'=>$qty,'option_ids'=>$resolved['option_ids']];
                }
                if(!$restored)throw new RuntimeException('Os itens salvos não estão mais disponíveis.');
                $cart=$restored;
            }catch(Throwable$e){$error=menu_friendly_error($e);}
        }elseif($action==='remove'){
            unset($cart[(string)($_POST['line_key']??'')]);
        }elseif($action==='clear'){
            $cart=[];
        }elseif($action==='checkout'){
            try{
                $order=(new PublicMenuService())->create(
                    $tenantId,
                    array_values($cart),
                    (string)($_POST['name']??''),
                    (string)($_POST['phone']??''),
                    (string)($_POST['address']??''),
                    $table?$tableToken:null,
                    $table?'table':$selectedFulfillment
                );
                $cart=[];
                header('Location: '.app_url('pedido.php?t='.rawurlencode($order['public_token'])),true,303);exit;
            }catch(Throwable$e){$error=menu_friendly_error($e);}
        }
        if($action!=='checkout'&&$error===null){
            $query=['empresa'=>$slug];if($table)$query['mesa']=$tableToken;else$query['unidade']=$unitCode;
            header('Location: '.app_url('menu.php?'.http_build_query($query)),true,303);exit;
        }
    }
}

$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');
$c->execute([$tenantId]);$categories=$c->fetchAll();
$p=$pdo->prepare('SELECT p.*,COALESCE(ui.stock_qty,0) unit_stock_qty,EXISTS(SELECT 1 FROM product_recipes r WHERE r.tenant_id=p.tenant_id AND r.product_id=p.id) has_recipe FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.active=1 ORDER BY p.category_id,p.name');
$p->execute([$publicUnitId,$tenantId]);$products=$p->fetchAll();
$modifierCatalog=$configured->catalogModifiers($pdo,$tenantId,array_column($products,'id'));
$categoryNames=[];foreach($categories as$cat)$categoryNames[(int)$cat['id']]=(string)$cat['name'];
$groups=[];foreach($products as$prod){$cid=(int)($prod['category_id']??0);$groups[$categoryNames[$cid]??'Outros'][]=$prod;}

$cartRows=[];$cartTotal=0;$cartCount=0;$invalid=[];
foreach($cart as$lineKey=>$line){
    try{
        $resolved=$configured->resolveLine($pdo,$tenantId,$line,false);
        $mods=[];foreach($resolved['modifiers']as$m)$mods[]=$m['group_name'].': '.$m['name'];
        $cartTotal+=(int)$resolved['total_cents'];
        $cartCount+=(int)ceil((float)$resolved['quantity']);
        $cartRows[]=['line_key'=>$lineKey,'id'=>$resolved['product_id'],'name'=>$resolved['name'],'qty'=>$resolved['quantity'],'line'=>$resolved['total_cents'],'mods'=>$mods];
    }catch(Throwable){
        $invalid[]=$lineKey;
        if($error===null)$error='Um item do carrinho foi alterado e precisa ser adicionado novamente.';
    }
}
foreach($invalid as$key)unset($cart[$key]);

$deliveryFee=max(0,(int)($settings['delivery_fee_cents']??0));
$minimum=max(0,(int)($settings['min_delivery_order_cents']??0));
$title=trim((string)($settings['menu_public_title']??''))?:$tenant['name'];
$subtitle=trim((string)($settings['menu_subtitle']??''))?:'Sabor, praticidade e uma experiência feita para você.';
$primary=menu_color($settings['menu_primary_color']??'','#6236df');
$background=menu_color($settings['menu_background_color']??'','#f7f7fb');
$surface=menu_color($settings['menu_surface_color']??'','#ffffff');
$text=menu_color($settings['menu_text_color']??'','#242136');
$layout=in_array(($settings['menu_layout']??'cards'),['cards','compact'],true)?(string)$settings['menu_layout']:'cards';
$headerStyle=in_array(($settings['menu_header_style']??'gradient'),['gradient','solid','minimal'],true)?(string)$settings['menu_header_style']:'gradient';
$showImages=(bool)($settings['menu_show_images']??true);
$showSearch=(bool)($settings['menu_show_search']??true);
$showBranding=(bool)($settings['menu_show_branding']??true);
$logoUrl=trim((string)($settings['menu_logo_url']??''));
$coverUrl=trim((string)($settings['menu_cover_url']??''));
$whatsapp=preg_replace('/\D+/','',(string)($settings['whatsapp']??''))?:'';
$canonical=app_url('menu.php?empresa='.rawurlencode($slug).'&unidade='.rawurlencode($unitCode));
$changeUnitUrl=app_url('loja.php?empresa='.rawurlencode($slug));
$unitAddress=trim((string)($selectedUnit['address']??''));
$storageKey='eventmenu:menu:'.$tenantId.':'.$publicUnitId.':'.($table?(int)$table['id']:0);
$cartBackup=array_values(array_map(static fn(array$line):array=>[
    'product_id'=>(int)($line['product_id']??0),
    'qty'=>(int)max(1,(float)($line['qty']??1)),
    'option_ids'=>array_values(array_map('intval',(array)($line['option_ids']??[])))
],array_filter($cart,'is_array')));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="<?= Security::e($primary) ?>">
<title><?= Security::e($title) ?> — <?= Security::e($selectedUnit['name']) ?></title>
<meta name="description" content="<?= Security::e($subtitle) ?>">
<link rel="canonical" href="<?= Security::e($canonical) ?>">
<link rel="stylesheet" href="<?= Security::e(app_url('assets/menu-premium-v5.css')) ?>">
<style>:root{--em-bg:<?= Security::e($background) ?>;--em-surface:<?= Security::e($surface) ?>;--em-text:<?= Security::e($text) ?>;--em-primary:<?= Security::e($primary) ?>}</style>
</head>
<body>
<div id="offline-banner" class="offline-banner" hidden>Sem internet. Seu pedido continua salvo neste aparelho.</div>
<main class="shell" data-menu-root data-storage-key="<?= Security::e($storageKey) ?>">
<header class="topbar">
    <div class="identity">
        <?php if($logoUrl!==''):?>
            <img class="logo" src="<?= Security::e($logoUrl) ?>" alt="Logo <?= Security::e($title) ?>" width="48" height="48" decoding="async">
        <?php else:?>
            <div class="logo monogram" aria-hidden="true"><?= Security::e(mb_strtoupper(mb_substr($title,0,1))) ?></div>
        <?php endif;?>
        <div>
            <strong><?= Security::e($title) ?></strong>
            <small><?= $table?'Pedido pela '.$table['name'].' · '.$selectedUnit['name']:$selectedUnit['name'].' · Cardápio digital' ?></small>
        </div>
    </div>
    <div class="contact">
        <?php if(!$table&&count($units)>1):?><a href="<?= Security::e($changeUnitUrl) ?>">Trocar unidade</a><?php endif;?>
        <?php if($whatsapp!==''):?><a target="_blank" rel="noopener" href="https://wa.me/<?= Security::e($whatsapp) ?>">Falar com a loja</a><?php endif;?>
    </div>
</header>

<section class="hero <?= Security::e($headerStyle) ?><?= $coverUrl!==''?' with-cover':'' ?>">
    <?php if($coverUrl!==''):?><img class="hero-cover" src="<?= Security::e($coverUrl) ?>" alt="" fetchpriority="high" decoding="async"><?php endif;?>
    <div class="hero-body">
        <span class="eyebrow"><?= $table?'Pedido na mesa':'Cardápio · '.Security::e(mb_strtoupper((string)$selectedUnit['name'])) ?></span>
        <h1><?= Security::e($title) ?></h1>
        <p><?= Security::e($subtitle) ?></p>
        <div class="meta">
            <span class="pill success">✓ Pedidos online</span>
            <?php if($table):?>
                <span class="pill"><?= Security::e($table['name']) ?></span>
                <span class="pill">Consumo no local</span>
            <?php else:?>
                <span class="pill">Retirada</span>
                <span class="pill">Delivery</span>
            <?php endif;?>
        </div>
        <div class="commercial-meta">
            <?php if(!$table):?>
                <div class="info"><span>Entrega</span><strong><?= $deliveryFee>0?menu_money($deliveryFee):'Consulte no pedido' ?></strong></div>
                <div class="info"><span>Pedido mínimo</span><strong><?= $minimum>0?menu_money($minimum):'Sem mínimo informado' ?></strong></div>
            <?php else:?>
                <div class="info"><span>Atendimento</span><strong><?= Security::e($table['name']) ?></strong></div>
            <?php endif;?>
            <div class="info"><span>Unidade</span><strong><?= Security::e((string)$selectedUnit['name']) ?></strong></div>
            <?php if($unitAddress!==''):?><div class="info"><span>Onde estamos</span><strong><?= Security::e($unitAddress) ?></strong></div><?php endif;?>
        </div>
        <?php if(!empty($settings['menu_message'])):?><div class="message"><?= Security::e((string)$settings['menu_message']) ?></div><?php endif;?>
    </div>
</section>

<div data-restore-host></div>
<?php if($error):?><div class="alert" role="alert"><?= Security::e($error) ?></div><?php endif;?>

<?php if($groups):?>
<div class="toolbar" aria-label="Navegação do cardápio">
    <div class="toolbar-row">
        <?php if($showSearch):?>
        <label class="search" aria-label="Buscar produtos">
            <span aria-hidden="true">⌕</span>
            <input id="menu-search" type="search" placeholder="O que você quer pedir?" autocomplete="off">
            <button type="button" class="search-clear" aria-label="Limpar busca">×</button>
        </label>
        <?php endif;?>
        <?php if($cartRows):?><a class="ghost-action" href="#cart" aria-label="Ir para o pedido">Pedido · <?= $cartCount ?></a><?php endif;?>
    </div>
    <nav class="cats" aria-label="Categorias">
        <?php $firstCategory=true;foreach($groups as$name=>$rows):$anchor='cat-'.substr(hash('sha256',$name),0,8);?>
            <a data-category-link class="<?= $firstCategory?'active':'' ?>" href="#<?= $anchor ?>"><?= Security::e($name) ?></a>
        <?php $firstCategory=false;endforeach;?>
    </nav>
</div>
<?php endif;?>

<div class="main">
<section aria-label="Produtos">
<?php if(!$groups):?>
    <div class="screen-state"><strong>Cardápio em preparação</strong><p>Os produtos serão publicados em breve.</p></div>
<?php else:?>
    <?php foreach($groups as$name=>$rows):$anchor='cat-'.substr(hash('sha256',$name),0,8);?>
    <section class="catalog-section" id="<?= $anchor ?>">
        <div class="section-head"><h2><?= Security::e($name) ?></h2><small><?= count($rows) ?> <?= count($rows)===1?'item':'itens' ?></small></div>
        <div class="products <?= Security::e($layout) ?>">
        <?php foreach($rows as$prod):
            $out=(int)$prod['track_stock']&&!(int)$prod['has_recipe']&&(float)$prod['unit_stock_qty']<=0;
            $hasImage=$showImages&&!empty($prod['image_url']);
            $productModifiers=$modifierCatalog[(int)$prod['id']]??[];
            $searchText=mb_strtolower(trim((string)$prod['name'].' '.(string)($prod['description']??'').' '.$name));
        ?>
        <article class="product<?= $hasImage?'':' no-image' ?>" data-product data-search="<?= Security::e($searchText) ?>">
            <?php if($hasImage):?><img class="photo" src="<?= Security::e($prod['image_url']) ?>" alt="<?= Security::e($prod['name']) ?>" loading="lazy" decoding="async" width="118" height="118"><?php endif;?>
            <div class="product-info">
                <h3><?= Security::e($prod['name']) ?></h3>
                <?php if(trim((string)($prod['description']??''))!==''):?><p class="desc"><?= Security::e($prod['description']) ?></p><?php endif;?>
                <form method="post" data-product-form data-base-price="<?= (int)$prod['price_cents'] ?>">
                    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>">
                    <input type="hidden" name="qty" value="1">
                    <?php if($productModifiers):?>
                    <div class="product-customizer">
                        <?php foreach($productModifiers as$mg):
                            $max=max(1,(int)$mg['max_select']);
                            $min=max((int)$mg['min_select'],(int)$mg['required']?1:0);
                            $rule=menu_modifier_rule($min,$max);
                        ?>
                        <section class="modifier-group" data-modifier-group data-min="<?= $min ?>" data-max="<?= $max ?>" data-rule="<?= Security::e($rule) ?>">
                            <div class="modifier-head">
                                <div><strong><?= Security::e($mg['name']) ?></strong><span class="modifier-rule"><?= Security::e($rule) ?></span></div>
                                <span class="modifier-badge<?= $min>0?' required':'' ?>"><?= $min>0?'Obrigatório':'Opcional' ?></span>
                            </div>
                            <div class="modifier-options">
                            <?php foreach($mg['options']as$opt):?>
                                <div class="modifier-option">
                                    <label><input data-price-delta="<?= (int)$opt['price_delta_cents'] ?>" type="<?= $max===1?'radio':'checkbox' ?>" name="option_ids[<?= (int)$mg['id'] ?>]<?= $max===1?'':'[]' ?>" value="<?= (int)$opt['id'] ?>"> <span><?= Security::e($opt['name']) ?></span></label>
                                    <strong><?= (int)$opt['price_delta_cents']!==0?Security::e(((int)$opt['price_delta_cents']>0?'+ ':'- ').menu_money(abs((int)$opt['price_delta_cents']))):'Incluído' ?></strong>
                                </div>
                            <?php endforeach;?>
                            </div>
                        </section>
                        <?php endforeach;?>
                    </div>
                    <?php endif;?>
                    <noscript><button class="quick-add"<?= $out?' disabled':'' ?>><?= $out?'Esgotado':'Adicionar' ?></button></noscript>
                </form>
                <div class="product-bottom">
                    <div>
                        <?php if($productModifiers):?><span class="from-label">A partir de</span><?php endif;?>
                        <span class="price"><?= menu_money((int)$prod['price_cents']) ?></span>
                        <?php if($out):?><span class="sold">Indisponível agora</span><?php endif;?>
                    </div>
                    <button type="button" class="open-product"<?= $out?' disabled':'' ?>><?= $out?'Indisponível':($productModifiers?'Escolher':'Adicionar') ?></button>
                </div>
            </div>
        </article>
        <?php endforeach;?>
        </div>
    </section>
    <?php endforeach;?>
<?php endif;?>
</section>

<aside class="cart" id="cart" aria-label="Seu pedido">
    <div class="cart-head">
        <div><span class="eyebrow"><?= $table?'Mesa':'Seu pedido' ?></span><h2><?= $cartCount>0?$cartCount.' '.($cartCount===1?'item':'itens'):'Carrinho vazio' ?></h2></div>
        <?php if($cartRows):?>
        <form method="post" data-clear-form>
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="clear">
            <button class="clear">Limpar</button>
        </form>
        <?php endif;?>
    </div>
    <?php if(!$cartRows):?>
        <div class="empty">Escolha seus produtos. Seu pedido fica salvo neste aparelho enquanto você navega.</div>
    <?php else:?>
        <?php foreach($cartRows as$item):?>
        <div class="cart-line">
            <div><strong><?= Security::e((string)$item['qty']) ?>× <?= Security::e($item['name']) ?></strong><small><?= menu_money((int)$item['line']) ?></small><?php if($item['mods']):?><div class="mods"><?= Security::e(implode(' · ',$item['mods'])) ?></div><?php endif;?></div>
            <form method="post" data-remove-form>
                <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="line_key" value="<?= Security::e($item['line_key']) ?>">
                <button class="remove" aria-label="Remover <?= Security::e($item['name']) ?>">×</button>
            </form>
        </div>
        <?php endforeach;?>
        <div class="total"><span>Subtotal</span><strong><?= menu_money($cartTotal) ?></strong></div>

        <form method="post" class="checkout-form" id="checkout">
            <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>">
            <input type="hidden" name="action" value="checkout">
            <div class="checkout-progress" aria-hidden="true"><span></span><span></span><span></span></div>

            <section class="checkout-step">
                <div class="step-label"><b>Seus dados</b><small>1 de 3</small></div>
                <label>Nome<input name="name" required autocomplete="name" placeholder="Como podemos te chamar?"></label>
                <label>Telefone<input name="phone"<?= $table?'':' required' ?> inputmode="tel" autocomplete="tel" placeholder="(22) 99999-9999"></label>
                <div class="checkout-actions"><button type="button" class="step-next" data-step-next>Continuar</button></div>
            </section>

            <section class="checkout-step" hidden>
                <div class="step-label"><b><?= $table?'Atendimento':'Entrega' ?></b><small>2 de 3</small></div>
                <?php if(!$table):?>
                    <div class="fulfillment-title">Como você quer receber?</div>
                    <div class="fulfillment">
                        <label class="choice"><input type="radio" name="fulfillment" value="pickup"<?= $selectedFulfillment==='pickup'?' checked':'' ?>><span><b>Retirada no local</b><small>Busque em <?= Security::e($selectedUnit['name']) ?> e apresente o código do pedido.</small></span></label>
                        <label class="choice"><input type="radio" name="fulfillment" value="delivery"<?= $selectedFulfillment==='delivery'?' checked':'' ?>><span><b>Receber em casa</b><small><?= $deliveryFee>0?'Taxa de '.menu_money($deliveryFee).'.':'A taxa será informada no pedido.' ?></small></span></label>
                    </div>
                    <label id="address-field">Endereço de entrega<textarea name="address" autocomplete="street-address" placeholder="Rua, número, bairro e referência"></textarea></label>
                    <div id="delivery-info" class="delivery-info"><?php if($minimum>0):?>Pedido mínimo para entrega: <?= menu_money($minimum) ?>. <?php endif;?>A taxa é aplicada somente quando você escolher entrega.</div>
                <?php else:?>
                    <div class="message">Seu pedido será enviado para <strong><?= Security::e($table['name']) ?></strong> nesta unidade.</div>
                <?php endif;?>
                <div class="checkout-actions"><button type="button" class="step-back" data-step-back>Voltar</button><button type="button" class="step-next" data-step-next>Continuar</button></div>
            </section>

            <section class="checkout-step" hidden>
                <div class="step-label"><b><?= $table?'Confirmar pedido':'Pagamento' ?></b><small>3 de 3</small></div>
                <div class="checkout-summary">
                    <?= $table?'Revise e envie seu pedido para a mesa.':'Seu pedido está pronto para continuar.' ?>
                    <strong><?= menu_money($cartTotal) ?></strong>
                    <?php if(!$table):?><span>Na próxima tela você poderá acompanhar o pedido e escolher a forma de pagamento disponível.</span><?php endif;?>
                </div>
                <div class="checkout-actions"><button type="button" class="step-back" data-step-back>Voltar</button><button type="submit" class="checkout"><?= $table?'Enviar pedido':'Continuar para pagamento' ?></button></div>
            </section>
        </form>
    <?php endif;?>
</aside>
</div>

<form method="post" id="restore-cart-form" hidden>
    <input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="cart_backup" value="">
</form>
<script type="application/json" id="menu-cart-state"><?= json_encode($cartBackup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP) ?></script>

<?php if($showBranding):?><footer class="powered">Tecnologia <b>EventMenu</b> · Pedido simples, atendimento melhor.</footer><?php endif;?>
</main>

<?php if($cartRows):?><a class="mobile-cart" href="#cart"><span>Ver pedido · <?= $cartCount ?> <?= $cartCount===1?'item':'itens' ?></span><strong><?= menu_money($cartTotal) ?></strong></a><?php endif;?>

<dialog id="product-dialog" class="product-dialog" aria-label="Personalizar produto">
    <div class="product-sheet">
        <div class="product-sheet-head"><img class="product-sheet-photo" alt=""><button type="button" class="dialog-close" aria-label="Fechar">×</button></div>
        <div class="product-sheet-body"><h2 class="sheet-title"></h2><p class="sheet-desc"></p><div class="sheet-price"></div></div>
        <div class="product-sheet-footer">
            <div class="qty-stepper" aria-label="Quantidade"><button type="button" data-qty-minus aria-label="Diminuir quantidade">−</button><output>1</output><button type="button" data-qty-plus aria-label="Aumentar quantidade">+</button></div>
            <button type="button" class="sheet-add">Adicionar</button>
        </div>
    </div>
</dialog>
<div class="toast-region" aria-live="polite"></div>
<script src="<?= Security::e(app_url('assets/menu-premium-v5.js')) ?>" defer></script>
</body>
</html>
