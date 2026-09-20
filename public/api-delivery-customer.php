<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\DeliveryCustomerAuthService;
use EventMenu\Services\DeliveryCustomerMarketplaceService;
use EventMenu\Services\DeliveryCustomerPaymentService;
use EventMenu\Services\MarketplaceCatalogService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, DELETE, OPTIONS');http_response_code(204);exit;}

function delivery_customer_out(array $data,int $status=200): never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function delivery_customer_body(): array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$d=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($d)?$d:[];}catch(Throwable){delivery_customer_out(['ok'=>false,'code'=>'INVALID_BODY','message'=>'Não foi possível ler os dados enviados.'],400);}}
function delivery_customer_bearer(): string{$header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));if($header===''&&function_exists('getallheaders')){$headers=getallheaders();$header=trim((string)($headers['Authorization']??$headers['authorization']??''));}return preg_match('/^Bearer\s+(.+)$/i',$header,$m)?trim($m[1]):'';}
function delivery_customer_error(Throwable $e): array{$m=trim($e->getMessage())?:'Não foi possível concluir agora. Tente novamente.';$code=match($m){'Confirme seu e-mail antes de entrar.'=>'EMAIL_NOT_VERIFIED','Sua sessão expirou. Entre novamente.','Entre na sua conta para continuar.'=>'UNAUTHENTICATED','E-mail ou senha inválidos.'=>'INVALID_CREDENTIALS','Já existe uma conta com este e-mail.'=>'EMAIL_IN_USE',default=>'VALIDATION_ERROR'};$lower=mb_strtolower($m);foreach(['sqlstate','select ','insert ','update ','delete ','pdo','sqlite','mysql','database','stack trace','exception']as$t)if(str_contains($lower,$t))return ['code'=>'INTERNAL_ERROR','message'=>'Não foi possível concluir agora. Tente novamente.'];return ['code'=>$code,'message'=>mb_substr($m,0,260)];}

