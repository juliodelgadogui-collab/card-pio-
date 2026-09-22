<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CustomerIdentityService;
use EventMenu\Services\WhatsAppCommerceService;
use EventMenu\Services\WhatsAppIntegrationService;
use EventMenu\Services\WhatsAppSupportService;

function wareg_fail(string $message):never{fwrite(STDERR,"WHATSAPP REGISTRATION CI FAIL: {$message}\n");exit(1);}
function wareg_assert(bool $ok,string $message):void{if(!$ok)wareg_fail($message);}

$pdo=Database::connection();if(Database::driver($pdo)!=='sqlite')wareg_fail('Este smoke exige SQLite.');
$slug='wa-registration-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active","{}")')->execute(['WhatsApp Registration CI',$slug]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Registration Admin',$slug.'@example.test',password_hash('CiPassword123!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='Registration Admin';unset($_SESSION['acting_tenant_id']);

$deviceId='registration-connect-'.bin2hex(random_bytes(8));$deviceHash=hash('sha256',$deviceId);
$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,engine) VALUES (?,?,?,"connected","baileys")')->execute([$tenantId,$deviceHash,'Registration CI Connect']);
(new WhatsAppIntegrationService())->ensureConnection($pdo,$tenantId);$pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=1 WHERE tenant_id=?')->execute([$tenantId]);

$commerce=new WhatsAppCommerceService();$phone='5522998877665';$seq=0;
$send=function(string $text)use($commerce,$deviceId,$phone,&$seq):array{$seq++;return$commerce->receiveInbound($deviceId,['provider_message_id'=>'CI.REG.'.$seq.'.'.bin2hex(random_bytes(3)),'phone'=>$phone,'name'=>'Contato WhatsApp','message_type'=>'text','text'=>$text,'timestamp'=>time(),'payload'=>[]]);};

// Primeiro contato: o telefone vem do WhatsApp e nunca deve ser perguntado ao cliente.
$r=$send('Oi');wareg_assert(($r['state']??'')==='REGISTER_NAME'&&!empty($r['reply_queued']),'Primeiro contato não iniciou cadastro pelo nome.');
$q=$pdo->prepare('SELECT id,customer_id,state,resume_state FROM whatsapp_conversations WHERE tenant_id=? AND phone=? LIMIT 1');$q->execute([$tenantId,$phone]);$conversation=$q->fetch(PDO::FETCH_ASSOC);wareg_assert($conversation&&$conversation['state']==='REGISTER_NAME'&&$conversation['resume_state']==='REGISTER_NAME','Estado inicial do cadastro não foi persistido para retomada.');$conversationId=(int)$conversation['id'];
$q=$pdo->prepare("SELECT message_text FROM whatsapp_messages WHERE tenant_id=? AND conversation_id=? AND direction='outbound' ORDER BY id DESC LIMIT 1");$q->execute([$tenantId,$conversationId]);$firstReply=(string)$q->fetchColumn();wareg_assert(str_contains($firstReply,'número de WhatsApp já foi identificado automaticamente'),'Cadastro voltou a pedir telefone que o Connect já conhece.');

$r=$send('Maria da Silva');wareg_assert(($r['state']??'')==='REGISTER_CPF','Nome não avançou para CPF.');
$q=$pdo->prepare('SELECT c.customer_id,cu.name,cu.phone_normalized,cu.document,cu.default_address FROM whatsapp_conversations c LEFT JOIN customers cu ON cu.id=c.customer_id AND cu.tenant_id=c.tenant_id WHERE c.id=? AND c.tenant_id=?');$q->execute([$conversationId,$tenantId]);$customer=$q->fetch(PDO::FETCH_ASSOC);$customerId=(int)($customer['customer_id']??0);wareg_assert($customerId>0&&$customer['name']==='Maria da Silva'&&$customer['phone_normalized']==='22998877665','Nome/telefone não foram vinculados corretamente ao cadastro.');

