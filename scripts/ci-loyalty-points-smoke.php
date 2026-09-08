<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\LoyaltyPointsService;

function loyalty_fail(string $message): never { fwrite(STDERR,"CI LOYALTY FAIL: {$message}\n"); exit(1); }
function loyalty_assert(bool $condition,string $message):void { if(!$condition)loyalty_fail($message); }

try {
    $pdo=Database::connection();
    $pdo->query('SELECT 1 FROM customer_points_reservations LIMIT 1');
    $service=new LoyaltyPointsService();
    $slug='ci-loyalty-'.bin2hex(random_bytes(4));
    $settings=json_encode([
        'points_enabled'=>true,
        'points_earn_amount_cents'=>100,
        'points_redeem_points'=>100,
        'points_redeem_value_cents'=>500,
        'points_min_redeem_points'=>100,
        'points_max_redeem_percent'=>50,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['CI Loyalty',$slug,$settings]);
    $tenantId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO customers (tenant_id,name,phone,points) VALUES (?,"Cliente Fidelidade","22999990000",300)')->execute([$tenantId]);
    $customerId=(int)$pdo->lastInsertId();

    $config=$service->config($tenantId,$pdo);
    loyalty_assert($config['enabled']===true,'Programa deveria estar ativo.');
    loyalty_assert($service->pointsForPaidAmount($tenantId,1599,$pdo)===15,'Regra de ganho configurável não foi aplicada.');

    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,channel,status,payment_status,subtotal_cents,discount_cents,total_cents) VALUES (?, ?, ?, "counter", "confirmed", "unpaid", 2000, 0, 2000)')->execute([bin2hex(random_bytes(20)),$tenantId,$customerId]);
    $orderId=(int)$pdo->lastInsertId();
    $reserved=$service->applyToExistingOrder($tenantId,$customerId,$orderId,100);
    loyalty_assert((int)$reserved['discount_cents']===500,'Desconto do resgate incorreto.');
    loyalty_assert((int)$reserved['total_cents']===1500,'Total do pedido não foi reduzido pelos pontos.');
    $summary=$service->summary($tenantId,$customerId,$pdo);
    loyalty_assert($summary['balance']===300&&$summary['reserved']===100&&$summary['available']===200,'Reserva não protegeu o saldo disponível.');

    Database::transaction(function(PDO $tx)use($service,$tenantId,$orderId):void{
        $s=$tx->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=?');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido de fidelidade não encontrado.');
        $service->settleForOrder($tx,$tenantId,$orderId,'ci:settlement:'.$orderId);
        $service->earnForOrder($tx,$tenantId,$order,$orderId,'ci:settlement:'.$orderId);
    });
    $summary=$service->summary($tenantId,$customerId,$pdo);
    loyalty_assert($summary['balance']===215&&$summary['reserved']===0&&$summary['available']===215,'Liquidação deveria usar 100 pontos e ganhar 15.');
    $movementCount=(int)$pdo->query('SELECT COUNT(*) FROM customer_points_movements WHERE tenant_id='.$tenantId.' AND customer_id='.$customerId)->fetchColumn();
    loyalty_assert($movementCount===2,'Ganho e resgate deveriam gerar exatamente dois movimentos.');

    Database::transaction(function(PDO $tx)use($service,$tenantId,$orderId):void{
        $s=$tx->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=?');$s->execute([$orderId,$tenantId]);$order=$s->fetch();
        $service->settleForOrder($tx,$tenantId,$orderId,'ci:settlement:'.$orderId);
        $service->earnForOrder($tx,$tenantId,$order,$orderId,'ci:settlement:'.$orderId);
    });
    loyalty_assert($service->summary($tenantId,$customerId,$pdo)['balance']===215,'Reprocessamento duplicou pontos.');

    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,channel,status,payment_status,subtotal_cents,discount_cents,total_cents) VALUES (?, ?, ?, "counter", "confirmed", "unpaid", 1000, 0, 1000)')->execute([bin2hex(random_bytes(20)),$tenantId,$customerId]);
    $order2=(int)$pdo->lastInsertId();
    $service->applyToExistingOrder($tenantId,$customerId,$order2,100);
    Database::transaction(function(PDO $tx)use($service,$tenantId,$order2):void{loyalty_assert($service->releaseForOrder($tx,$tenantId,$order2,true)===1,'Reserva não foi liberada.');});
    $o=$pdo->prepare('SELECT total_cents,discount_cents FROM orders WHERE id=?');$o->execute([$order2]);$order2row=$o->fetch();
    loyalty_assert((int)$order2row['total_cents']===1000&&(int)$order2row['discount_cents']===0,'Remoção da reserva não restaurou o total do pedido.');
    loyalty_assert($service->summary($tenantId,$customerId,$pdo)['available']===215,'Pontos liberados não voltaram a ficar disponíveis.');

    Database::transaction(function(PDO $tx)use($service,$tenantId,$orderId):void{$service->restoreRedeemedForRefund($tx,$tenantId,$orderId,777001);});
    loyalty_assert($service->summary($tenantId,$customerId,$pdo)['balance']===315,'Estorno não devolveu os pontos usados.');
    Database::transaction(function(PDO $tx)use($service,$tenantId,$orderId):void{$service->restoreRedeemedForRefund($tx,$tenantId,$orderId,777001);});
    loyalty_assert($service->summary($tenantId,$customerId,$pdo)['balance']===315,'Reprocessamento do estorno duplicou devolução de pontos.');

    $pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode(['points_enabled'=>false],JSON_UNESCAPED_UNICODE),$tenantId]);
    loyalty_assert($service->pointsForPaidAmount($tenantId,10000,$pdo)===0,'Programa desativado ainda gerou pontos.');

    echo 'CI loyalty points smoke OK ('.Database::driver($pdo).")\n";
} catch(Throwable $e) {
    loyalty_fail($e->getMessage()."\n".$e->getTraceAsString());
}
