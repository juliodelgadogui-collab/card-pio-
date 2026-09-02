<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

function index_base():string{$configured=(string)env('APP_URL','');$path=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';if($path===''||$path==='/'){$script=(string)($_SERVER['SCRIPT_NAME']??'');$dir=str_replace('\\','/',dirname($script));$path=$dir==='/'||$dir==='.'?'':$dir;}return rtrim($path,'/');}
function index_url(string $path=''):string{$base=index_base();if($path==='')return $base!==''?$base.'/':'/';if(!str_starts_with($path,'/'))$path='/'.$path;return $base.$path;}

$route=(string)($_GET['route']??'');
try{$pdo=Database::connection();}catch(Throwable $e){http_response_code(503);exit('<h1>EventMenu Premium</h1><p>Banco ainda não configurado. Acesse <a href="'.htmlspecialchars(index_url('/install.php'),ENT_QUOTES,'UTF-8').'">o instalador</a>.</p>');}

if($route==='login'){
    if(Auth::check()){header('Location: '.index_url('/?route='.Auth::homeRoute()));exit;}
    $error=!empty($_GET['blocked'])?'Sua sessão foi encerrada porque o usuário ou a empresa foi bloqueado.':null;
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada.';
        elseif(Auth::attempt((string)($_POST['email']??''),(string)($_POST['password']??''))){header('Location: '.index_url('/?route='.Auth::homeRoute()));exit;}
        else$error='E-mail ou senha inválidos.';
    }
    ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#6d4aff"><title>Entrar — EventMenu Premium</title><link rel="manifest" href="<?= Security::e(index_url('/manifest.webmanifest')) ?>"><link rel="stylesheet" href="<?= Security::e(index_url('/assets/app.css')) ?>?v=92p4"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Entrar no sistema</h1><p class="muted">Cada função abre automaticamente sua própria área de trabalho.</p><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus autocomplete="username"></label><label>Senha<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Entrar</button></form></main><script>if('serviceWorker'in navigator){navigator.serviceWorker.register('<?= Security::e(index_url('/sw.js')) ?>').catch(()=>{});}</script></body></html><?php exit;
}
if($route==='logout'){Auth::logout();header('Location: '.index_url('/?route=login'));exit;}
if(!Auth::check()){header('Location: '.index_url('/?route=login'));exit;}

require __DIR__.'/../app/admin_helpers.php';
if($route==='')$route=Auth::homeRoute();
if(Auth::role()==='super_admin'&&!Auth::tenantId()&&$route==='dashboard')$route='superadmin';
$routes=['superadmin'=>'superadmin.php','dashboard'=>'dashboard.php','pos'=>'pos.php','counter-orders'=>'counter_orders.php','fulfillment'=>'fulfillment.php','products'=>'products.php','orders'=>'orders.php','delivery'=>'delivery.php','my-deliveries'=>'my_deliveries.php','kitchen'=>'kitchen.php','restaurant'=>'restaurant.php','cash'=>'cash.php','customers'=>'customers.php','coupons'=>'coupons.php','events'=>'events.php','tickets'=>'tickets.php','guests'=>'guests.php','promoters'=>'promoters.php','payments'=>'payments.php','gateways'=>'gateways.php','users'=>'users.php','reports'=>'reports.php','audit'=>'audit.php','settings'=>'settings.php'];
$file=$routes[$route]??null;if(!$file){http_response_code(404);em_header('Página não encontrada','');echo '<section class="card"><h2>404</h2><p>A página solicitada não existe.</p><a class="button primary" href="'.Security::e(index_url('/?route='.Auth::homeRoute())).'">Voltar</a></section>';em_footer();exit;}
require __DIR__.'/../app/routes/'.$file;
