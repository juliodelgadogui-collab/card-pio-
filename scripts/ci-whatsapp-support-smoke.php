<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use EventMenu\Services\WhatsAppDesktopAgentService;
use EventMenu\Services\WhatsAppSupportService;

function sup_fail(string $m):never{fwrite(STDERR,"WHATSAPP SUPPORT CI FAIL: {$m}\n");exit(1);}
function sup_assert(bool $ok,string $m):void{if(!$ok)sup_fail($m);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')sup_fail('Este smoke exige SQLite.');
$uid='sup'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active","{}")')->execute(['Support CI',$uid]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Support Admin',$uid.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='Support Admin';unset($_SESSION['acting_tenant_id']);
sup_assert(Auth::can('support.manage'),'Administrador não recebeu support.manage.');sup_assert(in_array('support.manage',PermissionCatalog::rolePermissions('attendant'),true),'Atendente não recebeu support.manage.');

$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'principal','Principal']);$unitId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized,email,default_address) VALUES (?,?,?,?,?,?)')->execute([$tenantId,'Cliente Support','(11) 99999-6611','11999996611','support@example.test','Rua do Teste, 100']);$customerId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source) VALUES (?,?,'Principal','Rua do Teste, 100',1,'ci')")->execute([$tenantId,$customerId]);
$token=bin2hex(random_bytes(20));$pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,delivery_fee_cents,total_cents,delivery_address) VALUES (?,?,?,?, 'delivery','WHATSAPP','pending','unpaid',4200,500,4700,'Rua do Teste, 100')")->execute([$token,$tenantId,$unitId,$customerId]);$orderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,NULL,?,4200,1,4200)')->execute([$orderId,'Combo Support']);
$context=json_encode(['draft_order_id'=>$orderId,'fulfillment'=>'delivery','address_text'=>'Rua do Teste, 100','quoted_total_cents'=>4700],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,customer_id,draft_order_id,active_order_id,mode,state,context_json,last_inbound_at,last_activity_at) VALUES (?,?,?,?,?,'waiting_human','WAITING_HUMAN',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")->execute([$tenantId,'5511999996611',$customerId,$orderId,$orderId,$context]);$conversationId=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,provider_message_id,direction,message_type,message_text,status) VALUES (?,?,?,'inbound','text','Quero falar com uma pessoa','received')")->execute([$tenantId,$conversationId,'SUP-IN-'.bin2hex(random_bytes(4))]);

// Outro tenant nunca pode vazar para a caixa de entrada.
$uid2='sup'.bin2hex(random_bytes(4));$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active","{}")')->execute(['Support Other',$uid2]);$otherTenant=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO whatsapp_conversations (tenant_id,phone,mode,state,last_activity_at) VALUES (?,'5522999990000','waiting_human','WAITING_HUMAN',CURRENT_TIMESTAMP)")->execute([$otherTenant]);

$service=new WhatsAppSupportService();
$started=$service->startConversation($pdo,$tenantId,$userId,'(22) 99888-7766','Olá! Atendimento iniciado pela Central.');
sup_assert((int)$started['id']>0&&$started['mode']==='human','Central não conseguiu iniciar conversa manual.');
$q=$pdo->prepare('SELECT phone,mode,assigned_user_id FROM whatsapp_conversations WHERE id=? AND tenant_id=?');$q->execute([(int)$started['id'],$tenantId]);$startedRow=$q->fetch(PDO::FETCH_ASSOC);
sup_assert($startedRow&&$startedRow['phone']==='5522998887766'&&$startedRow['mode']==='human'&&(int)$startedRow['assigned_user_id']===$userId,'Nova conversa não normalizou telefone/atribuição.');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND recipient='5522998887766' AND event_type='support_manual'");$q->execute([$tenantId]);sup_assert((int)$q->fetchColumn()===1,'Primeira mensagem da nova conversa não entrou na outbox.');
$inbox=$service->inbox($pdo,$tenantId,'all','',50);sup_assert(count($inbox)===2,'Inbox não isolou a empresa ou não retornou as duas conversas esperadas.');sup_assert((int)$inbox[0]['unread_count']===1,'Mensagem recebida não apareceu como não lida.');
$unread=$service->inbox($pdo,$tenantId,'unread','',50);sup_assert(count($unread)===1&&(int)$unread[0]['id']===$conversationId,'Filtro de não lidas não retornou a conversa correta.');
$detail=$service->conversation($pdo,$tenantId,$conversationId);sup_assert((int)$detail['conversation']['customer_id']===$customerId,'Painel lateral não trouxe cliente.');sup_assert((int)$detail['active_order']['id']===$orderId&&count($detail['active_order_items'])===1,'Painel lateral não trouxe pedido/itens.');sup_assert(count($detail['addresses'])>=1,'Painel lateral não trouxe endereço.');
$service->markRead($pdo,$tenantId,$conversationId);$unread=$service->inbox($pdo,$tenantId,'unread','',50);sup_assert(count($unread)===0,'Marcação como lida não atualizou a fila.');

