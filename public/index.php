<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

$route=(string)($_GET['route']??'dashboard');
try{$pdo=Database::connection();}catch(Throwable $e){http_response_code(503);exit('<h1>EventMenu Premium</h1><p>Banco ainda não configurado. Copie <code>.env.example</code> para <code>.env</code>, configure o MySQL e acesse <a href="/install.php">/install.php</a>.</p>');}

if($route==='login'){
    if(Auth::check()){header('Location: /');exit;}$error=!empty($_GET['blocked'])?'Sua sessão foi encerrada porque o usuário ou a empresa foi bloqueado.':null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada.';
        elseif(Auth::attempt((string)($_POST['email']??''),(string)($_POST['password']??''))){header('Location: '.(Auth::role()==='super_admin'?'/?route=superadmin':'/'));exit;}
        else$error='E-mail ou senha inválidos.';
    }
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0f14"><title>Entrar — EventMenu Premium</title><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Entrar no painel</h1><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus autocomplete="username"></label><label>Senha<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Entrar</button></form></main><script>if('serviceWorker'in navigator){navigator.serviceWorker.register('/sw.js').catch(()=>{});}</script></body></html><?php exit;
}
if($route==='logout'){Auth::logout();header('Location: /?route=login');exit;}
if(!Auth::check()){header('Location: /?route=login');exit;}

require __DIR__.'/../app/admin_helpers.php';
if(Auth::role()==='super_admin' && !Auth::tenantId() && $route==='dashboard')$route='superadmin';
$routes=[
 'superadmin'=>'superadmin.php','dashboard'=>'dashboard.php','pos'=>'pos.php','products'=>'products.php','orders'=>'orders.php','delivery'=>'delivery.php','kitchen'=>'kitchen.php','restaurant'=>'restaurant.php','cash'=>'cash.php','customers'=>'customers.php','coupons'=>'coupons.php','events'=>'events.php','tickets'=>'tickets.php','guests'=>'guests.php','promoters'=>'promoters.php','payments'=>'payments.php','gateways'=>'gateways.php','users'=>'users.php','reports'=>'reports.php','audit'=>'audit.php','settings'=>'settings.php'
];
$file=$routes[$route]??null;if(!$file){http_response_code(404);em_header('Página não encontrada','');echo '<section class="card"><h2>404</h2><p>A página solicitada não existe.</p><a class="button primary" href="/">Voltar</a></section>';em_footer();exit;}
require __DIR__.'/../app/routes/'.$file;