try{
    $pdo=Database::connection();$action=strtolower(trim((string)($_GET['action']??'me')));$body=in_array($_SERVER['REQUEST_METHOD'],['POST','PUT','PATCH','DELETE'],true)?delivery_customer_body():[];$rate=new ApiRateLimitService();$auth=new DeliveryCustomerAuthService();$market=new DeliveryCustomerMarketplaceService();$payments=new DeliveryCustomerPaymentService();
    $subject=$rate->requestSubject('delivery-customer');

    if($action==='register'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.register',$subject,5,3600,'Muitas tentativas de cadastro. Aguarde e tente novamente.');$result=$auth->register($pdo,$body);delivery_customer_out(['ok'=>true]+$result,201);}
    if($action==='resend-verification'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.resend',$subject,4,900,'Aguarde alguns minutos antes de pedir outro e-mail.');$auth->resendVerification($pdo,(string)($body['email']??''));delivery_customer_out(['ok'=>true,'message'=>'Se houver um cadastro pendente, enviaremos um novo e-mail.']);}
    if($action==='login'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.login',$subject,20,600,'Muitas tentativas de acesso. Aguarde alguns minutos.');$result=$auth->login($pdo,$body,(string)($_SERVER['HTTP_USER_AGENT']??''),(string)($_SERVER['REMOTE_ADDR']??''));delivery_customer_out(['ok'=>true]+$result);}
    if($action==='forgot-password'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.forgot',$subject,5,3600,'Aguarde antes de pedir outro link.');try{$auth->forgotPassword($pdo,(string)($body['email']??''));}catch(Throwable$e){error_log('[delivery-forgot] '.$e::class.': '.$e->getMessage());}delivery_customer_out(['ok'=>true,'message'=>'Se a conta existir, enviaremos as instruções para o e-mail.']);}
    if($action==='reset-password'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.reset',$subject,8,3600,'Muitas tentativas. Aguarde.');Database::transaction(fn(PDO$tx)=>$auth->resetPassword($tx,(string)($body['token']??''),(string)($body['password']??'')));delivery_customer_out(['ok'=>true,'message'=>'Senha atualizada. Entre novamente.']);}

    $bearer=delivery_customer_bearer();$account=$auth->authenticate($pdo,$bearer);$accountId=(int)$account['id'];$rate->assertAllowed('delivery.customer.auth',$rate->requestSubject('account:'.$accountId),240,60,'Muitas atualizações em pouco tempo.');

    if($action==='logout'){
        if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $pushToken=trim((string)($body['push_token']??''));
        if($pushToken!=='')$pdo->prepare('UPDATE delivery_customer_push_devices SET active=0,last_seen_at=CURRENT_TIMESTAMP WHERE account_id=? AND push_token=?')->execute([$accountId,$pushToken]);
        $auth->logout($pdo,$bearer);delivery_customer_out(['ok'=>true]);
    }
    if($action==='me'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'customer'=>$auth->me($pdo,$accountId)]);}
    if($action==='profile-save'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'customer'=>$auth->updateProfile($pdo,$accountId,$body)]);}
    if($action==='address-save'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$address=Database::transaction(fn(PDO$tx):array=>$auth->saveAddress($tx,$accountId,$body));delivery_customer_out(['ok'=>true,'address'=>$address]);}
    if($action==='address-delete'){if(!in_array($_SERVER['REQUEST_METHOD'],['POST','DELETE'],true))delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);Database::transaction(fn(PDO$tx)=>$auth->deleteAddress($tx,$accountId,(int)($body['id']??$_GET['id']??0)));delivery_customer_out(['ok'=>true]);}
    if($action==='push-register'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$auth->registerPushDevice($pdo,$accountId,$body);delivery_customer_out(['ok'=>true]);}

    if($action==='stores'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'stores'=>$market->stores($pdo,$accountId,$_GET)]);}
    if($action==='catalog'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$tenantId=(int)($_GET['tenant_id']??0);$unitId=(int)($_GET['unit_id']??0);if($tenantId<1||$unitId<1)throw new RuntimeException('Escolha um restaurante.');$data=(new MarketplaceCatalogService())->catalog($pdo,$tenantId,$unitId);$data['checkout_session']=(new EventMenu\Services\MarketplaceEntryTokenService())->issue($pdo,$tenantId,$unitId,null);delivery_customer_out(['ok'=>true]+$data);}
    if($action==='favorite'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'favorite'=>$market->toggleFavorite($pdo,$accountId,(int)($body['tenant_id']??0),!empty($body['favorite']))]);}
    if($action==='coupon-quote'){
        if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('delivery.customer.coupon',$rate->requestSubject('account:'.$accountId),30,300,'Muitas tentativas de cupom. Aguarde um pouco.');
        $tenantId=(int)($body['tenant_id']??0);$subtotal=max(0,(int)($body['subtotal_cents']??0));$code=(string)($body['code']??'');
        if($tenantId<1||$subtotal<1)throw new RuntimeException('Carrinho inválido para aplicar cupom.');
        delivery_customer_out(['ok'=>true,'coupon'=>$market->couponQuote($pdo,$accountId,$tenantId,$code,$subtotal)]);
    }
    if($action==='order-create'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.order.create',$rate->requestSubject('account:'.$accountId),10,600,'Muitos pedidos enviados. Aguarde alguns minutos.');$order=Database::transaction(fn(PDO$tx):array=>$market->createOrder($tx,$accountId,$body));delivery_customer_out(['ok'=>true,'order'=>$order],201);}
    if($action==='orders'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'orders'=>$market->orders($pdo,$accountId,(int)($_GET['limit']??50))]);}
    if($action==='order'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'order'=>$market->order($pdo,$accountId,(int)($_GET['order_id']??0))]);}
    if($action==='reorder'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'reorder'=>$market->reorder($pdo,$accountId,(int)($body['order_id']??0))]);}
    if($action==='review'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$review=Database::transaction(fn(PDO$tx):array=>$market->review($tx,$accountId,(int)($body['order_id']??0),(int)($body['rating']??0),(string)($body['comment']??'')));delivery_customer_out(['ok'=>true,'review'=>$review]);}

    if($action==='payment-methods'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'methods'=>$market->paymentMethods($pdo,$accountId,(int)($_GET['order_id']??0))]);}
    if($action==='payment-pix'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.payment',$rate->requestSubject('account:'.$accountId),15,600,'Muitas tentativas de pagamento. Aguarde.');$result=$payments->pix($pdo,$accountId,(int)($body['order_id']??0),(string)($body['provider']??''),(string)($body['tax_id']??''));delivery_customer_out(['ok'=>true,'payment'=>$result],201);}
    if($action==='payment-card'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$rate->assertAllowed('delivery.customer.payment',$rate->requestSubject('account:'.$accountId),15,600,'Muitas tentativas de pagamento. Aguarde.');$result=$payments->card($pdo,$accountId,(int)($body['order_id']??0),$body);delivery_customer_out(['ok'=>true,'payment'=>$result],201);}
    if($action==='payment-cash'){if($_SERVER['REQUEST_METHOD']!=='POST')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$change=array_key_exists('change_for_cents',$body)&&$body['change_for_cents']!==null?(int)$body['change_for_cents']:null;delivery_customer_out(['ok'=>true,'payment'=>$market->markCash($pdo,$accountId,(int)($body['order_id']??0),$change)]);}
    if($action==='payment-status'){if($_SERVER['REQUEST_METHOD']!=='GET')delivery_customer_out(['ok'=>false,'message'=>'Ação indisponível.'],405);delivery_customer_out(['ok'=>true,'payment'=>$payments->status($pdo,$accountId,(int)($_GET['order_id']??0))]);}

    delivery_customer_out(['ok'=>false,'code'=>'NOT_FOUND','message'=>'Ação não encontrada.'],404);
}catch(ApiRateLimitExceededException$e){delivery_customer_out(['ok'=>false,'code'=>'RATE_LIMITED','message'=>delivery_customer_error($e)['message']],429);}catch(RuntimeException$e){$err=delivery_customer_error($e);$status=$err['code']==='UNAUTHENTICATED'?401:422;delivery_customer_out(['ok'=>false]+$err,$status);}catch(Throwable$e){error_log('[eventmenu-delivery-customer] '.$e::class.': '.$e->getMessage());delivery_customer_out(['ok'=>false,'code'=>'INTERNAL_ERROR','message'=>'Não foi possível concluir agora. Tente novamente.'],500);}