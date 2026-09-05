<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\DeliveryCashService;
use EventMenu\Services\NativePixService;
use EventMenu\Services\NfcService;
use EventMenu\Services\OrderService;
use EventMenu\Services\PosPaymentService;
use EventMenu\Services\TableService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function go_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function go_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){go_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function go_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)go_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')go_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);$tenantId=(int)$user['tenant_id'];$userId=(int)$user['id'];$action=(string)($_GET['action']??'context');$shift=new WorkShiftService();
    if($action==='context'){$permissions=Auth::effectivePermissions();go_out(['ok'=>true,'user'=>$user,'permissions'=>PermissionCatalog::appPermissionMap($permissions),'permission_names'=>$permissions,'modes'=>PermissionCatalog::modesForPermissions($permissions),'shift'=>$shift->current()]);}
    if($action==='shift-current')go_out(['ok'=>true,'shift'=>$shift->current()]);
    if($action==='shift-open'){go_method('POST');$body=go_body();go_out(['ok'=>true,'shift'=>$shift->open((string)($body['mode']??''),$deviceId,(string)($body['notes']??''))],201);}
    if($action==='shift-close'){go_method('POST');$body=go_body();go_out(['ok'=>true,'shift'=>$shift->close((string)($body['notes']??''))]);}
    if($action==='shift-summary'){$id=(int)($_GET['shift_id']??0);go_out(['ok'=>true,'summary'=>$shift->summary($id?:null)]);}

    if($action==='orders'){
        $current=$shift->current();if(!$current)throw new RuntimeException('Inicie seu turno antes de consultar a operação.');$mode=(string)$current['mode'];
        $sql='SELECT o.id,o.public_token,o.channel,o.status,o.payment_status,o.total_cents,o.delivery_address,o.assigned_delivery_user_id,o.table_id,o.tab_id,o.created_at,c.name customer_name,c.phone customer_phone,u.name delivery_name,rt.name table_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id WHERE o.tenant_id=?';$args=[$tenantId];
        if($mode==='delivery'){
            if(!Auth::can('orders.delivery'))throw new RuntimeException('Sua conta não possui operação de Delivery.');$sql.=' AND o.channel="delivery" AND o.assigned_delivery_user_id=? AND o.status IN ("ready","out_for_delivery","completed")';$args[]=$userId;
        }elseif($mode==='operation'){
            if(!Auth::can('orders.view')&&!Auth::can('orders.create')&&!Auth::can('orders.kitchen')&&!Auth::can('orders.dispatch'))throw new RuntimeException('Sua conta não possui acesso aos pedidos operacionais.');
            if(Auth::can('orders.kitchen')&&!Auth::can('orders.view')&&!Auth::can('orders.create')&&!Auth::can('orders.dispatch'))$sql.=' AND o.channel IN ("counter","pickup","table","delivery") AND o.status IN ("confirmed","preparing")';
        }elseif($mode==='pay'){
            if(!Auth::can('payments.manage')&&!Auth::can('cash.manage'))throw new RuntimeException('Sua conta não possui acesso ao modo Pay.');$sql.=' AND o.status<>"cancelled"';
        }else{
            if(!Auth::can('orders.view')&&!Auth::can('orders.create'))go_out(['ok'=>true,'orders'=>[]]);
        }
        $sql.=' ORDER BY o.id DESC LIMIT 200';$s=Database::connection()->prepare($sql);$s->execute($args);go_out(['ok'=>true,'orders'=>$s->fetchAll()]);
    }

    if($action==='order-qr-resolve'){
        if(!Auth::can('orders.dispatch')&&!Auth::can('delivery.assign')&&!Auth::can('orders.view'))throw new RuntimeException('Acesso negado ao QR de pedido.');
        $current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Use o QR de pedido durante um turno de Operação.');
        $value=trim((string)($_GET['value']??''));if($value==='')throw new RuntimeException('QR de pedido vazio.');
        if(preg_match('/[A-Fa-f0-9]{40}/',$value,$m))$value=$m[0];
        $s=Database::connection()->prepare('SELECT o.id,o.public_token,o.channel,o.status,o.payment_status,o.total_cents,o.delivery_address,o.assigned_delivery_user_id,o.created_at,c.name customer_name,c.phone customer_phone,u.name delivery_name,rt.name table_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id WHERE o.tenant_id=? AND o.public_token=? LIMIT 1');
        $s->execute([$tenantId,$value]);$order=$s->fetch();if(!$order)go_out(['ok'=>false,'error'=>'Pedido não encontrado para este QR.'],404);
        go_out(['ok'=>true,'order'=>$order]);
    }

    if($action==='order-status'){
        go_method('POST');$body=go_body();$orderId=(int)($body['order_id']??0);$target=(string)($body['status']??'');$current=$shift->current();if(!$current)throw new RuntimeException('Inicie seu turno antes de alterar pedidos.');
        if($current['mode']==='delivery'){
            if(!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado.');$q=Database::connection()->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND channel="delivery" AND assigned_delivery_user_id=?');$q->execute([$orderId,$tenantId,$userId]);if(!$q->fetchColumn())throw new RuntimeException('Pedido não está atribuído a este funcionário.');$source='delivery';
        }elseif($current['mode']==='operation'&&Auth::can('orders.kitchen')&&in_array($target,['preparing','ready'],true)){
            $source='kitchen';
        }elseif($current['mode']==='operation'&&Auth::can('orders.dispatch')&&in_array($target,['served','completed'],true)){
            $source='dispatch';
        }else{
            if(!Auth::can('orders.manage'))throw new RuntimeException('Sua função não pode alterar este status.');$source='panel';
        }
        go_out(['ok'=>true,'order'=>(new OrderService())->changeStatus($orderId,$target,$source)]);
    }

    if($action==='delivery-users'){
        if(!Auth::can('delivery.assign'))throw new RuntimeException('Acesso negado.');$current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Atribuição de entrega é feita no turno de Operação.');
        $s=Database::connection()->prepare('SELECT u.id,u.name,u.email,CASE WHEN ws.id IS NULL THEN 0 ELSE 1 END on_shift,ws.started_at FROM users u LEFT JOIN work_shifts ws ON ws.user_id=u.id AND ws.tenant_id=u.tenant_id AND ws.mode="delivery" AND ws.status="open" WHERE u.tenant_id=? AND u.role="delivery" AND u.status="active" ORDER BY on_shift DESC,u.name');$s->execute([$tenantId]);go_out(['ok'=>true,'delivery_users'=>$s->fetchAll()]);
    }
    if($action==='delivery-assign'){
        go_method('POST');if(!Auth::can('delivery.assign'))throw new RuntimeException('Acesso negado.');$current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Atribuição de entrega é feita no turno de Operação.');$body=go_body();$orderId=(int)($body['order_id']??0);$deliveryId=(int)($body['delivery_user_id']??0);
        Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$deliveryId):void{
            $o=$pdo->prepare(Database::portableSql($pdo,'SELECT id,channel,status,assigned_delivery_user_id FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['channel']!=='delivery')throw new RuntimeException('Somente pedido de Delivery pode ser atribuído.');if($order['status']!=='ready')throw new RuntimeException('O pedido precisa estar pronto para ser atribuído.');if($deliveryId<1)throw new RuntimeException('Escolha um entregador.');
            $d=$pdo->prepare('SELECT u.id FROM users u JOIN work_shifts ws ON ws.user_id=u.id AND ws.tenant_id=u.tenant_id AND ws.mode="delivery" AND ws.status="open" WHERE u.id=? AND u.tenant_id=? AND u.role="delivery" AND u.status="active" LIMIT 1');$d->execute([$deliveryId,$tenantId]);if(!$d->fetchColumn())throw new RuntimeException('Entregador sem turno de Delivery aberto.');
            $pdo->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryId,$orderId,$tenantId]);
        });
        Auth::audit('order.delivery_assigned','order',(string)$orderId,['delivery_user_id'=>$deliveryId,'source'=>'eventmenu_go']);go_out(['ok'=>true]);
    }

    if($action==='kitchen-board'){
        if(!Auth::can('orders.kitchen'))throw new RuntimeException('Acesso negado à cozinha.');$current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Inicie um turno de Operação para usar a cozinha.');$pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.id,o.channel,o.status,o.notes,o.created_at,rt.name table_name,c.name customer_name FROM orders o LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.channel IN ("counter","pickup","table","delivery") AND o.status IN ("confirmed","preparing") ORDER BY CASE WHEN o.status="preparing" THEN 0 ELSE 1 END,o.id');$s->execute([$tenantId]);$orders=$s->fetchAll();
        if($orders){$ids=array_column($orders,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$i=$pdo->prepare('SELECT order_id,name_snapshot,quantity,notes FROM order_items WHERE order_id IN ('.$marks.') ORDER BY id');$i->execute($ids);$group=[];foreach($i->fetchAll() as $row)$group[(int)$row['order_id']][]=$row;foreach($orders as &$order)$order['items']=$group[(int)$order['id']]??[];unset($order);}
        go_out(['ok'=>true,'tickets'=>$orders]);
    }

    if($action==='tables-list'){
        $current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Inicie um turno de Operação para acessar o salão.');
        go_out(['ok'=>true,'tables'=>(new TableService())->list()]);
    }
    if($action==='table-open'){
        go_method('POST');$current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Inicie um turno de Operação para abrir comanda.');$body=go_body();
        go_out(['ok'=>true,'tab'=>(new TableService())->open((int)($body['table_id']??0),(string)($body['label']??''))],201);
    }
    if($action==='table-close'){
        go_method('POST');$current=$shift->current();if(!$current||$current['mode']!=='operation')throw new RuntimeException('Inicie um turno de Operação para fechar comanda.');$body=go_body();
        go_out(['ok'=>true,'tab'=>(new TableService())->close((int)($body['tab_id']??0))]);
    }

    if($action==='catalog'){
        if(!Auth::can('orders.create')&&!Auth::can('catalog.manage'))go_out(['ok'=>false,'error'=>'Acesso negado.'],403);
        $s=Database::connection()->prepare('SELECT p.id,p.category_id,c.name category_name,p.name,p.description,p.sku,p.price_cents,p.stock_qty,p.track_stock,p.image_url FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.tenant_id=? AND p.active=1 ORDER BY COALESCE(c.sort_order,999999),c.name,p.name');$s->execute([$tenantId]);go_out(['ok'=>true,'products'=>$s->fetchAll()]);
    }

    if($action==='pix-create'){go_method('POST');$body=go_body();$amount=isset($body['amount_cents'])?(int)$body['amount_cents']:null;$pix=(new NativePixService())->create((int)($body['order_id']??0),(string)($body['tax_id']??''),$amount);go_out(['ok'=>true,'pix'=>$pix],201);}
    if($action==='nfc-intent'){go_method('POST');$body=go_body();$amount=isset($body['amount_cents'])?(int)$body['amount_cents']:null;$result=(new NfcService())->createIntent((int)($body['order_id']??0),$deviceId,$amount);go_out(['ok'=>true]+$result,201);}
    if($action==='nfc-verify'){go_method('POST');$body=go_body();go_out((new NfcService())->verifyIntent((string)($body['intent_token']??''),(string)($body['transaction_code']??''),$deviceId));}

    $posPayment=new PosPaymentService();
    if($action==='payment-status'){$id=(int)($_GET['order_id']??0);go_out(['ok'=>true,'payment'=>$posPayment->status($id)]);}
    if($action==='payment-cash'){go_method('POST');$body=go_body();go_out(['ok'=>true,'payment'=>$posPayment->cash((int)($body['order_id']??0),(int)($body['amount_cents']??0),(string)($body['idempotency_key']??''))]);}

    $deliveryCash=new DeliveryCashService();
    if($action==='delivery-cash-collect'){go_method('POST');$body=go_body();go_out(['ok'=>true,'receipt'=>$deliveryCash->collect((int)($body['order_id']??0),(int)($body['received_cents']??0))]);}
    if($action==='delivery-cash-outstanding'){$id=(int)($_GET['shift_id']??0);go_out(['ok'=>true,'cash'=>$deliveryCash->outstanding($id?:null)]);}
    if($action==='handoff-create'){go_method('POST');go_out(['ok'=>true,'handoff'=>$deliveryCash->createHandoff()],201);}
    if($action==='handoff-resolve'){$handoff=$deliveryCash->resolveHandoff((string)($_GET['token']??''));go_out(['ok'=>true,'handoff'=>$handoff]);}
    if($action==='handoff-confirm'){go_method('POST');$body=go_body();go_out(['ok'=>true,'handoff'=>$deliveryCash->confirmHandoff((string)($body['token']??''))]);}

    go_out(['ok'=>false,'error'=>'Endpoint EventMenu GO não encontrado.'],404);
}catch(RuntimeException $e){go_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))go_out(['ok'=>false,'error'=>$e->getMessage()],500);go_out(['ok'=>false,'error'=>'Erro interno.'],500);}
