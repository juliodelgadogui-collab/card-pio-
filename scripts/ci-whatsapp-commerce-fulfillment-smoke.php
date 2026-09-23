<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceService;
use EventMenu\Services\WhatsAppIntegrationService;

function wa2_fail(string $m):never{fwrite(STDERR,"WHATSAPP COMMERCE STAGE2 CI FAIL: {$m}\n");exit(1);}
function wa2_assert(bool $ok,string $m):void{if(!$ok)wa2_fail($m);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')wa2_fail('Este smoke exige SQLite.');
$slug='wa-stage2-'.bin2hex(random_bytes(4));$settings=['delivery_fee_cents'=>700,'min_delivery_order_cents'=>1000,'delivery_pickup_enabled'=>true,'delivery_paused'=>false];
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['WhatsApp Stage2 CI',$slug,json_encode($settings)]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'WA2 CI',$slug.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='WA2 CI';
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Lanches']);$categoryId=(int)$pdo->lastInsertId();

$seedProduct=function(string$name,int$price,float$stock)use($pdo,$tenantId,$categoryId,$unitId):int{
    $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,stock_qty,active) VALUES (?,?,?,?,1,?,1)')->execute([$tenantId,$categoryId,$name,$price,$stock]);$id=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO unit_inventory (tenant_id,unit_id,product_id,stock_qty,average_cost_cents,min_stock_qty) VALUES (?,?,?,?,0,0)')->execute([$tenantId,$unitId,$id,$stock]);return$id;
};
$productDelivery=$seedProduct('Combo Entrega',2000,10);$productPickup=$seedProduct('Combo Retirada',1500,10);$productUnknown=$seedProduct('Combo Novo Cliente',1800,10);$productLow=$seedProduct('Combo Sem Estoque',1600,1);$productCheap=$seedProduct('Combo Abaixo Mínimo',500,10);

$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized,default_address) VALUES (?,?,?,?,?)')->execute([$tenantId,'Cliente Entrega','(11) 99999-0001','11999990001','Rua Antiga, 10, Centro']);$customerDelivery=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Cliente Retirada','(11) 99999-0002','11999990002']);$customerPickup=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source) VALUES (?,?, 'Casa', ?,1,'manual')")->execute([$tenantId,$customerDelivery,'Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ']);$addressId=(int)$pdo->lastInsertId();

$deviceId='ci-stage2-'.bin2hex(random_bytes(8));$deviceHash=hash('sha256',$deviceId);$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,engine) VALUES (?,?,?,"connected","baileys")')->execute([$tenantId,$deviceHash,'CI Stage2']);(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);$pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=1 WHERE tenant_id=?')->execute([$tenantId]);
$commerce=new WhatsAppCommerceService();$seq=0;
$send=function(string$phone,string$text,?string$name=null)use($commerce,$deviceId,&$seq):array{$seq++;return$commerce->receiveInbound($deviceId,['provider_message_id'=>'CI.ST2.'.$seq.'.'.bin2hex(random_bytes(3)),'phone'=>$phone,'name'=>$name??'','message_type'=>'text','text'=>$text,'timestamp'=>time(),'payload'=>[]]);};

$makeDraft=function(string$phone,int$productId,int$qty,?int$customerId)use($pdo,$tenantId,$unitId):array{
    $token=bin2hex(random_bytes(20));$price=$pdo->prepare('SELECT price_cents,name FROM products WHERE id=? AND tenant_id=?');$price->execute([$productId,$tenantId]);$p=$price->fetch(PDO::FETCH_ASSOC);$subtotal=(int)$p['price_cents']*$qty;
    $pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?,'pending','WHATSAPP','draft','unpaid',?,0,0,?)")->execute([$token,$tenantId,$unitId,$customerId,$subtotal,$subtotal]);$orderId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)')->execute([$orderId,$productId,$p['name'],$p['price_cents'],$qty,$subtotal]);
    $ctx=json_encode(['draft_order_id'=>$orderId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,customer_id,draft_order_id,mode,state,context_json,last_activity_at) VALUES (?,?,?,?,'auto','CHOOSING_FULFILLMENT',?,CURRENT_TIMESTAMP)")->execute([$tenantId,$phone,$customerId,$orderId,$ctx]);return['order_id'=>$orderId,'conversation_id'=>(int)$pdo->lastInsertId()];
};

