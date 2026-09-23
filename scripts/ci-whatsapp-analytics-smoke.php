<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceAnalyticsService;

function waa_fail(string $message):never{fwrite(STDERR,"WHATSAPP ANALYTICS CI FAIL: {$message}\n");exit(1);}
function waa_assert(bool $condition,string $message):void{if(!$condition)waa_fail($message);}
$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')waa_fail('Este smoke exige SQLite.');
foreach(['whatsapp_commerce_events','whatsapp_commerce_analytics_settings'] as$table){$q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);waa_assert((string)$q->fetchColumn()===$table,"Tabela {$table} ausente.");}

$slug='wa-analytics-'.bin2hex(random_bytes(4));$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['Analytics CI',$slug]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Lanches']);$categoryId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,active) VALUES (?,?,?,?,0,1)')->execute([$tenantId,$categoryId,'Combo Analytics',2000]);$productId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Cliente Analytics','(11) 99999-0001','11999990001']);$customerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,customer_id,phone,mode,state,last_inbound_at,last_activity_at) VALUES (?,?,?,'auto','WELCOME',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$tenantId,$customerId,'5511999990001']);$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,provider_message_id,direction,message_type,message_text,status) VALUES (?,?,?,'inbound','text','Oi','received')")->execute([$tenantId,$conversationId,'analytics-ci-'.bin2hex(random_bytes(4))]);

$token=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pickup','WHATSAPP','completed','paid',2000,0,0,2000)")->execute([$token,$tenantId,$unitId,$customerId]);$paidOrderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)')->execute([$paidOrderId,$productId,'Combo Analytics',2000,2000]);
$pdo->prepare('UPDATE whatsapp_conversations SET active_order_id=? WHERE id=? AND tenant_id=?')->execute([$paidOrderId,$conversationId,$tenantId]);
$token2=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pending','WHATSAPP','draft','unpaid',2000,0,0,2000)")->execute([$token2,$tenantId,$unitId,$customerId]);$draftId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)')->execute([$draftId,$productId,'Combo Analytics',2000,2000]);

$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key,created_at) VALUES (?,?, 'cart_abandoned',?,'Volte ao carrinho','sent',1,5,CURRENT_TIMESTAMP,?,datetime('now','-10 minutes'))")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-abandoned-'.$paidOrderId)]);
$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key,sent_at) VALUES (?,?, 'order_received',?,'Pedido recebido','sent',1,5,CURRENT_TIMESTAMP,?,CURRENT_TIMESTAMP)")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-sent-'.$paidOrderId)]);
$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'test_failed',?,'Falhou','desktop_failed',5,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$paidOrderId,'5511999990001',hash('sha256','analytics-failed-'.$paidOrderId)]);
$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'test_waiting',?,'Fila','desktop_queued',0,5,CURRENT_TIMESTAMP,?)")->execute([$tenantId,$draftId,'5511999990001',hash('sha256','analytics-wait-'.$draftId)]);

$service=new WhatsAppCommerceAnalyticsService();$saved=$service->saveSettings($pdo,$tenantId,['timezone'=>'America/Sao_Paulo']);waa_assert($saved['timezone']==='America/Sao_Paulo','Fuso do tenant não foi salvo.');
waa_assert($service->track($pdo,$tenantId,'repeat_order',$conversationId,$paidOrderId,0,['source_order_id'=>1],'ci-repeat-'.$paidOrderId),'Evento de repetição não foi registrado.');
waa_assert(!$service->track($pdo,$tenantId,'repeat_order',$conversationId,$paidOrderId,0,[],'ci-repeat-'.$paidOrderId),'Idempotência não bloqueou duplicata.');
waa_assert($service->track($pdo,$tenantId,'upsell_offer',$conversationId,$paidOrderId,0,['product_ids'=>[$productId]],'ci-offer-'.$paidOrderId),'Oferta de upsell não foi registrada.');
waa_assert($service->track($pdo,$tenantId,'upsell_accepted',$conversationId,$paidOrderId,500,['product_id'=>$productId,'product_name'=>'Combo Analytics'],'ci-accepted-'.$paidOrderId),'Aceite de upsell não foi registrado.');
$pdo->prepare("INSERT INTO audit_logs (tenant_id,action,entity_type,entity_id,created_at) VALUES (?,'whatsapp.conversation_waiting_human','whatsapp_conversation',?,datetime('now','-5 minutes'))")->execute([$tenantId,(string)$conversationId]);
$pdo->prepare("INSERT INTO audit_logs (tenant_id,action,entity_type,entity_id,created_at) VALUES (?,'whatsapp.support_assumed','whatsapp_conversation',?,datetime('now','-3 minutes'))")->execute([$tenantId,(string)$conversationId]);
$pdo->prepare("INSERT INTO audit_logs (tenant_id,action,entity_type,entity_id) VALUES (?,'whatsapp.support_message_queued','whatsapp_conversation',?)")->execute([$tenantId,(string)$conversationId]);

