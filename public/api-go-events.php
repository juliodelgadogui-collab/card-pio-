<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\EventOperationsService;
use EventMenu\Services\EventOrderPickupService;
use EventMenu\Services\GuestService;
use EventMenu\Services\TicketCounterSaleService;
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
    if($action==='ticket-sale-catalog'){
        Auth::requirePermission('tickets.manage');$eventId=(int)($_GET['event_id']??0);$tenantId=Auth::tenantId();if(!$tenantId||$eventId<1)throw new RuntimeException('Selecione um evento válido.');
        $pdo=Database::pdo();$e=$pdo->prepare('SELECT id,name,status,starts_at,capacity_total FROM events WHERE id=? AND tenant_id=?');$e->execute([$eventId,$tenantId]);$event=$e->fetch();if(!$event||!in_array((string)$event['status'],['published','draft'],true))throw new RuntimeException('Evento indisponível para venda.');
        $s=$pdo->prepare('SELECT b.id,b.name,b.price_cents,b.quantity_total,b.quantity_sold,b.quantity_reserved,tt.name ticket_type_name FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.event_id=? AND b.active=1 ORDER BY b.id');$s->execute([$eventId]);$batches=[];foreach($s->fetchAll() as$b){$batches[]=['id'=>(int)$b['id'],'name'=>(string)$b['name'],'ticket_type_name'=>(string)($b['ticket_type_name']??''),'price_cents'=>(int)$b['price_cents'],'available'=>max(0,(int)$b['quantity_total']-(int)$b['quantity_sold']-(int)$b['quantity_reserved'])];}
        goe_out(['ok'=>true,'event'=>['id'=>(int)$event['id'],'name'=>(string)$event['name'],'starts_at'=>(string)$event['starts_at']],'batches'=>$batches,'can_courtesy'=>Auth::can('events.manage')]);
    }
    if($action==='ticket-sale'){
        goe_method('POST');$rate->assertAllowed('event.ticket.sale',$subject,60,60,'Muitas vendas em pouco tempo. Aguarde alguns segundos.');$body=goe_body();
        $sale=(new TicketCounterSaleService())->sell((int)($body['event_id']??0),(int)($body['batch_id']??0),(int)($body['quantity']??1),(string)($body['payment_method']??''),['name'=>$body['buyer_name']??'','email'=>$body['buyer_email']??'','phone'=>$body['buyer_phone']??'']);
        goe_out(['ok'=>true,'sale'=>$sale]);
    }
    if($action==='bar-order-resolve'){
        $rate->assertAllowed('event.order.resolve',$subject,180,60,'Muitas leituras de pedido em pouco tempo. Aguarde alguns segundos.');$eventId=(int)($_GET['event_id']??0);$value=(string)($_GET['value']??'');
        goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->resolve($eventId,$value)]);
    }
    if($action==='bar-order-deliver'){
        goe_method('POST');$rate->assertAllowed('event.order.deliver',$subject,90,60,'Muitas confirmações de retirada em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);$value=(string)($body['value']??'');
        goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->deliver($eventId,$value)]);
    }
    if($action==='ticket-checkin'){
        goe_method('POST');$rate->assertAllowed('event.ticket.checkin',$subject,240,60,'Muitas leituras de ingresso em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);if($eventId<1)throw new RuntimeException('Selecione o evento antes de validar o ingresso.');$result=(new TicketService())->checkIn((string)($body['token']??''),$eventId);goe_out(['ok'=>true,'result'=>$result]);
    }
    if($action==='guest-checkin'){
        goe_method('POST');$rate->assertAllowed('event.guest.checkin',$subject,240,60,'Muitas leituras de convidado em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);if($eventId<1)throw new RuntimeException('Selecione o evento antes de validar o convidado.');$guest=(new GuestService())->checkIn((string)($body['code']??''),$eventId);goe_out(['ok'=>true,'guest'=>$guest]);
    }

    goe_out(['ok'=>false,'error'=>'Endpoint de evento não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goe_out(['ok'=>false,'error'=>$e->getMessage()],500);goe_out(['ok'=>false,'error'=>'Erro interno.'],500);}