<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\MarketplaceCatalogService;

$pdo=Database::connection();
$rate=new ApiRateLimitService();
if(PHP_SAPI!=='cli'){
    $rate->assertAllowed(
        'public.marketplace.browse',
        $rate->requestSubject('delyvre'),
        120,
        60,
        'Muitas atualizações em pouco tempo. Aguarde alguns segundos.'
    );
}

$openTenant=max(0,(int)($_GET['open']??0));
$openUnit=max(0,(int)($_GET['unit']??0));
$campaign=mb_substr(trim((string)($_GET['campanha']??'')),0,80);
if($openTenant>0&&$openUnit>0){
    $s=$pdo->prepare('SELECT t.id,t.slug,u.id unit_id,u.code unit_code FROM tenants t JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id JOIN operating_units u ON u.tenant_id=t.id WHERE t.id=? AND u.id=? AND t.status="active" AND ms.participates=1 AND ms.status="active" AND u.active=1 LIMIT 1');
    $s->execute([$openTenant,$openUnit]);
    $store=$s->fetch();
    if(!$store){
        http_response_code(404);
        exit('Restaurante não disponível no DELYVRE.');
    }
    $_SESSION['_eventmenu_delivery_entry'][$openTenant]=[
        'unit_id'=>$openUnit,
        'campaign_code'=>$campaign?:null,
        'entered_at'=>time(),
        'expires_at'=>time()+7200,
    ];
    header('Location: '.app_url('menu.php?empresa='.rawurlencode((string)$store['slug']).'&unidade='.rawurlencode((string)$store['unit_code'])),true,302);
    exit;
}

$q=mb_substr(trim((string)($_GET['q']??'')),0,120);
$city=mb_substr(trim((string)($_GET['cidade']??'')),0,120);
$state=mb_substr(mb_strtoupper(trim((string)($_GET['uf']??''))),0,2);

$catalog=new MarketplaceCatalogService();
$stores=$catalog->stores($pdo,['q'=>$q,'city'=>$city,'state'=>$state]);
$cities=$pdo->query('SELECT DISTINCT city FROM marketplace_tenant_settings WHERE participates=1 AND status="active" AND city IS NOT NULL AND city<>"" ORDER BY city')->fetchAll(PDO::FETCH_COLUMN);
$states=$pdo->query('SELECT DISTINCT state FROM marketplace_tenant_settings WHERE participates=1 AND status="active" AND state IS NOT NULL AND state<>"" ORDER BY state')->fetchAll(PDO::FETCH_COLUMN);
$freeDelivery=array_values(array_filter($stores,static fn(array $store):bool=>(int)($store['delivery_fee_cents']??0)===0));

