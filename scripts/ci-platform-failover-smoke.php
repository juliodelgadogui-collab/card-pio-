<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ClusterNodeConfigService;
use EventMenu\Services\PlatformFailoverService;

function failover_ci_fail(string $message): never
{
    fwrite(STDERR, "FAILOVER CI FAIL: {$message}\n");
    exit(1);
}

function failover_ci_assert(bool $condition, string $message): void
{
    if (!$condition) failover_ci_fail($message);
}

$pdo = Database::connection();
try {
    $pdo->query('SELECT 1 FROM platform_failover_settings LIMIT 1');
} catch (Throwable $e) {
    failover_ci_fail('Tabela platform_failover_settings ausente: ' . $e->getMessage());
}

$service = new PlatformFailoverService();
$settings = $service->get();
failover_ci_assert(isset($settings['primary_url']), 'Configuração não retornou primary_url.');
failover_ci_assert(isset($settings['mode']), 'Configuração não retornou mode.');
failover_ci_assert(in_array((string)$settings['mode'], ['shared_db', 'read_only'], true), 'Modo de contingência inválido.');

$routing = $service->routingConfig();
foreach (['primary_url','contingency_url','enabled','mode','contingency_writable','cluster_id','config_version'] as $key) {
    failover_ci_assert(array_key_exists($key, $routing), 'Roteamento não retornou ' . $key . '.');
}

$health = $service->publicHealth();
failover_ci_assert(($health['ok'] ?? false) === true, 'Health local não retornou ok.');
failover_ci_assert(($health['service'] ?? '') === 'eventmenu-cluster', 'Health local não identificou o cluster EventMenu.');
failover_ci_assert(in_array((string)($health['node_role'] ?? ''), ['primary','contingency'], true), 'Papel do nó inválido.');

// Valida a primeira ativação sem depender de HTTP externo. O token temporário
// deve servir uma vez, o segredo precisa ficar cifrado no storage local e uma
// segunda tentativa deve ser recusada.
$clusterDir = dirname(__DIR__) . '/storage/cluster';
@unlink($clusterDir . '/node-config.json');
@unlink($clusterDir . '/bootstrap-used.json');
$previousRole = getenv('EVENTMENU_NODE_ROLE');
$previousToken = getenv('EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN');
$bootstrapToken = str_repeat('b', 32);
putenv('EVENTMENU_NODE_ROLE=contingency');
$_ENV['EVENTMENU_NODE_ROLE'] = 'contingency';
putenv('EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN=' . $bootstrapToken);
$_ENV['EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN'] = $bootstrapToken;

$clusterId = bin2hex(random_bytes(16));
$clusterSecret = bin2hex(random_bytes(32));
$nonce = bin2hex(random_bytes(16));
$payload = [
    'schema' => 1,
    'cluster_id' => $clusterId,
    'cluster_secret' => $clusterSecret,
    'primary_url' => 'https://primary.example.test/1',
    'mode' => 'read_only',
    'timestamp' => time(),
    'nonce' => $nonce,
];
$raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $raw, $bootstrapToken);
$node = new ClusterNodeConfigService();
$ack = $node->acceptBootstrap($payload, $raw, $signature);
failover_ci_assert(($ack['ok'] ?? false) === true, 'Bootstrap local não retornou ok.');
failover_ci_assert($node->clusterId() === $clusterId, 'Cluster ID não foi persistido no nó.');
failover_ci_assert(hash_equals($clusterSecret, $node->clusterSecret()), 'Segredo do cluster não foi recuperado após criptografia.');
failover_ci_assert($node->mode() === 'read_only', 'Modo do nó não foi persistido.');

$replayBlocked = false;
try { $node->acceptBootstrap($payload, $raw, $signature); }
catch (RuntimeException) { $replayBlocked = true; }
failover_ci_assert($replayBlocked, 'Token de bootstrap pôde ser reutilizado.');

@unlink($clusterDir . '/node-config.json');
@unlink($clusterDir . '/bootstrap-used.json');
if ($previousRole === false || $previousRole === '') { putenv('EVENTMENU_NODE_ROLE'); unset($_ENV['EVENTMENU_NODE_ROLE']); }
else { putenv('EVENTMENU_NODE_ROLE=' . $previousRole); $_ENV['EVENTMENU_NODE_ROLE'] = $previousRole; }
if ($previousToken === false || $previousToken === '') { putenv('EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN'); unset($_ENV['EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN']); }
else { putenv('EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN=' . $previousToken); $_ENV['EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN'] = $previousToken; }

fwrite(STDOUT, "CI platform failover smoke OK\n");
