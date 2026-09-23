<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceService;
use EventMenu\Services\WhatsAppDesktopAgentService;
use EventMenu\Services\WhatsAppIntegrationService;

function commerce_fail(string $message): never
{
    fwrite(STDERR, "WHATSAPP COMMERCE CI FAIL: {$message}\n");
    exit(1);
}

function commerce_assert(bool $condition, string $message): void
{
    if (!$condition) commerce_fail($message);
}

$pdo = Database::connection();
if (Database::driver($pdo) !== 'sqlite') commerce_fail('Este smoke test exige SQLite.');

$slug = 'wa-commerce-ci-' . bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')
    ->execute(['WhatsApp Commerce CI', $slug]);
$tenantId = (int)$pdo->lastInsertId();
commerce_assert($tenantId > 0, 'Tenant de teste não foi criado.');

$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')
    ->execute([$tenantId, 'WhatsApp Commerce CI', $slug . '@example.test', password_hash('CiPassword123!', PASSWORD_DEFAULT)]);
$userId = (int)$pdo->lastInsertId();
commerce_assert($userId > 0, 'Usuário de teste não foi criado.');

$_SESSION['user_id'] = $userId;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'WhatsApp Commerce CI';

$deviceId = 'ci-connect-' . bin2hex(random_bytes(8));
$deviceHash = hash('sha256', $deviceId);
$pdo->prepare('INSERT INTO whatsapp_desktop_agents (tenant_id,device_hash,device_label,status,engine) VALUES (?,?,?,"connected","baileys")')
    ->execute([$tenantId, $deviceHash, 'CI EventMenu Connect']);

$integration = new WhatsAppIntegrationService();
$connection = $integration->ensureConnection($pdo, $tenantId);
commerce_assert((int)($connection['commerce_enabled'] ?? 0) === 0, 'WhatsApp Commerce deveria nascer desligado.');

$commerce = new WhatsAppCommerceService();
$phone = '5511999988776';
$base = [
    'phone' => $phone,
    'name' => 'Cliente CI',
    'message_type' => 'text',
    'timestamp' => time(),
    'payload' => [],
];
$outboxCount = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=? AND event_type="commerce_auto"');

// OFF: persistir sem responder nem alterar o estado comercial.
$offId = 'CI.OFF.' . bin2hex(random_bytes(8));
$off = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $offId, 'text' => 'menu']);
commerce_assert(empty($off['duplicate']), 'Primeira mensagem foi marcada como duplicada.');
commerce_assert(($off['commerce_enabled'] ?? null) === false, 'Commerce desligado não foi respeitado.');
commerce_assert(empty($off['reply_queued']), 'Commerce desligado enfileirou resposta automática.');

$q = $pdo->prepare('SELECT id,mode,state,assigned_user_id FROM whatsapp_conversations WHERE tenant_id=? AND phone=? LIMIT 1');
$q->execute([$tenantId, $phone]);
$conversation = $q->fetch(PDO::FETCH_ASSOC);
commerce_assert(is_array($conversation), 'Conversa não foi persistida.');
$conversationId = (int)$conversation['id'];
commerce_assert($conversation['mode'] === 'auto' && $conversation['state'] === 'IDLE', 'Commerce desligado alterou estado ou modo da conversa.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 0, 'Commerce desligado criou outbox.');

$duplicate = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $offId, 'text' => 'menu']);
commerce_assert(!empty($duplicate['duplicate']), 'provider_message_id repetido não foi deduplicado.');
$q = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND provider_message_id=?');
$q->execute([$tenantId, $offId]);
commerce_assert((int)$q->fetchColumn() === 1, 'Mensagem duplicada foi persistida mais de uma vez.');

// Este smoke cobre o fluxo normal de um cliente já cadastrado. O primeiro cadastro
// (nome + CPF + confirmação) é coberto separadamente por ci-whatsapp-registration-smoke.php.
$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized,document) VALUES (?,?,?,?,?)')
    ->execute([$tenantId,'Cliente CI',$phone,'11999988776','52998224725']);