// Entrega com cliente conhecido e endereço salvo.
$delivery=$makeDraft('5511999990001',$productDelivery,1,$customerDelivery);$r=$send('5511999990001','1','Cliente Entrega');wa2_assert(($r['state']??'')==='SELECTING_ADDRESS','Entrega não abriu endereços salvos.');
$r=$send('5511999990001','1');wa2_assert(($r['state']??'')==='CONFIRMING_FULFILLMENT','Endereço salvo não abriu confirmação.');
$r=$send('5511999990001','1');wa2_assert(($r['state']??'')==='CHOOSING_PAYMENT','Entrega não parou na fronteira de pagamento.');
$q=$pdo->prepare('SELECT status,payment_status,channel,order_source,delivery_address,subtotal_cents,delivery_fee_cents,total_cents FROM orders WHERE id=?');$q->execute([$delivery['order_id']]);$o=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($o['status']==='pending'&&$o['payment_status']==='unpaid'&&$o['channel']==='delivery'&&$o['order_source']==='WHATSAPP','Pedido de entrega não virou pedido operacional normal.');wa2_assert($o['delivery_address']==='Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ','Endereço selecionado não foi gravado no pedido.');wa2_assert((int)$o['subtotal_cents']===2000&&(int)$o['delivery_fee_cents']===700&&(int)$o['total_cents']===2700,'Taxa/total de entrega divergiram.');
$q=$pdo->prepare('SELECT quantity,status FROM stock_reservations WHERE tenant_id=? AND order_id=? AND product_id=?');$q->execute([$tenantId,$delivery['order_id'],$productDelivery]);$reservation=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($reservation&&$reservation['status']==='reserved'&&(float)$reservation['quantity']===1.0,'Estoque da entrega não foi reservado.');$q=$pdo->prepare('SELECT stock_qty FROM unit_inventory WHERE tenant_id=? AND unit_id=? AND product_id=?');$q->execute([$tenantId,$unitId,$productDelivery]);wa2_assert((float)$q->fetchColumn()===9.0,'Saldo do estoque da entrega não foi reduzido.');
$q=$pdo->prepare('SELECT draft_order_id,active_order_id,state FROM whatsapp_conversations WHERE id=?');$q->execute([$delivery['conversation_id']]);$c=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($c['draft_order_id']===null&&(int)$c['active_order_id']===$delivery['order_id']&&$c['state']==='CHOOSING_PAYMENT','Conversa não foi vinculada ao pedido ativo.');
$q=$pdo->prepare('SELECT COUNT(*) FROM order_status_history WHERE tenant_id=? AND order_id=? AND from_status="draft" AND to_status="pending" AND source="whatsapp"');$q->execute([$tenantId,$delivery['order_id']]);wa2_assert((int)$q->fetchColumn()===1,'Histórico draft->pending do WhatsApp não foi registrado.');
$q=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$delivery['order_id']]);wa2_assert((int)$q->fetchColumn()===0,'ETAPA 2 criou pagamento.');$q=$pdo->prepare('SELECT COUNT(*) FROM production_jobs WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$delivery['order_id']]);wa2_assert((int)$q->fetchColumn()===0,'ETAPA 2 criou produção.');$q=$pdo->prepare('SELECT COUNT(*) FROM production_print_queue WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$delivery['order_id']]);wa2_assert((int)$q->fetchColumn()===0,'ETAPA 2 criou impressão.');
$q=$pdo->prepare("SELECT metadata FROM audit_logs WHERE tenant_id=? AND action='whatsapp.commerce.order_operational' AND entity_id=? ORDER BY id DESC LIMIT 1");$q->execute([$tenantId,(string)$delivery['order_id']]);$audit=(string)($q->fetchColumn()?:'');wa2_assert($audit!=='','Auditoria da operacionalização não foi criada.');wa2_assert(!str_contains($audit,'Rua das Flores'),'Endereço completo vazou para audit_logs.');

// Cliente novo: só é criado após informar o nome, depois salva endereço no CRM.
$unknown=$makeDraft('5521998880003',$productUnknown,1,null);$r=$send('5521998880003','1','Pessoa Nova');wa2_assert(($r['state']??'')==='ASKING_CUSTOMER_NAME','Cliente desconhecido não foi solicitado a informar o nome.');$r=$send('5521998880003','Pessoa Nova');wa2_assert(($r['state']??'')==='ENTERING_ADDRESS','Novo cliente sem endereço não foi solicitado a informar endereço.');$newAddress='Avenida Brasil, 500, Centro, Itaperuna - RJ';$r=$send('5521998880003',$newAddress);wa2_assert(($r['state']??'')==='CONFIRMING_FULFILLMENT','Novo endereço não avançou para confirmação.');
$q=$pdo->prepare('SELECT customer_id FROM whatsapp_conversations WHERE id=?');$q->execute([$unknown['conversation_id']]);$unknownCustomerId=(int)$q->fetchColumn();wa2_assert($unknownCustomerId>0,'Cliente novo não foi criado após confirmação do nome.');$q=$pdo->prepare('SELECT address_text,is_default,source FROM customer_addresses WHERE tenant_id=? AND customer_id=? ORDER BY id DESC LIMIT 1');$q->execute([$tenantId,$unknownCustomerId]);$saved=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($saved&&$saved['address_text']===$newAddress&&(int)$saved['is_default']===1&&$saved['source']==='whatsapp','Novo endereço não foi salvo no cadastro normal do cliente.');
$r=$send('5521998880003','1');wa2_assert(($r['state']??'')==='CHOOSING_PAYMENT','Novo cliente não conseguiu confirmar pedido.');

