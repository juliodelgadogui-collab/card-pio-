<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\OrderCancellationService;
use EventMenu\Services\SensitiveApiRateLimitService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function goc_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function goc_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($body)?$body:[];}catch(Throwable){goc_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function goc_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')goc_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')goc_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$user=$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'pending');$service=new OrderCancellationService();$limit=new SensitiveApiRateLimitService();
    if($action==='pending')goc_out(['ok'=>true,'requests'=>$service->pending()]);
    if($action==='order'){$orderId=(int)($_GET['order_id']??0);goc_out(['ok'=>true,'request'=>$service->forOrder($orderId)]);}
    goc_post();$body=goc_body();
    if($action==='request'){$limit->assertAllowed('cancel.request',$user,$deviceId);goc_out(['ok'=>true,'request'=>$service->request((int)($body['order_id']??0),(string)($body['reason']??''))],201);}
    if($action==='approve'){$limit->assertAllowed('cancel.approve',$user,$deviceId);goc_out(['ok'=>true,'result'=>$service->approve((int)($body['request_id']??0))]);}
    if($action==='reject'){$limit->assertAllowed('cancel.reject',$user,$deviceId);goc_out(['ok'=>true,'result'=>$service->reject((int)($body['request_id']??0),(string)($body['reason']??''))]);}
    goc_out(['ok'=>false,'error'=>'Endpoint de cancelamento não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){goc_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){goc_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goc_out(['ok'=>false,'error'=>$e->getMessage()],500);goc_out(['ok'=>false,'error'=>'Erro interno.'],500);}
