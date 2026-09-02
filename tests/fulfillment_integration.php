<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\FulfillmentService;
use EventMenu\Services\PaymentService;

function assert_fulfillment(bool $condition,string $message):void{if(!$condition){fwrite(STDERR,"Fulfillment integration failed: {$message}\n");exit(1);}}

$pdo=Database::connection();$suffix=bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['CI Fulfillment','ci-fulfillment-'.$suffix]);$tenantId=(int)$pdo->lastInsertId();
$hash=password_hash('fulfillment-ci-password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"cashier","active")')->execute([$tenantId,'Caixa Retirada','fulfillment-'.$suffix.'@example.com',$hash]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='cashier';$_SESSION['name']='Caixa Retirada';

$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,"Cerveja CI",1000,10,1,1)')->execute([$tenantId]);$productId=(int)$pdo->lastInsertId();
$order=(new CounterOrderService())->create([$productId=>5],['name'=>'Cliente Retirada'],'Retirada parcial');
$orderId=(int)$order['order_id'];$token=(string)$order['fulfillment_token'];

$stock=$pdo->prepare('SELECT stock_qty FROM products WHERE id=?');$stock->execute([$productId]);assert_fulfillment((float)$stock->fetchColumn()===5.0,'estoque não baixou 5 unidades na venda');

$service=new FulfillmentService();$blocked=false;
try{$summary=$service->byToken($token);$service->fulfill($orderId,(int)$summary['items'][0]['id'],1,'counter','antes do pagamento','fulfill-unpaid:'.$suffix.':0001');}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'totalmente paga');}
assert_fulfillment($blocked,'retirada foi liberada antes do pagamento');

$cash=new CashRegisterService();$cash->open(0,'CI retirada');
$payment=new PaymentService();$created=$payment->create($orderId,'manual','fulfillment-payment:'.$tenantId.':'.$orderId.':'.$suffix);
$payment->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'manual','provider_payment_id'=>'FULFILL-CI-'.$suffix,'amount_cents'=>5000,'currency'=>'BRL','account_reference'=>'manual','manual_method'=>'cash']);

$summary=$service->byToken($token);$itemId=(int)$summary['items'][0]['id'];
assert_fulfillment($summary['payment_status']==='paid','venda não ficou paga');
assert_fulfillment((float)$summary['items'][0]['remaining_quantity']===5.0,'saldo inicial de retirada não é 5');

$key1='fulfillment-ci:'.$tenantId.':'.$orderId.':first:'.$suffix;
$summary=$service->fulfill($orderId,$itemId,1,'scan','Primeira cerveja',$key1);
assert_fulfillment($summary['fulfillment_status']==='partial','venda não ficou parcialmente retirada');
assert_fulfillment((float)$summary['items'][0]['fulfilled_quantity']===1.0,'retirada de 1 não foi registrada');
assert_fulfillment((float)$summary['items'][0]['remaining_quantity']===4.0,'saldo após retirar 1 não ficou 4');
$stock->execute([$productId]);assert_fulfillment((float)$stock->fetchColumn()===5.0,'retirada baixou estoque novamente');

// Replay idempotente da mesma operação não entrega outra unidade.
$summary=$service->fulfill($orderId,$itemId,1,'scan','Replay',$key1);
assert_fulfillment((float)$summary['items'][0]['fulfilled_quantity']===1.0,'replay idempotente duplicou retirada');
$logs=$pdo->prepare('SELECT COUNT(*) FROM order_fulfillments WHERE tenant_id=? AND order_id=?');$logs->execute([$tenantId,$orderId]);assert_fulfillment((int)$logs->fetchColumn()===1,'replay criou log duplicado');

$key2='fulfillment-ci:'.$tenantId.':'.$orderId.':rest:'.$suffix;
$summary=$service->fulfill($orderId,$itemId,4,'counter','Saldo restante',$key2);
assert_fulfillment($summary['fulfillment_status']==='fulfilled','venda não ficou totalmente retirada');
assert_fulfillment((float)$summary['items'][0]['fulfilled_quantity']===5.0,'total retirado não ficou 5');
assert_fulfillment((float)$summary['items'][0]['remaining_quantity']===0.0,'saldo final não ficou zero');
$stock->execute([$productId]);assert_fulfillment((float)$stock->fetchColumn()===5.0,'estoque mudou após concluir retirada');

$over=false;try{$service->fulfill($orderId,$itemId,1,'counter','excesso','fulfillment-ci-over:'.$suffix.':0001');}catch(RuntimeException $e){$over=str_contains($e->getMessage(),'por completo')||str_contains($e->getMessage(),'saldo');}
assert_fulfillment($over,'sistema permitiu retirar acima do comprado');

$logs->execute([$tenantId,$orderId]);assert_fulfillment((int)$logs->fetchColumn()===2,'histórico não contém exatamente as duas retiradas válidas');

echo "Fulfillment integration OK\n";
