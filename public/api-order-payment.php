<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\PublicOrderPaymentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

function order_payment_out(array $data,int $status=200):never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function order_payment_body():array
{
    $raw=file_get_contents('php://input');
    if($raw===false||trim($raw)==='')return $_POST?:[];
    try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
    catch(Throwable){order_payment_out(['ok'=>false,'code'=>'INVALID_BODY','message'=>'Não foi possível ler os dados enviados.'],400);}
}

function order_payment_error(Throwable $e):array
{
    $message=trim((string)$e->getMessage())?:'Não foi possível concluir agora.';
    $lower=mb_strtolower($message);
    foreach(['sqlstate','select ','insert ','update ','delete ','pdo','sqlite','mysql','database','stack trace','exception','access_token','client_secret','private_key','webhook secret']as$term){
        if(str_contains($lower,$term))return ['code'=>'INTERNAL_ERROR','message'=>'Não foi possível concluir agora. Tente novamente.'];
    }
    return ['code'=>'VALIDATION_ERROR','message'=>mb_substr($message,0,260)];
}

try {
    $pdo=Database::connection();
    $action=strtolower(trim((string)($_GET['action']??'methods')));
    $body=in_array($_SERVER['REQUEST_METHOD'],['POST','PUT','PATCH'],true)?order_payment_body():[];
    $token=trim((string)($_GET['t']??$body['t']??''));
    if($token===''||strlen($token)>100)order_payment_out(['ok'=>false,'code'=>'INVALID_ORDER','message'=>'Pedido inválido.'],404);

    $rate=new ApiRateLimitService();
    $service=new PublicOrderPaymentService();
    $subject=$rate->requestSubject('public-order:'.substr(hash('sha256',$token),0,20));

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $csrf=(string)($body['_csrf']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));
        if(!Security::validateCsrf($csrf))order_payment_out(['ok'=>false,'code'=>'CSRF_EXPIRED','message'=>'A página expirou. Atualize e tente novamente.'],419);
    }

    if($action==='methods'){
        if($_SERVER['REQUEST_METHOD']!=='GET')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.methods',$subject,60,60,'Muitas atualizações. Aguarde alguns segundos.');
        $order=$service->order($pdo,$token);
        order_payment_out(['ok'=>true,'order'=>['id'=>(int)$order['id'],'payment_status'=>(string)$order['payment_status'],'status'=>(string)$order['status'],'total_cents'=>(int)$order['total_cents']],'profile'=>$service->currentProfile($pdo,$token),'methods'=>$service->methods($pdo,$token)]);
    }

    if($action==='profile'){
        if($_SERVER['REQUEST_METHOD']!=='POST')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.profile',$subject,20,300,'Muitas tentativas de atualização. Aguarde um pouco.');
        order_payment_out(['ok'=>true,'profile'=>$service->profile($pdo,$token,(string)($body['email']??''),(string)($body['document']??''))]);
    }

    if($action==='pix'){
        if($_SERVER['REQUEST_METHOD']!=='POST')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.charge',$subject,15,600,'Muitas tentativas de pagamento. Aguarde alguns minutos.');
        order_payment_out(['ok'=>true,'payment'=>$service->pix($pdo,$token,(string)($body['provider']??''))],201);
    }

    if($action==='card'){
        if($_SERVER['REQUEST_METHOD']!=='POST')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.charge',$subject,15,600,'Muitas tentativas de pagamento. Aguarde alguns minutos.');
        $payload=[
            'provider'=>'mercadopago',
            'card_token'=>(string)($body['card_token']??''),
            'payment_method_id'=>(string)($body['payment_method_id']??''),
            'payment_type_id'=>(string)($body['payment_type_id']??''),
            'installments'=>(int)($body['installments']??1),
            'issuer_id'=>(string)($body['issuer_id']??''),
        ];
        order_payment_out(['ok'=>true,'payment'=>$service->card($pdo,$token,$payload)],201);
    }

    if($action==='cash'){
        if($_SERVER['REQUEST_METHOD']!=='POST')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.cash',$subject,10,600,'Muitas alterações de pagamento. Aguarde um pouco.');
        $change=array_key_exists('change_for_cents',$body)&&$body['change_for_cents']!==null?(int)$body['change_for_cents']:null;
        order_payment_out(['ok'=>true,'payment'=>$service->cash($pdo,$token,$change)]);
    }

    if($action==='status'){
        if($_SERVER['REQUEST_METHOD']!=='GET')order_payment_out(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('public.order.payment.status',$subject,120,60,'Muitas atualizações. Aguarde alguns segundos.');
        order_payment_out(['ok'=>true,'payment'=>$service->status($pdo,$token)]);
    }

    order_payment_out(['ok'=>false,'code'=>'NOT_FOUND','message'=>'Ação não encontrada.'],404);
} catch(ApiRateLimitExceededException $e) {
    $error=order_payment_error($e);order_payment_out(['ok'=>false,'code'=>'RATE_LIMITED','message'=>$error['message']],429);
} catch(RuntimeException $e) {
    $error=order_payment_error($e);order_payment_out(['ok'=>false]+$error,422);
} catch(Throwable $e) {
    error_log('[eventmenu-public-payment] '.$e::class.': '.$e->getMessage());
    order_payment_out(['ok'=>false,'code'=>'INTERNAL_ERROR','message'=>'Não foi possível concluir agora. Tente novamente.'],500);
}
