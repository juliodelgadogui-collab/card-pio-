<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Security;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\MaintenanceService;
use EventMenu\Services\PaymentService;
use EventMenu\Services\PublicOrderService;
use EventMenu\Services\TicketService;

$checks=[
    class_exists(Security::class),
    class_exists(CheckoutService::class),
    class_exists(PaymentService::class),
    class_exists(PublicOrderService::class),
    class_exists(TicketService::class),
    class_exists(MaintenanceService::class),
    strlen(Security::randomKey(16))===32,
    class_exists('Stripe\\StripeClient'),
    class_exists('MercadoPago\\Webhook\\WebhookSignatureValidator'),
];
if(in_array(false,$checks,true)){fwrite(STDERR,"Smoke test failed\n");exit(1);}echo "Smoke test OK\n";
