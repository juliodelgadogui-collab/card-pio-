<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

$pdo=Database::connection();
Auth::enforceCurrentUser();
$tenantId=Auth::tenantId();

function em_money(int|float|string $cents):string{return 'R$ '.number_format(((int)$cents)/100,2,',','.');}
function em_csrf():string{return Security::e(Security::csrfToken());}
function em_post_csrf():void{if(!Security::validateCsrf($_POST['_csrf']??null)){http_response_code(419);exit('Sessão expirada. Atualize a página.');}}
function em_base_path():string{$configured=(string)env('APP_URL','');$path=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';if($path===''||$path==='/'){$script=(string)($_SERVER['SCRIPT_NAME']??'');$dir=str_replace('\\','/',dirname($script));$path=$dir==='/'||$dir==='.'?'':$dir;}return rtrim($path,'/');}
function em_url(string $path=''):string{$base=em_base_path();if($path==='')return $base!==''?$base.'/':'/';if(!str_starts_with($path,'/'))$path='/'.$path;return $base.$path;}
function em_go(string $route,array $params=[]):never{$params=['route'=>$route]+$params;header('Location: '.em_url('/?'.http_build_query($params)));exit;}
function em_slug(string $value):string{$v=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;$v=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$v)??'','-'));return $v?:bin2hex(random_bytes(3));}
function em_selected(mixed $a,mixed $b):string{return (string)$a===(string)$b?' selected':'';}
function em_checked(bool|int|string $v):string{return(bool)$v?' checked':'';}
function em_flash(?string $type=null,?string $message=null):?array{if($type&&$message){$_SESSION['_flash']=[$type,$message];return null;}$f=$_SESSION['_flash']??null;unset($_SESSION['_flash']);return $f;}
function em_can_nav(string $permission):bool{if($permission==='superadmin.only')return Auth::role()==='super_admin';return $permission==='reports.any'?(Auth::can('reports.view')||Auth::can('reports.own')):Auth::can($permission);}
function em_role_label(?string $role):string{return Auth::roleLabel($role);}
function em_status_label(?string $status):string{return match($status){'active'=>'Ativo','blocked'=>'Bloqueado','pending'=>'Pendente','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','completed'=>'Concluído','cancelled'=>'Cancelado','paid'=>'Pago','unpaid'=>'Não pago','partial'=>'Parcial','fulfilled'=>'Entregue',default=>$status?:'—'};}
function em_initials(string $name):string{$parts=preg_split('/\s+/',trim($name))?:[];$a=$parts[0]??'E';$b=count($parts)>1?$parts[array_key_last($parts)]:'';return mb_strtoupper(mb_substr($a,0,1).mb_substr($b,0,1));}

function em_nav():array
{
    $role=Auth::role();
    if($role==='kitchen')return [['kitchen','Tela da cozinha','orders.kitchen','kitchen']];
    if($role==='delivery')return [['my-deliveries','Minhas entregas','orders.delivery','delivery']];
    if($role==='counter')return [['pos','Nova venda','orders.create','pos'],['counter-orders','Minhas vendas','counter.orders','orders'],['fulfillment','Retiradas','fulfillment.manage','scan']];
    if($role==='cashier')return [['pos','PDV / Balcão','orders.create','pos'],['fulfillment','Retiradas','fulfillment.manage','scan'],['orders','Pedidos','orders.view','orders'],['cash','Caixa','payments.manage','cash'],['customers','Clientes','customers.manage','users']];
    if($role==='waiter')return [['restaurant','Mesas e comandas','tables.manage','tables'],['pos','Lançar pedido','orders.create','pos'],['fulfillment','Retiradas','fulfillment.manage','scan'],['orders','Pedidos','orders.view','orders']];
    if($role==='promoter')return [['guests','Convidados','guests.manage','users'],['reports','Meus relatórios','reports.any','chart']];
    return array_filter([
        $role==='super_admin'?['superadmin','Super ADM','superadmin.only','building']:null,
        ['dashboard','Visão geral','dashboard','home'],['pos','PDV / Balcão','orders.create','pos'],['fulfillment','Retiradas','fulfillment.manage','scan'],['products','Cardápio','catalog.manage','menu'],['orders','Pedidos','orders.view','orders'],['delivery','Delivery','delivery.assign','delivery'],['kitchen','Cozinha / KDS','orders.kitchen','kitchen'],['restaurant','Mesas e comandas','tables.manage','tables'],['cash','Caixa','payments.manage','cash'],['customers','Clientes e pontos','customers.manage','users'],['coupons','Cupons','coupons.manage','coupon'],['events','Eventos','events.manage','calendar'],['tickets','Ingressos / Check-in','tickets.manage','ticket'],['guests','Convidados','guests.manage','users'],['promoters','Promotores','promoters.manage','promoter'],['payments','Pagamentos','payments.manage','card'],['gateways','Gateways e NFC','gateways.manage','nfc'],['users','Equipe','users.manage','team'],['reports','Relatórios','reports.any','chart'],['audit','Auditoria','audit.view','audit'],['settings','Configurações','settings.manage','settings']
    ]);
}

