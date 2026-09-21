<?php

declare(strict_types=1);

namespace EventMenu\Services;

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

        Migrator::run($pdo);

        if (!$this->isReady($pdo)) {
            throw new RuntimeException('O servidor do DELYVRE ainda não concluiu a atualização do banco de dados. Tente novamente em alguns segundos.');
        }
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
}
