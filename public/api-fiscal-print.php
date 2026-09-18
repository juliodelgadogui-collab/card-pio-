<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\FiscalDanfeService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}

function fiscal_print_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    if($_SERVER['REQUEST_METHOD']!=='GET')fiscal_print_out(['ok'=>false,'error'=>'Método não permitido.'],405);
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')fiscal_print_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $documentId=(int)($_GET['document_id']??0);fiscal_print_out(['ok'=>true,'danfe'=>(new FiscalDanfeService())->data($documentId)]);
}catch(RuntimeException$e){fiscal_print_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable$e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))fiscal_print_out(['ok'=>false,'error'=>$e->getMessage()],500);fiscal_print_out(['ok'=>false,'error'=>'Erro interno.'],500);}
