<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceConversionService;
use EventMenu\Services\WhatsAppIntegrationService;

function wac_fail(string $m):never{fwrite(STDERR,"WHATSAPP CONVERSION CI FAIL: {$m}\n");exit(1);}
function wac_assert(bool $ok,string $m):void{if(!$ok)wac_fail($m);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')wac_fail('Este smoke exige SQLite.');
$q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='whatsapp_commerce_conversion_settings'");$q->execute();wac_assert((string)$q->fetchColumn()==='whatsapp_commerce_conversion_settings','Migração 078 não criou configurações de conversão.');

$slug='wa-conv-'.bin2hex(random_bytes(4));$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['Conversão CI',$slug,json_encode(['delivery_pickup_enabled'=>true])]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,0,1)')->execute([$tenantId,'Lanches']);$categoryId=(int)$pdo->lastInsertId();
$insProduct=$pdo->prepare('INSERT INTO products (tenant_id,category_id,name,price_cents,track_stock,active) VALUES (?,?,?,?,0,1)');
$insProduct->execute([$tenantId,$categoryId,'X-Bacon',1200]);$burgerId=(int)$pdo->lastInsertId();
$insProduct->execute([$tenantId,$categoryId,'Refrigerante',500]);$drinkId=(int)$pdo->lastInsertId();
$insProduct->execute([$tenantId,$categoryId,'Sobremesa',700]);$dessertId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'Maria Cliente','(11) 99999-1111','11999991111']);$customerId=(int)$pdo->lastInsertId();

$makeOrder=function(array$items,string$status='completed')use($pdo,$tenantId,$unitId,$customerId):int{
    $subtotal=0;foreach($items as$item)$subtotal+=(int)$item[2];$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pickup','EVENTMENU_OWN',?,'paid',?,0,0,?)")->execute([bin2hex(random_bytes(20)),$tenantId,$unitId,$customerId,$status,$subtotal,$subtotal]);$orderId=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)');foreach($items as$item)$ins->execute([$orderId,(int)$item[0],(string)$item[1],(int)$item[2],(int)$item[2]]);return$orderId;
};
$oldPair=$makeOrder([[$burgerId,'X-Bacon',1000],[$drinkId,'Refrigerante',500]]);$latest=$makeOrder([[$burgerId,'X-Bacon',1000]]);
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,customer_id,phone,mode,state,last_inbound_at,last_activity_at) VALUES (?,?,?,'auto','WELCOME',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$tenantId,$customerId,'5511999991111']);$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare('SELECT * FROM whatsapp_conversations WHERE id=?')->execute([$conversationId]);$conversation=$pdo->query('SELECT * FROM whatsapp_conversations WHERE id='.(int)$conversationId)->fetch(PDO::FETCH_ASSOC);
$service=new WhatsAppCommerceConversionService();$defaults=$service->settings($pdo,$tenantId);wac_assert((int)$defaults['repeat_last_order_enabled']===1&&(int)$defaults['upsell_enabled']===1&&(int)$defaults['abandoned_cart_enabled']===1,'Funções de conversão deveriam nascer ativas.');

$repeat=$service->repeatLastOrder($pdo,$tenantId,$conversation);wac_assert(($repeat['state']??'')==='CART','Repetir último pedido não criou carrinho.');$draftId=(int)($repeat['draft_order_id']??0);wac_assert($draftId>0,'Repetir último pedido não retornou draft.');
$q=$pdo->prepare('SELECT product_id,unit_price_cents,total_cents FROM order_items WHERE order_id=?');$q->execute([$draftId]);$repeated=$q->fetchAll(PDO::FETCH_ASSOC);wac_assert(count($repeated)===1&&(int)$repeated[0]['product_id']===$burgerId,'Repetir não respeitou o pedido mais recente.');wac_assert((int)$repeated[0]['unit_price_cents']===1200&&(int)$repeated[0]['total_cents']===1200,'Repetir pedido não recalculou pelo preço atual.');wac_assert(str_contains((string)$repeat['reply'],'preços atuais'),'Cliente não foi avisado sobre atualização de preços.');

