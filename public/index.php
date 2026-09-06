<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;

$route=(string)($_GET['route']??'dashboard');
try{$pdo=Database::connection();}catch(Throwable $e){http_response_code(503);$install=Security::e(app_url('install.php'));exit('<h1>EventMenu Premium</h1><p>Banco ainda não configurado. Copie <code>.env.example</code> para <code>.env</code>, configure MySQL ou SQLite e acesse <a href="'.$install.'">instalação</a>.</p>');}

if($route==='login'){
    if(Auth::check()) app_redirect(Auth::isSuperAdmin()&&!Auth::tenantId()?'?route=super':'');
    $error=!empty($_GET['blocked'])?'Sua sessão foi encerrada porque o usuário ou a empresa foi bloqueado.':null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada.';
        elseif(Auth::attempt((string)($_POST['email']??''),(string)($_POST['password']??''))) app_redirect(Auth::isSuperAdmin()?'?route=super':'');
        else $error='E-mail ou senha inválidos ou temporariamente bloqueados por excesso de tentativas.';
    }
    $manifest=Security::e(app_url('manifest.webmanifest'));$css=Security::e(app_url('assets/app.css'));$sw=json_encode(app_url('sw.js'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0f14"><title>Entrar — EventMenu Premium</title><link rel="manifest" href="<?= $manifest ?>"><link rel="stylesheet" href="<?= $css ?>"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Entrar no painel</h1><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus autocomplete="username"></label><label>Senha<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Entrar</button></form></main><script>if('serviceWorker'in navigator){navigator.serviceWorker.register(<?= $sw ?>).catch(()=>{});}</script></body></html><?php exit;
}
if($route==='logout'){Auth::logout();app_redirect('?route=login');}
if(!Auth::check()) app_redirect('?route=login');

require __DIR__.'/../app/admin_helpers.php';
if(Auth::isSuperAdmin()&&!Auth::tenantId()&&$route==='dashboard') em_go('super');
$routes=[
 'super'=>'super.php','dashboard'=>'dashboard.php','pos'=>'pos.php','cash'=>'cash.php','kitchen'=>'kitchen.php','delivery'=>'delivery.php','products'=>'products.php','inventory'=>'inventory.php','orders'=>'orders.php','restaurant'=>'restaurant.php','customers'=>'customers.php','coupons'=>'coupons.php','events'=>'events.php','tickets'=>'tickets.php','guests'=>'guests.php','promoters'=>'promoters.php','payments'=>'payments.php','gateways'=>'gateways.php','users'=>'users.php','units'=>'units.php','reports'=>'reports.php','audit'=>'audit.php','settings'=>'settings.php'
];
$file=$routes[$route]??null;if(!$file){http_response_code(404);em_header('Página não encontrada','');echo '<section class="card"><h2>404</h2><p>A página solicitada não existe.</p><a class="button primary" href="'.Security::e(app_url('')).'">Voltar</a></section>';em_footer();exit;}
if($route!=='super'&&Auth::tenantId()&&!TenantFeatures::routeEnabled($route,Auth::tenantId())){http_response_code(403);em_header('Módulo não habilitado','');echo '<section class="card"><h2>Módulo fora do tipo de operação</h2><p>Esta empresa está configurada como <strong>'.Security::e(TenantFeatures::label(Auth::tenantId())).'</strong>. O módulo solicitado não faz parte desta operação.</p><a class="button primary" href="'.Security::e(app_url('')).'">Voltar</a></section>';em_footer();exit;}
require __DIR__.'/../app/routes/'.$file;