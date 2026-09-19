<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\LoyaltyPointsService;
use EventMenu\Services\OrderHistoryService;
use EventMenu\Services\OrderService;
use EventMenu\Services\SensitiveApiRateLimitService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function goo_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function goo_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){goo_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function goo_method(string $method):void{if($_SERVER['REQUEST_METHOD']!==$method)goo_out(['ok'=>false,'error'=>'Método não permitido.'],405);}
function goo_unit(?array $shift):?int{return $shift&&isset($shift['unit_id'])&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;}
function goo_assert_unit(int $tenantId,int $orderId,?array $shift):array{
    $s=Database::connection()->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
    $shiftUnit=goo_unit($shift);$orderUnit=$order['unit_id']!==null?(int)$order['unit_id']:null;
    if($shiftUnit!==$orderUnit)throw new RuntimeException('Este pedido não pertence à unidade do turno atual.');
    return $order;
}
function goo_loyalty(PDO $pdo,int $tenantId,array $order):?array{
    $customerId=(int)($order['customer_id']??0);if($customerId<1||!Auth::can('loyalty.redeem'))return null;
    $service=new LoyaltyPointsService();$config=$service->config($tenantId,$pdo);$summary=$service->summary($tenantId,$customerId,$pdo);
    $r=$pdo->prepare('SELECT points,discount_cents,status FROM customer_points_reservations WHERE tenant_id=? AND order_id=? LIMIT 1');$r->execute([$tenantId,(int)$order['id']]);$reservation=$r->fetch()?:null;
    return [
        'enabled'=>(bool)$config['enabled'],
        'customer_id'=>$customerId,
        'balance'=>(int)$summary['balance'],
        'reserved'=>(int)$summary['reserved'],
        'available'=>(int)$summary['available'],
        'redeem_points'=>(int)$config['redeem_points'],
        'redeem_value_cents'=>(int)$config['redeem_value_cents'],
        'min_redeem_points'=>(int)$config['min_redeem_points'],
        'max_redeem_percent'=>(int)$config['max_redeem_percent'],
        'order_reservation'=>$reservation?['points'=>(int)$reservation['points'],'discount_cents'=>(int)$reservation['discount_cents'],'status'=>(string)$reservation['status']]:null,
    ];
}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')goo_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);$tenantId=(int)$user['tenant_id'];$action=(string)($_GET['action']??'detail');$shift=(new WorkShiftService())->current();
    if(!$shift)throw new RuntimeException('Inicie seu turno antes de consultar pedidos.');

    if($action==='source-meta'){
        goo_method('GET');
        $allowed=Auth::can('orders.view')||Auth::can('orders.create')||Auth::can('orders.kitchen')||Auth::can('orders.dispatch')||Auth::can('orders.delivery')||Auth::can('payments.manage');
        if(!$allowed)throw new RuntimeException('Acesso negado aos pedidos.');
        $raw=trim((string)($_GET['ids']??''));
        $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/[^0-9]+/',$raw)?:[]),static fn(int$id):bool=>$id>0)));
        if(!$ids)goo_out(['ok'=>true,'orders'=>[]]);
        if(count($ids)>200)throw new RuntimeException('Limite de pedidos excedido.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $sql='SELECT id,order_source FROM orders WHERE tenant_id=? AND id IN ('.$marks.')';
        $args=array_merge([$tenantId],$ids);
        $unitId=goo_unit($shift);if($unitId!==null){$sql.=' AND unit_id=?';$args[]=$unitId;}
        if((string)$shift['mode']==='delivery'){$sql.=' AND channel="delivery" AND assigned_delivery_user_id=?';$args[]=(int)$user['id'];}
        $sql.=' ORDER BY id DESC';$s=Database::connection()->prepare($sql);$s->execute($args);$meta=[];
        foreach($s->fetchAll()as$row){$source=strtoupper(trim((string)($row['order_source']??'EVENTMENU_OWN')));$meta[]=['order_id'=>(int)$row['id'],'order_source'=>$source==='EVENTMENU_DELIVERY'?'EVENTMENU_DELIVERY':'EVENTMENU_OWN'];}
        goo_out(['ok'=>true,'orders'=>$meta]);
    }

    if($action==='accept'){
        goo_method('POST');if($shift['mode']!=='operation')throw new RuntimeException('Aceitação de pedido é feita no turno de Operação.');if(!Auth::can('orders.dispatch')&&!Auth::can('orders.manage'))throw new RuntimeException('Sua função não pode aceitar pedidos.');
        $body=goo_body();$orderId=(int)($body['order_id']??0);$order=goo_assert_unit($tenantId,$orderId,$shift);if($order['status']!=='pending')throw new RuntimeException('Somente pedido novo pendente pode ser aceito.');
        goo_out(['ok'=>true,'order'=>(new OrderService())->changeStatus($orderId,'confirmed','accept')]);
    }

    if($action==='loyalty-apply'){
        goo_method('POST');if(!Auth::can('loyalty.redeem'))throw new RuntimeException('Sua função não pode usar pontos em pedidos.');
        (new SensitiveApiRateLimitService())->assertAllowed('loyalty.apply',$user,$deviceId);
        $body=goo_body();$orderId=(int)($body['order_id']??0);$points=(int)($body['points']??0);$order=goo_assert_unit($tenantId,$orderId,$shift);$customerId=(int)($order['customer_id']??0);if($customerId<1)throw new RuntimeException('Identifique o cliente antes de usar pontos.');
        $result=(new LoyaltyPointsService())->applyToExistingOrder($tenantId,$customerId,$orderId,$points);Auth::audit('customer.points_reserved','order',(string)$orderId,['customer_id'=>$customerId,'points'=>$points,'discount_cents'=>$result['discount_cents'],'source'=>'eventmenu_go']);
        $fresh=goo_assert_unit($tenantId,$orderId,$shift);goo_out(['ok'=>true,'result'=>$result,'loyalty'=>goo_loyalty(Database::connection(),$tenantId,$fresh),'order_total_cents'=>(int)$fresh['total_cents']]);
    }

    if($action==='loyalty-remove'){
        goo_method('POST');if(!Auth::can('loyalty.redeem'))throw new RuntimeException('Sua função não pode alterar o resgate de pontos.');
        (new SensitiveApiRateLimitService())->assertAllowed('loyalty.remove',$user,$deviceId);
        $body=goo_body();$orderId=(int)($body['order_id']??0);$order=goo_assert_unit($tenantId,$orderId,$shift);$customerId=(int)($order['customer_id']??0);if($customerId<1)throw new RuntimeException('Este pedido não possui cliente identificado.');
        Database::transaction(function(PDO $tx)use($tenantId,$orderId):void{if((new LoyaltyPointsService())->releaseForOrder($tx,$tenantId,$orderId,true)!==1)throw new RuntimeException('Este pedido não possui pontos reservados.');});Auth::audit('customer.points_reservation_removed','order',(string)$orderId,['customer_id'=>$customerId,'source'=>'eventmenu_go']);
        $fresh=goo_assert_unit($tenantId,$orderId,$shift);goo_out(['ok'=>true,'loyalty'=>goo_loyalty(Database::connection(),$tenantId,$fresh),'order_total_cents'=>(int)$fresh['total_cents']]);
    }

    if($action==='detail'){
        $orderId=(int)($_GET['order_id']??0);$order=goo_assert_unit($tenantId,$orderId,$shift);
        $allowed=Auth::can('orders.view')||Auth::can('orders.manage')||Auth::can('orders.kitchen')||Auth::can('orders.dispatch')||Auth::can('payments.manage');
        if(!$allowed&&Auth::can('orders.delivery')&&(int)($order['assigned_delivery_user_id']??0)===(int)$user['id'])$allowed=true;if(!$allowed)throw new RuntimeException('Acesso negado ao pedido.');
        $pdo=Database::connection();$items=$pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);
        $customer=null;if($order['customer_id']){$c=$pdo->prepare('SELECT id,name,phone,email FROM customers WHERE id=? AND tenant_id=? LIMIT 1');$c->execute([$order['customer_id'],$tenantId]);$customer=$c->fetch()?:null;}
        $timeline=(new OrderHistoryService())->timeline($orderId);
        goo_out(['ok'=>true,'detail'=>['order'=>$order,'customer'=>$customer,'items'=>$items->fetchAll(),'timeline'=>$timeline,'loyalty'=>goo_loyalty($pdo,$tenantId,$order)]]);
    }

    goo_out(['ok'=>false,'error'=>'Endpoint operacional de pedido não encontrado.'],404);
}catch(ApiRateLimitExceededException $e){goo_out(['ok'=>false,'error'=>$e->getMessage()],429);}catch(RuntimeException $e){goo_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable $e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))goo_out(['ok'=>false,'error'=>$e->getMessage()],500);goo_out(['ok'=>false,'error'=>'Erro interno.'],500);}
