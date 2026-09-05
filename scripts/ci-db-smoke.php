<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Services\GatewayService;

function fail_ci(string $message): never
{
    fwrite(STDERR, "CI FAIL: {$message}\n");
    exit(1);
}

function assert_ci(bool $condition, string $message): void
{
    if (!$condition) fail_ci($message);
}

try {
    $pdo = Database::connection();
    $driver = Database::driver($pdo);
    echo "Driver: {$driver}\n";

    $schemaPath = Database::schemaPath($pdo);
    $schema = file_get_contents($schemaPath);
    if ($schema === false) fail_ci("Schema não encontrado: {$schemaPath}");
    $pdo->exec($schema);
    Migrator::run($pdo);

    $requiredTables = [
        'tenants','users','categories','products','customers','orders','order_items',
        'events','ticket_batches','tickets','payment_gateways','payments','webhook_events',
        'stock_movements','audit_logs','restaurant_tables','tabs','coupons',
        'coupon_redemptions','coupon_reservations','customer_points_movements','promoters',
        'promoter_commissions','event_guests','ticket_checkin_logs','nfc_devices','migrations',
    ];
    foreach ($requiredTables as $table) {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        } catch (\Throwable $e) {
            fail_ci("Tabela ausente ou inválida: {$table} - {$e->getMessage()}");
        }
    }

    $slug = 'ci-' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Tenant', $slug]);
    $tenantId = (int)$pdo->lastInsertId();
    assert_ci($tenantId > 0, 'Falha ao inserir tenant.');

    $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([
        $tenantId,
        'CI Admin',
        $slug . '@example.test',
        password_hash('CiPassword123!', PASSWORD_DEFAULT),
    ]);
    $userId = (int)$pdo->lastInsertId();
    assert_ci($userId > 0, 'Falha ao inserir usuário.');

    $pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,"CI",0,1)')->execute([$tenantId]);
    $categoryId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,sku,price_cents,stock_qty,track_stock,active) VALUES (?,?,"Produto CI",?,1000,10,1,1)')->execute([$tenantId, $categoryId, 'CI-' . $slug]);
    $productId = (int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?, ?, "counter", "pending", "unpaid", 1000, 1000, ?)')->execute([bin2hex(random_bytes(20)), $tenantId, $userId]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?, ?, "Produto CI", 1000, 1, 1000)')->execute([$orderId, $productId]);

    Database::transaction(function (\PDO $tx) use ($tenantId, $orderId): void {
        $sql = Database::portableSql($tx, 'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
        $stmt = $tx->prepare($sql);
        $stmt->execute([$orderId, $tenantId]);
        if (!$stmt->fetch()) throw new \RuntimeException('Lock/read do pedido falhou.');
    });

    $portableIgnore = Database::portableSql($pdo, 'INSERT IGNORE INTO migrations (migration) VALUES (?)');
    $pdo->prepare($portableIgnore)->execute(['ci-portable-ignore']);
    $pdo->prepare($portableIgnore)->execute(['ci-portable-ignore']);
    $count = (int)$pdo->query('SELECT COUNT(*) FROM migrations WHERE migration="ci-portable-ignore"')->fetchColumn();
    assert_ci($count === 1, 'INSERT IGNORE/OR IGNORE não está idempotente.');

    $functions = $pdo->query('SELECT NOW() now_value, GREATEST(0,-1) greatest_value, IF(1,"yes","no") if_value')->fetch();
    assert_ci(is_array($functions), 'Funções SQL de compatibilidade falharam.');
    assert_ci((int)$functions['greatest_value'] === 0, 'GREATEST incompatível.');
    assert_ci((string)$functions['if_value'] === 'yes', 'IF incompatível.');

    $rollbackSlug = 'rollback-' . bin2hex(random_bytes(4));
    try {
        Database::transaction(function (\PDO $tx) use ($rollbackSlug): void {
            $tx->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Rollback', $rollbackSlug]);
            throw new \RuntimeException('rollback-test');
        });
        fail_ci('Transação de rollback não lançou exceção.');
    } catch (\RuntimeException $e) {
        if ($e->getMessage() !== 'rollback-test') throw $e;
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM tenants WHERE slug=?');
    $s->execute([$rollbackSlug]);
    assert_ci((int)$s->fetchColumn() === 0, 'Rollback não reverteu a gravação.');

    $reflection = new \ReflectionClass(GatewayService::class);
    $method = $reflection->getMethod('header');
    $headerValue = $method->invoke(new GatewayService(), ['Stripe-Signature' => 'ci-signature'], 'stripe-signature');
    assert_ci($headerValue === 'ci-signature', 'Normalização de headers de webhook regrediu.');

    echo "CI DB smoke OK ({$driver})\n";
} catch (\Throwable $e) {
    fail_ci($e->getMessage() . "\n" . $e->getTraceAsString());
}
