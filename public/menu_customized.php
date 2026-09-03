<?php

declare(strict_types=1);

/**
 * Camada visual do Cardápio Digital.
 * Mantém todo o fluxo transacional/estoque/opções do menu.php e aplica a
 * personalização salva no Editor do Cardápio sem duplicar regras de negócio.
 */
ob_start();
require __DIR__.'/menu.php';
$html=(string)ob_get_clean();

if(!isset($tenant)||!is_array($tenant)||!isset($settings)||!is_array($settings)){
    echo $html;return;
}

$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$color=static function(mixed $value,string $fallback):string{$v=(string)$value;return preg_match('/^#[0-9a-fA-F]{6}$/',$v)?strtolower($v):$fallback;};
$safeUrl=static function(mixed $value):string{$v=trim((string)$value);return $v!==''&&filter_var($v,FILTER_VALIDATE_URL)&&preg_match('#^https?://#i',$v)?$v:'';};

$primary=$color($tenant['primary_color']??null,'#6d4aff');
$secondary=$color($tenant['secondary_color']??null,'#8b5cf6');
$background=$color($settings['menu_background_color']??null,'#f5f6fb');
$layout=in_array(($settings['menu_layout']??'classic'),['classic','compact','cards'],true)?(string)$settings['menu_layout']:'classic';
$categoryStyle=in_array(($settings['menu_category_style']??'chips'),['chips','tabs','hidden'],true)?(string)$settings['menu_category_style']:'chips';
$logo=$safeUrl($settings['menu_logo_url']??'');$banner=$safeUrl($settings['menu_banner_url']??'');
$headline=trim((string)($settings['menu_headline']??''))?:((string)($tenant['name']??'Cardápio'));
$subtitle=trim((string)($settings['menu_subtitle']??''));if($subtitle==='')$subtitle=isset($unit)&&is_array($unit)?'Unidade '.($unit['name']??''):'Cardápio digital · delivery · retirada';
$search=(bool)($settings['menu_search_enabled']??true);$showImages=(bool)($settings['menu_show_images']??true);$showDescriptions=(bool)($settings['menu_show_descriptions']??true);$showPreparation=(bool)($settings['menu_show_preparation']??true);$showBadges=(bool)($settings['menu_show_badges']??true);$showBrand=(bool)($settings['menu_show_eventmenu_brand']??true);
$cartTitle=trim((string)($settings['menu_cart_title']??'Meu pedido'))?:'Meu pedido';$addLabel=trim((string)($settings['menu_add_button_label']??'Adicionar'))?:'Adicionar';

$classes=['menu-layout-'.$layout,'category-'.$categoryStyle];if(!$showImages)$classes[]='menu-hide-images';if(!$showDescriptions)$classes[]='menu-hide-descriptions';if(!$showPreparation)$classes[]='menu-hide-preparation';if(!$showBadges)$classes[]='menu-hide-badges';
$html=str_replace('<body class="menu-body">','<body class="menu-body '.$e(implode(' ',$classes)).'">',$html);

