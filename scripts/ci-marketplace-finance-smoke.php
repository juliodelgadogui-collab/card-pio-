<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Services\MarketplaceCommissionService;
use EventMenu\Services\MarketplaceReconciliationService;
use EventMenu\Services\PlatformBillingService;
use EventMenu\Services\PublicMenuService;
use RuntimeException;
use Throwable;

function market_fail(string $message): never
{
    fwrite(STDERR, "MARKETPLACE CI FAIL: {$message}\n");
    exit(1);
}

function market_assert(bool $condition, string $message): void
{
    if (!$condition) market_fail($message);
}

try {
    $pdo = Database::connection();
    $schema = file_get_contents(Database::schemaPath($pdo));
    if ($schema === false) market_fail('Schema ausente.');
    $pdo->exec($schema);
    Migrator::run($pdo);

    foreach (['marketplace_tenant_settings','marketplace_commission_rules','marketplace_order_commissions','platform_invoices','platform_invoice_items','marketplace_promotion_accounts','marketplace_promotion_ledger'] as $table) {
        try {
            $pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');
        } catch (Throwable $e) {
            market_fail('Tabela ausente: '.$table.' · '.$e->getMessage());
        }
    }

    $slug = 'market-ci-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Marketplace CI',$slug]);
    $tenantId = (int)$pdo->lastInsertId();
    $planId = (int)$pdo->query('SELECT id FROM saas_plans WHERE code="premium" LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO tenant_subscriptions (tenant_id,plan_id,status,billing_cycle) VALUES (?,? ,"active","monthly")')->execute([$tenantId,$planId]);
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,"principal","Principal",1)')->execute([$tenantId]);
    $unitId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO marketplace_tenant_settings (tenant_id,participates,status,joined_at,city,state) VALUES (?,1,"active",CURRENT_TIMESTAMP,"Cidade CI","RJ")')->execute([$tenantId]);
    $pdo->prepare('INSERT INTO categories (tenant_id,name,active) VALUES (?,"Lanches",1)')->execute([$tenantId]);
    $cat = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,sku,price_cents,stock_qty,track_stock,active) VALUES (?, ?,"Produto Marketplace","MARKET-CI",8000,100,0,1)')->execute([$tenantId,$cat]);
    $productId = (int)$pdo->lastInsertId();

    $menu = new PublicMenuService();
    $own = $menu->create($tenantId,[['product_id'=>$productId,'qty'=>1]],'Cliente Próprio','22999990001','Rua Própria',null,'delivery',null,'EVENTMENU_OWN',null,$unitId);
    $s = $pdo->prepare('SELECT COUNT(*) FROM marketplace_order_commissions WHERE order_id=?');
    $s->execute([$own['id']]);
    market_assert((int)$s->fetchColumn() === 0, 'Pedido próprio gerou comissão de marketplace.');

    $market = $menu->create($tenantId,[['product_id'=>$productId,'qty'=>1]],'Cliente Marketplace','22999990002','Rua Marketplace',null,'delivery',null,MarketplaceCommissionService::ORDER_SOURCE,null,$unitId);
    market_assert($market['order_source'] === MarketplaceCommissionService::ORDER_SOURCE, 'Origem do marketplace não foi persistida.');

    $s = $pdo->prepare('SELECT * FROM marketplace_order_commissions WHERE order_id=?');
    $s->execute([$market['id']]);
    $commission = $s->fetch();
    market_assert(is_array($commission), 'Comissão provisionada não foi criada.');
    market_assert($commission['status'] === 'provisioned', 'Estado inicial da comissão não é provisioned.');
    market_assert((int)$commission['products_gross_cents'] === 8000, 'Valor original dos produtos incorreto.');
    market_assert((int)$commission['calculation_base_cents'] === 8000, 'Base de cálculo incorreta.');
    market_assert((int)$commission['commission_bps'] === 400, 'Taxa padrão inicial não foi registrada como snapshot de 4%.');
    market_assert((int)$commission['commission_cents'] === 320, 'Comissão calculada incorretamente.');

    // Provisioning must be idempotent for the same tenant/order.
    $again = Database::transaction(fn(PDO $tx) => (new MarketplaceCommissionService())->provision($tx,$tenantId,(int)$market['id']));
    market_assert((int)($again['id'] ?? 0) === (int)$commission['id'], 'Provisionamento repetido criou/trocou a comissão do pedido.');
    $s = $pdo->prepare('SELECT COUNT(*) FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=?');
    $s->execute([$tenantId,$market['id']]);
    market_assert((int)$s->fetchColumn() === 1, 'Provisionamento repetido gerou comissão duplicada.');

    // A caller from another tenant must never receive or mutate this commission.
    $foreignSlug = 'market-foreign-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Marketplace Foreign CI',$foreignSlug]);
    $foreignTenantId = (int)$pdo->lastInsertId();
    $tenantIsolationBlocked = false;
    try {
        Database::transaction(fn(PDO $tx) => (new MarketplaceCommissionService())->provision($tx,$foreignTenantId,(int)$market['id']));
    } catch (RuntimeException) {
        $tenantIsolationBlocked = true;
    }
    market_assert($tenantIsolationBlocked, 'Tenant estrangeiro conseguiu acessar/provisionar comissão de outro tenant.');

    // Change the live rule after checkout. The order must keep its historical 4%
    // snapshot when it becomes due instead of recalculating with the new rate.
    $pdo->prepare('UPDATE marketplace_commission_rules SET rate_bps=999 WHERE id=?')->execute([(int)$commission['rule_id']]);
    $pdo->prepare('UPDATE orders SET status="completed",payment_status="paid" WHERE id=?')->execute([$market['id']]);
    Database::transaction(fn(PDO $tx) => (new MarketplaceReconciliationService())->reconcileOrder($tx,$tenantId,(int)$market['id']));
    $s = $pdo->prepare('SELECT * FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=?');
    $s->execute([$tenantId,$market['id']]);
    $dueCommission = $s->fetch();
    market_assert(($dueCommission['status'] ?? null) === 'due', 'Conclusão não transformou comissão em devida.');
    market_assert((int)($dueCommission['commission_bps'] ?? -1) === 400, 'Alteração posterior da regra mudou a taxa snapshot do pedido.');
    market_assert((int)($dueCommission['commission_cents'] ?? -1) === 320, 'Alteração posterior da regra mudou o valor histórico da comissão.');

    $period = gmdate('Y-m');
    $start = $period.'-01';
    $end = gmdate('Y-m-t');
    $billing = new PlatformBillingService();
    $invoice = Database::transaction(fn(PDO $tx) => $billing->generate($tx,$tenantId,$start,$end));
    $marketLines = array_values(array_filter($invoice['items'], fn(array $i) => $i['item_type'] === 'marketplace_commission'));
    market_assert(count($marketLines) === 1, 'Fatura não trouxe exatamente uma comissão do marketplace.');
    market_assert((int)$marketLines[0]['order_id'] === (int)$market['id'], 'Fatura vinculou comissão ao pedido errado.');
    market_assert((int)$marketLines[0]['calculation_base_cents'] === 8000, 'Fatura perdeu base de cálculo da comissão.');
    market_assert((int)$marketLines[0]['commission_bps'] === 400, 'Fatura perdeu taxa snapshot.');

    Database::transaction(fn(PDO $tx) => $billing->issue($tx,(int)$invoice['id']));
    Database::transaction(fn(PDO $tx) => $billing->markPaid($tx,(int)$invoice['id']));
    $s = $pdo->prepare('SELECT status FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=?');
    $s->execute([$tenantId,$market['id']]);
    market_assert($s->fetchColumn() === 'paid', 'Liquidação da fatura não liquidou a comissão.');

    $pdo->prepare('UPDATE orders SET status="cancelled",payment_status="refunded" WHERE id=?')->execute([$market['id']]);
    Database::transaction(fn(PDO $tx) => (new MarketplaceReconciliationService())->reconcileOrder($tx,$tenantId,(int)$market['id']));
    $s = $pdo->prepare('SELECT status FROM marketplace_order_commissions WHERE tenant_id=? AND order_id=?');
    $s->execute([$tenantId,$market['id']]);
    market_assert($s->fetchColumn() === 'reversed', 'Estorno posterior ao pagamento não marcou a comissão como estornada.');
    $s = $pdo->prepare('SELECT COUNT(*) FROM platform_invoice_items WHERE tenant_id=? AND reference_key=? AND item_type="credit_adjustment" AND amount_cents=-320');
    $s->execute([$tenantId,'marketplace-reversal:'.(int)$commission['id']]);
    market_assert((int)$s->fetchColumn() === 1, 'Estorno pago não gerou crédito financeiro idempotente.');

    echo "Marketplace finance smoke OK\n";
} catch (Throwable $e) {
    market_fail($e->getMessage().'\n'.$e->getTraceAsString());
}
