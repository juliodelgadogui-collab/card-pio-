<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\EventOperationsService;
use EventMenu\Services\EventOrderPickupService;
use EventMenu\Services\GuestService;
use EventMenu\Services\TicketService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function goe_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function goe_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){goe_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function goe_method(string $method):void{if($_SERVER['REQUEST_METHOD']!==$method)goe_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')goe_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='events')throw new RuntimeException('Inicie um turno no modo Eventos.');
    $action=(string)($_GET['action']??'overview');$service=new EventOperationsService();$rate=new ApiRateLimitService();$subject=$deviceId!==''?$deviceId:$rate->requestSubject();

    if($action==='overview')goe_out(['ok'=>true,'events'=>$service->overview()]);
    if($action==='recent'){$eventId=(int)($_GET['event_id']??0);goe_out(['ok'=>true]+$service->recentEntries($eventId));}
    if($action==='bar-order-resolve'){
        $rate->assertAllowed('event.order.resolve',$subject,180,60,'Muitas leituras de pedido em pouco tempo. Aguarde alguns segundos.');$eventId=(int)($_GET['event_id']??0);$value=(string)($_GET['value']??'');
        goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->resolve($eventId,$value)]);
    }
    if($action==='bar-order-deliver'){
        goe_method('POST');$rate->assertAllowed('event.order.deliver',$subject,90,60,'Muitas confirmações de retirada em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);$value=(string)($body['value']??'');
        goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->deliver($eventId,$value)]);
    }
    if($action==='ticket-checkin'){goe_method('POST');$rate->assertAllowed('event.ticket.checkin',$subject,240,60,'Muitas leituras de ingresso em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$result=(new TicketService())->checkIn((string)($body['token']??''));goe_out(['ok'=>true,'result'=>$result]);}
    if($action==='guest-checkin'){goe_method('POST');$rate->assertAllowed('event.guest.checkin',$subject,240,60,'Muitas leituras de convidado em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$guest=(new GuestService())->checkIn((string)($body['code']??''));goe_out(['ok'=>true,'guest'=>$guest]);}

    goe_out(['ok'=>false,'error'=>'Endpoint de evento não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goe_out(['ok'=>false,'error'=>$e->getMessage()],500);goe_out(['ok'=>false,'error'=>'Erro interno.'],500);}
