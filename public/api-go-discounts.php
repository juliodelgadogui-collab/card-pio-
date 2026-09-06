<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\OrderDiscountService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function godis_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function godis_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($body)?$body:[];}catch(Throwable){godis_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function godis_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')godis_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')godis_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'pending');$service=new OrderDiscountService();
    if($action==='pending')godis_out(['ok'=>true,'requests'=>$service->pending()]);
    if($action==='order'){$orderId=(int)($_GET['order_id']??0);godis_out(['ok'=>true,'request'=>$service->forOrder($orderId)]);}
    if($action==='policy')godis_out(['ok'=>true,'policy'=>$service->policy()]);
    godis_post();$body=godis_body();
    if($action==='request'){
        $type=strtolower((string)($body['discount_type']??'fixed'));
        $value=$type==='percent'?(int)($body['value_bps']??0):(int)($body['amount_cents']??$body['value_cents']??0);
        godis_out(['ok'=>true,'request'=>$service->request((int)($body['order_id']??0),$value,(string)($body['reason']??''),$type)],201);
    }
    if($action==='approve')godis_out(['ok'=>true,'result'=>$service->approve((int)($body['request_id']??0))]);
    if($action==='reject')godis_out(['ok'=>true,'result'=>$service->reject((int)($body['request_id']??0),(string)($body['reason']??''))]);
    godis_out(['ok'=>false,'error'=>'Endpoint de desconto não encontrado.'],404);
}catch(RuntimeException $e){godis_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))godis_out(['ok'=>false,'error'=>$e->getMessage()],500);godis_out(['ok'=>false,'error'=>'Erro interno.'],500);}
