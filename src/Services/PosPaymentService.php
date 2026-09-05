<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PosPaymentService
{
    public function status(int $orderId):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $balance=(new PaymentService())->remaining($orderId,$tenantId);
        $s=Database::connection()->prepare('SELECT id,provider,amount_cents,status,verified_at,created_at FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id');$s->execute([$tenantId,$orderId]);
        $balance['payments']=$s->fetchAll();return $balance;
    }

    public function cash(int $orderId,int $amountCents,string $idempotencyKey):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('cash.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');
        if($amountCents<=0)throw new RuntimeException('Informe um valor de parcela maior que zero.');if(strlen(trim($idempotencyKey))<12)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$amountCents,$idempotencyKey):array{
            $cash=new CashService();if(!$cash->currentSession())throw new RuntimeException('Abra o caixa antes de receber dinheiro.');
            $payments=new PaymentService();$payment=$payments->create($orderId,'manual',$idempotencyKey,$amountCents);
            $payments->confirmVerified([
                'payment_id'=>(int)$payment['id'],'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'manual',
                'provider_payment_id'=>'CASH-GO-'.$tenantId.'-'.$payment['id'],'amount_cents'=>(int)$payment['amount_cents'],
                'currency'=>'BRL','account_reference'=>'manual','source'=>'eventmenu_go_pos',
            ]);
            $cash->recordPaidPayment((int)$payment['id'],'cash');
            Auth::audit('payment.cash_split','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>(int)$payment['amount_cents'],'user_id'=>$userId]);
            return $this->status($orderId);
        });
    }
}
