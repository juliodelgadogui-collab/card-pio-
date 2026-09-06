<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ManagerOperationsService;
use EventMenu\Services\OrderReopenService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function gom_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gom_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){gom_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function gom_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)gom_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gom_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift)throw new RuntimeException('Inicie um turno antes de abrir o painel gerencial.');
    if(!in_array($shift['mode'],['operation','pay'],true))throw new RuntimeException('O painel gerencial está disponível nos modos Operação ou Pay.');
    $action=(string)($_GET['action']??'overview');$service=new ManagerOperationsService();
    if($action==='overview')gom_out(['ok'=>true,'overview'=>$service->overview()]);
    if($action==='details')gom_out(['ok'=>true,'details'=>$service->details()]);
    if($action==='transfer-delivery'){gom_method('POST');$body=gom_body();gom_out(['ok'=>true,'order'=>$service->transferDelivery((int)($body['order_id']??0),(int)($body['delivery_user_id']??0))]);}
    if($action==='reopen-candidates')gom_out(['ok'=>true,'orders'=>(new OrderReopenService())->candidates()]);
    if($action==='reopen-order'){gom_method('POST');$body=gom_body();gom_out(['ok'=>true,'order'=>(new OrderReopenService())->reopen((int)($body['order_id']??0),(string)($body['reason']??''))]);}
    if($action==='cancel-order')gom_out(['ok'=>false,'error'=>'Cancelamento direto foi desativado. Use o fluxo de solicitação e autorização.'],410);
    gom_out(['ok'=>false,'error'=>'Endpoint gerencial não encontrado.'],404);
}catch(RuntimeException $e){gom_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gom_out(['ok'=>false,'error'=>$e->getMessage()],500);gom_out(['ok'=>false,'error'=>'Erro interno.'],500);}
