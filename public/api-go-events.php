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
function goe_column_exists(PDO $pdo,string $table,string $column):bool{try{if(Database::isSqlite($pdo)){$q=$pdo->query('PRAGMA table_info("'.str_replace('"','""',$table).'")');foreach($q->fetchAll(PDO::FETCH_ASSOC) as$row)if((string)($row['name']??'')===$column)return true;return false;}$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);return(int)$q->fetchColumn()>0;}catch(Throwable){return false;}}
function goe_table_exists(PDO $pdo,string $table):bool{try{if(Database::isSqlite($pdo)){$q=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(int)$q->fetchColumn()>0;}$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);return(int)$q->fetchColumn()>0;}catch(Throwable){return false;}}
function goe_ticket_catalog(PDO $pdo,int $eventId):array{
    if(!goe_table_exists($pdo,'ticket_batches'))throw new RuntimeException('Bilheteria ainda não preparada no servidor: tabela de lotes ausente.');
    $required=['id','event_id','name'];foreach($required as$c)if(!goe_column_exists($pdo,'ticket_batches',$c))throw new RuntimeException('Bilheteria precisa de atualização no servidor: campo ticket_batches.'.$c.' ausente.');
    $hasPrice=goe_column_exists($pdo,'ticket_batches','price_cents');$hasTotal=goe_column_exists($pdo,'ticket_batches','quantity_total');$hasSold=goe_column_exists($pdo,'ticket_batches','quantity_sold');$hasReserved=goe_column_exists($pdo,'ticket_batches','quantity_reserved');$hasActive=goe_column_exists($pdo,'ticket_batches','active');$hasTypeId=goe_column_exists($pdo,'ticket_batches','ticket_type_id');$hasTypes=goe_table_exists($pdo,'ticket_types');
    $cols=['b.id','b.name',$hasPrice?'b.price_cents':'0 AS price_cents',$hasTotal?'b.quantity_total':'0 AS quantity_total',$hasSold?'b.quantity_sold':'0 AS quantity_sold',$hasReserved?'b.quantity_reserved':'0 AS quantity_reserved'];
    $join='';if($hasTypeId&&$hasTypes&&goe_column_exists($pdo,'ticket_types','name')){$cols[]='tt.name AS ticket_type_name';$join=' LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id';}else{$cols[]="'' AS ticket_type_name";}
    $where=' WHERE b.event_id=?'.($hasActive?' AND b.active=1':'');$sql='SELECT '.implode(',',$cols).' FROM ticket_batches b'.$join.$where.' ORDER BY b.id';
    try{$s=$pdo->prepare($sql);$s->execute([$eventId]);}catch(Throwable $e){error_log('[EventMenu ticket-sale-catalog] '.get_class($e).': '.$e->getMessage());throw new RuntimeException('Falha ao carregar os lotes do evento. O servidor registrou o diagnóstico.');}
    $out=[];foreach($s->fetchAll(PDO::FETCH_ASSOC) as$b){$out[]=['id'=>(int)$b['id'],'name'=>(string)$b['name'],'ticket_type_name'=>(string)($b['ticket_type_name']??''),'price_cents'=>(int)($b['price_cents']??0),'available'=>max(0,(int)($b['quantity_total']??0)-(int)($b['quantity_sold']??0)-(int)($b['quantity_reserved']??0))];}return$out;
}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')goe_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$auth->authenticate($token,$deviceId);
    $shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='events')throw new RuntimeException('Inicie um turno no modo Eventos.');$action=(string)($_GET['action']??'overview');$service=new EventOperationsService();$rate=new ApiRateLimitService();$subject=$deviceId!==''?$deviceId:$rate->requestSubject();
    if($action==='overview')goe_out(['ok'=>true,'events'=>$service->overview()]);
    if($action==='recent'){$eventId=(int)($_GET['event_id']??0);goe_out(['ok'=>true]+$service->recentEntries($eventId));}
    if($action==='ticket-sale-catalog'){
        Auth::requirePermission('tickets.manage');$eventId=(int)($_GET['event_id']??0);$tenantId=Auth::tenantId();if(!$tenantId||$eventId<1)throw new RuntimeException('Selecione um evento válido.');$pdo=Database::pdo();
        if(!goe_table_exists($pdo,'events'))throw new RuntimeException('Estrutura de eventos ausente no servidor.');
        foreach(['id','name','status','starts_at','tenant_id'] as$c)if(!goe_column_exists($pdo,'events',$c))throw new RuntimeException('Bilheteria precisa de atualização no servidor: campo events.'.$c.' ausente.');
        $e=$pdo->prepare('SELECT id,name,status,starts_at FROM events WHERE id=? AND tenant_id=?');$e->execute([$eventId,$tenantId]);$event=$e->fetch(PDO::FETCH_ASSOC);if(!$event||!in_array((string)$event['status'],['published','draft'],true))throw new RuntimeException('Evento indisponível para venda.');
        $batches=goe_ticket_catalog($pdo,$eventId);goe_out(['ok'=>true,'event'=>['id'=>(int)$event['id'],'name'=>(string)$event['name'],'starts_at'=>(string)$event['starts_at']],'batches'=>$batches,'can_courtesy'=>Auth::can('events.manage')]);
    }
    if($action==='ticket-sale'){goe_method('POST');$rate->assertAllowed('event.ticket.sale',$subject,60,60,'Muitas vendas em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$sale=(new TicketCounterSaleService())->sell((int)($body['event_id']??0),(int)($body['batch_id']??0),(int)($body['quantity']??1),(string)($body['payment_method']??''),['name'=>$body['buyer_name']??'','email'=>$body['buyer_email']??'','phone'=>$body['buyer_phone']??'']);goe_out(['ok'=>true,'sale'=>$sale]);}
    if($action==='bar-order-resolve'){$rate->assertAllowed('event.order.resolve',$subject,180,60,'Muitas leituras de pedido em pouco tempo. Aguarde alguns segundos.');$eventId=(int)($_GET['event_id']??0);$value=(string)($_GET['value']??'');goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->resolve($eventId,$value)]);}
    if($action==='bar-order-deliver'){goe_method('POST');$rate->assertAllowed('event.order.deliver',$subject,90,60,'Muitas confirmações de retirada em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);$value=(string)($body['value']??'');goe_out(['ok'=>true,'order'=>(new EventOrderPickupService())->deliver($eventId,$value)]);}
    if($action==='ticket-checkin'){goe_method('POST');$rate->assertAllowed('event.ticket.checkin',$subject,240,60,'Muitas leituras de ingresso em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);if($eventId<1)throw new RuntimeException('Selecione o evento antes de validar o ingresso.');$result=(new TicketService())->checkIn((string)($body['token']??''),$eventId);goe_out(['ok'=>true,'result'=>$result]);}
    if($action==='guest-checkin'){goe_method('POST');$rate->assertAllowed('event.guest.checkin',$subject,240,60,'Muitas leituras de convidado em pouco tempo. Aguarde alguns segundos.');$body=goe_body();$eventId=(int)($body['event_id']??0);if($eventId<1)throw new RuntimeException('Selecione o evento antes de validar o convidado.');$guest=(new GuestService())->checkIn((string)($body['code']??''),$eventId);goe_out(['ok'=>true,'guest'=>$guest]);}
    goe_out(['ok'=>false,'error'=>'Endpoint de evento não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){goe_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){error_log('[EventMenu api-go-events] '.get_class($e).': '.$e->getMessage());if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goe_out(['ok'=>false,'error'=>$e->getMessage()],500);goe_out(['ok'=>false,'error'=>'Erro interno. Diagnóstico registrado no servidor.'],500);}
