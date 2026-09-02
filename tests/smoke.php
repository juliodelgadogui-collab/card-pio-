<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Security;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CheckoutReconciliationService;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\DeliveryService;
use EventMenu\Services\MaintenanceService;
use EventMenu\Services\OnlineOrderingService;
use EventMenu\Services\OrderSchedulingService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PaymentService;
use EventMenu\Services\PublicOrderService;
use EventMenu\Services\RefundService;
use EventMenu\Services\StockService;
use EventMenu\Services\TicketService;

$checks=[
    class_exists(Security::class),
    class_exists(CheckoutService::class),
    class_exists(CheckoutReconciliationService::class),
    class_exists(PaymentService::class),
    class_exists(PublicOrderService::class),
    class_exists(CounterOrderService::class),
    class_exists(CashRegisterService::class),
    class_exists(DeliveryService::class),
    class_exists(OnlineOrderingService::class),
    class_exists(OrderSchedulingService::class),
    class_exists(TicketService::class),
    class_exists(MaintenanceService::class),
    class_exists(OrderWorkflowService::class),
    class_exists(StockService::class),
    class_exists(RefundService::class),
    strlen(Security::randomKey(16))===32,
    class_exists('Stripe\\StripeClient'),
    class_exists('MercadoPago\\Webhook\\WebhookSignatureValidator'),
];
if(in_array(false,$checks,true)){fwrite(STDERR,"Smoke test failed\n");exit(1);}echo "Smoke test OK\n";
