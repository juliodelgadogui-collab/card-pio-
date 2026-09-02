<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CheckoutReconciliationService;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\DeliveryService;
use EventMenu\Services\FulfillmentService;
use EventMenu\Services\MaintenanceService;
use EventMenu\Services\NfcDeviceService;
use EventMenu\Services\NfcPaymentService;
use EventMenu\Services\OnlineOrderingService;
use EventMenu\Services\OrderSchedulingService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PagBankSecurityService;
use EventMenu\Services\PaymentService;
use EventMenu\Services\PublicOrderService;
use EventMenu\Services\RefundCoordinatorService;
use EventMenu\Services\RefundService;
use EventMenu\Services\StockService;
use EventMenu\Services\TicketService;

$checks=[class_exists(Auth::class),class_exists(Security::class),class_exists(CheckoutService::class),class_exists(CheckoutReconciliationService::class),class_exists(PaymentService::class),class_exists(PublicOrderService::class),class_exists(CounterOrderService::class),class_exists(FulfillmentService::class),class_exists(CashRegisterService::class),class_exists(RefundCoordinatorService::class),class_exists(NfcDeviceService::class),class_exists(NfcPaymentService::class),class_exists(PagBankSecurityService::class),class_exists(DeliveryService::class),class_exists(OnlineOrderingService::class),class_exists(OrderSchedulingService::class),class_exists(TicketService::class),class_exists(MaintenanceService::class),class_exists(OrderWorkflowService::class),class_exists(StockService::class),class_exists(RefundService::class),strlen(Security::randomKey(16))===32,class_exists('Stripe\\StripeClient'),class_exists('MercadoPago\\Webhook\\WebhookSignatureValidator')];

$old=$_SESSION;
$_SESSION=['user_id'=>100,'tenant_id'=>1,'name'=>'Teste'];
$_SESSION['role']='counter';$checks[]=Auth::homeRoute()==='pos';$checks[]=Auth::can('orders.create');$checks[]=Auth::can('fulfillment.manage');$checks[]=Auth::can('counter.orders');$checks[]=!Auth::can('orders.view');$checks[]=!Auth::can('payments.manage');$checks[]=Auth::roleLabel()==='Balconista';
$_SESSION['role']='cashier';$checks[]=Auth::homeRoute()==='pos';$checks[]=Auth::can('payments.manage');$checks[]=Auth::can('orders.view');$checks[]=Auth::roleLabel()==='Caixa';
$_SESSION['role']='kitchen';$checks[]=Auth::homeRoute()==='kitchen';$checks[]=Auth::can('orders.kitchen');$checks[]=!Auth::can('orders.view');$checks[]=!Auth::can('payments.manage');
$_SESSION['role']='delivery';$checks[]=Auth::homeRoute()==='my-deliveries';$checks[]=Auth::can('orders.delivery');$checks[]=!Auth::can('orders.view');$checks[]=!Auth::can('payments.manage');
$_SESSION['role']='waiter';$checks[]=Auth::homeRoute()==='restaurant';$checks[]=Auth::can('tables.manage');$checks[]=!Auth::can('payments.manage');
$_SESSION=$old;

if(in_array(false,$checks,true)){fwrite(STDERR,"Smoke test failed\n");exit(1);}echo "Smoke test OK\n";