$css='<style id="eventmenu-menu-custom">:root{--brand:'.$e($primary).';--brand2:'.$e($secondary).';--menu-custom-bg:'.$e($background).'}body.menu-body{background:var(--menu-custom-bg)!important}.menu-logo-custom{width:88px;height:88px;border-radius:22px;object-fit:cover;background:#fff;border:3px solid #fff;box-shadow:0 10px 30px #0004;margin-bottom:10px}.menu-custom-nav{display:flex;gap:8px;overflow:auto;padding:14px 2px 10px;position:sticky;top:0;z-index:30;background:color-mix(in srgb,var(--menu-custom-bg) 94%,transparent);backdrop-filter:blur(12px)}.menu-custom-nav a{white-space:nowrap;text-decoration:none;color:#303341;font-weight:800;background:white;border:1px solid #e2e4ee;padding:10px 14px;border-radius:999px}.category-tabs .menu-custom-nav a{border-radius:8px;background:transparent;border-width:0 0 3px}.category-hidden .menu-custom-nav{display:none}.menu-custom-search{margin:8px 0 14px}.menu-custom-search input{width:100%;padding:14px 16px!important;border-radius:16px!important;background:white!important;color:#222!important;border:1px solid #dfe1ea!important;box-shadow:0 5px 20px #1f244012}.menu-layout-compact .product{padding:9px 0}.menu-layout-compact .product-image{width:66px;height:66px;border-radius:12px}.menu-layout-compact .option-box{padding:8px}.menu-layout-cards .category-card{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.menu-layout-cards .category-card>.section-head,.menu-layout-cards .category-card>h2{grid-column:1/-1}.menu-layout-cards .product{border:1px solid #ececf2!important;border-radius:18px;padding:14px!important;display:flex!important;flex-direction:column;justify-content:space-between}.menu-layout-cards .product-main{flex-direction:column}.menu-layout-cards .product-image{width:100%;height:160px}.menu-layout-cards .add-panel{min-width:0;width:100%}.menu-hide-images .product-image{display:none!important}.menu-hide-descriptions .product-main>div>.muted:first-of-type{display:none!important}.menu-hide-preparation .product-main>div>.muted:last-child{display:none!important}.menu-hide-badges .badge-premium{display:none!important}@media(max-width:820px){.menu-layout-cards .category-card{grid-template-columns:1fr}.menu-custom-nav{top:0}}</style>';
$html=str_replace('</head>',$css.'</head>',$html);

$brand=$showBrand?'<div style="font-weight:900;opacity:.92">EventMenu Premium</div>':'';
$logoHtml=$logo!==''?'<img class="menu-logo-custom" src="'.$e($logo).'" alt="Logo '.$e($tenant['name']??'').'">':'';
$heroStyle='';if($banner!=='')$heroStyle=' style="background-image:linear-gradient(90deg,rgba(20,16,35,.80),rgba(50,28,105,.48)),url(\''.$e($banner).'\');background-size:cover;background-position:center"';
$message=!empty($settings['menu_message'])?'<p>'.$e($settings['menu_message']).'</p>':'';
$newHero='<section class="menu-hero"'.$heroStyle.'>'.$logoHtml.$brand.'<h1>'.$e($headline).'</h1><p>'.$e($subtitle).'</p>'.$message.'</section>';
$html=preg_replace('#<section class="menu-hero">.*?</section>#s',$newHero,$html,1)??$html;

$visibleCategories=[];
if(isset($categories,$products)&&is_array($categories)&&is_array($products)){
    foreach($categories as$cat){foreach($products as$product){if((int)($product['category_id']??0)===(int)($cat['id']??0)){$visibleCategories[]=$cat;break;}}}
}
$nav='';
if($categoryStyle!=='hidden'&&$visibleCategories){$nav='<nav class="menu-custom-nav" aria-label="Categorias">';foreach($visibleCategories as$cat)$nav.='<a href="#categoria-'.(int)$cat['id'].'">'.$e($cat['name']).'</a>';$nav.='<a href="#carrinho">Carrinho</a></nav>';}
if($search)$nav.='<div class="menu-custom-search"><input type="search" id="menuCustomSearch" placeholder="Buscar no cardápio..." autocomplete="off"></div>';
$html=str_replace($newHero,$newHero.$nav,$html);
foreach($visibleCategories as$cat)$html=preg_replace('/<article class="category-card">/','<article class="category-card" id="categoria-'.(int)$cat['id'].'">',$html,1)??$html;

$html=str_replace('<h2>Seu pedido</h2>','<h2>'.$e($cartTitle).'</h2>',$html);
$html=str_replace('<button class="primary">Adicionar</button>','<button class="primary">'.$e($addLabel).'</button>',$html);

$script='<script id="eventmenu-menu-search">(function(){const input=document.getElementById("menuCustomSearch");if(!input)return;const n=s=>String(s||"").toLocaleLowerCase("pt-BR").normalize("NFD").replace(/[\\u0300-\\u036f]/g,"");input.addEventListener("input",()=>{const q=n(input.value);document.querySelectorAll(".category-card").forEach(card=>{const products=[...card.querySelectorAll(".product")];if(!products.length)return;products.forEach(p=>p.style.display=!q||n(p.textContent).includes(q)?"":"none");card.style.display=products.some(p=>p.style.display!=="none")?"":"none";});});})();</script>';
$html=str_replace('</body>',$script.'</body>',$html);

echo $html;
