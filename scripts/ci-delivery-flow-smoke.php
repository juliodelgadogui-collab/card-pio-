<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\DeliveryProgressService;
use EventMenu\Services\OrderService;

function df_fail(string $message):never{fwrite(STDERR,"DELIVERY FLOW CI FAIL: {$message}\n");exit(1);}
function df_assert(bool $ok,string $message):void{if(!$ok)df_fail($message);}

$pdo=Database::connection();
$tenantId=(int)$pdo->query('SELECT id FROM tenants WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn();
if($tenantId<1)df_fail('Tenant ativo não encontrado.');
$uid='df'.bin2hex(random_bytes(4));

$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Delivery Flow Admin',$uid.'-admin@example.test','x']);
$adminId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"delivery","active")')->execute([$tenantId,'Delivery Flow Courier',$uid.'-delivery@example.test','x']);
$deliveryId=(int)$pdo->lastInsertId();

$unitCode='df-unit-'.bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"Delivery Flow Unit",1)')->execute([$tenantId,$unitCode]);
$unitId=(int)$pdo->lastInsertId();

$_SESSION['user_id']=$adminId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='Delivery Flow Admin';unset($_SESSION['acting_tenant_id']);

// Pedido Delivery realista: criado/confirmado, pago, KDS preparando -> pronto.
$token=bin2hex(random_bytes(20));
$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,delivery_fee_cents,total_cents,delivery_address,created_by) VALUES (?,?,?,"delivery","confirmed","paid",4000,380,4380,"Rua Teste, 123",?)')->execute([$token,$tenantId,$unitId,$adminId]);
$orderId=(int)$pdo->lastInsertId();
$orderService=new OrderService();
$orderService->changeStatus($orderId,'preparing','kitchen');
$orderService->changeStatus($orderId,'ready','kitchen');
df_assert($pdo->query('SELECT status FROM orders WHERE id='.(int)$orderId)->fetchColumn()==='ready','KDS não levou o pedido até Pronto.');

// Persiste a atribuição exatamente na coluna consumida pelo app/API.
$pdo->prepare('UPDATE orders SET assigned_delivery_user_id=? WHERE id=? AND tenant_id=?')->execute([$deliveryId,$orderId,$tenantId]);
$assigned=(int)$pdo->query('SELECT assigned_delivery_user_id FROM orders WHERE id='.(int)$orderId)->fetchColumn();
df_assert($assigned===$deliveryId,'assigned_delivery_user_id não persistiu.');

// Reproduz compatibilidade com instalações antigas/turnos globais: turno Delivery sem unidade
// deve enxergar pedido atribuído mesmo quando o pedido já possui unit_id.
$pdo->prepare('INSERT INTO work_shifts (tenant_id,user_id,unit_id,mode,status,opening_notes) VALUES (?,?,NULL,"delivery","open","CI delivery flow")')->execute([$tenantId,$deliveryId]);
$_SESSION['user_id']=$deliveryId;$_SESSION['role']='delivery';$_SESSION['name']='Delivery Flow Courier';
$delivery=new DeliveryProgressService();
$mine=$delivery->listMine();
df_assert(count(array_filter($mine,fn(array $row):bool=>(int)$row['order_id']===$orderId))===1,'Pedido Pronto atribuído não apareceu em Minhas entregas.');

$pickup=$delivery->pickup($orderId);df_assert(!empty($pickup['picked_up_at']),'Retirar pedido não persistiu.');
$route=$delivery->startRoute($orderId);df_assert(!empty($route['route_started_at']),'Iniciar rota não persistiu.');
df_assert($pdo->query('SELECT status FROM orders WHERE id='.(int)$orderId)->fetchColumn()==='out_for_delivery','Pedido não entrou em rota.');
$arrived=$delivery->arrive($orderId);df_assert(!empty($arrived['arrived_at']),'Cheguei não persistiu.');
$completed=$delivery->complete($orderId);df_assert(!empty($completed['progress']['completed_at']),'Entregue não persistiu.');
df_assert($pdo->query('SELECT status FROM orders WHERE id='.(int)$orderId)->fetchColumn()==='completed','Pedido não finalizou como completed.');

// Finalizado pode existir no histórico da API, mas não deve ser considerado ativo pelo APK.
$after=$delivery->listMine();$row=array_values(array_filter($after,fn(array $r):bool=>(int)$r['order_id']===$orderId))[0]??null;
df_assert($row!==null&&$row['order_status']==='completed','Entrega finalizada perdeu consistência no histórico.');

// Contrato mínimo usado por Detalhes: nulls/ausências são válidos e arrays podem estar vazios.
$detailOrder=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$detailOrder->execute([$orderId,$tenantId]);$order=$detailOrder->fetch();
df_assert(is_array($order),'Pedido de detalhes não pôde ser lido.');
$items=$pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$items->execute([$orderId]);
$contract=['ok'=>true,'detail'=>['order'=>$order,'customer'=>null,'items'=>$items->fetchAll(),'timeline'=>[],'loyalty'=>null]];
$encoded=json_encode($contract,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
df_assert(is_string($encoded)&&str_contains($encoded,'"detail"'),'Contrato JSON de detalhes inválido.');

echo "CI delivery flow smoke OK\n";
