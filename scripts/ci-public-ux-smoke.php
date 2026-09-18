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
$menuCss=ux_file('public/assets/menu-premium-v5.css');
$menuJs=ux_file('public/assets/menu-premium-v5.js');
$orderCss=ux_file('public/assets/order-premium-v5.css');
$orderJs=ux_file('public/assets/order-premium-v5.js');

ux_has($menu,"data-menu-root",'raiz do cardápio premium');
ux_has($menu,"product-dialog",'personalização do produto em sheet/modal');
ux_has($menu,"checkout-progress",'checkout progressivo');
ux_has($menu,"action' value=\"restore\"",'restauração do carrinho');
ux_has($menu,"ConfiguredOrderService",'revalidação server-side dos itens');
ux_has($menuJs,"localStorage",'persistência local segura da experiência');
ux_has($menuJs,"IntersectionObserver",'categoria ativa durante navegação');
ux_has($menuCss,"@media(max-width:370px)",'responsividade de celular pequeno');
ux_has($menuCss,"prefers-reduced-motion",'acessibilidade de movimento');

ux_has($order,"timeline-card",'timeline visual do pedido');
ux_has($order,"existingForPublicOrder",'GPS somente com link real existente');
ux_has($order,"data-payment-form",'prevenção visual de envio repetido');
ux_has($order,"Continuar para pagamento",'linguagem neutra de pagamento');
ux_has($orderCss,"tracking-action",'ação de acompanhamento da entrega');
ux_has($orderJs,"navigator.onLine",'estado offline do acompanhamento');

// O provider pode existir internamente em input/serviço, mas marcas de gateway não devem virar CTA do cliente.
ux_not_has($order,'Pagar com Mercado Pago','Mercado Pago exposto no CTA público');
ux_not_has($order,'Pagar com PagBank','PagBank exposto no CTA público');
ux_not_has($order,'Pagar com Stripe','Stripe exposto no CTA público');
ux_not_has($order,'transaction ID','identificador técnico na interface pública');
ux_not_has($order,'webhook','webhook na interface pública');

fwrite(STDOUT,"public-ux-smoke: OK\n");
