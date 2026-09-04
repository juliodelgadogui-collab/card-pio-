<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\FulfillmentService;
use EventMenu\Services\OrderWorkflowService;
use EventMenu\Services\PaymentService;

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

// Cenário real do balcão: vende 5 cervejas, recebe, entrega 1 no ato e deixa 4 de saldo.
(new CashRegisterService())->open(0,'CI retirada imediata');
$beer=$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,"Cerveja",1000,10,1,1)');$beer->execute([$tenantId]);$beerId=(int)$pdo->lastInsertId();
$sale=(new CounterOrderService())->create([['product_id'=>$beerId,'qty'=>5,'option_ids'=>[]]],['name'=>'Cliente cerveja','phone'=>'22990002222'],'Retira uma agora');
$payments=new PaymentService();$payments->create((int)$sale['order_id'],'manual','pos-ci-beer-'.$tenantId.'-'.$sale['order_id']);$payments->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>(int)$sale['order_id'],'provider'=>'manual','provider_payment_id'=>'POS-CI-BEER-'.$sale['order_id'],'amount_cents'=>5000,'currency'=>'BRL','account_reference'=>'manual','manual_method'=>'cash']);
$item=$pdo->prepare('SELECT id FROM order_items WHERE order_id=? AND product_id=? LIMIT 1');$item->execute([$sale['order_id'],$beerId]);$itemId=(int)$item->fetchColumn();
(new FulfillmentService())->fulfill((int)$sale['order_id'],$itemId,1,'counter','Entregue no ato da venda','pos-ci-now-'.$sale['order_id'].'-'.$itemId);
$summary=(new FulfillmentService())->byOrder($tenantId,(int)$sale['order_id']);
assert_pos($summary['payment_status']==='paid','venda das cervejas não ficou paga');
assert_pos($summary['fulfillment_status']==='partial','retirada de 1/5 não ficou parcial');
assert_pos((float)$summary['items'][0]['quantity']===5.0,'quantidade comprada de cervejas incorreta');
assert_pos((float)$summary['items'][0]['fulfilled_quantity']===1.0,'retirada imediata não registrou 1 unidade');
assert_pos((float)$summary['items'][0]['remaining_quantity']===4.0,'saldo após retirada imediata deveria ser 4');
$beerStock=$pdo->prepare('SELECT stock_qty FROM products WHERE id=?');$beerStock->execute([$beerId]);assert_pos((float)$beerStock->fetchColumn()===5.0,'retirada imediata baixou estoque novamente');
$beerMoves=$pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out"');$beerMoves->execute([$tenantId,$sale['order_id']]);assert_pos((int)$beerMoves->fetchColumn()===1,'venda das cervejas gerou saída de estoque duplicada');
$fulfillments=$pdo->prepare('SELECT COUNT(*) FROM order_fulfillments WHERE tenant_id=? AND order_id=?');$fulfillments->execute([$tenantId,$sale['order_id']]);assert_pos((int)$fulfillments->fetchColumn()===1,'retirada imediata não gerou um único movimento de entrega');

echo "POS integration OK\n";
