<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\DeliveryCustomerAuthService;
use EventMenu\Services\DeliveryPaymentMethodService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo json_encode(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED','message'=>'Ação indisponível.'],JSON_UNESCAPED_UNICODE);exit;}

$header=trim((string)($_SERVER['HTTP_AUTHORIZATION']??''));if($header===''&&function_exists('getallheaders')){$headers=getallheaders();$header=trim((string)($headers['Authorization']??$headers['authorization']??''));}
$token=preg_match('/^Bearer\s+(.+)$/i',$header,$m)?trim($m[1]):'';
try{
    $pdo=Database::connection();$account=(new DeliveryCustomerAuthService())->authenticate($pdo,$token);$orderId=(int)($_GET['order_id']??0);if($orderId<1)throw new RuntimeException('Pedido inválido.');
    $methods=(new DeliveryPaymentMethodService())->forOrder($pdo,(int)$account['id'],$orderId);
    echo json_encode(['ok'=>true,'methods'=>$methods],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(RuntimeException$e){$message=trim($e->getMessage())?:'Não foi possível carregar as formas de pagamento.';$status=str_contains(mb_strtolower($message),'sessão')||str_contains(mb_strtolower($message),'conta para continuar')?401:422;http_response_code($status);echo json_encode(['ok'=>false,'code'=>$status===401?'UNAUTHENTICATED':'VALIDATION_ERROR','message'=>mb_substr($message,0,240)],JSON_UNESCAPED_UNICODE);}
catch(Throwable$e){error_log('[delivery-payment-methods] '.$e::class.': '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'code'=>'INTERNAL_ERROR','message'=>'Não foi possível carregar as formas de pagamento.'],JSON_UNESCAPED_UNICODE);}
