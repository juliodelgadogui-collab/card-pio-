<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseRepairService
{
    public function __construct(private ?PDO $pdo = null, private ?string $sqlitePath = null)
    {
        $this->pdo ??= Database::connection();
    }

    public function diagnose(): array
    {
        $pdo = $this->pdo;
        $driver = Database::driver($pdo);
        $result = [
            'driver' => $driver,
            'integrity' => null,
            'healthy' => true,
            'issues' => [],
            'checks' => [],
            'migrations' => [],
        ];

        if ($driver !== 'sqlite') {
            $result['checks'][] = ['label' => 'Banco de dados', 'ok' => true, 'detail' => 'Driver ' . $driver . '. O reparo automático desta página é exclusivo para SQLite.'];
            return $result;
        }

        try {
            $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        } catch (Throwable $e) {
            $integrity = 'Falha ao executar integrity_check: ' . $e->getMessage();
        }
        $result['integrity'] = $integrity;
        $this->check($result, 'Integridade SQLite', $integrity === 'ok', $integrity === 'ok' ? 'PRAGMA integrity_check = ok' : $integrity);

        foreach ([
            '021_operating_units.sql',
            '034_production_print_queue.sql',
            '035_operational_hardening.sql',
            '052_production_desktop_print_claim.sql',
            '053_delivery_location_tracking.sql',
            '054_delivery_public_tracking.sql',
            '055_eventmenu_delivery_marketplace.sql',
            '056_marketplace_entry_tokens.sql',
            '057_marketplace_campaign_assignments.sql',
        ] as $migration) {
            $result['migrations'][$migration] = $this->migrationApplied($migration);
        }

        foreach (['tenants','orders','operating_units','production_stations','production_print_queue'] as $table) {
            $exists = $this->tableExists($table);
            $this->check($result, 'Tabela ' . $table, $exists, $exists ? 'Existe.' : 'Ausente.');
        }

        $requiredColumns = [
            'orders' => ['unit_id'],
            'work_shifts' => ['unit_id'],
            'cash_sessions' => ['unit_id'],
            'restaurant_tables' => ['unit_id'],
            'production_stations' => ['unit_id','desktop_device_id'],
            'production_prints' => ['unit_id'],
            'production_print_queue' => ['unit_id','attempts','claimed_at','claimed_device_id','last_error','next_attempt_at','updated_at'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            if (!$this->tableExists($table)) continue;
            foreach ($columns as $column) {
                $ok = $this->columnExists($table, $column);
                $this->check($result, $table . '.' . $column, $ok, $ok ? 'Existe.' : 'Coluna ausente.');
            }
        }

        $trackingColumns = [
            'delivery_location_events' => ['tenant_id','delivery_user_id','shift_id','unit_id','order_id','recorded_at'],
            'delivery_live_locations' => ['tenant_id','delivery_user_id','shift_id','unit_id','order_id','received_at'],
        ];
        foreach ($trackingColumns as $table => $columns) {
            if (!$this->tableExists($table)) continue;
            foreach ($columns as $column) {
                $ok = $this->columnExists($table, $column);
                $this->check($result, $table . '.' . $column, $ok, $ok ? 'Existe.' : 'Coluna ausente na estrutura legada de GPS/Delivery.');
            }
        }

        if ($this->migrationApplied('035_operational_hardening.sql') && $this->tableExists('production_print_queue') && !$this->columnExists('production_print_queue', 'unit_id')) {
            $this->check($result, 'Consistência da migration 035', false, 'A migration 035 está registrada como aplicada, mas production_print_queue.unit_id não existe.');
        }
        if ($this->migrationApplied('052_production_desktop_print_claim.sql') && $this->tableExists('production_print_queue') && !$this->columnExists('production_print_queue', 'updated_at')) {
            $this->check($result, 'Consistência da migration 052', false, 'A migration 052 está registrada como aplicada, mas production_print_queue.updated_at não existe.');
        }
        if ($this->tableExists('delivery_location_events') && !$this->columnExists('delivery_location_events', 'unit_id')) {
            $this->check($result, 'Compatibilidade da migration 053', false, 'delivery_location_events já existe, mas não possui unit_id. A migration 053 falharia ao criar o índice por unidade.');
        }
        if ($this->tableExists('delivery_live_locations') && !$this->columnExists('delivery_live_locations', 'unit_id')) {
            $this->check($result, 'Compatibilidade da migration 053 (tempo real)', false, 'delivery_live_locations já existe, mas não possui unit_id. A migration 053 falharia ao criar o índice por unidade.');
        }

        return $result;
    }

    public function repair(): array
    {
        $pdo = $this->pdo;
        if (!Database::isSqlite($pdo)) throw new RuntimeException('O reparo automático desta página é exclusivo para SQLite.');

        $integrity = (string)$pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') throw new RuntimeException('O SQLite informou problema de integridade física: ' . $integrity . '. O reparo estrutural foi bloqueado para proteger os dados.');

        $backup = $this->backupSqlite();
        $actions = [];
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');

            if (!$this->tableExists('operating_units')) {
                $pdo->exec("CREATE TABLE operating_units (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,code TEXT NOT NULL,name TEXT NOT NULL,address TEXT NULL,active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,UNIQUE (tenant_id,code))");
                $actions[] = 'Tabela operating_units criada.';
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_operating_unit_active ON operating_units(tenant_id,active,name)');
            if ($this->tableExists('tenants')) {
                $pdo->exec("INSERT INTO operating_units (tenant_id,code,name,address,active) SELECT t.id,'principal','Principal',NULL,1 FROM tenants t WHERE NOT EXISTS (SELECT 1 FROM operating_units ou WHERE ou.tenant_id=t.id)");
            }

            foreach (['work_shifts','orders','cash_sessions','restaurant_tables'] as $table) {
                if ($this->tableExists($table) && !$this->columnExists($table, 'unit_id')) {
                    $this->addColumn($table, 'unit_id', 'INTEGER NULL');
                    $actions[] = $table . '.unit_id criado.';
                }
                if ($this->tableExists($table) && $this->columnExists($table, 'tenant_id') && $this->columnExists($table, 'unit_id')) {
                    $pdo->exec('UPDATE "' . $table . '" SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id="' . $table . '".tenant_id) WHERE unit_id IS NULL');
                }
            }
            if ($this->tableExists('orders')) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_order_unit ON orders(tenant_id,unit_id,status,created_at)');
            if ($this->tableExists('work_shifts')) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_work_shift_unit ON work_shifts(tenant_id,unit_id,status,started_at)');
            if ($this->tableExists('cash_sessions')) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cash_session_unit ON cash_sessions(tenant_id,unit_id,status,opened_at)');
            if ($this->tableExists('restaurant_tables')) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_restaurant_table_unit ON restaurant_tables(tenant_id,unit_id,status,name)');

            if ($this->tableExists('production_stations')) {
                if (!$this->columnExists('production_stations', 'unit_id')) { $this->addColumn('production_stations','unit_id','INTEGER NULL'); $actions[]='production_stations.unit_id criado.'; }
                if (!$this->columnExists('production_stations', 'desktop_device_id')) { $this->addColumn('production_stations','desktop_device_id','TEXT NULL'); $actions[]='production_stations.desktop_device_id criado.'; }
                $pdo->exec('UPDATE production_stations SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_stations.tenant_id) WHERE unit_id IS NULL');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_station_unit ON production_stations(tenant_id,unit_id,active,sort_order,name)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active)');
            }

            if ($this->tableExists('production_prints')) {
                if (!$this->columnExists('production_prints', 'unit_id')) { $this->addColumn('production_prints','unit_id','INTEGER NULL'); $actions[]='production_prints.unit_id criado.'; }
                $pdo->exec('UPDATE production_prints SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_prints.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_prints.tenant_id)) WHERE unit_id IS NULL');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_print_unit ON production_prints(tenant_id,unit_id,created_at)');
            }

            if (!$this->tableExists('production_print_queue') && $this->tableExists('production_stations') && $this->tableExists('orders')) {
                $pdo->exec("CREATE TABLE production_print_queue (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,unit_id INTEGER NULL,station_id INTEGER NOT NULL,order_id INTEGER NOT NULL,queue_type TEXT NOT NULL DEFAULT 'auto',status TEXT NOT NULL DEFAULT 'pending',requested_by INTEGER NULL,reason TEXT NULL,payload_hash TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,printed_at TEXT NULL,failed_at TEXT NULL,attempts INTEGER NOT NULL DEFAULT 0,claimed_at TEXT NULL,claimed_device_id TEXT NULL,last_error TEXT NULL,next_attempt_at TEXT NULL,updated_at TEXT NULL,FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,FOREIGN KEY (station_id) REFERENCES production_stations(id) ON DELETE RESTRICT,FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL)");
                $actions[] = 'Tabela production_print_queue criada.';
            }

            if ($this->tableExists('production_print_queue')) {
                $columns = [
                    'unit_id'=>'INTEGER NULL','attempts'=>'INTEGER NOT NULL DEFAULT 0','claimed_at'=>'TEXT NULL','claimed_device_id'=>'TEXT NULL','last_error'=>'TEXT NULL','next_attempt_at'=>'TEXT NULL','updated_at'=>'TEXT NULL',
                ];
                foreach ($columns as $column => $definition) {
                    if (!$this->columnExists('production_print_queue', $column)) { $this->addColumn('production_print_queue',$column,$definition); $actions[]='production_print_queue.'.$column.' criado.'; }
                }
                $pdo->exec('UPDATE production_print_queue SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_print_queue.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_print_queue.tenant_id)) WHERE unit_id IS NULL');
                $pdo->exec('UPDATE production_print_queue SET updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP) WHERE updated_at IS NULL');
                $pdo->exec('CREATE TRIGGER IF NOT EXISTS trg_prod_print_queue_updated_at_insert AFTER INSERT ON production_print_queue FOR EACH ROW WHEN NEW.updated_at IS NULL BEGIN UPDATE production_print_queue SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_queue_unit ON production_print_queue(tenant_id,unit_id,status,created_at)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at)');
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_prod_auto_print_order_station ON production_print_queue(tenant_id,station_id,order_id,queue_type) WHERE queue_type='auto'");
            }

            $this->repairDeliveryTrackingUnitScope($actions);

            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['backup' => basename($backup), 'actions' => $actions, 'diagnosis' => $this->diagnose()];
    }

    private function repairDeliveryTrackingUnitScope(array &$actions): void
    {
        $pdo = $this->pdo;
        $tables = ['delivery_location_events','delivery_live_locations'];
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) continue;
            if (!$this->columnExists($table, 'unit_id')) {
                $this->addColumn($table, 'unit_id', 'INTEGER NULL');
                $actions[] = $table . '.unit_id criado.';
            }
            if (!$this->columnExists($table, 'tenant_id') || !$this->columnExists($table, 'unit_id')) continue;

            $parts = [];
            if ($this->columnExists($table, 'order_id') && $this->tableExists('orders') && $this->columnExists('orders', 'unit_id')) {
                $parts[] = '(SELECT o.unit_id FROM orders o WHERE o.id="' . $table . '".order_id)';
            }
            if ($this->columnExists($table, 'shift_id') && $this->tableExists('work_shifts') && $this->columnExists('work_shifts', 'unit_id')) {
                $parts[] = '(SELECT ws.unit_id FROM work_shifts ws WHERE ws.id="' . $table . '".shift_id)';
            }
            $parts[] = '(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id="' . $table . '".tenant_id)';
            $pdo->exec('UPDATE "' . $table . '" SET unit_id=COALESCE(' . implode(',', $parts) . ') WHERE unit_id IS NULL');
        }

        if ($this->columnsExist('delivery_location_events', ['tenant_id','order_id','recorded_at'])) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_location_route ON delivery_location_events(tenant_id,order_id,recorded_at)');
        }
        if ($this->columnsExist('delivery_location_events', ['tenant_id','unit_id','delivery_user_id','recorded_at'])) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_location_user ON delivery_location_events(tenant_id,unit_id,delivery_user_id,recorded_at)');
        }
        if ($this->columnsExist('delivery_live_locations', ['tenant_id','unit_id','received_at'])) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_live_unit ON delivery_live_locations(tenant_id,unit_id,received_at)');
        }
        if ($this->columnsExist('delivery_live_locations', ['tenant_id','order_id','received_at'])) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_delivery_live_order ON delivery_live_locations(tenant_id,order_id,received_at)');
        }
    }

    private function backupSqlite(): string
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/storage/backups';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar storage/backups.');
        $target = $dir . '/db-repair-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        try {
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($target));
        } catch (Throwable) {
            $path = $this->resolveSqlitePath();
            if (!is_file($path)) throw new RuntimeException('Não foi possível localizar o arquivo SQLite para criar backup antes do reparo.');
            try { $this->pdo->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Throwable) {}
            if (!copy($path, $target)) throw new RuntimeException('Falha ao criar backup do SQLite. O reparo foi cancelado.');
        }
        if (!is_file($target) || filesize($target) === 0) throw new RuntimeException('O backup gerado ficou vazio. O reparo foi cancelado.');
        @chmod($target, 0640);
        return $target;
    }

    private function resolveSqlitePath(): string
    {
        if ($this->sqlitePath !== null && $this->sqlitePath !== '') return $this->sqlitePath;
        $path = (string)env('DB_SQLITE_PATH', 'storage/eventmenu.sqlite');
        if ($path !== ':memory:' && !str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) $path = dirname(__DIR__, 2) . '/' . ltrim($path, '/\\');
        return $path;
    }

    private function addColumn(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) return;
        $this->pdo->exec('ALTER TABLE "' . str_replace('"','""',$table) . '" ADD COLUMN "' . str_replace('"','""',$column) . '" ' . $definition);
    }

    private function tableExists(string $table): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $s->execute([$table]);
        return (bool)$s->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!$this->tableExists($table)) return false;
        $tableSql = str_replace('"','""',$table);
        foreach ($this->pdo->query('PRAGMA table_info("' . $tableSql . '")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name']) && strcasecmp((string)$row['name'], $column) === 0) return true;
        }
        return false;
    }

    private function columnsExist(string $table, array $columns): bool
    {
        if (!$this->tableExists($table)) return false;
        foreach ($columns as $column) if (!$this->columnExists($table, (string)$column)) return false;
        return true;
    }

    private function migrationApplied(string $name): bool
    {
        if (!$this->tableExists('migrations')) return false;
        $s = $this->pdo->prepare('SELECT 1 FROM migrations WHERE migration=? LIMIT 1');
        $s->execute([$name]);
        return (bool)$s->fetchColumn();
    }

    private function check(array &$result, string $label, bool $ok, string $detail): void
    {
        $result['checks'][] = ['label'=>$label,'ok'=>$ok,'detail'=>$detail];
        if (!$ok) { $result['healthy'] = false; $result['issues'][] = $label . ': ' . $detail; }
    }
}
