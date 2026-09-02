<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\PublicOrderService;

function assert_delivery(bool $condition,string $message):void{
    if(!$condition){fwrite(STDERR,"Delivery integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();
$pdo->exec("INSERT INTO tenants (name,slug,plan,status) VALUES ('CI Delivery','ci-delivery','premium','active')");
$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,track_stock,active) VALUES (?,"Produto teste",1000,0,1)')->execute([$tenantId]);
$productId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO delivery_zones (tenant_id,name,match_type,match_value,fee_cents,min_order_cents,free_above_cents,eta_min_minutes,eta_max_minutes,sort_order,active) VALUES (?,"Zona CEP","postal_prefix","283",700,1500,5000,25,45,0,1)')->execute([$tenantId]);
$zoneId=(int)$pdo->lastInsertId();

$service=new PublicOrderService();
$buyer=['name'=>'Cliente CI','phone'=>'22999990000','email'=>'ci@example.com','postal_code'=>'28300-000','neighborhood'=>'Centro','city'=>'Cidade Teste'];
$result=$service->createDelivery($tenantId,[['product_id'=>$productId,'qty'=>2]],$buyer,'Rua Teste, 10');
assert_delivery((int)$result['subtotal_cents']===2000,'subtotal incorreto');
assert_delivery((int)$result['delivery_fee_cents']===700,'taxa da zona incorreta');
assert_delivery((int)$result['total_cents']===2700,'total com frete incorreto');
assert_delivery((int)$result['eta_min_minutes']===25&&(int)$result['eta_max_minutes']===45,'ETA incorreto');

$stmt=$pdo->prepare('SELECT delivery_zone_id,delivery_fee_cents,delivery_eta_min_minutes,delivery_eta_max_minutes FROM orders WHERE id=?');
$stmt->execute([$result['order_id']]);
$order=$stmt->fetch();
assert_delivery((int)$order['delivery_zone_id']===$zoneId,'zona não persistida');
assert_delivery((int)$order['delivery_fee_cents']===700,'frete não persistido');

$free=$service->createDelivery($tenantId,[['product_id'=>$productId,'qty'=>5]],['name'=>'Cliente CI 2','phone'=>'22999990001','postal_code'=>'28310-000','neighborhood'=>'Centro','city'=>'Cidade Teste'],'Rua Teste, 20');
assert_delivery((int)$free['subtotal_cents']===5000,'subtotal do frete grátis incorreto');
assert_delivery((int)$free['delivery_fee_cents']===0,'frete grátis não aplicado');
assert_delivery((int)$free['total_cents']===5000,'total do frete grátis incorreto');

$minimumBlocked=false;
try{
    $service->createDelivery($tenantId,[['product_id'=>$productId,'qty'=>1]],['name'=>'Cliente CI 3','phone'=>'22999990002','postal_code'=>'28320-000','neighborhood'=>'Centro','city'=>'Cidade Teste'],'Rua Teste, 30');
}catch(RuntimeException $e){$minimumBlocked=str_contains($e->getMessage(),'Pedido mínimo');}
assert_delivery($minimumBlocked,'pedido abaixo do mínimo não foi bloqueado');

$outsideBlocked=false;
try{
    $service->createDelivery($tenantId,[['product_id'=>$productId,'qty'=>2]],['name'=>'Cliente CI 4','phone'=>'22999990003','postal_code'=>'99999-000','neighborhood'=>'Outro','city'=>'Outra Cidade'],'Rua Fora, 40');
}catch(RuntimeException $e){$outsideBlocked=str_contains($e->getMessage(),'fora da área');}
assert_delivery($outsideBlocked,'endereço fora da área não foi bloqueado');

$count=$pdo->prepare('SELECT COUNT(*) FROM delivery_events WHERE tenant_id=? AND event_type="created"');
$count->execute([$tenantId]);
assert_delivery((int)$count->fetchColumn()===2,'histórico de criação da entrega incorreto');

echo "Delivery integration OK\n";