$assumed=$service->assume($pdo,$tenantId,$conversationId,$userId);sup_assert($assumed['mode']==='human','Atendimento não foi assumido.');sup_assert($assumed['resume_state']==='CONFIRMING_FULFILLMENT','Estado de retomada do bot não foi inferido/preservado.');
$mine=$service->inbox($pdo,$tenantId,'mine','',50);sup_assert(count($mine)===1&&(int)$mine[0]['assigned_user_id']===$userId,'Filtro Minhas não retornou a conversa assumida.');

$sent=$service->sendManual($pdo,$tenantId,$conversationId,$userId,'Olá! Estou assumindo seu atendimento.');sup_assert((int)$sent['outbox_id']>0,'Resposta manual não criou outbox.');
$q=$pdo->prepare('SELECT event_type,status,recipient FROM whatsapp_outbox WHERE id=? AND tenant_id=?');$q->execute([$sent['outbox_id'],$tenantId]);$outbox=$q->fetch(PDO::FETCH_ASSOC);sup_assert($outbox&&$outbox['event_type']==='support_manual'&&$outbox['status']==='desktop_queued'&&$outbox['recipient']==='5511999996611','Resposta manual não entrou na fila EventMenu Connect.');
$q=$pdo->prepare('SELECT status,outbox_id FROM whatsapp_messages WHERE id=? AND tenant_id=?');$q->execute([$sent['message_id'],$tenantId]);$history=$q->fetch(PDO::FETCH_ASSOC);sup_assert($history&&$history['status']==='queued'&&(int)$history['outbox_id']===(int)$sent['outbox_id'],'Histórico outbound não ficou ligado à fila.');

$agent=new WhatsAppDesktopAgentService();$device='support-ci-device-'.$uid;$agent->heartbeat($device,'Support CI','connected','5511999999999','');$claims=$agent->claim($device,10);$claim=null;foreach($claims as$row)if((int)$row['id']===(int)$sent['outbox_id']){$claim=$row;break;}sup_assert(is_array($claim),'EventMenu Connect não conseguiu reservar a resposta manual.');$agent->acknowledge($device,(int)$claim['id'],(string)$claim['claim_token'],'SUP-OUT-'.bin2hex(random_bytes(4)));
$q=$pdo->prepare('SELECT status,provider_message_id FROM whatsapp_messages WHERE tenant_id=? AND outbox_id=?');$q->execute([$tenantId,$sent['outbox_id']]);$acked=$q->fetch(PDO::FETCH_ASSOC);sup_assert($acked&&$acked['status']==='sent'&&!empty($acked['provider_message_id']),'ACK do Connect não atualizou o histórico da Central.');

$resumed=$service->resumeAutomatic($pdo,$tenantId,$conversationId,$userId);sup_assert($resumed['mode']==='auto'&&$resumed['state']==='CONFIRMING_FULFILLMENT','Retorno ao automático não restaurou o fluxo.');$q=$pdo->prepare('SELECT mode,state,context_json,assigned_user_id,resume_state FROM whatsapp_conversations WHERE id=? AND tenant_id=?');$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);sup_assert($row&&$row['mode']==='auto'&&$row['state']==='CONFIRMING_FULFILLMENT'&&$row['assigned_user_id']===null&&$row['resume_state']===null,'Conversa não foi limpa corretamente ao voltar ao automático.');$restored=json_decode((string)$row['context_json'],true);sup_assert(is_array($restored)&&($restored['address_text']??'')==='Rua do Teste, 100','Contexto do bot não foi restaurado.');
$blocked=false;try{$service->sendManual($pdo,$tenantId,$conversationId,$userId,'Não deve enviar');}catch(RuntimeException){$blocked=true;}sup_assert($blocked,'Envio manual foi permitido com o bot automático ativo.');

// Falha/retry também precisa refletir no histórico da Central.
$service->assume($pdo,$tenantId,$conversationId,$userId);$retry=$service->sendManual($pdo,$tenantId,$conversationId,$userId,'Teste de retry');$claims=$agent->claim($device,10);$retryClaim=null;foreach($claims as$r)if((int)$r['id']===(int)$retry['outbox_id']){$retryClaim=$r;break;}sup_assert(is_array($retryClaim),'Mensagem de retry não foi reservada.');$agent->fail($device,(int)$retryClaim['id'],(string)$retryClaim['claim_token'],'Impressora não; teste de rede do WhatsApp');$q=$pdo->prepare('SELECT status FROM whatsapp_messages WHERE tenant_id=? AND outbox_id=?');$q->execute([$tenantId,$retry['outbox_id']]);sup_assert($q->fetchColumn()==='retrying','Falha transitória do Connect não apareceu como retrying no histórico.');

echo "CI WhatsApp Support smoke OK\n";
