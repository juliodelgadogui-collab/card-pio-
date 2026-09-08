<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\NotificationService;
use EventMenu\Services\PushDeviceService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function gon_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gon_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){gon_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function gon_method(string $method):void{if($_SERVER['REQUEST_METHOD']!==$method)gon_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gon_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'list');$service=new NotificationService();
    if($action==='list'){$limit=(int)($_GET['limit']??100);gon_out(['ok'=>true,'notifications'=>$service->inbox($limit)]);}
    if($action==='read'){gon_method('POST');$body=gon_body();$service->markRead((int)($body['notification_id']??0));gon_out(['ok'=>true]);}
    if($action==='read-all'){gon_method('POST');gon_out(['ok'=>true,'updated'=>$service->markAllRead()]);}
    if(in_array($action,['push-register','push-unregister','push-status'],true)){
        if($deviceId==='')gon_out(['ok'=>false,'error'=>'Identificação do aparelho obrigatória.'],422);$push=new PushDeviceService();
        if($action==='push-register'){gon_method('POST');(new ApiRateLimitService())->assertAllowed('push.register',$deviceId,20,3600);$body=gon_body();$registered=$push->register($deviceId,(string)($body['push_token']??''),(string)($body['platform']??'android'));gon_out(['ok'=>true,'push'=>$registered]);}
        if($action==='push-unregister'){gon_method('POST');gon_out(['ok'=>true,'updated'=>$push->unregister($deviceId)]);}
        gon_out(['ok'=>true,'push'=>$push->status($deviceId)]);
    }
    gon_out(['ok'=>false,'error'=>'Endpoint de notificações não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){gon_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){gon_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gon_out(['ok'=>false,'error'=>$e->getMessage()],500);gon_out(['ok'=>false,'error'=>'Erro interno.'],500);}