// Retirada: sem taxa e sem endereço.
$pickup=$makeDraft('5511999990002',$productPickup,1,$customerPickup);$r=$send('5511999990002','2','Cliente Retirada');wa2_assert(($r['state']??'')==='CONFIRMING_FULFILLMENT','Retirada não abriu confirmação.');$r=$send('5511999990002','1');wa2_assert(($r['state']??'')==='CHOOSING_PAYMENT','Retirada não virou pedido operacional.');$q=$pdo->prepare('SELECT status,channel,delivery_address,delivery_fee_cents,total_cents FROM orders WHERE id=?');$q->execute([$pickup['order_id']]);$o=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($o['status']==='pending'&&$o['channel']==='pickup'&&$o['delivery_address']===null&&(int)$o['delivery_fee_cents']===0&&(int)$o['total_cents']===1500,'Pedido de retirada ficou com dados de entrega incorretos.');

// Falta de estoque deve reverter status, canal e qualquer reserva de forma atômica.
$low=$makeDraft('5511999990004',$productLow,2,$customerPickup);$r=$send('5511999990004','2','Cliente Retirada');wa2_assert(($r['state']??'')==='CONFIRMING_FULFILLMENT','Cenário sem estoque não chegou à confirmação.');$r=$send('5511999990004','1');wa2_assert(($r['state']??'')==='CONFIRMING_FULFILLMENT','Falta de estoque não manteve o pedido em confirmação.');$q=$pdo->prepare('SELECT status,channel,delivery_fee_cents,total_cents FROM orders WHERE id=?');$q->execute([$low['order_id']]);$o=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($o['status']==='draft'&&$o['channel']==='pending'&&(int)$o['delivery_fee_cents']===0,'Rollback de estoque deixou pedido parcialmente operacional.');$q=$pdo->prepare('SELECT COUNT(*) FROM stock_reservations WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$low['order_id']]);wa2_assert((int)$q->fetchColumn()===0,'Rollback deixou reserva parcial.');$q=$pdo->prepare('SELECT stock_qty FROM unit_inventory WHERE tenant_id=? AND unit_id=? AND product_id=?');$q->execute([$tenantId,$unitId,$productLow]);wa2_assert((float)$q->fetchColumn()===1.0,'Rollback alterou o saldo do estoque.');$q=$pdo->prepare('SELECT draft_order_id,active_order_id FROM whatsapp_conversations WHERE id=?');$q->execute([$low['conversation_id']]);$c=$q->fetch(PDO::FETCH_ASSOC);wa2_assert((int)$c['draft_order_id']===$low['order_id']&&$c['active_order_id']===null,'Rollback perdeu o draft ou criou pedido ativo.');

// Pedido abaixo do mínimo de entrega permanece draft.
$cheap=$makeDraft('5511999990005',$productCheap,1,$customerDelivery);$r=$send('5511999990005','1','Cliente Entrega');wa2_assert(($r['state']??'')==='SELECTING_ADDRESS','Pedido barato não abriu seleção de endereço.');$r=$send('5511999990005','1');wa2_assert(!empty($r['validation_error']),'Pedido abaixo do mínimo não foi rejeitado.');$q=$pdo->prepare('SELECT status,channel FROM orders WHERE id=?');$q->execute([$cheap['order_id']]);$o=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($o['status']==='draft'&&$o['channel']==='pending','Pedido abaixo do mínimo deixou de ser draft.');

// Retirada desabilitada não pode avançar.
$settings['delivery_pickup_enabled']=false;$pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode($settings),$tenantId]);$disabled=$makeDraft('5511999990006',$productPickup,1,$customerPickup);$r=$send('5511999990006','2','Cliente Retirada');wa2_assert(($r['state']??'')==='CHOOSING_FULFILLMENT','Retirada desabilitada avançou indevidamente.');$q=$pdo->prepare('SELECT status,channel FROM orders WHERE id=?');$q->execute([$disabled['order_id']]);$o=$q->fetch(PDO::FETCH_ASSOC);wa2_assert($o['status']==='draft'&&$o['channel']==='pending','Retirada desabilitada alterou o draft.');

echo "WhatsApp Commerce Stage 2 fulfillment smoke: OK\n";
