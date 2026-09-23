<?php

declare(strict_types=1);

$root=dirname(__DIR__);

function ux_file(string $path): string
{
    global $root;
    $full=$root.'/'.$path;
    $content=@file_get_contents($full);
    if($content===false)throw new RuntimeException('Arquivo ausente no contrato UX: '.$path);
    return $content;
}
function ux_has(string $content,string $needle,string $label): void
{
    if(!str_contains($content,$needle))throw new RuntimeException('Contrato UX ausente: '.$label);
}
function ux_not_has(string $content,string $needle,string $label): void
{
    if(str_contains($content,$needle))throw new RuntimeException('Vazamento UX detectado: '.$label);
}

$menu=ux_file('public/menu.php');
$order=ux_file('public/pedido.php');
$orderPaymentApi=ux_file('public/api-order-payment.php');
$menuCss=ux_file('public/assets/menu-premium-v5.css');
$menuJs=ux_file('public/assets/menu-premium-v5.js');
$orderCss=ux_file('public/assets/order-premium-v5.css');
$orderJs=ux_file('public/assets/order-premium-v5.js');
$panelBridge=ux_file('public/assets/premium-v4.css');
$panelV5=ux_file('public/assets/premium-v5.css');
$panelRuntime=ux_file('public/assets/premium-v5-runtime.css');
$serviceWorker=ux_file('public/sw.js');

ux_has($menu,'data-menu-root','raiz do cardápio premium');
ux_has($menu,'product-dialog','personalização do produto em sheet/modal');
ux_has($menu,'checkout-progress','checkout progressivo');
ux_has($menu,'name="action" value="restore"','restauração do carrinho');
ux_has($menu,'ConfiguredOrderService','revalidação server-side dos itens');
ux_has($menuJs,'localStorage','persistência local segura da experiência');
ux_has($menuJs,'IntersectionObserver','categoria ativa durante navegação');
ux_has($menuCss,'@media(max-width:370px)','responsividade de celular pequeno');
ux_has($menuCss,'prefers-reduced-motion','acessibilidade de movimento');

ux_has($order,'timeline-card','timeline visual do pedido');
ux_has($order,'existingForPublicOrder','GPS somente com link real existente');
ux_has($order,'data-payment-form','prevenção visual do fallback legado');
ux_has($order,'Como deseja pagar?','escolha clara do meio de pagamento');
ux_has($order,'Gerar PIX','PIX dentro do EventMenu');
ux_has($order,'api-order-payment.php','API nativa de pagamento do pedido');
ux_has($order,'https://sdk.mercadopago.com/js/v2','SDK oficial Mercado Pago V2');
ux_has($order,'payment_type_id','seleção crédito ou débito validada no servidor');
ux_has($orderPaymentApi,"action==='pix'",'endpoint PIX nativo');
ux_has($orderPaymentApi,"action==='card'",'endpoint cartão tokenizado');
ux_has($orderPaymentApi,"action==='status'",'consulta server-side do pagamento');
ux_has($orderCss,'tracking-action','ação de acompanhamento da entrega');
ux_has($orderJs,'navigator.onLine','estado offline do acompanhamento');

// O painel administrativo precisa receber o Premium v5 pelo CSS já carregado pelo HTML.
// Assim o design não depende da execução do JavaScript nem de um bundle antigo em cache.
ux_has($panelBridge,'@import url("./premium-v5.css','ponte CSS direta para Premium v5');
ux_has($panelBridge,'@import url("./premium-v5-runtime.css','runtime visual carregado pelo CSS principal');
ux_has($panelBridge,'--em-sidebar:#101828','shell visual Premium v5 evidente');
ux_has($panelV5,'EventMenu Premium v5','folha principal Premium v5 presente');
ux_has($panelRuntime,'Premium v5','runtime visual Premium v5 presente');
ux_has($panelRuntime,'.route-super .platform-cockpit{display:grid!important','cockpit do Super ADM protegido contra CSS legado');
ux_has($panelRuntime,'grid-template-columns:repeat(3,minmax(0,1fr))!important','cockpit desktop em grade previsível');
ux_has($panelRuntime,'@media(min-width:1440px)','cockpit desktop amplo com quatro colunas');
ux_has($panelRuntime,'body.em-premium .sidebar .nav a{display:flex!important','menu alinha ícone e rótulo em todas as larguras');
ux_has($panelRuntime,'.route-super .platform-cockpit{grid-template-columns:1fr!important','fallback mobile do cockpit em uma coluna');
ux_has($serviceWorker,"eventmenu-static-v8",'invalidação do cache visual anterior');
ux_has($serviceWorker,"'/assets/premium-v5.css'",'service worker conhece Premium v5');

// O fluxo principal de Mercado Pago/PagBank precisa permanecer dentro do EventMenu.
// Stripe pode continuar como fallback legado opcional para preservar compatibilidade,
// mas MP/PagBank não podem voltar a ser CTAs externos.
ux_not_has($order,'Pagar com Mercado Pago','Mercado Pago exposto como checkout externo');
ux_not_has($order,'Pagar com PagBank','PagBank exposto como checkout externo');
ux_not_has($order,'name="legacy_provider" value="mercadopago"','redirect legado Mercado Pago');
ux_not_has($order,'name="legacy_provider" value="pagbank"','redirect legado PagBank');
ux_not_has($order,'transaction ID','identificador técnico na interface pública');

fwrite(STDOUT,"public-ux-smoke: OK\n");
