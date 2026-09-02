<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CashRegisterService;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\PaymentService;

function assert_cash(bool $condition,string $message):void{
    if(!$condition){fwrite(STDERR,"Cash integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();$suffix=bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['CI Cash','ci-cash-'.$suffix]);$tenantId=(int)$pdo->lastInsertId();
$hash=password_hash('cash-ci-password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"cashier","active")')->execute([$tenantId,'Caixa CI','cash-'.$suffix.'@example.com',$hash]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='cashier';$_SESSION['name']='Caixa CI';
$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,"Venda caixa",2000,5,1,1)')->execute([$tenantId]);$productId=(int)$pdo->lastInsertId();

$order=(new CounterOrderService())->create([$productId=>1]);
$payment=new PaymentService();$key='cash-ci:'.$tenantId.':'.$order['order_id'];$payment->create((int)$order['order_id'],'manual',$key);
$blocked=false;
try{$payment->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>(int)$order['order_id'],'provider'=>'manual','provider_payment_id'=>'MANUAL-CI-'.$suffix,'amount_cents'=>2000,'currency'=>'BRL','account_reference'=>'manual','manual_method'=>'cash']);}catch(RuntimeException $e){$blocked=str_contains($e->getMessage(),'Abra o caixa');}
assert_cash($blocked,'pagamento manual foi aceito sem caixa aberto');
$status=$pdo->prepare('SELECT payment_status FROM orders WHERE id=?');$status->execute([$order['order_id']]);assert_cash($status->fetchColumn()!=='paid','pedido ficou pago após falha do caixa');

$cash=new CashRegisterService();$session=$cash->open(1000,'Troco inicial');assert_cash($session['status']==='open','caixa não abriu');
$payment->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>(int)$order['order_id'],'provider'=>'manual','provider_payment_id'=>'MANUAL-CI-'.$suffix,'amount_cents'=>2000,'currency'=>'BRL','account_reference'=>'manual','manual_method'=>'cash']);
$status->execute([$order['order_id']]);assert_cash($status->fetchColumn()==='paid','pedido não foi pago após abrir caixa');
$movement=$pdo->prepare('SELECT COUNT(*) FROM cash_movements WHERE tenant_id=? AND order_id=? AND type="sale" AND method="cash" AND amount_cents=2000');$movement->execute([$tenantId,$order['order_id']]);assert_cash((int)$movement->fetchColumn()===1,'venda manual não foi lançada uma única vez');

// Confirmação repetida não pode duplicar movimento.
$payment->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>(int)$order['order_id'],'provider'=>'manual','provider_payment_id'=>'MANUAL-CI-'.$suffix,'amount_cents'=>2000,'currency'=>'BRL','account_reference'=>'manual','manual_method'=>'cash']);
$movement->execute([$tenantId,$order['order_id']]);assert_cash((int)$movement->fetchColumn()===1,'reconfirmação duplicou movimento de caixa');

$current=$cash->current();assert_cash((int)$current['expected_live_cents']===3000,'saldo esperado após venda incorreto');
$cash->addMovement('deposit',500,'Reforço de troco');$cash->addMovement('withdrawal',200,'Sangria teste');
$current=$cash->current();assert_cash((int)$current['expected_live_cents']===3300,'saldo esperado após suprimento/sangria incorreto');
$closed=$cash->close(3200,'Conferência CI');assert_cash($closed['status']==='closed','caixa não fechou');assert_cash((int)$closed['expected_cash_cents']===3300,'esperado de fechamento incorreto');assert_cash((int)$closed['declared_cash_cents']===3200,'declarado incorreto');assert_cash((int)$closed['difference_cents']===-100,'diferença de fechamento incorreta');

echo "Cash integration OK\n";
