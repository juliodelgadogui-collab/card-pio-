<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\OrderUnitRoutingService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function gou_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gou_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){gou_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function gou_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)gou_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gou_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);$tenantId=(int)$user['tenant_id'];$action=(string)($_GET['action']??'list');$units=new OperatingUnitService();
    if($action==='list'){
        $s=Database::connection()->prepare('SELECT name FROM tenants WHERE id=? LIMIT 1');$s->execute([$tenantId]);
        gou_out(['ok'=>true,'tenant'=>['id'=>$tenantId,'name'=>(string)($s->fetchColumn()?:'')],'units'=>$units->availableForCurrentUser()]);
    }
    if($action==='shift-open'){
        gou_method('POST');$body=gou_body();$unitId=isset($body['unit_id'])&&(int)$body['unit_id']>0?(int)$body['unit_id']:null;
        $shift=(new WorkShiftService())->open((string)($body['mode']??''),$deviceId,(string)($body['notes']??''),$unitId);
        gou_out(['ok'=>true,'shift'=>$shift],201);
    }
    if($action==='current')gou_out(['ok'=>true,'shift'=>(new WorkShiftService())->current()]);
    if($action==='delivery-unassigned')gou_out(['ok'=>true,'orders'=>(new OrderUnitRoutingService())->pending()]);
    if($action==='delivery-assign-unit'){
        gou_method('POST');$body=gou_body();$order=(new OrderUnitRoutingService())->assign((int)($body['order_id']??0),(int)($body['unit_id']??0));
        gou_out(['ok'=>true,'order'=>$order]);
    }
    gou_out(['ok'=>false,'error'=>'Endpoint de unidade não encontrado.'],404);
}catch(RuntimeException $e){gou_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gou_out(['ok'=>false,'error'=>$e->getMessage()],500);gou_out(['ok'=>false,'error'=>'Erro interno.'],500);}
