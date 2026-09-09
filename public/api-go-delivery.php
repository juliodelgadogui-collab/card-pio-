<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\DeliveryProgressService;
use EventMenu\Services\DeliveryTrackingService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function god_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function god_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($body)?$body:[];}catch(Throwable){god_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function god_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')god_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')god_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'list');$service=new DeliveryProgressService();
    if($action==='list')god_out(['ok'=>true,'progress'=>$service->listMine()]);
    if($action==='live-locations')god_out(['ok'=>true,'locations'=>(new DeliveryTrackingService())->activeForManager()]);
    god_post();$body=god_body();$orderId=(int)($body['order_id']??0);
    if($action==='location'){
        $location=(new DeliveryTrackingService())->update($orderId,(float)($body['latitude']??999),(float)($body['longitude']??999),isset($body['accuracy_m'])?(float)$body['accuracy_m']:null,isset($body['speed_mps'])?(float)$body['speed_mps']:null,isset($body['bearing_deg'])?(float)$body['bearing_deg']:null,isset($body['captured_at'])?(string)$body['captured_at']:null);
        god_out(['ok'=>true,'location'=>$location]);
    }
    if($action==='pickup')god_out(['ok'=>true,'progress'=>$service->pickup($orderId)]);
    if($action==='start-route')god_out(['ok'=>true,'progress'=>$service->startRoute($orderId)]);
    if($action==='arrive')god_out(['ok'=>true,'progress'=>$service->arrive($orderId)]);
    if($action==='complete')god_out(['ok'=>true]+$service->complete($orderId));
    god_out(['ok'=>false,'error'=>'Endpoint de progresso de entrega não encontrado.'],404);
}catch(RuntimeException $e){god_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))god_out(['ok'=>false,'error'=>$e->getMessage()],500);god_out(['ok'=>false,'error'=>'Erro interno.'],500);}
