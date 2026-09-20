<?php

declare(strict_types=1);

function native_payment_fail(string $message):never{fwrite(STDERR,"NATIVE PAYMENT CI FAIL: {$message}\n");exit(1);}
function native_payment_assert(bool $ok,string $message):void{if(!$ok)native_payment_fail($message);}

$api=(string)file_get_contents(dirname(__DIR__).'/public/api.php');
$publicOrder=(string)file_get_contents(dirname(__DIR__).'/src/Services/PublicOrderPaymentService.php');
$pedido=(string)file_get_contents(dirname(__DIR__).'/public/pedido.php');

native_payment_assert(str_contains($api,"if(\$action==='pix-checkout')"),'A rota legada pix-checkout foi removida em vez de preservada.');
native_payment_assert(str_contains($api,'new NativePixService()'),'pix-checkout não usa o motor PIX nativo.');
native_payment_assert(!str_contains($api,'CheckoutService'),'api.php voltou a depender de CheckoutService para o fluxo operacional.');
native_payment_assert(str_contains($api,"'redirect'=>false"),'Resposta compatível do pix-checkout não declara fluxo sem redirecionamento.');
native_payment_assert(str_contains($publicOrder,'new DeliveryCustomerPaymentService()'),'Checkout Web não está compartilhando o motor seguro do Delivery.');
native_payment_assert(str_contains($publicOrder,'new DeliveryPaymentMethodService()'),'Checkout Web não consulta os meios habilitados pelo servidor.');
native_payment_assert(str_contains($pedido,'api-order-payment.php'),'Pedido Web não usa a API nativa de pagamento.');
native_payment_assert(!str_contains($pedido,'init_point'),'Pedido Web voltou a expor init_point do Mercado Pago.');

echo "CI native payment route smoke OK\n";
