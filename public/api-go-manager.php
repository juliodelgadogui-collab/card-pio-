<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ManagerOperationsService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}

function gom_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gom_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift)throw new RuntimeException('Inicie um turno antes de abrir o painel gerencial.');
    if(!in_array($shift['mode'],['operation','pay'],true))throw new RuntimeException('O painel gerencial está disponível nos modos Operação ou Pay.');
    $action=(string)($_GET['action']??'overview');
    if($action==='overview')gom_out(['ok'=>true,'overview'=>(new ManagerOperationsService())->overview()]);
    gom_out(['ok'=>false,'error'=>'Endpoint gerencial não encontrado.'],404);
}catch(RuntimeException $e){gom_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gom_out(['ok'=>false,'error'=>$e->getMessage()],500);gom_out(['ok'=>false,'error'=>'Erro interno.'],500);}