$r=$send('11111111111');wareg_assert(($r['state']??'')==='REGISTER_CPF','CPF inválido avançou o cadastro.');$q=$pdo->prepare('SELECT document FROM customers WHERE id=? AND tenant_id=?');$q->execute([$customerId,$tenantId]);wareg_assert(trim((string)$q->fetchColumn())==='','CPF inválido foi persistido.');

$cpf='52998224725';$r=$send($cpf);wareg_assert(($r['state']??'')==='REGISTER_CONFIRM','CPF válido não avançou para confirmação.');$q=$pdo->prepare('SELECT document,default_address FROM customers WHERE id=? AND tenant_id=?');$q->execute([$customerId,$tenantId]);$profile=$q->fetch(PDO::FETCH_ASSOC);wareg_assert(($profile['document']??'')===$cpf,'CPF válido não foi persistido no cliente.');wareg_assert(trim((string)($profile['default_address']??''))==='','Cadastro inicial obrigou endereço antes da escolha de entrega.');
$q=$pdo->prepare("SELECT message_text FROM whatsapp_messages WHERE tenant_id=? AND conversation_id=? AND direction='outbound' ORDER BY id DESC LIMIT 1");$q->execute([$tenantId,$conversationId]);$confirmText=(string)$q->fetchColumn();wareg_assert(str_contains($confirmText,'***.***.***-25')&&!str_contains($confirmText,$cpf),'Confirmação não mascarou o CPF.');wareg_assert(str_contains($confirmText,'somente se você escolher entrega'),'Confirmação não explicou quando o endereço será solicitado.');

// Se o cliente pedir humano no meio do cadastro, a etapa precisa sobreviver ao handoff.
$r=$send('ATENDENTE');wareg_assert(($r['mode']??'')==='waiting_human','Cadastro não permitiu transferência para atendimento humano.');
$support=new WhatsAppSupportService();$assumed=$support->assume($pdo,$tenantId,$conversationId,$userId);wareg_assert(($assumed['resume_state']??'')==='REGISTER_CONFIRM','Handoff perdeu a etapa de confirmação do cadastro.');$resumed=$support->resumeAutomatic($pdo,$tenantId,$conversationId,$userId);wareg_assert(($resumed['state']??'')==='REGISTER_CONFIRM','Retorno ao automático não restaurou a etapa do cadastro.');

$r=$send('1');wareg_assert(($r['state']??'')==='WELCOME'&&!empty($r['registration_complete']),'Confirmação não concluiu o cadastro.');
$q=$pdo->prepare('SELECT state,resume_state,resume_context_json,customer_id FROM whatsapp_conversations WHERE id=? AND tenant_id=?');$q->execute([$conversationId,$tenantId]);$done=$q->fetch(PDO::FETCH_ASSOC);wareg_assert($done&&$done['state']==='WELCOME'&&$done['resume_state']===null&&$done['resume_context_json']===null&&(int)$done['customer_id']===$customerId,'Cadastro concluído deixou estado de retomada residual.');

// CPF fica salvo para o fluxo de pagamento; não pode vazar em mensagens automáticas.
$identity=new CustomerIdentityService();wareg_assert($identity->isValidCpf((string)$profile['document'])&&$identity->maskCpf((string)$profile['document'])==='***.***.***-25','Validação/máscara de CPF divergiu do cadastro.');
$q=$pdo->prepare("SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND conversation_id=? AND direction='outbound' AND message_text LIKE ?");$q->execute([$tenantId,$conversationId,'%'.$cpf.'%']);wareg_assert((int)$q->fetchColumn()===0,'CPF completo vazou em mensagem de saída.');

// Um contato já cadastrado não deve repetir o onboarding.
$r=$send('MENU');wareg_assert(($r['state']??'')==='WELCOME'&&empty($r['registration']),'Cliente já cadastrado voltou indevidamente ao onboarding.');

echo "WhatsApp customer registration smoke OK\n";