// ON: MENU deve mudar para WELCOME e produzir exatamente uma resposta idempotente.
$pdo->prepare('UPDATE whatsapp_connections SET commerce_enabled=1 WHERE tenant_id=?')->execute([$tenantId]);
$menuId = 'CI.MENU.' . bin2hex(random_bytes(8));
$menu = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $menuId, 'text' => 'MENU']);
commerce_assert(($menu['state'] ?? '') === 'WELCOME', 'Comando MENU não levou a WELCOME para cliente cadastrado.');
commerce_assert(!empty($menu['reply_queued']), 'Comando MENU não enfileirou resposta.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 1, 'MENU deveria gerar uma única resposta automática.');

$menuDuplicate = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $menuId, 'text' => 'MENU']);
commerce_assert(!empty($menuDuplicate['duplicate']), 'Reentrega do MENU não foi reconhecida como duplicada.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 1, 'Reentrega do MENU duplicou o outbox.');

// Transferência básica: entra em waiting_human e pausa a automação nas mensagens seguintes.
$humanId = 'CI.HUMAN.' . bin2hex(random_bytes(8));
$human = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $humanId, 'text' => 'Quero falar com atendente']);
commerce_assert(($human['mode'] ?? '') === 'waiting_human', 'Comando de atendente não pausou o bot.');
commerce_assert(($human['state'] ?? '') === 'WAITING_HUMAN', 'Estado WAITING_HUMAN não foi persistido.');
commerce_assert(!empty($human['reply_queued']), 'Transferência humana não confirmou a entrada na fila.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 2, 'Transferência humana deveria gerar apenas uma confirmação adicional.');

$pausedId = 'CI.PAUSED.' . bin2hex(random_bytes(8));
$paused = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $pausedId, 'text' => 'menu']);
commerce_assert(($paused['mode'] ?? '') === 'waiting_human', 'Mensagem enquanto aguardava atendente reativou o bot.');
commerce_assert(empty($paused['reply_queued']), 'Bot respondeu enquanto aguardava atendimento humano.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 2, 'Modo humano criou resposta automática indevida.');

$queue = $commerce->humanQueue($pdo, $tenantId);
commerce_assert(count($queue) === 1 && (int)$queue[0]['id'] === $conversationId, 'Conversa não apareceu na fila humana básica.');
$assumed = $commerce->setHumanMode($pdo, $tenantId, $conversationId, 'human', $userId);
commerce_assert(($assumed['mode'] ?? '') === 'human', 'Funcionário não conseguiu assumir a conversa.');
$q = $pdo->prepare('SELECT mode,state,assigned_user_id FROM whatsapp_conversations WHERE id=? AND tenant_id=?');
$q->execute([$conversationId, $tenantId]);
$humanRow = $q->fetch(PDO::FETCH_ASSOC);
commerce_assert($humanRow && $humanRow['mode'] === 'human' && $humanRow['state'] === 'HUMAN' && (int)$humanRow['assigned_user_id'] === $userId, 'Assumir atendimento não persistiu responsável e modo humano.');

$humanMessageId = 'CI.HUMAN.ACTIVE.' . bin2hex(random_bytes(8));
$humanMessage = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $humanMessageId, 'text' => 'Vocês receberam?']);
commerce_assert(($humanMessage['mode'] ?? '') === 'human' && empty($humanMessage['reply_queued']), 'Bot respondeu enquanto funcionário estava atendendo.');

$resumed = $commerce->setHumanMode($pdo, $tenantId, $conversationId, 'auto', null);
commerce_assert(($resumed['mode'] ?? '') === 'auto', 'Conversa não voltou ao modo automático.');
$q->execute([$conversationId, $tenantId]);
$autoRow = $q->fetch(PDO::FETCH_ASSOC);
commerce_assert($autoRow && $autoRow['mode'] === 'auto' && $autoRow['state'] === 'WELCOME' && $autoRow['assigned_user_id'] === null, 'Voltar ao automático não limpou responsável/estado humano.');

// Comando global de acompanhamento deve ser reconhecido mesmo antes da etapa de criação de pedidos.
$orderCommandId = 'CI.ORDER.' . bin2hex(random_bytes(8));
$orderCommand = $commerce->receiveInbound($deviceId, $base + ['provider_message_id' => $orderCommandId, 'text' => 'acompanhar pedido']);
commerce_assert(!empty($orderCommand['reply_queued']), 'Comando global acompanhar pedido não foi reconhecido.');
$outboxCount->execute([$tenantId]);
commerce_assert((int)$outboxCount->fetchColumn() === 3, 'Comando de acompanhamento deveria gerar apenas uma resposta adicional.');

$q = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND direction="inbound"');
$q->execute([$tenantId]);
commerce_assert((int)$q->fetchColumn() === 6, 'Histórico inbound não preservou exatamente as mensagens únicas.');

// A fila de saída da conversa deve acompanhar claim/ACK do mesmo EventMenu Connect.
$desktop = new WhatsAppDesktopAgentService();
$claimed = $desktop->claim($deviceId, 5);
commerce_assert(count($claimed) === 3, 'EventMenu Connect não recebeu as três respostas automáticas esperadas.');
foreach ($claimed as $index => $row) {
    commerce_assert(($row['event_type'] ?? '') === 'commerce_auto', 'Claim retornou evento inesperado.');
    commerce_assert(!empty($row['claim_token']), 'Claim sem token de reserva.');
    $desktop->acknowledge($deviceId, (int)$row['id'], (string)$row['claim_token'], 'CI-SENT-' . $index . '-' . bin2hex(random_bytes(4)));
}
$q = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND direction="outbound" AND status="sent"');
$q->execute([$tenantId]);
commerce_assert((int)$q->fetchColumn() === 3, 'ACK do Connect não atualizou o histórico outbound.');

$q = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=?');
$q->execute([$tenantId]);
commerce_assert((int)$q->fetchColumn() === 9, 'Quantidade final de mensagens persistidas divergiu.');

echo "WhatsApp Commerce inbound smoke: OK\n";
