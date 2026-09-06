<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\UniversalQrService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function goqr_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function goqr_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){goqr_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')goqr_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift)throw new RuntimeException('Inicie um turno antes de usar o QR operacional.');
    $service=new UniversalQrService();$action=(string)($_GET['action']??'resolve');

    if($action==='resolve'){
        $value=(string)($_GET['value']??'');goqr_out(['ok'=>true,'qr'=>$service->resolve($value)]);
    }
    if($action==='issue'){
        if($_SERVER['REQUEST_METHOD']!=='POST')goqr_out(['ok'=>false,'error'=>'Método não permitido.'],405);$body=goqr_body();
        $qr=$service->issue((string)($body['type']??''),(int)($body['entity_id']??0),(string)($body['label']??''),isset($body['ttl_hours'])?(int)$body['ttl_hours']:null);goqr_out(['ok'=>true,'qr'=>$qr],201);
    }
    if($action==='revoke'){
        if($_SERVER['REQUEST_METHOD']!=='POST')goqr_out(['ok'=>false,'error'=>'Método não permitido.'],405);$body=goqr_body();$service->revoke((string)($body['type']??''),(int)($body['entity_id']??0));goqr_out(['ok'=>true]);
    }
    goqr_out(['ok'=>false,'error'=>'Endpoint de QR não encontrado.'],404);
}catch(RuntimeException $e){goqr_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goqr_out(['ok'=>false,'error'=>$e->getMessage()],500);goqr_out(['ok'=>false,'error'=>'Erro interno.'],500);}
