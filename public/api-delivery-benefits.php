<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\DeliveryCustomerAuthService;
use EventMenu\Services\DeliveryCustomerMarketplaceService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function delivery_benefits_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function delivery_benefits_bearer():string{$header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));if($header===''&&function_exists('getallheaders')){$headers=getallheaders();$header=trim((string)($headers['Authorization']??$headers['authorization']??''));}return preg_match('/^Bearer\s+(.+)$/i',$header,$m)?trim($m[1]):'';}
function delivery_benefits_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){delivery_benefits_out(['ok'=>false,'code'=>'INVALID_BODY','message'=>'Não foi possível ler os dados enviados.'],400);}}
function delivery_benefits_safe(Throwable$e):string{$m=trim($e->getMessage())?:'Não foi possível calcular os benefícios.';$lower=mb_strtolower($m);foreach(['sqlstate','select ','insert ','update ','pdo','sqlite','mysql','database','exception']as$t)if(str_contains($lower,$t))return'Não foi possível calcular os benefícios agora.';return mb_substr($m,0,260);}

try{
    $pdo=Database::connection();$auth=new DeliveryCustomerAuthService();$account=$auth->authenticate($pdo,delivery_benefits_bearer());$accountId=(int)$account['id'];$rate=new ApiRateLimitService();$rate->assertAllowed('delivery.customer.benefits',$rate->requestSubject('account:'.$accountId),80,60,'Muitas consultas em pouco tempo.');
    $market=new DeliveryCustomerMarketplaceService();$action=strtolower(trim((string)($_GET['action']??'summary')));
    if($action==='summary'){
        if($_SERVER['REQUEST_METHOD']!=='GET')delivery_benefits_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$tenantId=(int)($_GET['tenant_id']??0);delivery_benefits_out(['ok'=>true,'benefits'=>$market->benefits($pdo,$accountId,$tenantId)]);
    }
    if($action==='coupon-quote'){
        if($_SERVER['REQUEST_METHOD']!=='POST')delivery_benefits_out(['ok'=>false,'message'=>'Ação indisponível.'],405);$body=delivery_benefits_body();$tenantId=(int)($body['tenant_id']??0);$subtotal=max(0,(int)($body['subtotal_cents']??0));$code=(string)($body['coupon_code']??'');$quote=$market->couponQuote($pdo,$accountId,$tenantId,$code,$subtotal);delivery_benefits_out(['ok'=>true,'coupon'=>$quote]);
    }
    delivery_benefits_out(['ok'=>false,'code'=>'NOT_FOUND','message'=>'Ação não encontrada.'],404);
}catch(ApiRateLimitExceededException$e){delivery_benefits_out(['ok'=>false,'code'=>'RATE_LIMITED','message'=>delivery_benefits_safe($e)],429);}catch(RuntimeException$e){$m=delivery_benefits_safe($e);$status=in_array($m,['Sua sessão expirou. Entre novamente.','Entre na sua conta para continuar.'],true)?401:422;delivery_benefits_out(['ok'=>false,'code'=>$status===401?'UNAUTHENTICATED':'VALIDATION_ERROR','message'=>$m],$status);}catch(Throwable$e){error_log('[delivery-benefits] '.$e::class.': '.$e->getMessage());delivery_benefits_out(['ok'=>false,'code'=>'INTERNAL_ERROR','message'=>'Não foi possível calcular os benefícios agora.'],500);}
