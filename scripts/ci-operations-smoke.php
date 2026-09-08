<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CashService;
use EventMenu\Services\OperatingUnitService;
use EventMenu\Services\OrderCancellationService;
use EventMenu\Services\OrderService;

function ops_fail(string $message): never { fwrite(STDERR,"OPS CI FAIL: {$message}\n"); exit(1); }
function ops_assert(bool $ok,string $message):void{if(!$ok)ops_fail($message);}

$pdo=Database::connection();$tenantId=(int)$pdo->query('SELECT id FROM tenants WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn();if($tenantId<1)ops_fail('Tenant ativo não encontrado.');
$uid='ops'.bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Ops Admin',$uid.'-admin@example.test','x']);$adminId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"delivery","active")')->execute([$tenantId,'Ops Delivery',$uid.'-delivery@example.test','x']);$deliveryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"delivery","active")')->execute([$tenantId,'Other Delivery',$uid.'-other@example.test','x']);$otherDeliveryId=(int)$pdo->lastInsertId();

$_SESSION['user_id']=$adminId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='Ops Admin';unset($_SESSION['acting_tenant_id']);
$service=new OrderService();

$token=bin2hex(random_bytes(20));$pdo->prepare('INSERT INTO orders (public_token,tenant_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?, ?, "counter", "confirmed", "unpaid", 1000, 1000, ?)')->execute([$token,$tenantId,$adminId]);$kitchenOrder=(int)$pdo->lastInsertId();
$service->changeStatus($kitchenOrder,'preparing','kitchen');$service->changeStatus($kitchenOrder,'ready','kitchen');
$status=$pdo->query('SELECT status FROM orders WHERE id='.(int)$kitchenOrder)->fetchColumn();ops_assert($status==='ready','Fluxo da cozinha falhou.');
try{$service->changeStatus($kitchenOrder,'completed','panel');ops_fail('Pedido não pago foi concluído.');}catch(RuntimeException){}

$paid=bin2hex(random_bytes(20));$pdo->prepare('INSERT INTO orders (public_token,tenant_id,assigned_delivery_user_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?, ?, ?, "delivery", "ready", "paid", 2000, 2000, ?)')->execute([$paid,$tenantId,$deliveryId,$adminId]);$deliveryOrder=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$otherDeliveryId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='delivery';$_SESSION['name']='Other Delivery';
try{$service->changeStatus($deliveryOrder,'out_for_delivery','delivery');ops_fail('Entregador não atribuído alterou pedido.');}catch(RuntimeException){}

$_SESSION['user_id']=$deliveryId;$_SESSION['role']='delivery';$_SESSION['name']='Ops Delivery';
$service->changeStatus($deliveryOrder,'out_for_delivery','delivery');$service->changeStatus($deliveryOrder,'completed','delivery');
$status=$pdo->query('SELECT status FROM orders WHERE id='.(int)$deliveryOrder)->fetchColumn();ops_assert($status==='completed','Fluxo do entregador falhou.');

$paidCancel=bin2hex(random_bytes(20));$pdo->prepare('INSERT INTO orders (public_token,tenant_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?, ?, "counter", "confirmed", "paid", 500, 500, ?)')->execute([$paidCancel,$tenantId,$adminId]);$paidOrder=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$adminId;$_SESSION['role']='admin';$_SESSION['name']='Ops Admin';
try{$service->changeStatus($paidOrder,'cancelled','panel');ops_fail('Pedido pago foi cancelado sem estorno.');}catch(RuntimeException){}

