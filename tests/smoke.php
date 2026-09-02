<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\BackupService;
use EventMenu\Services\BusinessUnitService;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CatalogOptionsService;
use EventMenu\Services\CheckoutReconciliationService;
use EventMenu\Services\CheckoutService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\DeliveryService;
use EventMenu\Services\FulfillmentService;
use EventMenu\Services\LegalService;
use EventMenu\Services\MailQueueService;
use EventMenu\Services\MaintenanceService;
use EventMenu\Services\NfcDeviceService;
use EventMenu\Services\NfcPaymentService;
use EventMenu\Services\NotificationService;
use EventMenu\Services\OnlineOrderingService;
use EventMenu\Services\OrderSchedulingService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PagBankSecurityService;
use EventMenu\Services\PasswordResetService;
use EventMenu\Services\PaymentService;
use EventMenu\Services\PublicOrderService;
use EventMenu\Services\RefundCoordinatorService;
use EventMenu\Services\RefundService;
use EventMenu\Services\StockService;
use EventMenu\Services\TenantModuleService;
use EventMenu\Services\TicketService;
use EventMenu\Services\WaiterCallService;

$checks=[];
$check=function(string $name,bool $ok)use(&$checks):void{$checks[$name]=$ok;};
foreach([
    Auth::class,Security::class,CheckoutService::class,CheckoutReconciliationService::class,PaymentService::class,
    PublicOrderService::class,CounterOrderService::class,FulfillmentService::class,CashRegisterService::class,
    RefundCoordinatorService::class,NfcDeviceService::class,NfcPaymentService::class,PagBankSecurityService::class,
    DeliveryService::class,OnlineOrderingService::class,OrderSchedulingService::class,TicketService::class,
    MaintenanceService::class,OrderWorkflowService::class,StockService::class,RefundService::class,
    CatalogOptionsService::class,BusinessUnitService::class,NotificationService::class,WaiterCallService::class,
    PasswordResetService::class,LegalService::class,BackupService::class,MailQueueService::class,TenantModuleService::class,
] as$class)$check('class '.$class,class_exists($class));
$check('security random key',strlen(Security::randomKey(16))===32);
$check('stripe sdk',class_exists('Stripe\\StripeClient'));
$check('mercado pago webhook validator',class_exists('MercadoPago\\Webhook\\WebhookSignatureValidator'));

$oldSession=$_SESSION;$oldScript=$_SERVER['SCRIPT_NAME']??null;
$_SESSION=['user_id'=>100,'tenant_id'=>1,'name'=>'Teste'];
$_SESSION['role']='counter';
$check('counter home',Auth::homeRoute()==='pos');
$check('counter create order',Auth::can('orders.create'));
$check('counter fulfillment',Auth::can('fulfillment.manage'));
$check('counter own orders',Auth::can('counter.orders'));
$check('counter cannot view all orders',!Auth::can('orders.view'));
$check('counter cannot receive',!Auth::can('payments.manage'));
$check('counter label',Auth::roleLabel()==='Balconista');

$_SESSION['role']='cashier';
$check('cashier home',Auth::homeRoute()==='pos');
$check('cashier receive',Auth::can('payments.manage'));
$check('cashier orders',Auth::can('orders.view'));
$check('cashier cannot refund',!Auth::can('refunds.manage'));
$check('cashier label',Auth::roleLabel()==='Caixa');

$_SESSION['role']='kitchen';
$check('kitchen home',Auth::homeRoute()==='kitchen');
$check('kitchen permission',Auth::can('orders.kitchen'));
$check('kitchen cannot view admin orders',!Auth::can('orders.view'));
$check('kitchen cannot receive',!Auth::can('payments.manage'));

$_SESSION['role']='delivery';
$check('delivery home',Auth::homeRoute()==='my-deliveries');
$check('delivery permission',Auth::can('orders.delivery'));
$check('delivery cannot view all orders',!Auth::can('orders.view'));
$check('delivery cannot receive',!Auth::can('payments.manage'));

$_SESSION['role']='waiter';
$check('waiter home',Auth::homeRoute()==='restaurant');
$check('waiter tables',Auth::can('tables.manage'));
$check('waiter calls',Auth::can('waiter.calls'));
$check('waiter cannot receive',!Auth::can('payments.manage'));

$_SERVER['SCRIPT_NAME']='/1/index.php';
$check('subfolder login url',Auth::appUrl('/?route=login')==='/1/?route=login');
$check('subfolder update url',Auth::appUrl('/update.php')==='/1/update.php');
$_SERVER['SCRIPT_NAME']='/index.php';
$check('root login url',Auth::appUrl('/?route=login')==='/?route=login');

$check('module route map menu',TenantModuleService::routeModule('products')==='menu');
$check('module route map restaurant',TenantModuleService::routeModule('kitchen')==='restaurant');
$check('core route has no module',TenantModuleService::routeModule('users')===null);

$_SESSION=$oldSession;
if($oldScript===null)unset($_SERVER['SCRIPT_NAME']);else $_SERVER['SCRIPT_NAME']=$oldScript;

$failed=array_keys(array_filter($checks,fn(bool $ok):bool=>!$ok));
if($failed){fwrite(STDERR,"Smoke test failed:\n - ".implode("\n - ",$failed)."\n");exit(1);}
echo 'Smoke test OK ('.count($checks).' checks)'.PHP_EOL;
