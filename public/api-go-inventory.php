<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\InventorySnapshotService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}

try{
    if($_SERVER['REQUEST_METHOD']!=='GET'){
        http_response_code(405);
        echo json_encode(['ok'=>false,'error'=>'Método não permitido.'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $auth=new ApiAuthService();
    $token=ApiAuthService::bearerToken();
    if($token==='')throw new RuntimeException('Sessão necessária.');
    $auth->authenticate($token,ApiAuthService::deviceId());

    $action=(string)($_GET['action']??'snapshot');
    if($action!=='snapshot'){
        http_response_code(404);
        echo json_encode(['ok'=>false,'error'=>'Endpoint de estoque não encontrado.'],JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lowOnly=filter_var($_GET['low_only']??false,FILTER_VALIDATE_BOOL);
    echo json_encode(
        ['ok'=>true]+(new InventorySnapshotService())->snapshot($lowOnly),
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
}catch(RuntimeException$e){
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable$e){
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'error'=>filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL)?$e->getMessage():'Erro interno.'
    ],JSON_UNESCAPED_UNICODE);
}
