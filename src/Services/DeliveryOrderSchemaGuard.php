<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Keeps the DELYVRE checkout resilient when application files are updated
 * before the database migrations are applied on shared hosting.
 *
 * The fast path only performs read-only schema probes. Migrations are executed
 * exclusively when a required table/column is missing or after a schema-related
 * database exception. This avoids running the complete migration scanner for
 * every order while still recovering installations with schema drift.
 */
final class DeliveryOrderSchemaGuard
{
    /** @var list<string> */
    private const PROBES = [
        'SELECT id,unit_id,order_source,marketplace_campaign_code,subtotal_cents,discount_cents,delivery_fee_cents,total_cents FROM orders WHERE 1=0',
        'SELECT tenant_id,unit_id,nonce_hash,expires_at,used_at FROM marketplace_entry_tokens WHERE 1=0',
        'SELECT id,scope_type,rate_bps,priority,active FROM marketplace_commission_rules WHERE 1=0',
        'SELECT tenant_id,unit_id,order_id,order_source,commission_bps,commission_cents,status FROM marketplace_order_commissions WHERE 1=0',
        'SELECT account_id,tenant_id,order_id,created_at FROM delivery_customer_order_links WHERE 1=0',
        'SELECT tenant_id,order_id,product_id,status FROM stock_reservations WHERE 1=0',
        'SELECT tenant_id,order_id,to_status,source FROM order_status_history WHERE 1=0',
        'SELECT id,tenant_id,active FROM operating_units WHERE 1=0',
        'SELECT id,code FROM saas_plans WHERE 1=0',
        'SELECT tenant_id,plan_id,status FROM tenant_subscriptions WHERE 1=0',
    ];

    public function ensure(PDO $pdo, bool $force = false): void
    {
        if (!$force && $this->isReady($pdo)) return;
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Não foi possível atualizar a estrutura do DELYVRE durante uma transação ativa.');
        }

        $migrationError = null;
        try {
            Migrator::run($pdo);
        } catch (Throwable $error) {
            $migrationError = $error;
            error_log('[eventmenu-delyvre-schema] migration: ' . $error::class . ': ' . $error->getMessage());
        }

        if ($this->isReady($pdo)) return;

        // Instalações SQLite antigas podem ter a migration registrada como
        // aplicada, mas estar com tabela/coluna ausente por upload interrompido.
        // Reaplicamos apenas DDL idempotente e os dois campos do marketplace.
        if (Database::isSqlite($pdo)) {
            $this->repairSqliteDrift($pdo);
            if ($this->isReady($pdo)) return;
        }

        $suffix = $migrationError ? ' Detalhe técnico registrado no log do servidor.' : '';
        throw new RuntimeException('O servidor do DELYVRE ainda não concluiu a atualização do banco de dados. Tente novamente em alguns segundos.' . $suffix);
    }

    public function isReady(PDO $pdo): bool
    {
        foreach (self::PROBES as $sql) {
            try {
                $statement = $pdo->query($sql);
                if ($statement === false) return false;
                $statement->closeCursor();
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    public function isSchemaFailure(Throwable $error): bool
    {
        if (!$error instanceof PDOException) return false;

        $message = strtolower($error->getMessage());
        foreach ([
            'no such table',
            'no such column',
            'has no column named',
            'unknown column',
            'base table or view not found',
            "doesn't exist",
            'does not exist',
            'undefined column',
            'undefined table',
        ] as $needle) {
            if (str_contains($message, $needle)) return true;
        }

        $state = strtoupper((string)$error->getCode());
        return in_array($state, ['42S02', '42S22', '42703', '42P01'], true);
    }

    private function repairSqliteDrift(PDO $pdo): void
    {
        $this->ensureSqliteColumn($pdo, 'orders', 'unit_id', 'INTEGER NULL');
        $this->ensureSqliteColumn($pdo, 'orders', 'order_source', "TEXT NOT NULL DEFAULT 'EVENTMENU_OWN'");
        $this->ensureSqliteColumn($pdo, 'orders', 'marketplace_campaign_code', 'TEXT NULL');

        // Migrations abaixo são naturalmente idempotentes no SQLite.
        foreach ([
            '011_stock_reservations.sql',
            '029_commercial_plans.sql',
            '056_marketplace_entry_tokens.sql',
            '058_delivery_customer_accounts.sql',
        ] as $migration) {
            $this->execSqliteMigration($pdo, $migration);
        }

        // A 055 possui ALTER TABLE e CREATE TABLE sem IF NOT EXISTS. Os ALTERs
        // já foram tratados acima; transformamos somente os CREATE TABLE para
        // uma reaplicação segura, preservando os dados existentes.
        $marketplacePath = $this->sqliteMigrationPath('055_eventmenu_delivery_marketplace.sql');
        $marketplaceSql = file_get_contents($marketplacePath);
        if ($marketplaceSql !== false) {
            $marketplaceSql = preg_replace('/^\s*ALTER\s+TABLE\s+orders\s+ADD\s+COLUMN\s+[^;]+;\s*$/mi', '', $marketplaceSql) ?? $marketplaceSql;
            $marketplaceSql = preg_replace('/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i', 'CREATE TABLE IF NOT EXISTS ', $marketplaceSql) ?? $marketplaceSql;
            $pdo->exec($marketplaceSql);
        }

        // A migration 022 não é idempotente, então só é executada quando a
        // tabela realmente não existe.
        if (!$this->sqliteTableExists($pdo, 'order_status_history')) {
            $this->execSqliteMigration($pdo, '022_order_status_history.sql');
        }
    }

    private function ensureSqliteColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!$this->sqliteTableExists($pdo, $table)) return;
        foreach ($pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)($row['name'] ?? '') === $column) return;
        }
        $quotedTable = '"' . str_replace('"', '""', $table) . '"';
        $quotedColumn = '"' . str_replace('"', '""', $column) . '"';
        $pdo->exec('ALTER TABLE ' . $quotedTable . ' ADD COLUMN ' . $quotedColumn . ' ' . $definition);
    }

    private function sqliteTableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=? LIMIT 1");
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    }

    private function execSqliteMigration(PDO $pdo, string $name): void
    {
        $sql = file_get_contents($this->sqliteMigrationPath($name));
        if ($sql === false) throw new RuntimeException('Não foi possível ler a migration de reparo ' . $name . '.');
        $pdo->exec($sql);
    }

    private function sqliteMigrationPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/database/sqlite/migrations/' . $name;
    }
}
