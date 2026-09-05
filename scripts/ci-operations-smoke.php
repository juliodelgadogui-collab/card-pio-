<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
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

echo "CI operations smoke OK\n";
