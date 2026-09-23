<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceAnalyticsService;

function waa_fail(string $message):never{fwrite(STDERR,"WHATSAPP ANALYTICS CI FAIL: {$message}\n");exit(1);}
function waa_assert(bool $condition,string $message):void{if(!$condition)waa_fail($message);}

$pdo=Database::connection();
if(Database::driver($pdo)!=='sqlite')waa_fail('Este smoke exige SQLite.');
$q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_commerce_events'");$q->execute();waa_assert((string)$q->fetchColumn()==='whatsapp_commerce_events','Migração 079 não criou whatsapp_commerce_events.');

$slug='wa-analytics-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['Analytics CI',$slug]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Lanches']);$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,active) VALUES (?,?,?,?,0,1)')->execute([$tenantId,$categoryId,'Combo Analytics',2000]);$productId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Cliente Analytics','(11) 99999-0001','11999990001']);$customerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,customer_id,phone,mode,state,last_inbound_at,last_activity_at) VALUES (?,?,?,'auto','WELCOME',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$tenantId,$customerId,'5511999990001']);$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,provider_message_id,direction,message_type,message_text,status) VALUES (?,?,?,'inbound','text','Oi','received')")->execute([$tenantId,$conversationId,'analytics-ci-'.bin2hex(random_bytes(4))]);

$token=bin2hex(random_bytes(20));
$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pickup','WHATSAPP','completed','paid',2000,0,0,2000)")->execute([$token,$tenantId,$unitId,$customerId]);$paidOrderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)')->execute([$paidOrderId,$productId,'Combo Analytics',2000,2000]);

$token2=bin2hex(random_bytes(20));
$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pending','WHATSAPP','draft','unpaid',2000,0,0,2000)")->execute([$token2,$tenantId,$unitId,$customerId]);$draftId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)')->execute([$draftId,$productId,'Combo Analytics',2000,2000]);

$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'cart_abandoned',?,'Volte ao carrinho','sent',1,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-abandoned-'.$paidOrderId)]);
$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key,sent_at) VALUES (?,?, 'order_received',?,'Pedido recebido','sent',1,5,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-sent-'.$paidOrderId)]);
$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'test_failed',?,'Falhou','desktop_failed',5,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-failed-'.$paidOrderId)]);

$service=new WhatsAppCommerceAnalyticsService();
waa_assert($service->track($pdo,$tenantId,'repeat_order',$conversationId,$paidOrderId,0,['source_order_id'=>1],'ci-repeat-'.$paidOrderId),'Evento de repetição não foi registrado.');
waa_assert(!$service->track($pdo,$tenantId,'repeat_order',$conversationId,$paidOrderId,0,[],'ci-repeat-'.$paidOrderId),'Idempotência dos eventos não bloqueou duplicata.');
waa_assert($service->track($pdo,$tenantId,'upsell_offer',$conversationId,$paidOrderId,0,['product_ids'=>[$productId]],'ci-offer-'.$paidOrderId),'Oferta de upsell não foi registrada.');
waa_assert($service->track($pdo,$tenantId,'upsell_accepted',$conversationId,$paidOrderId,500,['product_id'=>$productId],'ci-accepted-'.$paidOrderId),'Aceite de upsell não foi registrado.');

$report=$service->report($pdo,$tenantId,7);
waa_assert((int)$report['funnel']['conversations']===1,'Funil não contou a conversa do tenant.');
waa_assert((int)$report['funnel']['carts_started']===2,'Funil não contou os dois carrinhos/pedidos originados no WhatsApp.');
waa_assert((int)$report['funnel']['orders_placed']===1&&(int)$report['funnel']['paid_orders']===1,'Funil não separou pedido finalizado e pago.');
waa_assert((int)$report['sales']['paid_sales_cents']===2000,'Vendas pagas não usam somente confirmação real.');
waa_assert((int)$report['recovery']['reminders']===1&&(int)$report['recovery']['recovered']===1,'Recuperação do carrinho não foi calculada pelo pedido real.');
waa_assert((int)$report['sales']['repeat_started']===1&&(int)$report['sales']['repeat_finalized']===1,'Métrica de repetição está incorreta.');
waa_assert((int)$report['upsell']['orders_with_upsell']===1&&(int)$report['upsell']['paid_value_cents']===500,'Métrica de upsell pago está incorreta.');
waa_assert((int)$report['messages']['inbound']===1&&(int)$report['messages']['failed']===1,'Saúde das mensagens está incorreta.');
waa_assert(!empty($report['top_products'])&&(string)$report['top_products'][0]['name']==='Combo Analytics','Ranking de produtos não usa pedidos reais do WhatsApp.');

$route=(string)file_get_contents(dirname(__DIR__).'/app/routes/whatsapp-results.php');
$settings=(string)file_get_contents(dirname(__DIR__).'/app/routes/whatsapp.php');
waa_assert(str_contains($route,'Resultados e conversão')&&str_contains($route,'Valor extra confirmado por sugestões'),'Tela de resultados não contém os indicadores esperados.');
waa_assert(str_contains($settings,'whatsapp-results.php')&&str_contains($settings,'Ver resultados'),'Configuração do WhatsApp não possui acesso ao painel de resultados.');

echo "WhatsApp analytics smoke: OK\n";
