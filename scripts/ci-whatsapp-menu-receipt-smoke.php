<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppOrderReceiptService;
use EventMenu\Services\WhatsAppOrderReceivedSyncService;

function menu_receipt_fail(string $message): never
{
    fwrite(STDERR, "WHATSAPP MENU RECEIPT CI FAIL: {$message}\n");
    exit(1);
}

function menu_receipt_assert(bool $condition,string $message): void
{
    if(!$condition)menu_receipt_fail($message);
}

$pdo=Database::connection();
if(Database::driver($pdo)!=='sqlite')menu_receipt_fail('Este smoke test exige SQLite.');

$slug='wa-menu-receipt-ci-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['Restaurante Comprovante CI',$slug]);
$tenantId=(int)$pdo->lastInsertId();
menu_receipt_assert($tenantId>0,'Tenant de teste não foi criado.');

$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,address,active) VALUES (?,?,?,?,1)')->execute([$tenantId,'CENTRO','Unidade Centro','Rua Teste, 100']);
$unitId=(int)$pdo->lastInsertId();
menu_receipt_assert($unitId>0,'Unidade de teste não foi criada.');

$phone='5511999988776';
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)')->execute([$tenantId,'Cliente Comprovante',$phone]);
$customerId=(int)$pdo->lastInsertId();
menu_receipt_assert($customerId>0,'Cliente de teste não foi criado.');

$token=bin2hex(random_bytes(20));
$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents,delivery_address,created_at) VALUES (?,?,?,?,"delivery","pending","unpaid",4200,200,800,4800,?,CURRENT_TIMESTAMP)')
    ->execute([$token,$tenantId,$unitId,$customerId,'Rua do Cliente, 45']);
$orderId=(int)$pdo->lastInsertId();
menu_receipt_assert($orderId>0,'Pedido de teste não foi criado.');

$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,NULL,?,?,?,?,?)')
    ->execute([$orderId,'X-Burger Especial',2100,2,4200,'Sem cebola']);
$orderItemId=(int)$pdo->lastInsertId();
menu_receipt_assert($orderItemId>0,'Item do pedido não foi criado.');

$service=new WhatsAppOrderReceiptService();
$firstId=$service->queue($pdo,$tenantId,$orderId);
menu_receipt_assert(is_int($firstId)&&$firstId>0,'Comprovante não foi colocado na fila.');

$q=$pdo->prepare("SELECT id,event_type,recipient,message_text,status,idempotency_key FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_received' ORDER BY id");
$q->execute([$tenantId,$orderId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
menu_receipt_assert(count($rows)===1,'O primeiro envio deveria criar exatamente um comprovante.');
$row=$rows[0];
menu_receipt_assert((int)$row['id']===$firstId,'ID retornado não corresponde ao comprovante enfileirado.');
menu_receipt_assert((string)$row['recipient']===$phone,'Destinatário do comprovante está incorreto.');
menu_receipt_assert((string)$row['status']==='desktop_queued','Comprovante não nasceu na fila do EventMenu Connect.');
$message=(string)$row['message_text'];
menu_receipt_assert(str_contains($message,'COMPROVANTE DO PEDIDO'),'Mensagem não contém o título do comprovante.');
menu_receipt_assert(str_contains($message,'Pedido #'.$orderId),'Mensagem não contém o número do pedido.');
menu_receipt_assert(str_contains($message,'X-Burger Especial'),'Mensagem não contém os itens do pedido.');
menu_receipt_assert(str_contains($message,'Total: R$ 48,00'),'Mensagem não contém o total correto.');
menu_receipt_assert(str_contains($message,'Pagamento: Aguardando pagamento'),'Mensagem não informa corretamente que o pagamento ainda não foi confirmado.');
menu_receipt_assert(str_contains($message,'pedido.php?t='.rawurlencode($token)),'Mensagem não contém o link de acompanhamento do pedido.');
menu_receipt_assert(str_contains($message,'Pagamentos só são considerados confirmados'),'Mensagem não preserva a regra de validação real do pagamento.');

$secondId=$service->queue($pdo,$tenantId,$orderId);
menu_receipt_assert($secondId===$firstId,'Nova tentativa não reutilizou o comprovante idempotente.');
$q->execute([$tenantId,$orderId]);
menu_receipt_assert(count($q->fetchAll(PDO::FETCH_ASSOC)?:[])===1,'Nova tentativa duplicou o comprovante.');

// Mesmo que a automação genérica seja ativada depois, o sincronizador deve enxergar
// o order_received já existente e não criar outra mensagem para o mesmo pedido.
$pdo->prepare("UPDATE whatsapp_connections SET automation_enabled=1,automation_enabled_at='2000-01-01 00:00:00' WHERE tenant_id=?")->execute([$tenantId]);
$pdo->prepare("UPDATE whatsapp_event_templates SET enabled=1 WHERE tenant_id=? AND event_type='order_received'")->execute([$tenantId]);
$synced=(new WhatsAppOrderReceivedSyncService())->syncForTenant($pdo,$tenantId,20);
menu_receipt_assert($synced===0,'Sincronização genérica tentou criar um segundo order_received.');
$q->execute([$tenantId,$orderId]);
menu_receipt_assert(count($q->fetchAll(PDO::FETCH_ASSOC)?:[])===1,'Sincronizador duplicou o comprovante do cardápio.');

$publicMenuSource=(string)file_get_contents(dirname(__DIR__).'/src/Services/PublicMenuService.php');
menu_receipt_assert(str_contains($publicMenuSource,'new WhatsAppOrderReceiptService()'),'Checkout público não está ligado ao serviço de comprovante do WhatsApp.');
menu_receipt_assert(str_contains($publicMenuSource,'[whatsapp-menu-receipt]'),'Falha de WhatsApp poderia ficar sem diagnóstico no checkout.');

echo "WhatsApp menu receipt smoke: OK\n";
