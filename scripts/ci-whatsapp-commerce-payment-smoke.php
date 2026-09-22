<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommercePaymentService;
use EventMenu\Services\WhatsAppCommerceService;
use EventMenu\Services\WhatsAppIntegrationService;

function wa3_fail(string $message):never{fwrite(STDERR,"WHATSAPP COMMERCE STAGE3 CI FAIL: {$message}\n");exit(1);}
function wa3_assert(bool $ok,string $message):void{if(!$ok)wa3_fail($message);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')wa3_fail('Este smoke exige SQLite.');
$slug='wa-stage3-'.bin2hex(random_bytes(4));$settings=['delivery_fee_cents'=>500,'min_delivery_order_cents'=>0,'delivery_pickup_enabled'=>true,'delivery_cash_enabled'=>true,'delivery_paused'=>false];
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['WhatsApp Stage3 CI',$slug,json_encode($settings)]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'WA3 CI',$slug.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='WA3 CI';
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Lanches']);$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,stock_qty,active) VALUES (?,?,?,?,1,10,1)')->execute([$tenantId,$categoryId,'Combo Stage3',2500]);$productId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO unit_inventory (tenant_id,unit_id,product_id,stock_qty,average_cost_cents,min_stock_qty) VALUES (?,?,?,?,0,0)')->execute([$tenantId,$unitId,$productId,10]);
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Cliente Stage3','(11) 98888-3001','11988883001']);$customerId=(int)$pdo->lastInsertId();

$config=Crypto::encryptJson(['pix_enabled'=>true,'pix_default'=>true,'pix_priority'=>1,'card_enabled'=>true,'credit_enabled'=>true,'debit_enabled'=>true,'public_key'=>'TEST-PUBLIC-KEY','access_token'=>'TEST-ACCESS-TOKEN','max_installments'=>6]);
$pdo->prepare("INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,active) VALUES (?,'mercadopago','123456789',?,1)")->execute([$tenantId,$config]);

$deviceId='ci-stage3-'.bin2hex(random_bytes(8));$deviceHash=hash('sha256',$deviceId);$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,engine) VALUES (?,?,?,"connected","baileys")')->execute([$tenantId,$deviceHash,'CI Stage3']);(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);$pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=1 WHERE tenant_id=?')->execute([$tenantId]);
$commerce=new WhatsAppCommerceService();$payment=new WhatsAppCommercePaymentService();$seq=0;
$send=function(string $phone,string $text)use($commerce,$deviceId,&$seq):array{$seq++;return$commerce->receiveInbound($deviceId,['provider_message_id'=>'CI.ST3.'.$seq.'.'.bin2hex(random_bytes(3)),'phone'=>$phone,'name'=>'Cliente Stage3','message_type'=>'text','text'=>$text,'timestamp'=>time(),'payload'=>[]]);};

$makeOperational=function(string $phone,int $total,bool $reserveStock=true)use($pdo,$tenantId,$unitId,$customerId,$productId):array{
    $token=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?,'delivery','WHATSAPP','pending','unpaid',?,0,0,?)")->execute([$token,$tenantId,$unitId,$customerId,$total,$total]);$orderId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,1,?)')->execute([$orderId,$productId,'Combo Stage3',$total,$total]);
    if($reserveStock){$expires=(new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');$pdo->prepare("INSERT INTO stock_reservations (tenant_id,unit_id,order_id,product_id,quantity,status,expires_at) VALUES (?,?,?,?,1,'reserved',?)")->execute([$tenantId,$unitId,$orderId,$productId,$expires]);}
    $pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,customer_id,active_order_id,mode,state,last_activity_at) VALUES (?,?,?,?,'auto','CHOOSING_PAYMENT',CURRENT_TIMESTAMP)")->execute([$tenantId,$phone,$customerId,$orderId]);return['order_id'=>$orderId,'conversation_id'=>(int)$pdo->lastInsertId(),'token'=>$token];
};

// PIX: a conversa coleta perfil mínimo antes de qualquer chamada externa.
$pixOrder=$makeOperational('5511988883001',2500);$menu=$payment->prompt($pdo,$tenantId,$pixOrder['conversation_id'],$pixOrder['order_id']);wa3_assert(($menu['state']??'')==='CHOOSING_PAYMENT','Menu de pagamento não ficou em CHOOSING_PAYMENT.');wa3_assert(str_contains((string)$menu['reply'],'PIX')&&str_contains((string)$menu['reply'],'Cartão')&&str_contains((string)$menu['reply'],'Dinheiro'),'Menu não refletiu os métodos configurados.');
$r=$send('5511988883001','1');wa3_assert(($r['state']??'')==='CHOOSING_PAYMENT','PIX não permaneceu na etapa de pagamento.');$q=$pdo->prepare('SELECT context_json FROM whatsapp_conversations WHERE id=?');$q->execute([$pixOrder['conversation_id']]);$ctx=json_decode((string)$q->fetchColumn(),true);wa3_assert(($ctx['payment_step']??'')==='profile_email','PIX não pediu e-mail quando o perfil estava incompleto.');
$r=$send('5511988883001','email-invalido');$q->execute([$pixOrder['conversation_id']]);$ctx=json_decode((string)$q->fetchColumn(),true);wa3_assert(($ctx['payment_step']??'')==='profile_email','E-mail inválido avançou o fluxo PIX.');
$r=$send('5511988883001','cliente.stage3@example.test');$q->execute([$pixOrder['conversation_id']]);$ctx=json_decode((string)$q->fetchColumn(),true);wa3_assert(($ctx['payment_step']??'')==='profile_document'&&($ctx['payment_email']??'')==='cliente.stage3@example.test','E-mail válido não avançou para CPF/CNPJ.');$q2=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=?');$q2->execute([$tenantId,$pixOrder['order_id']]);wa3_assert((int)$q2->fetchColumn()===0,'Fluxo PIX criou cobrança antes do perfil estar completo.');

