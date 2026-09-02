<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\LegalService;
use EventMenu\Services\PasswordResetService;

function index_base():string{$configured=(string)env('APP_URL','');$path=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';if($path===''||$path==='/'){$script=(string)($_SERVER['SCRIPT_NAME']??'');$dir=str_replace('\\','/',dirname($script));$path=$dir==='/'||$dir==='.'?'':$dir;}return rtrim($path,'/');}
function index_url(string $path=''):string{$base=index_base();if($path==='')return $base!==''?$base.'/':'/';if(!str_starts_with($path,'/'))$path='/'.$path;return $base.$path;}
function auth_page(string $title,string $body):never{?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#6d4aff"><title><?= Security::e($title) ?> — EventMenu Premium</title><link rel="stylesheet" href="<?= Security::e(index_url('/assets/app.css')) ?>?v=92p5"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><?= $body ?></main></body></html><?php exit;}

$route=(string)($_GET['route']??'');
try{$pdo=Database::connection();}catch(Throwable $e){http_response_code(503);exit('<h1>EventMenu Premium</h1><p>Banco ainda não configurado. Acesse <a href="'.htmlspecialchars(index_url('/install.php'),ENT_QUOTES,'UTF-8').'">o instalador</a>.</p>');}

if($route==='forgot-password'){
    $message=null;if($_SERVER['REQUEST_METHOD']==='POST'){if(!Security::validateCsrf($_POST['_csrf']??null))$message='Sessão expirada. Atualize a página.';else{try{(new PasswordResetService())->request((string)($_POST['email']??''));}catch(Throwable $e){error_log('[password reset] '.$e->getMessage());}$message='Se o e-mail estiver cadastrado, as instruções de redefinição serão enviadas.';}}
    ob_start();?><h1>Redefinir senha</h1><p class="muted">Informe o e-mail da sua conta.</p><?php if($message):?><div class="alert"><?= Security::e($message) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus></label><button class="primary">Enviar instruções</button></form><p><a href="<?= Security::e(index_url('/?route=login')) ?>">Voltar ao login</a></p><?php auth_page('Redefinir senha',(string)ob_get_clean());
}
if($route==='reset-password'){
    $token=(string)($_GET['token']??$_POST['token']??'');$error=null;$ok=false;if($_SERVER['REQUEST_METHOD']==='POST'){if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada.';else try{(new PasswordResetService())->apply($token,(string)($_POST['password']??''));$ok=true;}catch(Throwable $e){$error=$e->getMessage();}}
    ob_start();?><h1>Nova senha</h1><?php if($ok):?><div class="alert ok">Senha redefinida. Todos os dispositivos de pagamento vinculados à conta foram revogados.</div><a class="button primary" href="<?= Security::e(index_url('/?route=login')) ?>">Entrar</a><?php else:?><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="token" value="<?= Security::e($token) ?>"><label>Nova senha<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><button class="primary">Salvar nova senha</button></form><?php endif;?><?php auth_page('Nova senha',(string)ob_get_clean());
}
if($route==='login'){
    if(Auth::check()){header('Location: '.index_url('/?route='.Auth::homeRoute()));exit;}$error=!empty($_GET['blocked'])?'Sua sessão foi encerrada porque o usuário ou a empresa foi bloqueado.':null;
    if($_SERVER['REQUEST_METHOD']==='POST'){if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada.';elseif(Auth::attempt((string)($_POST['email']??''),(string)($_POST['password']??''))){header('Location: '.index_url('/?route='.Auth::homeRoute()));exit;}else$error='E-mail ou senha inválidos.';}
    ob_start();?><h1>Entrar no sistema</h1><p class="muted">Cada função abre automaticamente sua própria área de trabalho.</p><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>E-mail<input type="email" name="email" required autofocus autocomplete="username"></label><label>Senha<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Entrar</button></form><p><a href="<?= Security::e(index_url('/?route=forgot-password')) ?>">Esqueci minha senha</a></p><?php auth_page('Entrar',(string)ob_get_clean());
}
if($route==='logout'){Auth::logout();header('Location: '.index_url('/?route=login'));exit;}
if(!Auth::check()){header('Location: '.index_url('/?route=login'));exit;}

require __DIR__.'/../app/admin_helpers.php';
if($route==='')$route=Auth::homeRoute();if(Auth::role()==='super_admin'&&!Auth::tenantId()&&$route==='dashboard')$route='superadmin';
if($route!=='legal-accept'){try{$pending=(new LegalService())->pendingForUser((int)Auth::id());if($pending){header('Location: '.index_url('/?route=legal-accept'));exit;}}catch(Throwable){}}
$routes=['superadmin'=>'superadmin.php','dashboard'=>'dashboard.php','pos'=>'pos.php','counter-orders'=>'counter_orders.php','fulfillment'=>'fulfillment.php','products'=>'products.php','product-options'=>'product_options.php','orders'=>'orders.php','delivery'=>'delivery.php','my-deliveries'=>'my_deliveries.php','kitchen'=>'kitchen.php','restaurant'=>'restaurant.php','cash'=>'cash.php','customers'=>'customers.php','coupons'=>'coupons.php','events'=>'events.php','tickets'=>'tickets.php','guests'=>'guests.php','promoters'=>'promoters.php','payments'=>'payments.php','gateways'=>'gateways.php','users'=>'users.php','units'=>'units.php','notifications'=>'notifications.php','search'=>'search.php','export'=>'export.php','reports'=>'reports.php','audit'=>'audit.php','settings'=>'settings.php','settings-hub'=>'settings_hub.php','legal'=>'legal.php','legal-accept'=>'legal_accept.php','backup'=>'backup.php','diagnostic'=>'diagnostic.php'];
$file=$routes[$route]??null;if(!$file){http_response_code(404);em_header('Página não encontrada','');echo '<section class="card"><h2>404</h2><p>A página solicitada não existe.</p><a class="button primary" href="'.Security::e(index_url('/?route='.Auth::homeRoute())).'">Voltar</a></section>';em_footer();exit;}require __DIR__.'/../app/routes/'.$file;
