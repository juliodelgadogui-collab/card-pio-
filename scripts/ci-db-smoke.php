<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\BackgroundJobService;
use EventMenu\Services\GatewayService;
use EventMenu\Services\ProductionService;
use EventMenu\Services\RuntimeStatusService;
use EventMenu\Services\SystemHealthService;

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
        'promoter_commissions','event_guests','ticket_checkin_logs','nfc_devices',
        'cash_sessions','cash_movements','saas_plans','tenant_subscriptions','migrations',
        'background_jobs','push_devices','system_runtime_status','api_rate_limits',
    ];
    foreach ($requiredTables as $table) {
        try {
            $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        } catch (\Throwable $e) {
            fail_ci("Tabela ausente ou inválida: {$table} - {$e->getMessage()}");
        }
    }

    $planCount = (int)$pdo->query('SELECT COUNT(*) FROM saas_plans WHERE active=1')->fetchColumn();
    assert_ci($planCount >= 3, 'Catálogo comercial inicial não foi criado.');
    $premiumPlanId = (int)$pdo->query('SELECT id FROM saas_plans WHERE code="premium" LIMIT 1')->fetchColumn();
    assert_ci($premiumPlanId > 0, 'Plano Premium inicial não encontrado.');

    $slug = 'ci-' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Tenant', $slug]);
    $tenantId = (int)$pdo->lastInsertId();
    assert_ci($tenantId > 0, 'Falha ao inserir tenant.');

    $pdo->prepare('INSERT INTO tenant_subscriptions (tenant_id,plan_id,status,billing_cycle) VALUES (?,? ,"active","monthly")')->execute([$tenantId,$premiumPlanId]);
    $subscription = $pdo->prepare('SELECT ts.status,p.code FROM tenant_subscriptions ts JOIN saas_plans p ON p.id=ts.plan_id WHERE ts.tenant_id=?');
    $subscription->execute([$tenantId]);
    $subscriptionRow = $subscription->fetch();
    assert_ci(is_array($subscriptionRow) && $subscriptionRow['status'] === 'active' && $subscriptionRow['code'] === 'premium', 'Assinatura comercial não pôde ser vinculada ao tenant.');

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

    $runtime = new RuntimeStatusService();
    $runtime->set('ci.heartbeat','ok','CI ativo.',['driver'=>$driver]);
    $heartbeat = $runtime->get('ci.heartbeat');
    assert_ci(is_array($heartbeat) && $heartbeat['state'] === 'ok', 'Runtime status não persistiu.');

    $jobs = new BackgroundJobService();
    $dedupe = 'ci-job-' . bin2hex(random_bytes(6));
    $jobId = $jobs->enqueue('push.notification',['notification_id'=>999999],$tenantId,$dedupe,null,2);
    assert_ci($jobId > 0, 'Fila não aceitou tarefa.');
    $sameJobId = $jobs->enqueue('push.notification',['notification_id'=>999999],$tenantId,$dedupe,null,2);
    assert_ci($sameJobId === $jobId, 'Dedupe da fila não é idempotente.');
    $jobResult = $jobs->runBatch(5,'ci-worker');
    assert_ci((int)$jobResult['completed'] >= 1, 'Worker não concluiu tarefa segura de CI.');

    $rate = new ApiRateLimitService();
    $rateSubject = 'ci-' . bin2hex(random_bytes(6));
    $rate->assertAllowed('ci.bucket',$rateSubject,1,60);
    $blocked = false;
    try { $rate->assertAllowed('ci.bucket',$rateSubject,1,60); }
    catch (\RuntimeException) { $blocked = true; }
    assert_ci($blocked, 'Rate limit não bloqueou o excesso.');

    $unitCode = 'ci-unit-' . bin2hex(random_bytes(3));
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"Bar CI",1)')->execute([$tenantId,$unitCode]);
    $unitId = (int)$pdo->lastInsertId();
    assert_ci($unitId > 0, 'Unidade de teste do evento não foi criada.');
    $eventSlug = 'ci-event-' . bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO events (tenant_id,name,slug,starts_at,status,bar_enabled,bar_unit_id) VALUES (?,?,?,CURRENT_TIMESTAMP,"published",1,?)')->execute([$tenantId,'Evento CI',$eventSlug,$unitId]);
    $eventId = (int)$pdo->lastInsertId();
    assert_ci($eventId > 0, 'Evento de teste do bar não foi criado.');
    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,event_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?,?,?,?,"event_bar","confirmed","paid",1000,1000,?)')->execute([bin2hex(random_bytes(20)),$tenantId,$unitId,$eventId,$userId]);
    $eventOrderId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?, ?, "Bebida pronta CI", 1000, 1, 1000)')->execute([$eventOrderId,$productId]);
    Database::transaction(function (\PDO $tx) use ($tenantId,$eventOrderId): void {
        (new ProductionService())->ensureOrderJobs($tx,$tenantId,$eventOrderId,null);
    });
    $s=$pdo->prepare('SELECT status FROM orders WHERE id=? AND tenant_id=?');$s->execute([$eventOrderId,$tenantId]);
    assert_ci((string)$s->fetchColumn()==='ready','Pedido event_bar sem preparo não ficou pronto automaticamente.');

    $health = (new SystemHealthService())->snapshot();
    assert_ci(isset($health['checks']['database'],$health['checks']['queue'],$health['checks']['backup']), 'Snapshot de saúde incompleto.');
    assert_ci(($health['checks']['queue']['state'] ?? null) === 'ok', 'Fila vazia gerou alerta falso no health check.');

    echo "CI DB smoke OK ({$driver})\n";
} catch (\Throwable $e) {
    fail_ci($e->getMessage() . "\n" . $e->getTraceAsString());
}
