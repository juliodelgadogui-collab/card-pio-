<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\SensitiveApiRateLimitService;
use EventMenu\Services\TabSplitNfcService;
use EventMenu\Services\TabSplitPaymentService;
use EventMenu\Services\TabSplitPixService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function gotab_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gotab_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){gotab_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function gotab_post():void{if($_SERVER['REQUEST_METHOD']!=='POST')gotab_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gotab_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$user=$auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift||!in_array($shift['mode'],['operation','pay'],true))throw new RuntimeException('A conta dividida exige turno Operação ou Pay aberto.');
    $action=(string)($_GET['action']??'account');$service=new TabSplitPaymentService();$limit=new SensitiveApiRateLimitService();

    if($action==='account'){$tabId=(int)($_GET['tab_id']??0);gotab_out(['ok'=>true,'account'=>$service->account($tabId)]);}
    if($action==='group-create'){
        gotab_post();$limit->assertAllowed('tab.group_create',$user,$deviceId);$body=gotab_body();$options=is_array($body['options']??null)?$body['options']:[];$group=$service->create((int)($body['tab_id']??0),(string)($body['split_type']??''),(string)($body['method']??''),$options,(string)($body['idempotency_key']??''));gotab_out(['ok'=>true,'group'=>$group],201);
    }
    if($action==='group-status'){$groupId=(int)($_GET['group_id']??0);gotab_out(['ok'=>true,'group'=>$service->status($groupId)]);}
    if($action==='group-cancel'){gotab_post();$limit->assertAllowed('tab.group_cancel',$user,$deviceId);$body=gotab_body();gotab_out(['ok'=>true,'group'=>$service->cancel((int)($body['group_id']??0))]);}
    if($action==='pix-create'){gotab_post();$limit->assertAllowed('tab.pix_create',$user,$deviceId);$body=gotab_body();gotab_out(['ok'=>true,'pix'=>(new TabSplitPixService())->create((int)($body['group_id']??0),(string)($body['tax_id']??''))],201);}
    if($action==='pix-status'){$groupId=(int)($_GET['group_id']??0);gotab_out(['ok'=>true]+(new TabSplitPixService())->status($groupId));}
    if($action==='nfc-intent'){gotab_post();$limit->assertAllowed('tab.nfc_intent',$user,$deviceId);$body=gotab_body();gotab_out(['ok'=>true]+(new TabSplitNfcService())->createIntent((int)($body['group_id']??0),$deviceId),201);}
    if($action==='nfc-verify'){gotab_post();$limit->assertAllowed('tab.nfc_verify',$user,$deviceId);$body=gotab_body();gotab_out((new TabSplitNfcService())->verifyIntent((string)($body['intent_token']??''),(string)($body['transaction_code']??''),$deviceId));}
    gotab_out(['ok'=>false,'error'=>'Endpoint de conta dividida não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){gotab_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){gotab_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gotab_out(['ok'=>false,'error'=>$e->getMessage()],500);gotab_out(['ok'=>false,'error'=>'Erro interno.'],500);}
