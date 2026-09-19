<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function run(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (Database::isSqlite($pdo)) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            self::repairSqliteLegacyDrift($pdo);
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $dir = Database::migrationDirectory($pdo);
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            $stmt = $pdo->prepare('SELECT 1 FROM migrations WHERE migration=?');
            $stmt->execute([$name]);
            if ($stmt->fetchColumn()) continue;

            if (Database::isSqlite($pdo) && $name === '052_production_desktop_print_claim.sql') {
                self::prepareSqliteProductionPrintUpgrade($pdo);
            }

            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Não foi possível ler a migração ' . $name);

            $transactional = Database::isSqlite($pdo) && !$pdo->inTransaction();
            if ($transactional) $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
                $stmt->execute([$name]);
                if ($transactional) $pdo->commit();
                $applied[] = $name;
            } catch (\Throwable $e) {
                if ($transactional && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $applied;
    }

    private static function repairSqliteLegacyDrift(PDO $pdo): void
    {
        if (!self::migrationApplied($pdo, '035_operational_hardening.sql')) return;
        self::ensureProductionUnitColumns($pdo);

        if (self::migrationApplied($pdo, '052_production_desktop_print_claim.sql')) {
            self::ensureProductionPrintClaimColumns($pdo);
            self::finalizeProductionPrintUpgrade($pdo);
        }
    }

    private static function prepareSqliteProductionPrintUpgrade(PDO $pdo): void
    {
        self::ensureProductionUnitColumns($pdo);
        self::ensureProductionPrintClaimColumns($pdo);
    }

    private static function ensureProductionUnitColumns(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'operating_units')) {
            throw new RuntimeException('Banco SQLite legado inconsistente: a tabela operating_units não existe. A atualização 021 precisa ser reparada antes de continuar.');
        }

        if (self::tableExists($pdo, 'production_stations')) {
            self::ensureColumn($pdo, 'production_stations', 'unit_id', 'INTEGER NULL');
            $pdo->exec('UPDATE production_stations SET unit_id=(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_stations.tenant_id) WHERE unit_id IS NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_station_unit ON production_stations(tenant_id,unit_id,active,sort_order,name)');
        }

        if (self::tableExists($pdo, 'production_prints')) {
            self::ensureColumn($pdo, 'production_prints', 'unit_id', 'INTEGER NULL');
            if (self::tableExists($pdo, 'orders') && self::columnExists($pdo, 'orders', 'unit_id')) {
                $pdo->exec('UPDATE production_prints SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_prints.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_prints.tenant_id)) WHERE unit_id IS NULL');
            } else {
                $pdo->exec('UPDATE production_prints SET unit_id=COALESCE((SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_prints.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_prints.tenant_id)) WHERE unit_id IS NULL');
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_print_unit ON production_prints(tenant_id,unit_id,created_at)');
        }

        if (self::tableExists($pdo, 'production_print_queue')) {
            self::ensureColumn($pdo, 'production_print_queue', 'unit_id', 'INTEGER NULL');
            if (self::tableExists($pdo, 'orders') && self::columnExists($pdo, 'orders', 'unit_id')) {
                $pdo->exec('UPDATE production_print_queue SET unit_id=COALESCE((SELECT o.unit_id FROM orders o WHERE o.id=production_print_queue.order_id),(SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_print_queue.tenant_id)) WHERE unit_id IS NULL');
            } else {
                $pdo->exec('UPDATE production_print_queue SET unit_id=COALESCE((SELECT ps.unit_id FROM production_stations ps WHERE ps.id=production_print_queue.station_id),(SELECT MIN(ou.id) FROM operating_units ou WHERE ou.tenant_id=production_print_queue.tenant_id)) WHERE unit_id IS NULL');
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_production_queue_unit ON production_print_queue(tenant_id,unit_id,status,created_at)');
        }
    }

    private static function ensureProductionPrintClaimColumns(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'production_stations') || !self::tableExists($pdo, 'production_print_queue')) {
            throw new RuntimeException('Banco SQLite legado inconsistente: estrutura de impressão da produção ausente.');
        }

        self::ensureColumn($pdo, 'production_stations', 'desktop_device_id', 'TEXT NULL');
        self::ensureColumn($pdo, 'production_print_queue', 'attempts', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'production_print_queue', 'claimed_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'production_print_queue', 'claimed_device_id', 'TEXT NULL');
        self::ensureColumn($pdo, 'production_print_queue', 'last_error', 'TEXT NULL');
        self::ensureColumn($pdo, 'production_print_queue', 'next_attempt_at', 'TEXT NULL');
        self::ensureColumn($pdo, 'production_print_queue', 'updated_at', 'TEXT NULL');
    }

    private static function finalizeProductionPrintUpgrade(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'production_print_queue')) return;
        $pdo->exec('UPDATE production_print_queue SET updated_at=COALESCE(updated_at,created_at,CURRENT_TIMESTAMP) WHERE updated_at IS NULL');
        $pdo->exec('CREATE TRIGGER IF NOT EXISTS trg_prod_print_queue_updated_at_insert AFTER INSERT ON production_print_queue FOR EACH ROW WHEN NEW.updated_at IS NULL BEGIN UPDATE production_print_queue SET updated_at=CURRENT_TIMESTAMP WHERE id=NEW.id; END');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_print_claim ON production_print_queue(tenant_id,unit_id,status,next_attempt_at,created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prod_station_desktop ON production_stations(tenant_id,unit_id,desktop_device_id,active)');
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::columnExists($pdo, $table, $column)) return;
        $tableSql = str_replace('"', '""', $table);
        $columnSql = str_replace('"', '""', $column);
        $pdo->exec('ALTER TABLE "' . $tableSql . '" ADD COLUMN "' . $columnSql . '" ' . $definition);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $s->execute([$table]);
        return (bool)$s->fetchColumn();
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if (!self::tableExists($pdo, $table)) return false;
        $tableSql = str_replace('"', '""', $table);
        foreach ($pdo->query('PRAGMA table_info("' . $tableSql . '")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['name']) && strcasecmp((string)$row['name'], $column) === 0) return true;
        }
        return false;
    }

    private static function migrationApplied(PDO $pdo, string $name): bool
    {
        $s = $pdo->prepare('SELECT 1 FROM migrations WHERE migration=? LIMIT 1');
        $s->execute([$name]);
        return (bool)$s->fetchColumn();
    }
}
