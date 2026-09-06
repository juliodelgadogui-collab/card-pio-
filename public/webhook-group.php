<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\TabSplitPixService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function group_webhook_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    if($_SERVER['REQUEST_METHOD']!=='POST')group_webhook_out(['ok'=>false,'error'=>'Método não permitido.'],405);
    $provider=strtolower(trim((string)($_GET['provider']??'')));$tenant=trim((string)($_GET['tenant']??''));if($provider!=='pagbank'||$tenant==='')group_webhook_out(['ok'=>false,'error'=>'Webhook inválido.'],400);
    $raw=file_get_contents('php://input');if($raw===false||$raw==='')group_webhook_out(['ok'=>false,'error'=>'Payload ausente.'],400);
    $headers=function_exists('getallheaders')?getallheaders():[];
    group_webhook_out((new TabSplitPixService())->processWebhook($tenant,$raw,is_array($headers)?$headers:[]));
}catch(RuntimeException $e){group_webhook_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))group_webhook_out(['ok'=>false,'error'=>$e->getMessage()],500);group_webhook_out(['ok'=>false,'error'=>'Erro interno.'],500);}