function delivery_money(int $cents):string
{
    return $cents<=0?'Grátis':'R$ '.number_format($cents/100,2,',','.');
}
function delivery_home_url(string $q='',string $city='',string $state=''):string
{
    $params=[];
    if($q!=='')$params['q']=$q;
    if($city!=='')$params['cidade']=$city;
    if($state!=='')$params['uf']=$state;
    return app_url('delivery.php'.($params?'?'.http_build_query($params):''));
}
function delivery_open_url(array $store,string $campaign=''):string
{
    $url='delivery.php?open='.(int)$store['tenant_id'].'&unit='.(int)$store['unit_id'];
    if($campaign!=='')$url.='&campanha='.rawurlencode($campaign);
    return app_url($url);
}
function delivery_location_label(string $city,string $state):string
{
    if($city!==''&&$state!=='')return $city.' · '.$state;
    if($city!=='')return $city;
    if($state!=='')return $state;
    return 'Escolha sua região';
}
function delivery_store_card(array $store,string $campaign=''):void
{
    $name=(string)($store['name']??'Restaurante');
    $description=trim((string)($store['description']??''));
    $location=trim((string)($store['city']??'').((string)($store['state']??'')!==''?' · '.(string)$store['state']:''));
    $openUrl=delivery_open_url($store,$campaign);
    $accepting=!array_key_exists('accepting_orders',$store)||!empty($store['accepting_orders']);
    $fee=(int)($store['delivery_fee_cents']??0);
    $eta=max(5,(int)($store['delivery_eta_minutes']??45));
    ?>
    <article class="store-card">
        <a class="store-card-link" href="<?=Security::e($openUrl)?>" aria-label="Abrir <?=Security::e($name)?>"></a>
        <div class="store-cover">
            <?php if(trim((string)($store['cover_url']??''))!==''):?>
                <img src="<?=Security::e((string)$store['cover_url'])?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
            <?php endif;?>
            <?php if(trim((string)($store['logo_url']??''))!==''):?>
                <img class="store-logo" src="<?=Security::e((string)$store['logo_url'])?>" alt="Logo <?=Security::e($name)?>" loading="lazy" decoding="async" referrerpolicy="no-referrer">
            <?php else:?>
                <div class="store-logo store-monogram" aria-hidden="true"><?=Security::e(mb_strtoupper(mb_substr($name,0,1)))?></div>
            <?php endif;?>
        </div>
        <div class="store-body">
            <div class="store-heading">
                <div>
                    <h3><?=Security::e($name)?></h3>
                    <?php if(trim((string)($store['unit_name']??''))!==''):?><span class="store-unit"><?=Security::e((string)$store['unit_name'])?></span><?php endif;?>
                </div>
            </div>
            <div class="store-meta">
                <span class="meta <?=$accepting?'open':'closed'?>"><?=$accepting?'Aberto para pedidos':'Fechado agora'?></span>
                <span class="meta"><?=$eta?>–<?=min(240,$eta+15)?> min</span>
                <span class="meta <?=$fee===0?'free':''?>">Entrega <?=Security::e(delivery_money($fee))?></span>
                <?php if((int)($store['minimum_order_cents']??0)>0):?><span class="meta">Mín. <?=Security::e(delivery_money((int)$store['minimum_order_cents']))?></span><?php endif;?>
            </div>
            <p class="store-address"><?=Security::e($description!==''?$description:$location)?></p>
            <a class="store-button" href="<?=Security::e($openUrl)?>">Ver cardápio</a>
        </div>
    </article>
    <?php
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#fffaf7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="description" content="DELYVRE — escolha restaurantes, faça seu pedido e acompanhe a entrega.">
    <title>DELYVRE · Escolha. Peça. Receba.</title>
    <link rel="stylesheet" href="<?=Security::e(app_url('assets/delivery-marketplace.css'))?>">
</head>
<body>
<div class="market-shell">
    <header class="market-top">
        <div class="top-inner">
            <a class="brand" href="<?=Security::e(app_url('delivery.php'))?>" aria-label="DELYVRE — início">
                <span class="brand-mark" aria-hidden="true">D</span>
                <span class="brand-copy">DELYVRE<small>Escolha. Peça. Receba.</small></span>
            </a>
            <form class="search" method="get" id="buscar" role="search">
                <input name="q" value="<?=Security::e($q)?>" placeholder="Buscar comida ou restaurante" aria-label="Buscar comida ou restaurante" autocomplete="off">
                <input type="hidden" name="cidade" value="<?=Security::e($city)?>">
                <input type="hidden" name="uf" value="<?=Security::e($state)?>">
            </form>
            <div class="top-actions" aria-label="Ações da conta">
                <a class="top-action" href="#beneficios">Benefícios</a>
                <a class="top-action primary" href="#conta">Entrar</a>
            </div>
        </div>
    </header>

    <div class="location-strip" id="localizacao">
        <div class="location-main">
            <span class="location-icon" aria-hidden="true">⌖</span>
            <div class="location-copy">
                <span>Entrega em</span>
                <strong><?=Security::e(delivery_location_label($city,$state))?></strong>
            </div>
        </div>
        <a class="location-change" href="#regioes">Alterar</a>
    </div>

    <section class="hero" aria-labelledby="delyvre-hero-title">
        <div class="hero-copy">
            <small>DELYVRE</small>
            <h1 id="delyvre-hero-title">Escolha. Peça. Receba.</h1>
            <p>Seus restaurantes em um só lugar. Descubra sabores, escolha do seu jeito e acompanhe o pedido até chegar.</p>
            <div class="hero-actions">
                <a class="hero-button" href="#restaurantes">Começar a pedir</a>
                <a class="hero-button secondary" href="#frete-gratis">Ver frete grátis</a>
            </div>
        </div>
    </section>

    <nav class="filter-row" id="regioes" aria-label="Filtrar restaurantes por região">
        <a class="chip <?=$city===''&&$state===''?'active':''?>" href="<?=Security::e(delivery_home_url($q))?>">Todos</a>
        <?php foreach($cities as $c):?>
            <a class="chip <?=$city===$c?'active':''?>" href="<?=Security::e(delivery_home_url($q,(string)$c,''))?>"><?=Security::e((string)$c)?></a>
        <?php endforeach;?>
        <?php foreach($states as $uf):?>
            <a class="chip <?=$state===$uf?'active':''?>" href="<?=Security::e(delivery_home_url($q,'',(string)$uf))?>"><?=Security::e((string)$uf)?></a>
        <?php endforeach;?>
    </nav>

    <?php if($freeDelivery):?>
        <section id="frete-gratis" aria-labelledby="free-title">
            <div class="section-head">
                <div>
                    <h2 id="free-title">Frete grátis</h2>
                    <p>Boas escolhas sem taxa de entrega.</p>
                </div>
                <a class="section-link" href="#restaurantes">Ver todos</a>
            </div>
            <div class="store-shelf">
                <?php foreach(array_slice($freeDelivery,0,6) as $store)delivery_store_card($store,$campaign);?>
            </div>
        </section>
    <?php endif;?>

    <section id="restaurantes" aria-labelledby="restaurants-title">
        <div class="section-head">
            <div>
                <h2 id="restaurants-title"><?=$q!==''?'Resultados para “'.Security::e($q).'”':'Restaurantes para você'?></h2>
                <p><?=count($stores)?> <?=count($stores)===1?'opção encontrada':'opções encontradas'?><?=($city!==''||$state!=='')?' na região escolhida':''?>.</p>
            </div>
        </div>

        <?php if(!$stores):?>
            <div class="empty" role="status">
                <div class="state-icon" aria-hidden="true">⌕</div>
                <strong>Nenhum restaurante encontrado</strong>
                <p>Tente buscar outro nome, comida ou remova o filtro de região.</p>
            </div>
        <?php else:?>
            <div class="store-grid">
                <?php foreach($stores as $store)delivery_store_card($store,$campaign);?>
            </div>
        <?php endif;?>
    </section>

    <section class="promo-banner" id="beneficios" style="margin-top:28px">
        <div>
            <h3>Benefícios que acompanham você</h3>
            <p>Cupons, vantagens e ofertas entram no momento certo do pedido. Ao acessar sua conta, seus benefícios ficam reunidos em um só lugar.</p>
        </div>
        <div class="promo-mark" aria-hidden="true">DELYVRE</div>
    </section>

    <section class="state-card" id="conta" style="margin-top:18px">
        <div class="state-icon" aria-hidden="true">☺</div>
        <strong>Navegue primeiro. Entre quando precisar.</strong>
        <p>Você pode descobrir restaurantes e cardápios sem login. A conta será solicitada apenas para pedir, salvar endereço, favoritos, histórico e benefícios.</p>
    </section>

    <footer class="market-foot">DELYVRE · Escolha. Peça. Receba.</footer>
</div>

<nav class="mobile-nav" aria-label="Navegação principal do DELYVRE">
    <a class="active" href="<?=Security::e(app_url('delivery.php'))?>"><span class="nav-icon" aria-hidden="true">⌂</span><span>Início</span></a>
    <a href="#buscar"><span class="nav-icon" aria-hidden="true">⌕</span><span>Buscar</span></a>
    <a href="#conta"><span class="nav-icon" aria-hidden="true">▤</span><span>Pedidos</span></a>
    <a href="#beneficios"><span class="nav-icon" aria-hidden="true">◇</span><span>Benefícios</span></a>
    <a href="#conta"><span class="nav-icon" aria-hidden="true">○</span><span>Perfil</span></a>
</nav>
</body>
</html>
