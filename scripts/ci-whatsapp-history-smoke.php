<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppDesktopAgentService;

function wa_history_fail(string $message): never
{
    fwrite(STDERR, "WHATSAPP HISTORY CI FAIL: {$message}\n");
    exit(1);
}

function wa_history_assert(bool $condition,string $message):void
{
    if(!$condition)wa_history_fail($message);
}

$pdo=Database::connection();
if(Database::driver($pdo)!=='sqlite')wa_history_fail('Este smoke test exige SQLite.');

$slug='wa-history-ci-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['WhatsApp History CI',$slug]);
$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'WhatsApp History CI',$slug.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);
$userId=(int)$pdo->lastInsertId();
wa_history_assert($tenantId>0&&$userId>0,'Tenant/usuário não criado.');

$_SESSION['user_id']=$userId;
$_SESSION['tenant_id']=$tenantId;
$_SESSION['role']='admin';
$_SESSION['name']='WhatsApp History CI';

$deviceId='ci-history-'.bin2hex(random_bytes(8));
$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,engine) VALUES (?,?,?,"connected","baileys")')->execute([$tenantId,hash('sha256',$deviceId),'CI History Connect']);

$phone='5511988877665';
$key=hash('sha256','history|'.$tenantId.'|'.bin2hex(random_bytes(8)));
$pdo->prepare('INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,NULL,"order_confirmed",?,?,"desktop_queued",0,5,CURRENT_TIMESTAMP,?)')->execute([$tenantId,$phone,'✅ Pedido confirmado pelo EventMenu.',$key]);
$outboxId=(int)$pdo->lastInsertId();
wa_history_assert($outboxId>0,'Outbox genérico não criado.');

$service=new WhatsAppDesktopAgentService();
$claimed=$service->claim($deviceId,1);
wa_history_assert(count($claimed)===1,'Mensagem genérica não foi entregue ao claim.');
wa_history_assert((int)$claimed[0]['id']===$outboxId,'Claim retornou mensagem diferente.');

$q=$pdo->prepare('SELECT m.*,c.phone conversation_phone FROM whatsapp_messages m JOIN whatsapp_conversations c ON c.id=m.conversation_id AND c.tenant_id=m.tenant_id WHERE m.tenant_id=? AND m.outbox_id=? LIMIT 1');
$q->execute([$tenantId,$outboxId]);$history=$q->fetch(PDO::FETCH_ASSOC);
wa_history_assert(is_array($history),'Mensagem genérica não foi vinculada ao histórico.');
wa_history_assert($history['direction']==='outbound'&&$history['status']==='sending','Histórico genérico não entrou como outbound/sending.');
wa_history_assert($history['conversation_phone']===$phone,'Histórico foi vinculado à conversa errada.');
$meta=json_decode((string)($history['payload_json']??''),true);
wa_history_assert(is_array($meta)&&($meta['event_type']??'')==='order_confirmed','Histórico não preservou a origem do evento.');

$service->acknowledge($deviceId,$outboxId,(string)$claimed[0]['claim_token'],'CI-HISTORY-SENT-'.bin2hex(random_bytes(4)));
$q=$pdo->prepare('SELECT status,provider_message_id FROM whatsapp_messages WHERE tenant_id=? AND outbox_id=? LIMIT 1');
$q->execute([$tenantId,$outboxId]);$sent=$q->fetch(PDO::FETCH_ASSOC);
wa_history_assert($sent&&$sent['status']==='sent'&&str_starts_with((string)$sent['provider_message_id'],'CI-HISTORY-SENT-'),'ACK não atualizou o histórico genérico.');

echo "WhatsApp generic history smoke: OK\n";
