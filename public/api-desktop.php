<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\PermissionCatalog;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\DesktopHardwareService;
use EventMenu\Services\FiscalCertificateService;
use EventMenu\Services\FiscalService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\TerminalPaymentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function desktop_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function desktop_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){desktop_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function desktop_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)desktop_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();
    $token=ApiAuthService::bearerToken();
    $deviceId=ApiAuthService::deviceId();
    if($token==='')desktop_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);
    $action=(string)($_GET['action']??'context');
    $fiscal=new FiscalService();$certificates=new FiscalCertificateService();$hardware=new DesktopHardwareService();$terminalPayments=new TerminalPaymentService();

    if($action==='context'){
        $effective=Auth::effectivePermissions();
        desktop_out([
            'ok'=>true,'user'=>$user,
            'permissions'=>PermissionCatalog::appPermissionMap($effective),
            'permission_names'=>$effective,
            'units'=>(new OperatingUnitService())->availableForCurrentUser(),
        ]);
    }

    if($action==='fiscal-profile'){
        $unitId=(int)($_GET['unit_id']??0);
        desktop_out(['ok'=>true,'profile'=>$fiscal->profileForUnit($unitId)]);
    }
    if($action==='fiscal-profile-save'){
        desktop_method('POST');
        desktop_out(['ok'=>true,'profile'=>$fiscal->saveProfile(desktop_body())]);
    }
    if($action==='certificate-save-a1'){
        desktop_method('POST');$body=desktop_body();
        desktop_out(['ok'=>true,'certificate'=>$certificates->saveA1((int)($body['profile_id']??0),(string)($body['pfx_base64']??''),(string)($body['password']??''),(string)($body['storage_scope']??'server'))],201);
    }
    if($action==='certificate-register-a3'){
        desktop_method('POST');$body=desktop_body();
        desktop_out(['ok'=>true,'certificate'=>$certificates->registerA3Reference((int)($body['profile_id']??0),(string)($body['subject']??''),(string)($body['serial_number']??''),(string)($body['thumbprint']??''),isset($body['valid_until'])?(string)$body['valid_until']:null)],201);
    }
    if($action==='fiscal-queue'){
        desktop_method('POST');$body=desktop_body();
        desktop_out(['ok'=>true,'document'=>$fiscal->queueForOrder((int)($body['order_id']??0),(string)($body['document']??''))],201);
    }
    if($action==='fiscal-documents')desktop_out(['ok'=>true,'documents'=>$fiscal->documents((int)($_GET['limit']??100))]);

    if($action==='terminal-list')desktop_out(['ok'=>true,'terminals'=>$hardware->terminalConfigs((int)($_GET['unit_id']??0))]);
    if($action==='terminal-save'){
        desktop_method('POST');
        desktop_out(['ok'=>true,'terminal'=>$hardware->saveTerminal(desktop_body())]);
    }
    if($action==='terminal-intent-create'){
        desktop_method('POST');$body=desktop_body();
        $reported=(string)($body['device_id']??$deviceId);
        if($deviceId!==''&&$reported!==''&&!hash_equals($deviceId,$reported))throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        desktop_out(['ok'=>true,'intent'=>$terminalPayments->createIntent((int)($body['order_id']??0),(int)($body['terminal_config_id']??0),(int)($body['amount_cents']??0),(string)($body['payment_type']??''),(int)($body['installments']??1),$reported,(string)($body['idempotency_key']??''))],201);
    }
    if($action==='terminal-intent-processing'){
        desktop_method('POST');$body=desktop_body();$reported=(string)($body['device_id']??$deviceId);
        if($deviceId!==''&&$reported!==''&&!hash_equals($deviceId,$reported))throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        desktop_out(['ok'=>true,'intent'=>$terminalPayments->markProcessing((string)($body['intent_token']??''),$reported)]);
    }
    if($action==='terminal-intent-result'){
        desktop_method('POST');$body=desktop_body();$reported=(string)($body['device_id']??$deviceId);
        if($deviceId!==''&&$reported!==''&&!hash_equals($deviceId,$reported))throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        desktop_out(['ok'=>true,'intent'=>$terminalPayments->recordLocalResult((string)($body['intent_token']??''),$reported,!empty($body['approved']),(string)($body['provider_transaction_id']??''),(string)($body['authorization_code']??''),is_array($body['raw_result']??null)?$body['raw_result']:[])]);
    }
    if($action==='terminal-intent-status')desktop_out(['ok'=>true,'intent'=>$terminalPayments->status((string)($_GET['intent_token']??''))]);

    if($action==='hardware-heartbeat'){
        desktop_method('POST');$body=desktop_body();
        $reported=(string)($body['device_id']??$deviceId);
        if($deviceId!==''&&$reported!==''&&!hash_equals($deviceId,$reported))throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');
        desktop_out(['ok'=>true,'binding'=>$hardware->heartbeat((int)($body['unit_id']??0),$reported,(string)($body['device_label']??''),is_array($body['hardware']??null)?$body['hardware']:[])]);
    }
    if($action==='hardware-list')desktop_out(['ok'=>true,'devices'=>$hardware->listBindings()]);
    if($action==='hardware-revoke'){
        desktop_method('POST');$body=desktop_body();$hardware->revokeBinding((int)($body['id']??0));desktop_out(['ok'=>true]);
    }

    desktop_out(['ok'=>false,'error'=>'Endpoint do Desktop não encontrado.'],404);
}catch(RuntimeException $e){desktop_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))desktop_out(['ok'=>false,'error'=>$e->getMessage()],500);desktop_out(['ok'=>false,'error'=>'Erro interno.'],500);}
