<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\FinanceService;
use EventMenu\Services\OperatingUnitService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}
function gofin_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
    if($_SERVER['REQUEST_METHOD']!=='GET')gofin_out(['ok'=>false,'error'=>'Método não permitido.'],405);
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gofin_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);Auth::requirePermission('finance.view');
    $from=(string)($_GET['from']??date('Y-m-01'));$to=(string)($_GET['to']??date('Y-m-d'));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||strtotime($from)>strtotime($to))throw new RuntimeException('Período inválido.');
    $unitIds=[];$unit=null;try{$unit=(new OperatingUnitService())->current();if($unit)$unitIds=[(int)$unit['id']];}catch(Throwable){}
    $data=(new FinanceService())->dashboard($from,$to,$unitIds);gofin_out(['ok'=>true,'scope'=>['unit_id'=>$unit['id']??null,'unit_name'=>$unit['name']??'Empresa'],'from'=>$from,'to'=>$to,'summary'=>$data['summary'],'dre'=>$data['dre'],'accounts'=>$data['accounts'],'open'=>array_slice($data['open'],0,15)]);
}catch(RuntimeException$e){gofin_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable$e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gofin_out(['ok'=>false,'error'=>$e->getMessage()],500);gofin_out(['ok'=>false,'error'=>'Erro interno.'],500);}
