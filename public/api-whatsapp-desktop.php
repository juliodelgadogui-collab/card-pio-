<?php
declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\TicketWhatsAppQueueService;
use EventMenu\Services\WhatsAppCommercePaymentService;
use EventMenu\Services\WhatsAppCommerceService;
use EventMenu\Services\WhatsAppCommerceStatusSyncService;
use EventMenu\Services\WhatsAppDesktopAgentService;
use EventMenu\Services\WhatsAppOrderReceivedSyncService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function whatsapp_desktop_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function whatsapp_desktop_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){whatsapp_desktop_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function whatsapp_desktop_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)whatsapp_desktop_out(['ok'=>false,'error'=>'Método não permitido.'],405);}
function whatsapp_desktop_device(string $sessionDevice,array $body):string{$reported=mb_substr(trim((string)($body['device_id']??$sessionDevice)),0,190);if($sessionDevice!==''&&$reported!==''&&!hash_equals($sessionDevice,$reported))throw new RuntimeException('Identificação do dispositivo não confere com a sessão.');if($reported==='')throw new RuntimeException('Dispositivo não identificado.');return$reported;}
function whatsapp_desktop_sync_automations():array{
    $result=['ticket_messages_queued'=>0,'ticket_sync_ok'=>true,'order_received_messages_queued'=>0,'order_received_sync_ok'=>true,'commerce_payment_messages_queued'=>0,'commerce_payment_sync_ok'=>true,'commerce_status_messages_queued'=>0,'commerce_status_sync_ok'=>true];
    try{$result['ticket_messages_queued']=(new TicketWhatsAppQueueService())->syncCurrentTenant(50);}catch(Throwable $e){$result['ticket_sync_ok']=false;if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))$result['ticket_sync_error']=$e->getMessage();}
    try{$result['order_received_messages_queued']=(new WhatsAppOrderReceivedSyncService())->syncCurrentTenant(50);}catch(Throwable $e){$result['order_received_sync_ok']=false;if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))$result['order_received_sync_error']=$e->getMessage();}
    try{$result['commerce_status_messages_queued']=(new WhatsAppCommerceStatusSyncService())->syncCurrentTenant(null,null,100);}catch(Throwable $e){$result['commerce_status_sync_ok']=false;if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))$result['commerce_status_sync_error']=$e->getMessage();}
    try{$result['commerce_payment_messages_queued']=(new WhatsAppCommercePaymentService())->syncPaidConversations(null,null,50);}catch(Throwable $e){$result['commerce_payment_sync_ok']=false;if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))$result['commerce_payment_sync_error']=$e->getMessage();}
    return$result;
}

try{
    $auth=new ApiAuthService();
    $token=ApiAuthService::bearerToken();
    $deviceId=ApiAuthService::deviceId();
    if($token==='')whatsapp_desktop_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    try{$auth->authenticate($token,$deviceId);}catch(RuntimeException $e){whatsapp_desktop_out(['ok'=>false,'error'=>$e->getMessage()],401);}
    if($deviceId==='')throw new RuntimeException('Dispositivo não identificado.');

    $service=new WhatsAppDesktopAgentService();
    $action=(string)($_GET['action']??'state');

    if($action==='state'){
        whatsapp_desktop_method('GET');
        whatsapp_desktop_out(['ok'=>true]+whatsapp_desktop_sync_automations()+$service->state($deviceId));
    }

    if($action==='heartbeat'){
        whatsapp_desktop_method('POST');
        $body=whatsapp_desktop_body();$reported=whatsapp_desktop_device($deviceId,$body);
        $sync=whatsapp_desktop_sync_automations();
        $state=$service->heartbeat($reported,(string)($body['device_label']??''),(string)($body['status']??'disconnected'),(string)($body['phone']??''),(string)($body['error']??''));
        whatsapp_desktop_out(['ok'=>true]+$sync+$state);
    }

    if($action==='inbound'){
        whatsapp_desktop_method('POST');
        $body=whatsapp_desktop_body();$reported=whatsapp_desktop_device($deviceId,$body);
        (new ApiRateLimitService())->assertAllowed('whatsapp.inbound','device:'.hash('sha256',$reported),600,60,'Muitas mensagens recebidas em pouco tempo.');
        $message=(new WhatsAppCommerceService())->receiveInbound($reported,$body);
        whatsapp_desktop_out(['ok'=>true,'message'=>$message]);
    }

    if($action==='claim'){
        whatsapp_desktop_method('POST');
        $body=whatsapp_desktop_body();$reported=whatsapp_desktop_device($deviceId,$body);
        $messages=$service->claim($reported,(int)($body['limit']??5));
        whatsapp_desktop_out(['ok'=>true,'messages'=>$messages]);
    }

    if($action==='ack'){
        whatsapp_desktop_method('POST');
        $body=whatsapp_desktop_body();$reported=whatsapp_desktop_device($deviceId,$body);
        $message=$service->acknowledge($reported,(int)($body['id']??0),(string)($body['claim_token']??''),(string)($body['external_message_id']??''));
        whatsapp_desktop_out(['ok'=>true,'message'=>$message]);
    }

    if($action==='fail'){
        whatsapp_desktop_method('POST');
        $body=whatsapp_desktop_body();$reported=whatsapp_desktop_device($deviceId,$body);
        $message=$service->fail($reported,(int)($body['id']??0),(string)($body['claim_token']??''),(string)($body['error']??''));
        whatsapp_desktop_out(['ok'=>true,'message'=>$message]);
    }

    whatsapp_desktop_out(['ok'=>false,'error'=>'Endpoint do WhatsApp Desktop não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){whatsapp_desktop_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){whatsapp_desktop_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))whatsapp_desktop_out(['ok'=>false,'error'=>$e->getMessage()],500);whatsapp_desktop_out(['ok'=>false,'error'=>'Erro interno.'],500);}