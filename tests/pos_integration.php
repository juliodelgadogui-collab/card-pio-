<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\OrderWorkflowService;

function assert_pos(bool $condition,string $message):void{
    if(!$condition){fwrite(STDERR,"POS integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();
$suffix=bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['CI POS','ci-pos-'.$suffix]);
$tenantId=(int)$pdo->lastInsertId();
$hash=password_hash('ci-pos-password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"cashier","active")')->execute([$tenantId,'Caixa CI','pos-'.$suffix.'@example.com',$hash]);
$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='cashier';$_SESSION['name']='Caixa CI';

$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,"Produto estoque",1200,10,1,1)')->execute([$tenantId]);$stockId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,track_stock,active) VALUES (?,"Produto livre",500,0,1)')->execute([$tenantId]);$freeId=(int)$pdo->lastInsertId();

$result=(new CounterOrderService())->create([$stockId=>2,$freeId=>1],['name'=>'Cliente PDV','phone'=>'22990001111'],'Sem gelo');
assert_pos((int)$result['total_cents']===2900,'total incorreto');
$order=$pdo->prepare('SELECT channel,status,payment_status,total_cents,created_by,notes FROM orders WHERE id=?');$order->execute([$result['order_id']]);$stored=$order->fetch();
assert_pos($stored&&$stored['channel']==='counter','canal balcão não persistido');
assert_pos($stored['status']==='confirmed','status inicial incorreto');
assert_pos($stored['payment_status']==='unpaid','pedido PDV nasceu pago indevidamente');
assert_pos((int)$stored['created_by']===$userId,'operador não persistido');
assert_pos($stored['notes']==='Sem gelo','observação não persistida');

$stock=$pdo->prepare('SELECT stock_qty FROM products WHERE id=?');$stock->execute([$stockId]);
assert_pos((float)$stock->fetchColumn()===8.0,'estoque não foi baixado');
$mov=$pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out"');$mov->execute([$tenantId,$result['order_id']]);
assert_pos((int)$mov->fetchColumn()===1,'movimentação de saída ausente ou duplicada');

(new OrderWorkflowService())->transition($tenantId,(int)$result['order_id'],'cancelled');
$stock->execute([$stockId]);assert_pos((float)$stock->fetchColumn()===10.0,'estoque não foi devolvido no cancelamento');
$rev=$pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="reversal"');$rev->execute([$tenantId,$result['order_id']]);
assert_pos((int)$rev->fetchColumn()===1,'reversão de estoque ausente ou duplicada');

// Repetir a mesma transição não pode gerar nova devolução.
(new OrderWorkflowService())->transition($tenantId,(int)$result['order_id'],'cancelled');
$stock->execute([$stockId]);assert_pos((float)$stock->fetchColumn()===10.0,'cancelamento repetido duplicou devolução');

echo "POS integration OK\n";
