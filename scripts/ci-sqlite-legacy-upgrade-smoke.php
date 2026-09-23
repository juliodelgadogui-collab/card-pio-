<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use PDO;

function legacy_fail(string $message): never
{
    fwrite(STDERR, "LEGACY UPGRADE FAIL: {$message}\n");
    exit(1);
}

function legacy_assert(bool $condition, string $message): void
{
    if (!$condition) legacy_fail($message);
}

$path = sys_get_temp_dir() . '/eventmenu-legacy-upgrade-' . bin2hex(random_bytes(5)) . '.sqlite';
@unlink($path);

try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE operating_units (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,active INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,unit_id INTEGER NULL)');
    $pdo->exec('CREATE TABLE production_stations (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,name TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1,sort_order INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE production_prints (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,station_id INTEGER NOT NULL,order_id INTEGER NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec("CREATE TABLE production_print_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        station_id INTEGER NOT NULL,
        order_id INTEGER NOT NULL,
        queue_type TEXT NOT NULL DEFAULT 'auto',
        status TEXT NOT NULL DEFAULT 'pending',
        requested_by INTEGER NULL,
        reason TEXT NULL,
        payload_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        printed_at TEXT NULL,
        failed_at TEXT NULL
    )");

    $pdo->exec('INSERT INTO operating_units (id,tenant_id,active) VALUES (7,1,1)');
    $pdo->exec('INSERT INTO orders (id,tenant_id,unit_id) VALUES (11,1,7)');
    $pdo->exec("INSERT INTO production_stations (id,tenant_id,name,active,sort_order) VALUES (3,1,'Cozinha',1,0)");
    $pdo->exec("INSERT INTO production_prints (tenant_id,station_id,order_id) VALUES (1,3,11)");
    $pdo->exec("INSERT INTO production_print_queue (tenant_id,station_id,order_id,payload_hash) VALUES (1,3,11,'legacy')");

    $dir = Database::migrationDirectory($pdo);
    $files = glob($dir . '/*.sql') ?: [];
    foreach ($files as $file) {
        $name = basename($file);
        if ($name === '052_production_desktop_print_claim.sql') continue;
        $s = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
        $s->execute([$name]);
    }

    $applied = Migrator::run($pdo);
    legacy_assert(in_array('052_production_desktop_print_claim.sql', $applied, true), 'A migration 052 não foi aplicada.');

    $columns = static function (PDO $pdo, string $table): array {
        return array_map(static fn(array $row): string => (string)$row['name'], $pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll());
    };

    $stationColumns = $columns($pdo, 'production_stations');
    $queueColumns = $columns($pdo, 'production_print_queue');
    $printColumns = $columns($pdo, 'production_prints');

    legacy_assert(in_array('unit_id', $stationColumns, true), 'production_stations.unit_id não foi reparado.');
    legacy_assert(in_array('desktop_device_id', $stationColumns, true), 'production_stations.desktop_device_id não foi criado.');
    legacy_assert(in_array('unit_id', $printColumns, true), 'production_prints.unit_id não foi reparado.');
    foreach (['unit_id','attempts','claimed_at','claimed_device_id','last_error','next_attempt_at','updated_at'] as $column) {
        legacy_assert(in_array($column, $queueColumns, true), 'production_print_queue.' . $column . ' não foi criado.');
    }

    $stationUnit = (int)$pdo->query('SELECT unit_id FROM production_stations WHERE id=3')->fetchColumn();
    $printUnit = (int)$pdo->query('SELECT unit_id FROM production_prints LIMIT 1')->fetchColumn();
    $queue = $pdo->query('SELECT unit_id,updated_at FROM production_print_queue LIMIT 1')->fetch();
    legacy_assert($stationUnit === 7, 'Unidade da estação legada não foi recuperada.');
    legacy_assert($printUnit === 7, 'Unidade da impressão legada não foi recuperada.');
    legacy_assert((int)($queue['unit_id'] ?? 0) === 7, 'Unidade da fila legada não foi recuperada.');
    legacy_assert(trim((string)($queue['updated_at'] ?? '')) !== '', 'updated_at legado não foi preenchido.');

    $migration = $pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration=?');
    $migration->execute(['052_production_desktop_print_claim.sql']);
    legacy_assert((int)$migration->fetchColumn() === 1, 'Migration 052 não foi registrada uma única vez.');

    Migrator::run($pdo);
    $migration->execute(['052_production_desktop_print_claim.sql']);
    legacy_assert((int)$migration->fetchColumn() === 1, 'Segunda execução deixou de ser idempotente.');

    echo "SQLite legacy upgrade smoke OK\n";
} catch (Throwable $e) {
    legacy_fail($e->getMessage() . "\n" . $e->getTraceAsString());
} finally {
    @unlink($path);
    @unlink($path . '-shm');
    @unlink($path . '-wal');
}
