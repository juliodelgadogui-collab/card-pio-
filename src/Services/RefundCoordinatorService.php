<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class RefundCoordinatorService
{
    public function request(int $paymentId,?int $amountCents,string $idempotencyKey,bool $restoreStock=false):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();
        $userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida para reembolso.');

        $probe=Database::connection()->prepare('SELECT provider FROM payments WHERE id=? AND tenant_id=? LIMIT 1');
        $probe->execute([$paymentId,$tenantId]);
        $provider=$probe->fetchColumn();
        if($provider===false)throw new RuntimeException('Pagamento não encontrado.');

        if((string)$provider!=='manual'){
            return (new RefundService())->request($paymentId,$amountCents,$idempotencyKey,$restoreStock);
        }

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$paymentId,$amountCents,$idempotencyKey,$restoreStock){
            $result=(new RefundService())->request($paymentId,$amountCents,$idempotencyKey,$restoreStock);
            if(($result['status']??'')!=='succeeded')throw new RuntimeException('O reembolso manual não foi confirmado.');

            $payment=$pdo->prepare('SELECT id,order_id,provider FROM payments WHERE id=? AND tenant_id=? FOR UPDATE');
            $payment->execute([$paymentId,$tenantId]);
            $row=$payment->fetch();
            if(!$row||$row['provider']!=='manual')throw new RuntimeException('Pagamento manual inválido durante a conciliação do caixa.');

            $sale=$pdo->prepare('SELECT method FROM cash_movements WHERE tenant_id=? AND order_id=? AND type="sale" ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $sale->execute([$tenantId,$row['order_id']]);
            $method=$sale->fetchColumn();
            if($method===false)throw new RuntimeException('Venda manual original não foi encontrada no caixa.');

            (new CashRegisterService())->recordManualRefund(
                $pdo,
                $tenantId,
                $userId,
                (int)$row['order_id'],
                (int)$result['amount_cents'],
                (string)$method,
                $idempotencyKey
            );

            return $result;
        });
    }
}