$q=$pdo->prepare('SELECT * FROM whatsapp_conversations WHERE id=?');$q->execute([$conversationId]);$conversation=$q->fetch(PDO::FETCH_ASSOC);$upsell=$service->beginUpsell($pdo,$tenantId,$conversation);wac_assert(($upsell['state']??'')==='UPSELL','Upsell não foi oferecido antes da finalização.');wac_assert(str_contains((string)$upsell['reply'],'Refrigerante'),'Upsell não usou histórico de itens comprados juntos.');
$q->execute([$conversationId]);$conversation=$q->fetch(PDO::FETCH_ASSOC);$afterUpsell=$service->handleUpsell($pdo,$tenantId,$conversation,'1');$count=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND product_id=?');$count->execute([$draftId,$drinkId]);wac_assert((int)$count->fetchColumn()===1,'Produto sugerido não foi adicionado ao carrinho.');wac_assert(in_array((string)($afterUpsell['state']??''),['UPSELL','CHOOSING_FULFILLMENT'],true),'Fluxo não continuou depois do upsell.');

(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);$pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=1 WHERE tenant_id=?')->execute([$tenantId]);
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized) VALUES (?,?,?,?)')->execute([$tenantId,'João Abandono','(11) 98888-2222','11988882222']);$customer2=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pending','WHATSAPP','draft','unpaid',700,0,0,700)")->execute([bin2hex(random_bytes(20)),$tenantId,$unitId,$customer2]);$abandonedDraft=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,1,?,NULL)')->execute([$abandonedDraft,$dessertId,'Sobremesa',700,700]);
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,customer_id,phone,mode,state,draft_order_id,last_inbound_at,last_activity_at) VALUES (?,?,?,'auto','CART',?,datetime('now','-2 hours'),datetime('now','-2 hours'))")->execute([$tenantId,$customer2,'5511988882222',$abandonedDraft]);$abandonedConversation=(int)$pdo->lastInsertId();
$queued=$service->recoverAbandonedCarts($pdo,10);wac_assert($queued===1,'Carrinho abandonado elegível não gerou lembrete.');$q=$pdo->prepare("SELECT event_type,message_text,status FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='cart_abandoned' LIMIT 1");$q->execute([$tenantId,$abandonedDraft]);$reminder=$q->fetch(PDO::FETCH_ASSOC);wac_assert(is_array($reminder)&&$reminder['status']==='desktop_queued','Lembrete não entrou na fila do EventMenu Connect.');wac_assert(str_contains((string)$reminder['message_text'],'MEU PEDIDO')&&str_contains((string)$reminder['message_text'],'R$ 7,00'),'Lembrete não orienta retomada com total atual.');wac_assert($service->recoverAbandonedCarts($pdo,10)===0,'Carrinho abandonado gerou lembrete duplicado.');

$settings=$service->saveSettings($pdo,$tenantId,['repeat_last_order_enabled'=>1,'upsell_enabled'=>0,'upsell_max_suggestions'=>2,'abandoned_cart_enabled'=>0,'abandoned_delay_minutes'=>90]);wac_assert((int)$settings['upsell_enabled']===0&&(int)$settings['abandoned_cart_enabled']===0&&(int)$settings['abandoned_delay_minutes']===90,'Configurações de conversão não foram persistidas.');

$commerce=(string)file_get_contents(dirname(__DIR__).'/src/Services/WhatsAppCommerceService.php');$maintenance=(string)file_get_contents(dirname(__DIR__).'/src/Services/MaintenanceService.php');wac_assert(str_contains($commerce,'WhatsAppCommerceConversionService')&&str_contains($commerce,"state==='UPSELL'"),'Fluxo inbound não integrou repetir/upsell.');wac_assert(str_contains($maintenance,'recoverAbandonedCarts'),'Manutenção não integrou recuperação de carrinho.');

echo "WhatsApp Commerce Conversion smoke: OK\n";
