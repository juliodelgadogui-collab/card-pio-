<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\OrderHistoryService;
use EventMenu\Services\OrderService;
use EventMenu\Services\PaymentService;
use EventMenu\Services\ProductionService;
use EventMenu\Services\WhatsAppCommerceStatusSyncService;
use EventMenu\Services\WhatsAppIntegrationService;

function wa4_fail(string $m):never{fwrite(STDERR,"WHATSAPP COMMERCE STAGE4 CI FAIL: {$m}\n");exit(1);}
function wa4_assert(bool $ok,string $m):void{if(!$ok)wa4_fail($m);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')wa4_fail('Este smoke exige SQLite.');
$uid='wa4'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active","{}")')->execute(['WhatsApp Stage4 CI',$uid]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'WA4 Admin',$uid.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='WA4 Admin';unset($_SESSION['acting_tenant_id']);
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Cozinha']);$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,stock_qty,active) VALUES (?,?,?,?,0,0,1)')->execute([$tenantId,$categoryId,'X Stage4',2500]);$productId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO production_stations (tenant_id,unit_id,code,name,station_type,sla_minutes,sort_order,printer_mode,active) VALUES (?,?,?,?,'kitchen',10,0,'manual',1)")->execute([$tenantId,$unitId,'cozinha','Cozinha']);$stationId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO product_unit_production_profiles (tenant_id,unit_id,product_id,station_id,production_enabled,prep_minutes,print_mode) VALUES (?,?,?,?,1,10,"inherit")')->execute([$tenantId,$unitId,$productId,$stationId]);
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Cliente Stage4','(11) 99999-4404','11999994404']);$customerId=(int)$pdo->lastInsertId();

$whats=new WhatsAppIntegrationService();$whats->ensureConnection($pdo,$tenantId);$templates=[];foreach($whats->defaultTemplates() as $event=>$message)$templates[$event]=['enabled'=>true,'message'=>'S4 '.strtoupper($event).' #{pedido} {status}'];$whats->saveConfiguration($pdo,$tenantId,true,$templates);

$token=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'delivery','WHATSAPP','pending','pending',2500,0,0,2500)")->execute([$token,$tenantId,$unitId,$customerId]);$orderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,1,2500)')->execute([$orderId,$productId,'X Stage4',2500]);
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,customer_id,active_order_id,mode,state,context_json,last_activity_at) VALUES (?,?,?,?, 'auto','CHOOSING_PAYMENT',?,CURRENT_TIMESTAMP)")->execute([$tenantId,'5511999994404',$customerId,$orderId,json_encode(['order_id'=>$orderId,'payment_step'=>'awaiting_payment'])]);$conversationId=(int)$pdo->lastInsertId();
$payKey='wa4-manual-'.$uid;$pdo->prepare("INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?, 'manual',?,2500,'BRL','created')")->execute([$tenantId,$orderId,$payKey]);$paymentId=(int)$pdo->lastInsertId();

(new PaymentService())->confirmVerified(['payment_id'=>$paymentId,'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'manual','provider_payment_id'=>'WA4-'.strtoupper(bin2hex(random_bytes(6))),'amount_cents'=>2500,'currency'=>'BRL','account_reference'=>'manual','source'=>'ci_stage4']);
$q=$pdo->prepare('SELECT status,payment_status FROM orders WHERE id=? AND tenant_id=?');$q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);wa4_assert($order&&$order['status']==='confirmed'&&$order['payment_status']==='paid','Pagamento integral não confirmou o pedido.');
$q=$pdo->prepare('SELECT id,status FROM production_jobs WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$orderId]);$job=$q->fetch(PDO::FETCH_ASSOC);wa4_assert($job&&$job['status']==='received','Pagamento confirmado não criou job normal de produção.');

$statusSync=new WhatsAppCommerceStatusSyncService();$statusSync->syncCurrentTenant($pdo,$tenantId,50);$statusSync->syncCurrentTenant($pdo,$tenantId,50);
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_confirmed'");$q->execute([$tenantId,$orderId]);wa4_assert((int)$q->fetchColumn()===1,'Pedido confirmado pelo pagamento gerou notificação duplicada ou ausente.');
$q=$pdo->prepare("SELECT message_text FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_confirmed' LIMIT 1");$q->execute([$tenantId,$orderId]);wa4_assert(str_contains((string)$q->fetchColumn(),'S4 ORDER_CONFIRMED #'.$orderId),'Template editável de confirmação não foi usado.');

$production=new ProductionService();$production->changeJobStatus((int)$job['id'],'preparing','CI Stage4');
$q=$pdo->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$orderId]);wa4_assert($q->fetchColumn()==='preparing','KDS não sincronizou pedido para preparing.');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='preparing'");$q->execute([$tenantId,$orderId]);wa4_assert((int)$q->fetchColumn()===1,'KDS não gerou exatamente uma mensagem de preparação.');

