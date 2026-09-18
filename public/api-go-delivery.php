<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\DeliveryLocationService;
use EventMenu\Services\DeliveryProgressService;
use EventMenu\Services\DeliveryPublicTrackingService;
use EventMenu\Support\OperationalDiagnostics;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function god_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function god_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$body=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($body)?$body:[];}catch(Throwable $e){$safe=OperationalDiagnostics::userFacing($e,'Não foi possível interpretar os dados enviados. Tente novamente.','delivery.request.parse');god_out(['ok'=>false,'error'=>$safe['message'],'reference'=>$safe['reference']],400);}}
function god_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')god_out(['ok'=>false,'error'=>'Esta ação não pode ser realizada desta forma.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')god_out(['ok'=>false,'error'=>'Sua sessão não é válida. Entre novamente.'],401);$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'list');$service=new DeliveryProgressService();$locations=new DeliveryLocationService();$publicTracking=new DeliveryPublicTrackingService();
    if($action==='list'){
        $progress=$service->listMine();
        foreach($progress as &$row){
            if(($row['order_status']??'')==='out_for_delivery'&&!empty($row['route_started_at'])&&empty($row['arrived_at'])&&empty($row['completed_at'])){
                try{$tracking=$publicTracking->issue((int)$row['order_id']);$row['tracking_url']=$tracking['url'];$row['tracking_expires_at']=$tracking['expires_at'];}catch(RuntimeException $e){OperationalDiagnostics::capture($e,'delivery.tracking.issue',['order_id'=>(int)$row['order_id']]);}
            }
        }unset($row);
        god_out(['ok'=>true,'progress'=>$progress]);
    }
    if($action==='manager-live')god_out(['ok'=>true,'locations'=>$locations->liveForManager()]);

    god_post();$body=god_body();
    if($action==='location')god_out(['ok'=>true]+$locations->record($body,$deviceId));

    $orderId=(int)($body['order_id']??0);
    if($action==='pickup')god_out(['ok'=>true,'progress'=>$service->pickup($orderId)]);
    if($action==='start-route'){
        $progress=$service->startRoute($orderId);$tracking=$publicTracking->issue($orderId);$progress['tracking_url']=$tracking['url'];$progress['tracking_expires_at']=$tracking['expires_at'];
        god_out(['ok'=>true,'progress'=>$progress]);
    }
    if($action==='arrive'){
        $progress=$service->arrive($orderId);$tenantId=Auth::tenantId();$userId=Auth::id();if($tenantId&&$userId)$locations->stopForOrder($tenantId,$userId,$orderId);
        god_out(['ok'=>true,'progress'=>$progress,'tracking'=>false]);
    }
    if($action==='complete'){
        $result=$service->complete($orderId);$tenantId=Auth::tenantId();$userId=Auth::id();if($tenantId&&$userId)$locations->stopForOrder($tenantId,$userId,$orderId);
        god_out(['ok'=>true,'tracking'=>false]+$result);
    }
    god_out(['ok'=>false,'error'=>'Esta ação de entrega não está disponível.'],404);
}catch(RuntimeException $e){$safe=OperationalDiagnostics::userFacing($e,'Não foi possível atualizar a entrega. Tente novamente.','delivery.operation',['action'=>(string)($_GET['action']??'')]);god_out(['ok'=>false,'error'=>$safe['message'],'reference'=>$safe['reference']],422);}catch(Throwable $e){$safe=OperationalDiagnostics::userFacing($e,'Não foi possível concluir a operação de entrega. Tente novamente.','delivery.unexpected',['action'=>(string)($_GET['action']??'')]);god_out(['ok'=>false,'error'=>$safe['message'],'reference'=>$safe['reference']],500);}
