<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ReceiptPresentationService;
use EventMenu\Services\ReceiptService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}
function gor_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gor_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $auth->authenticate($token,$deviceId);$action=(string)($_GET['action']??'order');$service=new ReceiptService();
    if($action==='order'){
        $receipt=$service->order((int)($_GET['order_id']??0));
        $receipt['presentation']=(new ReceiptPresentationService())->forOrder($receipt);
        gor_out(['ok'=>true,'receipt'=>$receipt]);
    }
    if($action==='group')gor_out(['ok'=>true,'receipt'=>$service->paymentGroup((int)($_GET['group_id']??0))]);
    gor_out(['ok'=>false,'error'=>'Endpoint de comprovante não encontrado.'],404);
}catch(RuntimeException $e){gor_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gor_out(['ok'=>false,'error'=>$e->getMessage()],500);gor_out(['ok'=>false,'error'=>'Erro interno.'],500);}
