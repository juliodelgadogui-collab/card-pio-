<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\CashService;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\GuestService;
use EventMenu\Services\NfcService;
use EventMenu\Services\OrderCreationService;
use EventMenu\Services\OrderService;
use EventMenu\Services\TicketService;

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}

function api_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function api_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){api_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function api_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)api_out(['ok'=>false,'error'=>'Método não permitido.'],405);}
function api_order_allowed(array $order):bool{$role=Auth::role();$uid=Auth::id();if($role==='delivery')return (int)($order['assigned_delivery_user_id']??0)===(int)$uid;if($role==='kitchen')return in_array($order['status'],['confirmed','preparing','ready'],true);return Auth::can('orders.view')||Auth::can('orders.create');}
function api_scanned_token(string $value):string{$value=trim($value);if(filter_var($value,FILTER_VALIDATE_URL)){$parts=parse_url($value);if(isset($parts['query'])){parse_str($parts['query'],$q);foreach(['t','token','code'] as $k)if(!empty($q[$k]))return trim((string)$q[$k]);}}return $value;}

$action=(string)($_GET['action']??'me');$auth=new ApiAuthService();
try{
    if($action==='login'){
        api_method('POST');$body=api_body();$result=$auth->login((string)($body['email']??''),(string)($body['password']??''),(string)($body['device_id']??''),(string)($body['device_label']??''));api_out(['ok'=>true]+$result);
    }

    $token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')api_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$user=$auth->authenticate($token,$deviceId);$pdo=Database::connection();$tenantId=(int)$user['tenant_id'];

    if($action==='logout'){api_method('POST');$auth->revoke($token);api_out(['ok'=>true]);}
    if($action==='me'){
        api_out(['ok'=>true,'user'=>$user,'permissions'=>['orders_create'=>Auth::can('orders.create'),'orders_manage'=>Auth::can('orders.manage'),'orders_kitchen'=>Auth::can('orders.kitchen'),'orders_delivery'=>Auth::can('orders.delivery'),'delivery_assign'=>Auth::can('delivery.assign'),'cash'=>Auth::can('cash.manage'),'nfc_collect'=>Auth::can('nfc.collect'),'tickets'=>Auth::can('tickets.manage'),'guests'=>Auth::can('guests.manage'),'tables'=>Auth::can('tables.manage')]]);
    }
    if($action==='products'){
        if(!Auth::can('orders.create')&&!Auth::can('catalog.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$s=$pdo->prepare('SELECT id,category_id,name,description,sku,price_cents,stock_qty,track_stock,image_url FROM products WHERE tenant_id=? AND active=1 ORDER BY name');$s->execute([$tenantId]);api_out(['ok'=>true,'products'=>$s->fetchAll()]);
    }
    if($action==='order-create'){
        api_method('POST');if(!Auth::can('orders.create'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$order=(new OrderCreationService())->create(api_body());api_out(['ok'=>true,'order'=>$order],201);
    }
    if($action==='orders'){
        $role=Auth::role();$sql='SELECT o.id,o.public_token,o.channel,o.status,o.payment_status,o.total_cents,o.delivery_address,o.assigned_delivery_user_id,o.table_id,o.tab_id,o.created_at,c.name customer_name,c.phone customer_phone,u.name delivery_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id WHERE o.tenant_id=?';$args=[$tenantId];
        if($role==='delivery'){$sql.=' AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery")';$args[]=Auth::id();}
        elseif($role==='kitchen'){$sql.=' AND o.status IN ("confirmed","preparing")';}
        elseif(!Auth::can('orders.view')&&!Auth::can('orders.create'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);
        $sql.=' ORDER BY o.id DESC LIMIT 200';$s=$pdo->prepare($sql);$s->execute($args);api_out(['ok'=>true,'orders'=>$s->fetchAll()]);
    }
    if($action==='order'){
        $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT o.*,c.name customer_name,c.phone customer_phone,u.name delivery_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id WHERE o.id=? AND o.tenant_id=?');$s->execute([$id,$tenantId]);$order=$s->fetch();if(!$order)api_out(['ok'=>false,'error'=>'Pedido não encontrado.'],404);if(!api_order_allowed($order))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$i=$pdo->prepare('SELECT id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$i->execute([$id]);api_out(['ok'=>true,'order'=>$order,'items'=>$i->fetchAll()]);
    }
    if($action==='order-status'){
        api_method('POST');$body=api_body();$id=(int)($body['order_id']??0);$status=(string)($body['status']??'');$s=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=?');$s->execute([$id,$tenantId]);$order=$s->fetch();if(!$order)api_out(['ok'=>false,'error'=>'Pedido não encontrado.'],404);if(!api_order_allowed($order))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);
        $role=Auth::role();if($role==='delivery')$source='delivery';elseif($role==='kitchen')$source='kitchen';else{if(!Auth::can('orders.manage'))api_out(['ok'=>false,'error'=>'Sua função pode visualizar/criar pedidos, mas não alterar o status operacional.'],403);$source='panel';}
        $changed=(new OrderService())->changeStatus($id,$status,$source);api_out(['ok'=>true,'order'=>$changed]);
    }
    if($action==='delivery-assign'){
        api_method('POST');if(!Auth::can('delivery.assign'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$orderId=(int)($body['order_id']??0);$deliveryId=(int)($body['delivery_user_id']??0);
        Database::transaction(function(PDO $tx)use($tenantId,$orderId,$deliveryId):void{$o=$tx->prepare(Database::portableSql($tx,'SELECT id,channel,status FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if($order['channel']!=='delivery')throw new RuntimeException('Somente pedidos de delivery podem ser atribuídos.');if(in_array($order['status'],['completed','cancelled'],true))throw new RuntimeException('Pedido já encerrado.');if($deliveryId){$d=$tx->prepare('SELECT id FROM users WHERE id=? AND tenant_id=? AND role="delivery" AND status="active"');$d->execute([$deliveryId,$tenantId]);if(!$d->fetchColumn())throw new RuntimeException('Entregador inválido.');}$tx->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryId?:null,$orderId,$tenantId]);});Auth::audit('order.delivery_assigned','order',(string)$orderId,['delivery_user_id'=>$deliveryId?:null,'source'=>'api']);api_out(['ok'=>true]);
    }
    if($action==='delivery-users'){
        if(!Auth::can('delivery.assign'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$s=$pdo->prepare('SELECT id,name,phone FROM users WHERE tenant_id=? AND role="delivery" AND status="active" ORDER BY name');$s->execute([$tenantId]);api_out(['ok'=>true,'delivery_users'=>$s->fetchAll()]);
    }
    if($action==='pix-checkout'){
        api_method('POST');$body=api_body();$orderId=(int)($body['order_id']??0);$s=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=?');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)api_out(['ok'=>false,'error'=>'Pedido não encontrado.'],404);if(!api_order_allowed($order))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);if(Auth::role()!=='delivery'&&!Auth::can('payments.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);if(Auth::role()==='delivery'&&(int)($order['assigned_delivery_user_id']??0)!==(int)Auth::id())api_out(['ok'=>false,'error'=>'Pedido não atribuído a este entregador.'],403);$checkout=(new CheckoutService())->create((string)$order['public_token'],'pagbank');api_out(['ok'=>true,'checkout'=>$checkout]);
    }
    if($action==='nfc-intent'){
        api_method('POST');if(!Auth::can('nfc.collect'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$result=(new NfcService())->createIntent((int)($body['order_id']??0),$deviceId);api_out(['ok'=>true]+$result,201);
    }
    if($action==='nfc-verify'){
        api_method('POST');if(!Auth::can('nfc.collect'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$result=(new NfcService())->verifyIntent((string)($body['intent_token']??''),(string)($body['transaction_code']??''),$deviceId);api_out($result);
    }
    if($action==='cash-current'){
        if(!Auth::can('cash.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$service=new CashService();api_out(['ok'=>true,'session'=>$service->currentSession()]);
    }
    if($action==='cash-open'){
        api_method('POST');if(!Auth::can('cash.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$session=(new CashService())->open(max(0,(int)($body['opening_cash_cents']??0)),(string)($body['notes']??''));api_out(['ok'=>true,'session'=>$session],201);
    }
    if($action==='cash-movement'){
        api_method('POST');if(!Auth::can('cash.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$id=(new CashService())->addManualMovement((string)($body['type']??''),(int)($body['amount_cents']??0),(string)($body['notes']??''),(string)($body['direction']??'in'));api_out(['ok'=>true,'movement_id'=>$id],201);
    }
    if($action==='cash-close'){
        api_method('POST');if(!Auth::can('cash.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$session=(new CashService())->close(max(0,(int)($body['counted_cash_cents']??0)),(string)($body['notes']??''));api_out(['ok'=>true,'session'=>$session]);
    }
    if($action==='cash-summary'){
        if(!Auth::can('cash.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$id=(int)($_GET['session_id']??0);api_out(['ok'=>true,'summary'=>(new CashService())->summary($id?:null)]);
    }
    if($action==='qr-resolve'){
        $raw=(string)($_GET['value']??'');$value=api_scanned_token($raw);if($value==='')api_out(['ok'=>false,'error'=>'Código vazio.'],400);
        if(Auth::can('tables.manage')||Auth::can('orders.create')){$s=$pdo->prepare('SELECT rt.id,rt.name,rt.seats,rt.status,rt.qr_token,t.id tab_id,t.label tab_label FROM restaurant_tables rt LEFT JOIN tabs t ON t.table_id=rt.id AND t.status="open" WHERE rt.tenant_id=? AND rt.qr_token=? LIMIT 1');$s->execute([$tenantId,$value]);if($row=$s->fetch())api_out(['ok'=>true,'type'=>'table','data'=>$row]);}
        if(Auth::can('tickets.manage')){$s=$pdo->prepare('SELECT t.id,t.code,t.status,t.event_id,e.name event_name,e.status event_status,c.name customer_name FROM tickets t JOIN events e ON e.id=t.event_id LEFT JOIN customers c ON c.id=t.customer_id WHERE t.tenant_id=? AND (t.qr_token=? OR t.code=?) LIMIT 1');$s->execute([$tenantId,$value,$value]);if($row=$s->fetch())api_out(['ok'=>true,'type'=>'ticket','data'=>$row]);}
        if(Auth::can('guests.manage')){$s=$pdo->prepare('SELECT g.id,g.name,g.status,g.plus_ones,g.event_id,e.name event_name,e.status event_status,g.checkin_code FROM event_guests g JOIN events e ON e.id=g.event_id WHERE g.tenant_id=? AND g.checkin_code=? LIMIT 1');$s->execute([$tenantId,$value]);if($row=$s->fetch())api_out(['ok'=>true,'type'=>'guest','data'=>$row]);}
        api_out(['ok'=>false,'error'=>'QR/código não reconhecido para sua função.'],404);
    }
    if($action==='ticket-checkin'){
        api_method('POST');if(!Auth::can('tickets.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$result=(new TicketService())->checkIn((string)($body['token']??''));api_out(['ok'=>true,'result'=>$result]);
    }
    if($action==='guest-checkin'){
        api_method('POST');if(!Auth::can('guests.manage'))api_out(['ok'=>false,'error'=>'Acesso negado.'],403);$body=api_body();$guest=(new GuestService())->checkIn((string)($body['code']??''));api_out(['ok'=>true,'guest'=>$guest]);
    }
    api_out(['ok'=>false,'error'=>'Endpoint não encontrado.'],404);
}catch(RuntimeException $e){api_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))api_out(['ok'=>false,'error'=>$e->getMessage()],500);api_out(['ok'=>false,'error'=>'Erro interno.'],500);}
