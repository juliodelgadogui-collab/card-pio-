<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
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

fwrite(STDOUT, "CI platform failover smoke OK\n");
