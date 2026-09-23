<?php

declare(strict_types=1);

$root=dirname(__DIR__);
function mp_fail(string $message): never { fwrite(STDERR,"MERCADO PAGO SECURITY CI FAIL: {$message}\n"); exit(1); }
function mp_has(string $haystack,string $needle,string $message): void { if(!str_contains($haystack,$needle))mp_fail($message); }

$gateway=(string)file_get_contents($root.'/src/Services/GatewayService.php');
$delivery=(string)file_get_contents($root.'/src/Services/DeliveryCustomerPaymentService.php');
$payment=(string)file_get_contents($root.'/src/Services/PaymentService.php');
$settings=(string)file_get_contents($root.'/public/delivery-settings.php');
$routes=(string)file_get_contents($root.'/app/routes/gateways.php');
$sqlite=(string)file_get_contents($root.'/database/sqlite/migrations/078_payment_gateway_webhook_aliases.sql');
$mysql=(string)file_get_contents($root.'/database/migrations/078_payment_gateway_webhook_aliases.sql');

mp_has($gateway,'https://api.mercadopago.com/users/me','salvamento não valida a credencial em /users/me');
mp_has($gateway,'hash_equals($expected,$collector)','Collector ID manual não é comparado à credencial');
mp_has($gateway,"\$collector!==(string)\$gateway['account_reference']",'webhook não compara collector_id ao account_reference local');
mp_has($gateway,"(\$payment['status']??'')==='approved'",'status approved não é reconhecido pelo verificador');
mp_has($gateway,'PaymentService())->confirmVerified','webhook aprovado não passa pelo validador financeiro comum');
mp_has($delivery,"\$reference!=='eventmenu:'.\$tenantId.':'.\$orderId",'checkout não valida external_reference');
mp_has($delivery,"\$metadata['tenant_id']",'checkout não valida tenant_id em metadata');
mp_has($delivery,"\$metadata['order_id']",'checkout não valida order_id em metadata');
mp_has($delivery,'$collector!==$accountReference','checkout não valida collector_id');
mp_has($delivery,"\$currency!=='BRL'",'checkout não recusa moeda divergente');
mp_has($delivery,"\$amount!==(int)\$payment['amount_cents']",'checkout não recusa valor divergente');
mp_has($payment,"(string)\$account!==(string)\$verified['account_reference']",'confirmação final não revalida conta recebedora');
mp_has($payment,"(int)\$payment['amount_cents']!==(int)\$verified['amount_cents']",'confirmação final não revalida valor');
mp_has($settings,'validateMercadoPagoAccount','Delivery Settings não usa validação canônica da conta');
mp_has($routes,'bloqueia a ativação','painel de gateways não informa bloqueio de Collector ID divergente');
mp_has($sqlite,'UNIQUE (provider, alias_slug)','SQLite permite alias ambíguo de webhook');
mp_has($mysql,'UNIQUE KEY uq_gateway_webhook_alias','MySQL permite alias ambíguo de webhook');

if(preg_match('/collector[_ -]?id\s*[=:>]\s*[\'\"]\d{5,}[\'\"]/i',$gateway.$delivery.$settings.$routes))mp_fail('Collector ID aparentemente hardcoded no código.');
if(str_contains($settings,'name="mp_access_token"')&&!str_contains($settings,'type="password"'))mp_fail('Access Token não está em campo password.');

echo "Mercado Pago security contract smoke: OK\n";
