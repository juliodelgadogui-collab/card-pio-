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
use EventMenu\Services\PosPaymentService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private, max-age=0');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function go_out(array $data,int $status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function go_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}catch(Throwable){go_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
function go_method(string $expected):void{if($_SERVER['REQUEST_METHOD']!==$expected)go_out(['ok'=>false,'error'=>'Método não permitido.'],405);}

try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')go_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,$deviceId);$tenantId=(int)$user['tenant_id'];$action=(string)($_GET['action']??'context');$shift=new WorkShiftService();
    if($action==='context'){$permissions=Auth::effectivePermissions();go_out(['ok'=>true,'user'=>$user,'permissions'=>PermissionCatalog::appPermissionMap($permissions),'permission_names'=>$permissions,'modes'=>PermissionCatalog::modesForPermissions($permissions),'shift'=>$shift->current()]);}
    if($action==='shift-current')go_out(['ok'=>true,'shift'=>$shift->current()]);
    if($action==='shift-open'){go_method('POST');$body=go_body();go_out(['ok'=>true,'shift'=>$shift->open((string)($body['mode']??''),$deviceId,(string)($body['notes']??''))],201);}
    if($action==='shift-close'){go_method('POST');$body=go_body();go_out(['ok'=>true,'shift'=>$shift->close((string)($body['notes']??''))]);}
    if($action==='shift-summary'){$id=(int)($_GET['shift_id']??0);go_out(['ok'=>true,'summary'=>$shift->summary($id?:null)]);}

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
