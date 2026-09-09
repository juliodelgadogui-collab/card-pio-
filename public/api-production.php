<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ProductionDesktopService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function prod_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function prod_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return$_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){prod_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function prod_method(string$expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)prod_out(['ok'=>false,'error'=>'Método não permitido.'],405);}
function prod_device(string$session,array$body):string{$reported=mb_substr(trim((string)($body['device_id']??$session)),0,190);if($session!==''&&$reported!==''&&!hash_equals($session,$reported))throw new RuntimeException('Identificação do computador não confere com a sessão.');if($reported==='')throw new RuntimeException('Computador não identificado.');return$reported;}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')prod_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'board');$service=new ProductionDesktopService();

    if($action==='board'){prod_method('GET');prod_out(['ok'=>true,'board'=>$service->board()]);}
    if($action==='expedition'){prod_method('GET');prod_out(['ok'=>true,'orders'=>$service->expedition()]);}
    if($action==='job-status'){prod_method('POST');$body=prod_body();prod_out(['ok'=>true,'job'=>$service->changeJobStatus((int)($body['job_id']??0),(string)($body['status']??''),(string)($body['reason']??''))]);}
    if($action==='order-expedite'){prod_method('POST');$body=prod_body();$service->expediteOrder((int)($body['order_id']??0));prod_out(['ok'=>true]);}
    if($action==='print-claim'){prod_method('POST');$body=prod_body();$reported=prod_device($deviceId,$body);prod_out(['ok'=>true,'print'=>$service->claimPrint($reported)]);}
    if($action==='print-complete'){prod_method('POST');$body=prod_body();$reported=prod_device($deviceId,$body);prod_out(['ok'=>true,'queue'=>$service->completePrint((int)($body['queue_id']??0),$reported,!empty($body['success']),(string)($body['error']??''))]);}
    if($action==='print-retry'){prod_method('POST');$body=prod_body();prod_out(['ok'=>true,'queue'=>$service->retryPrint((int)($body['queue_id']??0),(string)($body['reason']??''))]);}
    if($action==='station-printer'){prod_method('POST');Auth::requirePermission('production.manage');$body=prod_body();$reported=trim((string)($body['device_id']??''));if($reported!=='')$reported=prod_device($deviceId,$body);prod_out(['ok'=>true,'station'=>$service->bindStationPrinter((int)($body['station_id']??0),$reported,(string)($body['printer_target']??''),!empty($body['automatic']))]);}

    prod_out(['ok'=>false,'error'=>'Endpoint de produção não encontrado.'],404);
}catch(RuntimeException$e){prod_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable$e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))prod_out(['ok'=>false,'error'=>$e->getMessage()],500);prod_out(['ok'=>false,'error'=>'Erro interno.'],500);}
