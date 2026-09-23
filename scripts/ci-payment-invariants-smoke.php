<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\PaymentService;

function payment_invariant_fail(string $message): never { fwrite(STDERR,"PAYMENT INVARIANT CI FAIL: {$message}\n"); exit(1); }
function payment_invariant_assert(bool $ok,string $message): void { if(!$ok)payment_invariant_fail($message); }

try{
    $pdo=Database::connection();
    $driver=Database::driver($pdo);
    $suffix=bin2hex(random_bytes(6));
    $slug='ci-pay-'.$suffix;

    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Payment Tenant',$slug]);
    $tenantId=(int)$pdo->lastInsertId();
    payment_invariant_assert($tenantId>0,'Tenant de pagamento não foi criado.');

    $unitCode='ci-pay-unit-'.$suffix;
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"CI Payment Unit",1)')->execute([$tenantId,$unitCode]);
    $unitId=(int)$pdo->lastInsertId();
    payment_invariant_assert($unitId>0,'Unidade de pagamento não foi criada.');

    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents) VALUES (?, ?, ?, "counter", "pending", "unpaid", 1000, 1000)')->execute([bin2hex(random_bytes(20)),$tenantId,$unitId]);
    $orderId=(int)$pdo->lastInsertId();
    payment_invariant_assert($orderId>0,'Pedido de pagamento não foi criado.');

    $idemA='ci-payment-a-'.$suffix;
    $idemB='ci-payment-b-'.$suffix;
    $insert=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,"BRL","created")');
    $insert->execute([$tenantId,$orderId,'manual',$idemA,1000]);
    $paymentA=(int)$pdo->lastInsertId();
    $insert->execute([$tenantId,$orderId,'manual',$idemB,1000]);
    $paymentB=(int)$pdo->lastInsertId();
    payment_invariant_assert($paymentA>0&&$paymentB>0&&$paymentA!==$paymentB,'Cobranças de corrida não foram criadas.');

    // A constraint de idempotência é a última barreira contra duas requisições concorrentes
    // com a mesma chave chegarem antes de a aplicação enxergar a primeira.
    $duplicateKeyBlocked=false;
    try{
        $insert->execute([$tenantId,$orderId,'manual',$idemA,1000]);
    }catch(PDOException){$duplicateKeyBlocked=true;}
    payment_invariant_assert($duplicateKeyBlocked,'Banco aceitou duas cobranças com a mesma chave de idempotência.');

    $service=new PaymentService();
    $providerTxnA='ci-provider-a-'.$suffix;
    $verifiedA=[
        'tenant_id'=>$tenantId,
        'order_id'=>$orderId,
        'provider'=>'manual',
        'provider_payment_id'=>$providerTxnA,
        'amount_cents'=>1000,
        'currency'=>'BRL',
        'account_reference'=>'manual-ci',
        'payment_id'=>$paymentA,
        'source'=>'ci',
    ];
    $service->confirmVerified($verifiedA);
    $service->confirmVerified($verifiedA); // replay do mesmo evento precisa ser inofensivo.

    $s=$pdo->prepare('SELECT status FROM payments WHERE id=? AND tenant_id=?');$s->execute([$paymentA,$tenantId]);
    payment_invariant_assert((string)$s->fetchColumn()==='paid','Primeiro pagamento verificado não ficou pago.');
    $s=$pdo->prepare('SELECT payment_status FROM orders WHERE id=? AND tenant_id=?');$s->execute([$orderId,$tenantId]);
    payment_invariant_assert((string)$s->fetchColumn()==='paid','Pedido não foi liquidado após pagamento verificado.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$s->execute([$tenantId,$orderId]);
    payment_invariant_assert((int)$s->fetchColumn()===1,'Replay do mesmo evento duplicou pagamento liquidado.');

    // Simula uma segunda cobrança que também foi confirmada pelo provedor após o pedido já estar pago.
    // Ela precisa ficar destacada para estorno, sem liquidar o pedido/efeitos novamente.
    $verifiedB=[
        'tenant_id'=>$tenantId,
        'order_id'=>$orderId,
        'provider'=>'manual',
        'provider_payment_id'=>'ci-provider-b-'.$suffix,
        'amount_cents'=>1000,
        'currency'=>'BRL',
        'account_reference'=>'manual-ci',
        'payment_id'=>$paymentB,
        'source'=>'ci',
    ];
    $service->confirmVerified($verifiedB);
    $s=$pdo->prepare('SELECT status FROM payments WHERE id=? AND tenant_id=?');$s->execute([$paymentB,$tenantId]);
    payment_invariant_assert((string)$s->fetchColumn()==='duplicate_paid','Segunda cobrança aprovada não foi marcada como duplicate_paid.');

    $s=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$s->execute([$tenantId,$orderId]);
    payment_invariant_assert((int)$s->fetchColumn()===1000,'Cobrança duplicada alterou o total liquidado do pedido.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status="duplicate_paid"');$s->execute([$tenantId,$orderId]);
    payment_invariant_assert((int)$s->fetchColumn()===1,'Cobrança duplicada não ficou rastreável para estorno.');

    // IDs externos pertencem ao contexto da conta/tenant. O mesmo identificador não pode
    // fazer uma empresa bloquear ou contaminar a liquidação de outra empresa.
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Payment Tenant 2','ci-pay-2-'.$suffix]);
    $tenant2=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,"CI Payment Unit 2",1)')->execute([$tenant2,'ci-pay-unit-2-'.$suffix]);
    $unit2=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents) VALUES (?, ?, ?, "counter", "pending", "unpaid", 1000, 1000)')->execute([bin2hex(random_bytes(20)),$tenant2,$unit2]);
    $order2=(int)$pdo->lastInsertId();
    $insert->execute([$tenant2,$order2,'manual','ci-payment-c-'.$suffix,1000]);
    $paymentC=(int)$pdo->lastInsertId();
    $service->confirmVerified([
        'tenant_id'=>$tenant2,
        'order_id'=>$order2,
        'provider'=>'manual',
        'provider_payment_id'=>$providerTxnA,
        'amount_cents'=>1000,
        'currency'=>'BRL',
        'account_reference'=>'manual-ci-2',
        'payment_id'=>$paymentC,
        'source'=>'ci-cross-tenant',
    ]);
    $s=$pdo->prepare('SELECT status FROM payments WHERE id=? AND tenant_id=?');$s->execute([$paymentC,$tenant2]);
    payment_invariant_assert((string)$s->fetchColumn()==='paid','ID externo igual em outro tenant bloqueou pagamento válido.');
    $s=$pdo->prepare('SELECT payment_status FROM orders WHERE id=? AND tenant_id=?');$s->execute([$order2,$tenant2]);
    payment_invariant_assert((string)$s->fetchColumn()==='paid','Pagamento do segundo tenant não liquidou seu próprio pedido.');

    echo "CI payment invariants OK ({$driver})\n";
}catch(Throwable $e){
    payment_invariant_fail($e->getMessage()."\n".$e->getTraceAsString());
}