// Cartão: somente link seguro, sem coletar PAN/CVV pelo WhatsApp.
$cardOrder=$makeOperational('5511988883002',3200);$payment->prompt($pdo,$tenantId,$cardOrder['conversation_id'],$cardOrder['order_id']);$r=$send('5511988883002','2');wa3_assert(($r['state']??'')==='CHOOSING_PAYMENT','Cartão saiu indevidamente da etapa de pagamento.');$q=$pdo->prepare("SELECT message_text FROM whatsapp_messages WHERE tenant_id=? AND conversation_id=? AND direction='outbound' ORDER BY id DESC LIMIT 1");$q->execute([$tenantId,$cardOrder['conversation_id']]);$cardMessage=(string)$q->fetchColumn();wa3_assert(str_contains($cardMessage,'pedido.php?t='.$cardOrder['token']),'Cartão não enviou o checkout público seguro.');wa3_assert(str_contains($cardMessage,'CVV')&&str_contains($cardMessage,'não são enviados pelo WhatsApp'),'Mensagem de cartão não protegeu dados sensíveis.');$q2->execute([$tenantId,$cardOrder['order_id']]);wa3_assert((int)$q2->fetchColumn()===0,'Selecionar cartão pelo WhatsApp criou cobrança sem tokenização.');

// Dinheiro: preferência + troco, sem criar payment eletrônico, mantendo estoque reservado sem expiração.
$cashOrder=$makeOperational('5511988883003',2700);$payment->prompt($pdo,$tenantId,$cashOrder['conversation_id'],$cashOrder['order_id']);$r=$send('5511988883003','3');$q=$pdo->prepare('SELECT context_json FROM whatsapp_conversations WHERE id=?');$q->execute([$cashOrder['conversation_id']]);$ctx=json_decode((string)$q->fetchColumn(),true);wa3_assert(($ctx['payment_step']??'')==='cash_change','Dinheiro não abriu pergunta de troco.');$r=$send('5511988883003','100,00');wa3_assert(($r['state']??'')==='ORDER_ACTIVE','Dinheiro não concluiu a escolha da forma de pagamento.');$q=$pdo->prepare('SELECT method,provider,change_for_cents,source FROM order_payment_preferences WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$cashOrder['order_id']]);$pref=$q->fetch(PDO::FETCH_ASSOC);wa3_assert($pref&&$pref['method']==='cash'&&$pref['provider']===null&&(int)$pref['change_for_cents']===10000&&$pref['source']==='whatsapp','Preferência de dinheiro/troco não foi gravada corretamente.');$q=$pdo->prepare('SELECT expires_at,status FROM stock_reservations WHERE tenant_id=? AND order_id=?');$q->execute([$tenantId,$cashOrder['order_id']]);$reservation=$q->fetch(PDO::FETCH_ASSOC);wa3_assert($reservation&&$reservation['status']==='reserved'&&$reservation['expires_at']===null,'Dinheiro não manteve a reserva do estoque para cobrança operacional.');$q2->execute([$tenantId,$cashOrder['order_id']]);wa3_assert((int)$q2->fetchColumn()===0,'Escolha por dinheiro criou payment antes do recebimento físico.');

// Confirmação automática: heartbeat/sync enfileira uma única mensagem após payment_status=paid.
$pdo->prepare("UPDATE orders SET payment_status='paid',status='confirmed' WHERE id=? AND tenant_id=?")->execute([$cardOrder['order_id'],$tenantId]);$queued=$payment->syncPaidConversations($pdo,$tenantId,50);wa3_assert($queued===1,'Sincronizador não enfileirou confirmação de pagamento.');$q=$pdo->prepare('SELECT state FROM whatsapp_conversations WHERE id=?');$q->execute([$cardOrder['conversation_id']]);wa3_assert((string)$q->fetchColumn()==='ORDER_ACTIVE','Conversa paga não saiu de CHOOSING_PAYMENT.');$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='commerce_auto' AND message_text LIKE '%PAGAMENTO CONFIRMADO%'");$q->execute([$tenantId,$cardOrder['order_id']]);wa3_assert((int)$q->fetchColumn()===1,'Confirmação automática não entrou uma única vez na outbox do Connect.');$queuedAgain=$payment->syncPaidConversations($pdo,$tenantId,50);wa3_assert($queuedAgain===0,'Sincronizador tentou reenviar confirmação já processada.');$q->execute([$tenantId,$cardOrder['order_id']]);wa3_assert((int)$q->fetchColumn()===1,'Idempotência da confirmação automática falhou.');

// Nenhuma etapa de cartão armazenou PAN/CVV; o smoke garante que não há esses campos no contexto/mensagens criados aqui.
$q=$pdo->prepare('SELECT context_json FROM whatsapp_conversations WHERE id=?');$q->execute([$cardOrder['conversation_id']]);$rawContext=(string)$q->fetchColumn();wa3_assert(!str_contains(mb_strtolower($rawContext),'cvv')&&!str_contains(mb_strtolower($rawContext),'card_number'),'Contexto da conversa armazenou dado bruto de cartão.');

echo "WhatsApp Commerce Stage3 smoke OK\n";