$production->changeJobStatus((int)$job['id'],'ready','CI Stage4');
$q=$pdo->prepare('SELECT status FROM orders WHERE id=?');$q->execute([$orderId]);wa4_assert($q->fetchColumn()==='ready','KDS não sincronizou pedido para ready.');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='ready'");$q->execute([$tenantId,$orderId]);wa4_assert((int)$q->fetchColumn()===1,'KDS não gerou exatamente uma mensagem de pedido pronto.');

$orderService=new OrderService();$orderService->changeStatus($orderId,'out_for_delivery','panel');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='out_for_delivery'");$q->execute([$tenantId,$orderId]);wa4_assert((int)$q->fetchColumn()===1,'Saída para entrega foi duplicada entre OrderService e histórico.');
$orderService->changeStatus($orderId,'completed','panel');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='delivered'");$q->execute([$tenantId,$orderId]);wa4_assert((int)$q->fetchColumn()===1,'Entrega concluída foi duplicada entre OrderService e histórico.');

// Pedido recebido via WhatsApp: o histórico draft -> pending deve usar o template configurável uma única vez.
$token2=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,total_cents) VALUES (?,?,?,?, 'pickup','WHATSAPP','pending','unpaid',1000,1000)")->execute([$token2,$tenantId,$unitId,$customerId]);$receivedOrder=(int)$pdo->lastInsertId();
(new OrderHistoryService())->record($pdo,$tenantId,$receivedOrder,'draft','pending','whatsapp','Pedido do WhatsApp operacionalizado.');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_received'");$q->execute([$tenantId,$receivedOrder]);wa4_assert((int)$q->fetchColumn()===1,'Pedido recebido não gerou exatamente uma mensagem.');

// Dedupe explícito: dois caminhos internos diferentes para o mesmo lifecycle não criam duas linhas.
$whats->queueOrderEvent($pdo,$tenantId,$receivedOrder,'order_received','status:pending');$whats->queueOrderEvent($pdo,$tenantId,$receivedOrder,'order_received','history:999999');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_received'");$q->execute([$tenantId,$receivedOrder]);wa4_assert((int)$q->fetchColumn()===1,'Chave estável de lifecycle não bloqueou duplicidade.');

// Automação desligada deve impedir novos status sem afetar o pedido.
$pdo->prepare('UPDATE whatsapp_connections SET automation_enabled=0 WHERE tenant_id=?')->execute([$tenantId]);$token3=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,total_cents) VALUES (?,?,?,?, 'pickup','WHATSAPP','pending','unpaid',900,900)")->execute([$token3,$tenantId,$unitId,$customerId]);$offOrder=(int)$pdo->lastInsertId();(new OrderHistoryService())->record($pdo,$tenantId,$offOrder,'draft','pending','whatsapp','Automação desligada.');$q=$pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$offOrder]);wa4_assert((int)$q->fetchColumn()===0,'Automação desligada ainda enfileirou status.');

echo "CI WhatsApp Commerce Stage4 smoke OK\n";
