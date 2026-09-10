<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\HubService;
use EventMenu\Services\HubTerminalCatalogService;

function hub_ci_fail(string $message): never { fwrite(STDERR, "HUB CI FAIL: {$message}\n"); exit(1); }
function hub_ci_assert(bool $condition, string $message): void { if (!$condition) hub_ci_fail($message); }

$pdo = Database::connection();
foreach (['payment_terminal_configs','desktop_hardware_bindings','hub_pairing_codes','hub_device_links','hub_commands'] as $table) {
    try { $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1'); }
    catch (Throwable $e) { hub_ci_fail("Tabela ausente ou inválida: {$table} - {$e->getMessage()}"); }
}

$suffix = bin2hex(random_bytes(5));
$slug = 'hub-ci-' . $suffix;
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Hub CI Tenant', $slug]);
$tenantId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')
    ->execute([$tenantId, 'Hub CI Admin', "hub-admin-{$suffix}@example.test", 'x']);
$adminId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"waiter","active")')
    ->execute([$tenantId, 'Hub CI Waiter', "hub-waiter-{$suffix}@example.test", 'x']);
$waiterId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"Hub CI Unidade",1)')->execute([$tenantId, 'hub-' . $suffix]);
$unitId = (int)$pdo->lastInsertId();

$desktopDevice = 'desktop-ci-' . $suffix;
$mobileDevice = 'mobile-ci-' . $suffix;
$desktopHash = hash('sha256', $desktopDevice);
$hardware = json_encode(['default_printer'=>'CI Printer','customer_display'=>'CI Display','pinpad'=>'CI PINPad'], JSON_UNESCAPED_SLASHES);
$pdo->prepare('INSERT INTO desktop_hardware_bindings (tenant_id,unit_id,device_hash,device_label,hardware_json,last_seen_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)')
    ->execute([$tenantId, $unitId, $desktopHash, 'Desktop CI', $hardware]);
$desktopBindingId = (int)$pdo->lastInsertId();

$_SESSION['user_id'] = $adminId;
$_SESSION['tenant_id'] = $tenantId;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'Hub CI Admin';
unset($_SESSION['acting_tenant_id']);

$hub = new HubService();
$pairing = $hub->createPairingCode($unitId, $desktopDevice);
hub_ci_assert(str_starts_with((string)$pairing['qr'], 'EVENTMENU:HUB:'), 'QR de pareamento não foi gerado no formato esperado.');
hub_ci_assert(strtotime((string)$pairing['expires_at']) > time(), 'QR de pareamento já nasceu expirado.');

$_SESSION['user_id'] = $waiterId;
$_SESSION['role'] = 'waiter';
$_SESSION['name'] = 'Hub CI Waiter';
$link = $hub->claimPairing((string)$pairing['qr'], $mobileDevice, 'Celular CI');
hub_ci_assert((int)$link['desktop_binding_id'] === $desktopBindingId, 'Pareamento apontou para computador incorreto.');

$links = $hub->mobileLinks($mobileDevice);
hub_ci_assert(count($links) === 1, 'Celular não recebeu o computador pareado.');
hub_ci_assert(!empty($links[0]['desktop_online']), 'Computador recente não apareceu online.');
hub_ci_assert(($links[0]['hardware']['default_printer'] ?? '') === 'CI Printer', 'Status de periféricos não chegou ao celular.');

$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?,?,?,"counter","ready","unpaid",2500,2500,?)')
    ->execute([bin2hex(random_bytes(20)), $tenantId, $unitId, $waiterId]);
$orderId = (int)$pdo->lastInsertId();

$idempotency = 'hub-ci-print-' . $suffix;
$print = $hub->queueCommand($desktopBindingId, 'print_order', ['order_id'=>$orderId], $idempotency, $mobileDevice);
$printAgain = $hub->queueCommand($desktopBindingId, 'print_order', ['order_id'=>$orderId], $idempotency, $mobileDevice);
hub_ci_assert((int)$print['id'] === (int)$printAgain['id'], 'Idempotência gerou comando duplicado.');

$drawerDenied = false;
try { $hub->queueCommand($desktopBindingId, 'open_drawer', ['reason'=>'CI'], 'hub-ci-drawer-' . $suffix, $mobileDevice); }
catch (RuntimeException) { $drawerDenied = true; }
hub_ci_assert($drawerDenied, 'Garçom conseguiu solicitar abertura de gaveta sem cash.manage.');

$pdo->prepare('INSERT INTO payment_terminal_configs (tenant_id,unit_id,provider,enabled,integration_mode,terminal_label,pinpad_identifier) VALUES (?,?,"generic_tef",1,"local_service","PINPad CI","CI-01")')
    ->execute([$tenantId, $unitId]);
$terminalId = (int)$pdo->lastInsertId();
$terminals = (new HubTerminalCatalogService())->listForUnit($unitId);
hub_ci_assert(count($terminals) === 1 && (int)$terminals[0]['id'] === $terminalId, 'PINPad ativo não apareceu para terminal.request.');

$charge = $hub->queueCommand(
    $desktopBindingId,
    'tef_charge',
    ['order_id'=>$orderId,'terminal_config_id'=>$terminalId,'amount_cents'=>2500,'payment_type'=>'debit','installments'=>1],
    'hub-ci-charge-' . $suffix,
    $mobileDevice,
);
hub_ci_assert((string)$charge['status'] === 'queued', 'Solicitação de PINPad não entrou na fila.');

$paymentBefore = $pdo->prepare('SELECT payment_status FROM orders WHERE id=? AND tenant_id=?');
$paymentBefore->execute([$orderId, $tenantId]);
hub_ci_assert((string)$paymentBefore->fetchColumn() === 'unpaid', 'Fila do PINPad alterou o pagamento antes da confirmação do servidor.');

$desktopQueue = $hub->pollDesktop($desktopDevice, 20);
hub_ci_assert(count($desktopQueue) >= 2, 'Desktop não recebeu os comandos móveis pendentes.');
$claimed = $hub->claimCommand((int)$charge['id'], $desktopDevice);
hub_ci_assert((string)$claimed['status'] === 'claimed', 'Desktop não conseguiu assumir o comando.');
$completed = $hub->completeCommand((int)$charge['id'], $desktopDevice, true, ['approved_local'=>true,'verified'=>false,'message'=>'Aprovado localmente']);
hub_ci_assert((string)$completed['status'] === 'completed', 'Desktop não conseguiu concluir o comando.');

$paymentAfter = $pdo->prepare('SELECT payment_status FROM orders WHERE id=? AND tenant_id=?');
$paymentAfter->execute([$orderId, $tenantId]);
hub_ci_assert((string)$paymentAfter->fetchColumn() === 'unpaid', 'Aprovação local do PINPad marcou o pedido como pago indevidamente.');

$status = $hub->commandStatus((int)$charge['id'], $mobileDevice);
hub_ci_assert(($status['result']['approved_local'] ?? null) === true, 'Resultado local não retornou ao celular.');
hub_ci_assert(($status['result']['verified'] ?? null) === false, 'Comando local foi tratado como pagamento verificado.');

$otherDeviceBlocked = false;
try { $hub->commandStatus((int)$charge['id'], 'other-mobile-' . $suffix); }
catch (RuntimeException) { $otherDeviceBlocked = true; }
hub_ci_assert($otherDeviceBlocked, 'Outro dispositivo conseguiu consultar comando do celular pareado.');

$hub->revokeLink((int)$link['id']);
hub_ci_assert($hub->mobileLinks($mobileDevice) === [], 'Vínculo revogado continuou aparecendo ativo.');

echo "CI EventMenu GO Hub smoke OK\n";