function em_icon(string $name):string
{
    $paths=match($name){
        'home'=>'<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9.5 20v-6h5v6"/>',
        'pos'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h10M7 13h4M15 13h2M7 16h2M12 16h5"/>',
        'scan'=>'<path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3"/><path d="M7 12h10"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="7" cy="6" r="1"/><circle cx="7" cy="12" r="1"/><circle cx="7" cy="18" r="1"/>',
        'orders'=>'<path d="M6 3h12l2 4v14H4V7l2-4Z"/><path d="M4 8h16M8 12h8M8 16h5"/>',
        'delivery'=>'<path d="M3 7h11v10H3zM14 11h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'kitchen'=>'<path d="M6 3v7M10 3v7M8 3v18M15 3v7c0 2 1 3 3 3v8"/>',
        'tables'=>'<path d="M4 9h16M6 9l-2 10M18 9l2 10M8 5h8v4H8z"/>',
        'cash'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 9h10M7 15h4"/><circle cx="16.5" cy="14.5" r="2.5"/>',
        'users','team'=>'<circle cx="9" cy="8" r="3"/><path d="M3 20c.5-4 2.5-6 6-6s5.5 2 6 6M16 5.5a3 3 0 0 1 0 5.5M17 14c2.5.5 3.5 2.2 4 5"/>',
        'coupon'=>'<path d="M4 7a2 2 0 0 0 2-2h12a2 2 0 0 0 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 0-2 2H6a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4z"/><path d="m9 15 6-6M10 9.5h.01M14 14.5h.01"/>',
        'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
        'ticket'=>'<path d="M4 7a2 2 0 0 0 2-2h12v5a2 2 0 0 0 0 4v5H6a2 2 0 0 0-2-2v-3a2 2 0 0 0 0-4z"/><path d="M12 8v8"/>',
        'promoter'=>'<path d="M4 13h4l9-5v12l-9-5H4z"/><path d="M8 15v5"/>',
        'card'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'nfc'=>'<path d="M7 8a6 6 0 0 1 0 8M11 6a9 9 0 0 1 0 12M15 4a12 12 0 0 1 0 16"/>',
        'chart'=>'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'audit'=>'<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.5-2.5 1A8 8 0 0 0 14.5 6L14 3h-4l-.5 3A8 8 0 0 0 7.6 7L5 6 3 9.5 5.1 11a7 7 0 0 0 0 2L3 14.5 5 18l2.6-1a8 8 0 0 0 1.9 1L10 21h4l.5-3a8 8 0 0 0 1.9-1l2.6 1 2-3.5-2.1-1.5a7 7 0 0 0 .1-1Z"/>',
        'building'=>'<path d="M4 21V5l8-2v18M12 8h8v13M2 21h20M7 8h2M7 12h2M7 16h2M15 12h2M15 16h2"/>',
        default=>' <circle cx="12" cy="12" r="8"/>',
    };
    return '<svg viewBox="0 0 24 24" aria-hidden="true">'.$paths.'</svg>';
}

