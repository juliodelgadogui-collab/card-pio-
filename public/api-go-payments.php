<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\CardPresentService;
use EventMenu\Services\NativePixService;
use EventMenu\Services\PosPaymentService;
use EventMenu\Services\Payments\PaymentProviderRegistry;
use EventMenu\Services\Payments\SumUpProvider;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, POST, OPTIONS');http_response_code(204);exit;}
function gopay_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function gopay_body():array{$raw=file_get_contents('php://input');if($raw===false||trim($raw)==='')return $_POST?:[];try{$d=json_decode($raw,true,512,JSON_THROW_ON_ERROR);return is_array($d)?$d:[];}catch(Throwable){gopay_out(['ok'=>false,'error'=>'JSON inválido.'],400);}}
try{
    $auth=new ApiAuthService();$token=ApiAuthService::bearerToken();$deviceId=ApiAuthService::deviceId();if($token==='')gopay_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);$user=$auth->authenticate($token,$deviceId);$tenantId=(int)$user['tenant_id'];$action=(string)($_GET['action']??'capabilities');$shift=(new WorkShiftService())->current();if(!$shift)throw new RuntimeException('Inicie seu turno antes de usar pagamentos.');
    $registry=new PaymentProviderRegistry();$runtime=$registry->runtimeOptions($tenantId);
    if($action==='capabilities'){
        $card=$registry->preference($tenantId,'card_present');$pix=$registry->preference($tenantId,'pix');
        gopay_out(['ok'=>true,'card_present_provider'=>$card,'pix_provider'=>$pix,'runtime'=>$runtime,'providers'=>$registry->activeProviders($tenantId)]);
    }
    if($action==='card-session'){
        Auth::requirePermission('nfc.collect');if(empty($runtime['card_present_enabled']))throw new RuntimeException('Pagamento NFC está desativado para esta empresa.');$provider=$registry->preference($tenantId,'card_present');if(!$provider)throw new RuntimeException('Nenhum provedor NFC está ativo.');$session=match($provider){'sumup'=>(new SumUpProvider())->sdkSession($tenantId),default=>['provider'=>$provider]};gopay_out(['ok'=>true,'provider'=>$provider,'session'=>$session]);
    }
    if($action==='pix-status'){
        if($_SERVER['REQUEST_METHOD']!=='GET')gopay_out(['ok'=>false,'error'=>'Método não permitido.'],405);$paymentId=(int)($_GET['payment_id']??0);$status=(new NativePixService())->verifyPayment($paymentId);gopay_out(['ok'=>true,'pix'=>$status]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')gopay_out(['ok'=>false,'error'=>'Método não permitido.'],405);$body=gopay_body();
    if($action==='card-intent'){
        if(empty($runtime['card_present_enabled']))throw new RuntimeException('Pagamento NFC está desativado para esta empresa.');
        $result=(new CardPresentService())->createIntent((int)($body['order_id']??0),$deviceId,isset($body['amount_cents'])?(int)$body['amount_cents']:null,(string)($body['payment_method']??'credit'),(int)($body['installments_count']??1),trim((string)($body['provider']??''))?:null);gopay_out(['ok'=>true,'card_present'=>$result],201);
    }
    if($action==='card-verify'){
        $providerResult=is_array($body['provider_result']??null)?$body['provider_result']:[];$result=(new CardPresentService())->verifyIntent((string)($body['intent_token']??''),$providerResult,$deviceId);gopay_out($result);
    }
    if($action==='card-reconcile'){
        $result=(new CardPresentService())->verifyIntent((string)($body['intent_token']??''),[],$deviceId);gopay_out($result);
    }
    if($action==='card-fail'){
        (new CardPresentService())->failIntent((string)($body['intent_token']??''),(string)($body['reason']??'Falha no SDK'),$deviceId);gopay_out(['ok'=>true]);
    }
    if($action==='pix-create'){
        if(empty($runtime['pix_enabled']))throw new RuntimeException('PIX está desativado para esta empresa.');
        $pix=(new NativePixService())->create((int)($body['order_id']??0),(string)($body['tax_id']??''),isset($body['amount_cents'])?(int)$body['amount_cents']:null,trim((string)($body['provider']??''))?:null);gopay_out(['ok'=>true,'pix'=>$pix],201);
    }
    if($action==='external-terminal'){
        if(!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('Confirmação manual de maquininha é permitida somente em turno de Operação ou Pay.');
        $payment=(new PosPaymentService())->externalTerminal(
            (int)($body['order_id']??0),
            (int)($body['amount_cents']??0),
            (string)($body['payment_method']??''),
            (string)($body['machine_label']??''),
            (string)($body['transaction_reference']??''),
            (string)($body['idempotency_key']??''),
            $deviceId
        );
        gopay_out(['ok'=>true,'payment'=>$payment]);
    }
    gopay_out(['ok'=>false,'error'=>'Ação de pagamento inválida.'],404);
}catch(RuntimeException$e){gopay_out(['ok'=>false,'error'=>$e->getMessage()],422);}catch(Throwable$e){if(filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL))gopay_out(['ok'=>false,'error'=>$e->getMessage()],500);gopay_out(['ok'=>false,'error'=>'Erro interno.'],500);}
