<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;

$pdo=Database::connection();Auth::enforceCurrentUser();$tenantId=Auth::tenantId();
function em_money(int|float|string $cents):string{return 'R$ '.number_format(((int)$cents)/100,2,',','.');}
function em_csrf():string{return Security::e(Security::csrfToken());}
function em_post_csrf():void{if(!Security::validateCsrf($_POST['_csrf']??null)){http_response_code(419);exit('Sessão expirada. Atualize a página.');}}
function em_go(string $route,array $params=[]):never{$params=['route'=>$route]+$params;header('Location: '.app_url('?'.http_build_query($params)));exit;}
function em_slug(string $value):string{$v=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;$v=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$v)??'','-'));return $v?:bin2hex(random_bytes(3));}
function em_selected(mixed $a,mixed $b):string{return (string)$a===(string)$b?' selected':'';}
function em_checked(bool|int|string $v):string{return(bool)$v?' checked':'';}
function em_flash(?string $type=null,?string $message=null):?array{if($type&&$message){$_SESSION['_flash']=[$type,$message];return null;}$f=$_SESSION['_flash']??null;unset($_SESSION['_flash']);return $f;}
function em_asset_version(string $relative):string{$path=dirname(__DIR__).'/public/'.ltrim($relative,'/');$mtime=@filemtime($path);return $mtime!==false?(string)$mtime:'1';}
function em_can_nav(string $permission):bool{
    if($permission==='platform.manage') return Auth::isSuperAdmin();
    if(Auth::isSuperAdmin()&&!Auth::tenantId()) return false;
    return $permission==='reports.any'?(Auth::can('reports.view')||Auth::can('reports.own')):Auth::can($permission);
}
function em_nav():array{return[
    ['super','Plataforma','platform.manage'],
    ['dashboard','Visão geral','dashboard'],
    ['pos','Caixa / PDV','orders.create'],
    ['cash','Turno de caixa','cash.manage'],
    ['kitchen','Cozinha / KDS','orders.kitchen'],
    ['delivery','Entregas','orders.delivery'],
    ['products','Cardápio','catalog.manage'],
    ['inventory','Estoque','inventory.manage'],
    ['orders','Pedidos','orders.view'],
    ['restaurant','Mesas e comandas','tables.manage'],
    ['customers','Clientes e pontos','customers.manage'],
    ['coupons','Cupons','coupons.manage'],
    ['events','Eventos','events.manage'],
    ['tickets','Ingressos / Check-in','tickets.manage'],
    ['guests','Convidados','guests.manage'],
    ['promoters','Promotores','promoters.manage'],
    ['payments','Pagamentos','payments.manage'],
    ['gateways','Gateways e NFC','gateways.manage'],
    ['users','Equipe','users.manage'],
    ['units','Unidades','settings.manage'],
    ['reports','Relatórios','reports.any'],
    ['audit','Auditoria','audit.view'],
    ['settings','Configurações','settings.manage']
];}
function em_context_tenant_name():?string{
    if(!Auth::isSuperAdmin()||!Auth::tenantId()) return null;
    static $name=null,$loaded=false;if($loaded)return $name;$loaded=true;
    $s=Database::connection()->prepare('SELECT name FROM tenants WHERE id=?');$s->execute([Auth::tenantId()]);$v=$s->fetchColumn();$name=$v!==false?(string)$v:null;return $name;
}
function em_header(string $title,string $active):void{
    $flash=em_flash();
    $manifest=Security::e(app_url('manifest.webmanifest?v='.em_asset_version('manifest.webmanifest')));
    $css=Security::e(app_url('assets/app.css?v='.em_asset_version('assets/app.css')));
    $context=em_context_tenant_name();
    $type=Auth::tenantId()?TenantFeatures::label(Auth::tenantId()):null;
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#090d14"><meta name="color-scheme" content="dark"><title><?= Security::e($title) ?> — EventMenu Premium</title><link rel="manifest" href="<?= $manifest ?>"><link rel="stylesheet" href="<?= $css ?>"></head><body><div class="layout"><aside class="sidebar" aria-label="Navegação principal"><div class="sidebar-head"><a class="brand" href="<?= Security::e(app_url('?route='.(Auth::isSuperAdmin()&&!Auth::tenantId()?'super':'dashboard'))) ?>">EventMenu <span>Premium</span></a><button type="button" class="nav-toggle" aria-label="Abrir menu" aria-expanded="false"><span></span><span></span><span></span></button></div><?php if($context):?><div class="tenant-context"><small>Empresa em contexto</small><strong><?= Security::e($context) ?></strong><?php if($type):?><span><?= Security::e($type) ?></span><?php endif;?></div><?php endif;?><nav class="nav"><?php foreach(em_nav() as[$route,$label,$permission]):if(!em_can_nav($permission)||(!Auth::isSuperAdmin()||Auth::tenantId())&&!TenantFeatures::routeEnabled($route,Auth::tenantId()))continue;?><a class="<?= $active===$route?'active':'' ?>" href="<?= Security::e(app_url('?route='.urlencode($route))) ?>"><?= Security::e($label) ?></a><?php endforeach;?></nav><div class="sidebar-foot"><div class="user-chip"><span class="user-avatar"><?= Security::e(mb_strtoupper(mb_substr(Auth::name(),0,1))) ?></span><div><strong><?= Security::e(Auth::name()) ?></strong><small><?= Security::e((string)Auth::role()) ?></small></div></div><div class="foot-links"><?php if(Auth::isSuperAdmin()&&Auth::tenantId()):?><a href="<?= Security::e(app_url('?route=super&leave=1')) ?>">Sair da empresa</a><?php endif;?><a href="<?= Security::e(app_url('?route=logout')) ?>">Encerrar sessão</a></div></div></aside><main class="content"><header class="topbar"><div><span class="top-eyebrow"><?= Auth::isSuperAdmin()&&!Auth::tenantId()?'PLATAFORMA':'PAINEL' ?></span><h1><?= Security::e($title) ?></h1><div class="muted top-subtitle"><?= $context?Security::e($context).' · ':'' ?><?= $type?Security::e($type):'Gestão multiempresa' ?></div></div><?php if($context):?><div class="topbar-actions"><span class="context-pill"><?= Security::e($context) ?></span></div><?php endif;?></header><?php if($flash):?><div class="alert <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div><?php endif;?><?php
}
function em_footer():void{
    $sw=json_encode(app_url('sw.js?v='.em_asset_version('sw.js')),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    ?><script>
    (()=>{
      const sidebar=document.querySelector('.sidebar');
      const toggle=document.querySelector('.nav-toggle');
      const closeMenu=()=>{sidebar?.classList.remove('nav-open');toggle?.setAttribute('aria-expanded','false');document.body.classList.remove('menu-open')};
      toggle?.addEventListener('click',()=>{const open=!sidebar?.classList.contains('nav-open');sidebar?.classList.toggle('nav-open',open);toggle.setAttribute('aria-expanded',open?'true':'false');document.body.classList.toggle('menu-open',open)});
      document.querySelectorAll('.nav a').forEach(a=>a.addEventListener('click',closeMenu));
      window.addEventListener('resize',()=>{if(window.innerWidth>950)closeMenu()});
      if('serviceWorker'in navigator){navigator.serviceWorker.register(<?= $sw ?>).catch(()=>{});}
    })();
    </script></main></div></body></html><?php
}
function em_require_tenant():int{$id=Auth::tenantId();if(!$id){if(Auth::isSuperAdmin())em_go('super');http_response_code(403);exit('Selecione uma empresa.');}return $id;}
