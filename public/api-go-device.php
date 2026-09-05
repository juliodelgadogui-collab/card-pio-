<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\MobileDeviceStatusService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}

function god_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')god_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);$action=(string)($_GET['action']??'status');
    if($action==='status')god_out(['ok'=>true,'device'=>(new MobileDeviceStatusService())->current($deviceId,(int)$user['token_id'])]);
    god_out(['ok'=>false,'error'=>'Endpoint de aparelho não encontrado.'],404);
}catch(RuntimeException $e){god_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))god_out(['ok'=>false,'error'=>$e->getMessage()],500);god_out(['ok'=>false,'error'=>'Erro interno.'],500);}