function em_workspace_subtitle():string
{
    return match(Auth::role()){
        'counter'=>'Venda e retirada de produtos',
        'cashier'=>'Vendas, recebimentos e caixa',
        'waiter'=>'Mesas, comandas e atendimento',
        'kitchen'=>'Fila de preparo em tempo real',
        'delivery'=>'Entregas atribuídas ao seu usuário',
        'promoter'=>'Convidados e resultados da sua operação',
        default=>'Gerencie sua operação em um só lugar',
    };
}

function em_header(string $title,string $active):void
{
    $flash=em_flash();$name=Auth::name();$role=em_role_label(Auth::role());
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#6d4aff"><title><?= Security::e($title) ?> — EventMenu Premium</title><link rel="manifest" href="<?= Security::e(em_url('/manifest.webmanifest')) ?>"><link rel="stylesheet" href="<?= Security::e(em_url('/assets/app.css')) ?>?v=92p4"></head><body><div class="mobile-bar"><button class="mobile-menu-btn" type="button" id="mobileMenuBtn" aria-label="Abrir menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="mobile-brand">EventMenu <span>Premium</span></div><div class="mobile-avatar"><?= Security::e(em_initials($name)) ?></div></div><div class="sidebar-overlay" id="sidebarOverlay"></div><div class="layout"><aside class="sidebar" id="sidebar"><div class="brand-wrap"><div class="brand-mark">EM</div><div class="brand">EventMenu <span>Premium</span></div></div><div class="sidebar-label"><?= Security::e($role) ?></div><nav class="nav"><?php foreach(em_nav() as$item):[$route,$label,$permission,$icon]=$item;if(!em_can_nav($permission))continue;?><a class="<?= $active===$route?'active':'' ?>" href="<?= Security::e(em_url('/?route='.$route)) ?>"><span class="nav-icon"><?= em_icon($icon) ?></span><span><?= Security::e($label) ?></span></a><?php endforeach;?></nav><div class="sidebar-foot"><div class="user-avatar"><?= Security::e(em_initials($name)) ?></div><div class="sidebar-user"><strong><?= Security::e($name) ?></strong><span><?= Security::e($role) ?></span></div><a class="logout-link" href="<?= Security::e(em_url('/?route=logout')) ?>" title="Sair">Sair</a></div></aside><main class="content"><header class="topbar"><div class="topbar-copy"><h1><?= Security::e($title) ?></h1><div class="topbar-subtitle"><?= Security::e(em_workspace_subtitle()) ?></div></div><div class="topbar-actions"><span class="workspace-chip"><?= Security::e($role) ?></span><?php if(Auth::can('users.manage')&&Auth::tenantId()):?><a class="button secondary" href="<?= Security::e(em_url('/update.php')) ?>">Atualizar sistema</a><?php endif;?></div></header><?php if($flash):?><div class="alert <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div><?php endif;?><?php
}
function em_footer():void{$sw=em_url('/sw.js');?><script>(()=>{const body=document.body,btn=document.getElementById('mobileMenuBtn'),overlay=document.getElementById('sidebarOverlay');const close=()=>body.classList.remove('nav-open');if(btn)btn.addEventListener('click',()=>body.classList.toggle('nav-open'));if(overlay)overlay.addEventListener('click',close);document.querySelectorAll('.nav a').forEach(a=>a.addEventListener('click',close));window.addEventListener('keydown',e=>{if(e.key==='Escape')close();});if('serviceWorker'in navigator){navigator.serviceWorker.register('<?= Security::e($sw) ?>').catch(()=>{});}})();</script></main></div></body></html><?php }
function em_require_tenant():int{$id=Auth::tenantId();if(!$id){http_response_code(403);exit('Selecione uma empresa no Super ADM.');}return $id;}