$slug2='wa-other-'.bin2hex(random_bytes(4));$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['Outro Tenant',$slug2]);$otherTenant=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$otherTenant,'principal','Principal']);$otherUnit=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,'pickup','WHATSAPP','completed','paid',999900,0,0,999900)")->execute([bin2hex(random_bytes(20)),$otherTenant,$otherUnit]);

$report=$service->report($pdo,$tenantId,'7d');
waa_assert((int)$report['funnel']['conversations']===1,'Funil não contou a conversa do tenant.');
waa_assert((int)$report['funnel']['carts_started']===2&&(int)$report['funnel']['finalized_orders']===1,'Funil não separou carrinho e finalização.');
waa_assert((int)$report['funnel']['paid_orders']===1&&(int)$report['funnel']['completed_orders']===1,'Funil não separou pagamento e conclusão.');
waa_assert((int)$report['sales']['paid_sales_cents']===2000,'Venda misturou tenant ou pagamento não confirmado.');
waa_assert((int)$report['sales']['repeat_completed']===1&&(int)$report['sales']['repeat_paid_sales_cents']===2000,'Resultado real de repetição incorreto.');
waa_assert((int)$report['upsell']['accepted_items']===1&&(int)$report['upsell']['paid_value_cents']===500&&!empty($report['upsell']['top_products']),'Upsell real incorreto.');
waa_assert((int)$report['recovery']['abandoned']===1&&(int)$report['recovery']['recovered']===1&&(int)$report['recovery']['recovered_paid_sales_cents']===2000,'Atribuição de recuperação incorreta.');
waa_assert((int)$report['messages']['waiting']===1&&(int)$report['messages']['failed']===1&&(int)$report['messages']['sent']===2,'Saúde da outbox incorreta.');
waa_assert((int)$report['human']['transferred']===1&&(int)$report['human']['assumed']===1&&(int)$report['human']['manual_messages']===1,'Métricas humanas não usam auditoria real.');
waa_assert((int)$report['human']['average_wait_seconds']>=100,'Tempo de espera humano não foi calculado.');
waa_assert(!empty($report['top_products'])&&(string)$report['top_products'][0]['name']==='Combo Analytics','Ranking de produtos incorreto.');
$today=$service->report($pdo,$tenantId,'today');waa_assert((int)$today['sales']['paid_sales_cents']===2000,'Filtro Hoje incorreto.');
$custom=$service->report($pdo,$tenantId,'custom',date('Y-m-d'),date('Y-m-d'));waa_assert((string)$custom['period']['key']==='custom','Período personalizado não foi aplicado.');

$route=(string)file_get_contents(dirname(__DIR__).'/app/routes/whatsapp-results.php');$settingsRoute=(string)file_get_contents(dirname(__DIR__).'/app/routes/whatsapp.php');
foreach(['Hoje','Ontem','Este mês','Pedido concluído','Valor recuperado pago','Tempo médio até assumir','Pendência mais antiga'] as$needle)waa_assert(str_contains($route,$needle),'Tela não contém '.$needle.'.');
waa_assert(str_contains($settingsRoute,'whatsapp-results.php'),'Configuração do WhatsApp não possui acesso ao painel.');
echo "WhatsApp analytics smoke: OK\n";
