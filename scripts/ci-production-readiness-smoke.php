<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Services\ProductionReadinessService;

function readiness_fail(string $message): never { fwrite(STDERR, "READINESS CI FAIL: {$message}\n"); exit(1); }
function readiness_assert(bool $ok, string $message): void { if (!$ok) readiness_fail($message); }
function readiness_has(array $items, string $key): bool { foreach ($items as $item) if (($item['key'] ?? null) === $key) return true; return false; }

$base = [
    'checks' => [
        'database' => ['state' => 'ok', 'message' => 'Banco conectado.', 'details' => ['driver' => 'mysql']],
        'storage' => ['state' => 'ok', 'message' => 'Storage gravável.', 'details' => []],
        'cron' => ['state' => 'ok', 'message' => 'Cron ativo.', 'details' => []],
        'worker' => ['state' => 'ok', 'message' => 'Worker ativo.', 'details' => []],
        'queue' => ['state' => 'ok', 'message' => 'Fila em dia.', 'details' => []],
        'backup' => ['state' => 'ok', 'message' => 'Backup verificado.', 'details' => []],
        'gateways' => ['state' => 'warning', 'message' => 'Nenhum gateway.', 'details' => []],
        'push' => ['state' => 'warning', 'message' => 'Push não configurado.', 'details' => []],
        'webhooks' => ['state' => 'ok', 'message' => 'Nenhum webhook recebido ainda.', 'details' => ['last' => null, 'failed_last_24h' => 0]],
    ],
    'app' => [
        'environment' => 'production',
        'debug' => false,
        'url' => 'https://go.example.test',
        'session_secure' => true,
        'key' => 'readiness-ci-key-0123456789-ABCDEFGHIJKLMNOPQRSTUVWXYZ',
    ],
];

$service = new ProductionReadinessService();
$ready = $service->evaluate($base);
readiness_assert($ready['ready'] === true, 'Avisos opcionais bloquearam uma instalação saudável.');
readiness_assert($ready['state'] === 'ready', 'Estado saudável não retornou ready.');
readiness_assert(count($ready['blockers']) === 0, 'Instalação saudável retornou bloqueadores.');
readiness_assert(readiness_has($ready['warnings'], 'gateways'), 'Gateway ausente deveria aparecer como aviso.');
readiness_assert(readiness_has($ready['warnings'], 'push'), 'Push ausente deveria aparecer como aviso.');
readiness_assert(readiness_has($ready['warnings'], 'webhooks'), 'Webhook ainda não comprovado deveria aparecer como aviso.');
readiness_assert(($ready['payments']['ready'] ?? true) === false, 'Pagamento real não pode ficar pronto sem gateway/webhook comprovado.');
readiness_assert(readiness_has($ready['payments']['blockers'] ?? [], 'gateway_live'), 'Gateway ausente não bloqueou pagamentos reais.');
readiness_assert(readiness_has($ready['payments']['blockers'] ?? [], 'webhook_live'), 'Webhook ausente não bloqueou pagamentos reais.');

$blocked = $base;
$blocked['checks']['queue']['state'] = 'warning';
$blocked['app']['debug'] = true;
$blockedResult = $service->evaluate($blocked);
readiness_assert($blockedResult['ready'] === false, 'Fila ruim/debug ligado não bloquearam produção.');
readiness_assert(readiness_has($blockedResult['blockers'], 'queue'), 'Fila ruim não apareceu como bloqueador.');
readiness_assert(readiness_has($blockedResult['blockers'], 'debug'), 'Debug ligado não apareceu como bloqueador.');
readiness_assert(($blockedResult['payments']['ready'] ?? true) === false, 'Pagamentos reais não herdaram bloqueio crítico do sistema.');
readiness_assert(readiness_has($blockedResult['payments']['blockers'] ?? [], 'system_queue'), 'Fila ruim não propagou bloqueio para pagamentos.');
readiness_assert(readiness_has($blockedResult['payments']['blockers'] ?? [], 'system_debug'), 'Debug ligado não propagou bloqueio para pagamentos.');

$http = $base;
$http['app']['url'] = 'http://go.example.test';
$httpResult = $service->evaluate($http);
readiness_assert(!$httpResult['ready'] && readiness_has($httpResult['blockers'], 'https'), 'HTTP não bloqueou produção.');
readiness_assert(readiness_has($httpResult['payments']['blockers'] ?? [], 'system_https'), 'HTTP não bloqueou pagamentos reais.');

$weak = $base;
$weak['app']['key'] = 'change-me';
$weakResult = $service->evaluate($weak);
readiness_assert(!$weakResult['ready'] && readiness_has($weakResult['blockers'], 'app_key'), 'APP_KEY fraca não bloqueou produção.');
readiness_assert(readiness_has($weakResult['payments']['blockers'] ?? [], 'system_app_key'), 'APP_KEY fraca não bloqueou pagamentos reais.');

$sqlite = $base;
$sqlite['checks']['database']['details']['driver'] = 'sqlite';
$sqliteResult = $service->evaluate($sqlite);
readiness_assert($sqliteResult['ready'] === true, 'SQLite não deveria bloquear por si só.');
readiness_assert(readiness_has($sqliteResult['warnings'], 'database_capacity'), 'SQLite deveria gerar aviso de capacidade.');
readiness_assert(readiness_has($sqliteResult['payments']['warnings'] ?? [], 'payment_database_capacity'), 'SQLite deveria gerar aviso específico para pagamentos concorrentes.');

$fullyIntegrated = $base;
$fullyIntegrated['checks']['gateways'] = ['state' => 'ok', 'message' => 'Gateway ativo.', 'details' => ['providers' => [['provider' => 'pagbank', 'qty' => 1]]]];
$fullyIntegrated['checks']['push'] = ['state' => 'ok', 'message' => 'Push configurado.', 'details' => []];
$fullyIntegrated['checks']['webhooks'] = ['state' => 'ok', 'message' => 'Webhooks registrados.', 'details' => ['last' => ['provider' => 'pagbank', 'status' => 'processed'], 'failed_last_24h' => 0]];
$fullResult = $service->evaluate($fullyIntegrated);
readiness_assert($fullResult['ready'] === true, 'Integrações opcionais saudáveis alteraram prontidão incorretamente.');
readiness_assert(count($fullResult['warnings']) === 0, 'Instalação totalmente integrada ainda retornou avisos.');
readiness_assert(($fullResult['payments']['ready'] ?? false) === true, 'Gateway + webhook comprovado não liberaram prontidão de pagamentos.');
readiness_assert(count($fullResult['payments']['blockers'] ?? []) === 0, 'Pagamento integrado retornou bloqueadores indevidos.');

$webhookFailures = $fullyIntegrated;
$webhookFailures['checks']['webhooks']['state'] = 'warning';
$webhookFailures['checks']['webhooks']['details']['failed_last_24h'] = 2;
$failureResult = $service->evaluate($webhookFailures);
readiness_assert(($failureResult['payments']['ready'] ?? false) === true, 'Falha recente isolada não deve apagar comprovação de integração já válida.');
readiness_assert(readiness_has($failureResult['payments']['warnings'] ?? [], 'webhook_failures'), 'Falhas recentes de webhook precisam aparecer como alerta de pagamentos.');

echo "CI production readiness smoke OK\n";
