<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CustomerCommunicationService;
use EventMenu\Services\MailSettingsService;
use EventMenu\Services\WhatsAppSettingsService;

function cc_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$pdo=Database::connection();$slug='ci-comm-'.bin2hex(random_bytes(5));$tenantId=0;$customerId=0;$orderId=0;
try{
    $s=$pdo->prepare('INSERT INTO tenants (name,slug,status) VALUES (?,?,"active")');$s->execute(['CI Comunicação',$slug]);$tenantId=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,?,?)');$s->execute([$tenantId,'Cliente Teste','22999998888','cliente@example.test']);$customerId=(int)$pdo->lastInsertId();
    $publicToken=bin2hex(random_bytes(20));$s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,channel,status,payment_status,subtotal_cents,total_cents) VALUES (?,?,?,"delivery","confirmed","unpaid",2500,2500)');$s->execute([$publicToken,$tenantId,$customerId]);$orderId=(int)$pdo->lastInsertId();

    (new MailSettingsService())->save($tenantId,['enabled'=>true,'host'=>'smtp.example.test','port'=>587,'encryption'=>'tls','username'=>'','password'=>'','from_email'=>'pedidos@example.test','from_name'=>'CI EventMenu','reply_to_email'=>'']);
    $wa=new WhatsAppSettingsService();$wa->save($tenantId,['enabled'=>true,'phone_number_id'=>'123456789012345','access_token'=>'ci-secret-whatsapp-token','graph_version'=>'v25.0','confirmation_template'=>'eventmenu_order_confirmation','tracking_template'=>'eventmenu_delivery_tracking','language_code'=>'pt_BR']);
    $effective=$wa->effective($tenantId);cc_assert($effective!==null&&($effective['access_token']??'')==='ci-secret-whatsapp-token','Token do WhatsApp não foi protegido/recuperado corretamente.');

    $comm=new CustomerCommunicationService();$queued=$comm->queueOrderConfirmation($tenantId,$orderId);cc_assert($queued===2,'Confirmação deveria enfileirar e-mail e WhatsApp.');
    $q=$pdo->prepare('SELECT channel,status,recipient_hint FROM customer_communications WHERE tenant_id=? AND order_id=? AND event_type="order_confirmation" ORDER BY channel');$q->execute([$tenantId,$orderId]);$rows=$q->fetchAll();cc_assert(count($rows)===2,'Histórico da confirmação não possui dois canais.');
    foreach($rows as$row){cc_assert($row['status']==='pending','Comunicação configurada deveria ficar pendente até o worker.');cc_assert(!str_contains((string)$row['recipient_hint'],'cliente@example.test')&&!str_contains((string)$row['recipient_hint'],'22999998888'),'Destino completo vazou no histórico.');}

    $q=$pdo->prepare('SELECT payload FROM background_jobs WHERE tenant_id=? AND type="customer.communication" ORDER BY id');$q->execute([$tenantId]);$payloads=$q->fetchAll(PDO::FETCH_COLUMN);cc_assert(count($payloads)===2,'Fila não recebeu os dois canais da confirmação.');
    foreach($payloads as$payload){cc_assert(!str_contains((string)$payload,'cliente@example.test')&&!str_contains((string)$payload,'22999998888')&&!str_contains((string)$payload,$publicToken),'Dados sensíveis foram gravados no payload da confirmação.');}

    $tracking='https://example.test/rastreio.php?t='.str_repeat('a',64);$trackingQueued=$comm->queueDeliveryTracking($tenantId,$orderId,$tracking);cc_assert($trackingQueued===2,'Rastreamento deveria enfileirar e-mail e WhatsApp.');
    $q=$pdo->prepare('SELECT payload FROM background_jobs WHERE tenant_id=? AND type="customer.communication" AND dedupe_key LIKE ? ORDER BY id');$q->execute([$tenantId,'%delivery_tracking%']);$trackingPayloads=$q->fetchAll(PDO::FETCH_COLUMN);cc_assert(count($trackingPayloads)===2,'Fila não recebeu os dois canais do rastreamento.');foreach($trackingPayloads as$payload)cc_assert(!str_contains((string)$payload,$tracking),'Link privado de rastreamento foi salvo sem criptografia na fila.');

    echo "Customer communication smoke: OK\n";
}finally{
    if($tenantId>0){
        try{$pdo->prepare('DELETE FROM background_jobs WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM customer_communications WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM whatsapp_settings WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM mail_settings WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM orders WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM customers WHERE tenant_id=?')->execute([$tenantId]);}catch(Throwable){}
        try{$pdo->prepare('DELETE FROM tenants WHERE id=?')->execute([$tenantId]);}catch(Throwable){}
    }
}
