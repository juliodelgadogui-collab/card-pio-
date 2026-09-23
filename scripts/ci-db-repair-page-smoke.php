<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Services\DatabaseRepairService;

function fail_repair(string $message): never { fwrite(STDERR, "CI FAIL: {$message}\n"); exit(1); }
function assert_repair(bool $condition, string $message): void { if (!$condition) fail_repair($message); }
function has_column(\PDO $pdo,string $table,string $column):bool { foreach($pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(\PDO::FETCH_ASSOC) as $row) if(strcasecmp((string)$row['name'],$column)===0)return true; return false; }

$path = sys_get_temp_dir() . '/eventmenu-db-repair-' . bin2hex(random_bytes(4)) . '.sqlite';
@unlink($path);
$pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tenants(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,slug TEXT,status TEXT)');
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,name TEXT,email TEXT,password_hash TEXT,role TEXT,status TEXT)');
$pdo->exec('CREATE TABLE migrations(id INTEGER PRIMARY KEY AUTOINCREMENT,migration TEXT NOT NULL UNIQUE,applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("INSERT INTO migrations(migration) VALUES ('021_operating_units.sql'),('034_production_print_queue.sql'),('035_operational_hardening.sql'),('052_production_desktop_print_claim.sql')");
$pdo->exec("INSERT INTO tenants(name,slug,status) VALUES ('Legacy','legacy','active')");
$tenantId=(int)$pdo->lastInsertId();
$pdo->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,status TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE work_shifts(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,status TEXT,started_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE cash_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,status TEXT,opened_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec('CREATE TABLE restaurant_tables(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,status TEXT,name TEXT)');
$pdo->exec('CREATE TABLE production_stations(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,name TEXT,active INTEGER DEFAULT 1,sort_order INTEGER DEFAULT 0)');
$pdo->exec('CREATE TABLE production_prints(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,station_id INTEGER,order_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$pdo->exec("CREATE TABLE production_print_queue(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,station_id INTEGER NOT NULL,order_id INTEGER NOT NULL,queue_type TEXT NOT NULL DEFAULT 'auto',status TEXT NOT NULL DEFAULT 'pending',requested_by INTEGER NULL,reason TEXT NULL,payload_hash TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,printed_at TEXT NULL,failed_at TEXT NULL)");
$pdo->exec("CREATE TABLE delivery_location_events(id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,delivery_user_id INTEGER NOT NULL,shift_id INTEGER NOT NULL,order_id INTEGER NOT NULL,latitude REAL NOT NULL,longitude REAL NOT NULL,device_id_hash TEXT NOT NULL,recorded_at TEXT NOT NULL,received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE delivery_live_locations(tenant_id INTEGER NOT NULL,delivery_user_id INTEGER NOT NULL,shift_id INTEGER NOT NULL,order_id INTEGER NOT NULL,latitude REAL NOT NULL,longitude REAL NOT NULL,recorded_at TEXT NOT NULL,received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,device_id_hash TEXT NOT NULL,PRIMARY KEY(tenant_id,delivery_user_id))");
$pdo->exec("INSERT INTO orders(tenant_id,status) VALUES ({$tenantId},'confirmed')");$orderId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO work_shifts(tenant_id,status) VALUES ({$tenantId},'open')");$shiftId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO users(tenant_id,name,email,password_hash,role,status) VALUES ({$tenantId},'Entregador','driver@example.test','x','delivery','active')");$deliveryUserId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO production_stations(tenant_id,name,active,sort_order) VALUES ({$tenantId},'Cozinha',1,0)");$stationId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO production_print_queue(tenant_id,station_id,order_id,payload_hash) VALUES ({$tenantId},{$stationId},{$orderId},'legacy')");
$pdo->exec("INSERT INTO delivery_location_events(tenant_id,delivery_user_id,shift_id,order_id,latitude,longitude,device_id_hash,recorded_at) VALUES ({$tenantId},{$deliveryUserId},{$shiftId},{$orderId},-21.0,-41.0,'legacy-device','2026-09-19 12:00:00')");
$pdo->exec("INSERT INTO delivery_live_locations(tenant_id,delivery_user_id,shift_id,order_id,latitude,longitude,recorded_at,device_id_hash) VALUES ({$tenantId},{$deliveryUserId},{$shiftId},{$orderId},-21.0,-41.0,'2026-09-19 12:00:00','legacy-device')");

$service = new DatabaseRepairService($pdo,$path);
$before = $service->diagnose();
assert_repair(!$before['healthy'],'Banco legado inconsistente não foi detectado.');
assert_repair(!has_column($pdo,'production_print_queue','unit_id'),'Fixture legado já possui production_print_queue.unit_id indevidamente.');
assert_repair(!has_column($pdo,'delivery_location_events','unit_id'),'Fixture legado já possui delivery_location_events.unit_id indevidamente.');
assert_repair(!has_column($pdo,'delivery_live_locations','unit_id'),'Fixture legado já possui delivery_live_locations.unit_id indevidamente.');

$result = $service->repair();
assert_repair(!empty($result['backup']),'Reparo não criou backup.');
foreach (['unit_id','attempts','claimed_at','claimed_device_id','last_error','next_attempt_at','updated_at'] as $column) assert_repair(has_column($pdo,'production_print_queue',$column),'Coluna não reparada: production_print_queue.'.$column);
assert_repair(has_column($pdo,'production_stations','unit_id'),'production_stations.unit_id não foi reparada.');
assert_repair(has_column($pdo,'production_stations','desktop_device_id'),'desktop_device_id não foi reparada.');
assert_repair(has_column($pdo,'orders','unit_id'),'orders.unit_id não foi reparada.');
assert_repair(has_column($pdo,'delivery_location_events','unit_id'),'delivery_location_events.unit_id não foi reparada.');
assert_repair(has_column($pdo,'delivery_live_locations','unit_id'),'delivery_live_locations.unit_id não foi reparada.');
$unitId=(int)$pdo->query('SELECT unit_id FROM production_print_queue LIMIT 1')->fetchColumn();
assert_repair($unitId>0,'Fila de impressão não recebeu unidade após reparo.');
$gpsUnitId=(int)$pdo->query('SELECT unit_id FROM delivery_location_events LIMIT 1')->fetchColumn();
assert_repair($gpsUnitId>0,'Histórico GPS não recebeu unidade após reparo.');
$liveUnitId=(int)$pdo->query('SELECT unit_id FROM delivery_live_locations LIMIT 1')->fetchColumn();
assert_repair($liveUnitId>0,'GPS ao vivo não recebeu unidade após reparo.');
$after = $service->diagnose();
assert_repair($after['healthy'],'Diagnóstico continuou inconsistente após reparo: '.implode(' | ',$after['issues']??[]));

@unlink($path);
echo "CI DB repair page smoke OK\n";