// O painel web precisa conseguir solicitar/analisar cancelamento pela unidade sem depender de turno do app.
$unitCode='ops-unit-'.bin2hex(random_bytes(3));$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"Operação Web CI",1)')->execute([$tenantId,$unitCode]);$panelUnitId=(int)$pdo->lastInsertId();
$cancelToken=bin2hex(random_bytes(20));$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?,?,?,"counter","confirmed","unpaid",1500,1500,?)')->execute([$cancelToken,$tenantId,$panelUnitId,$adminId]);$panelCancelOrder=(int)$pdo->lastInsertId();
$cancelService=new OrderCancellationService();$request=$cancelService->requestFromPanel($panelCancelOrder,'Cliente desistiu',$panelUnitId);ops_assert((int)$request['order_id']===$panelCancelOrder,'Painel não criou solicitação de cancelamento.');
$pending=$cancelService->pendingForUnit($panelUnitId);ops_assert(count(array_filter($pending,fn(array$r):bool=>(int)$r['id']===(int)$request['id']))===1,'Solicitação não apareceu na fila da gerência.');
try{$cancelService->approveFromPanel((int)$request['id'],$panelUnitId+999);ops_fail('Cancelamento foi aprovado por unidade incorreta.');}catch(RuntimeException){}
$approved=$cancelService->approveFromPanel((int)$request['id'],$panelUnitId);ops_assert($approved['status']==='approved','Gerente não aprovou cancelamento pelo painel.');
$panelCancelStatus=$pdo->query('SELECT status FROM orders WHERE id='.(int)$panelCancelOrder)->fetchColumn();ops_assert($panelCancelStatus==='cancelled','Pedido não ficou cancelado após aprovação no painel.');

// O restante do smoke usa o contexto oficial da mesma unidade do painel.
(new OperatingUnitService())->selectCurrent($panelUnitId);
$cash=new CashService();
$cashSession=$cash->open(10000,'CI abertura');
ops_assert((int)$cashSession['opening_cash_cents']===10000,'Abertura do caixa falhou.');
ops_assert((int)$cashSession['unit_id']===$panelUnitId,'Caixa não abriu na unidade selecionada.');
try{$cash->open(100);ops_fail('Segundo caixa foi aberto para o mesmo operador.');}catch(RuntimeException){}
$cash->addManualMovement('supply',2000,'Suprimento CI');
$cash->addManualMovement('withdrawal',1000,'Sangria CI');
$cash->addManualMovement('adjustment',500,'Ajuste CI','out');

$cashToken=bin2hex(random_bytes(20));
$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?, ?, ?, "counter", "confirmed", "paid", 3000, 3000, ?)')->execute([$cashToken,$tenantId,$panelUnitId,$adminId]);
$cashOrder=(int)$pdo->lastInsertId();
$providerPaymentId='CASH-CI-'.strtoupper(bin2hex(random_bytes(6)));
$paymentKey='cash-ci-'.$uid;
$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,provider_payment_id,idempotency_key,amount_cents,currency,status,verified_at) VALUES (?, ?, "manual", ?, ?, 3000, "BRL", "paid", CURRENT_TIMESTAMP)')->execute([$tenantId,$cashOrder,$providerPaymentId,$paymentKey]);
$paymentId=(int)$pdo->lastInsertId();
$cash->recordPaidPayment($paymentId,'cash');
$cash->recordPaidPayment($paymentId,'cash');

$cashSummary=$cash->summary((int)$cashSession['id']);
ops_assert((int)$cashSummary['expected_cash_cents']===13500,'Saldo esperado do caixa divergente.');
$recorded=0;foreach($cashSummary['movements'] as $movement){if((int)($movement['payment_id']??0)===$paymentId)$recorded++;}
ops_assert($recorded===1,'Pagamento entrou mais de uma vez no caixa.');
$closed=$cash->close(13400,'CI fechamento');
ops_assert((int)$closed['expected_cash_cents']===13500,'Fechamento calculou esperado incorreto.');
ops_assert((int)$closed['difference_cents']===-100,'Diferença do caixa incorreta.');
try{$cash->addManualMovement('supply',100,'Após fechar');ops_fail('Caixa fechado aceitou movimento.');}catch(RuntimeException){}

echo "CI operations smoke OK\n";
