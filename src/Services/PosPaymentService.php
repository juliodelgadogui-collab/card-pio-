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
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');

        // Operators with payment permission can inspect any order in their tenant.
        // A delivery user can only poll the payment of a delivery assigned to them;
        // this is necessary because delivery PIX is allowed after arrival confirmation.
        if(!Auth::can('payments.manage')){
            if(!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado ao pagamento.');
            $assigned=Database::connection()->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND channel="delivery" AND assigned_delivery_user_id=? LIMIT 1');
            $assigned->execute([$orderId,$tenantId,$userId]);
            if(!$assigned->fetchColumn())throw new RuntimeException('Este pedido não está atribuído a você.');
        }

        $balance=(new PaymentService())->remaining($orderId,$tenantId);
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT id,provider,provider_payment_id,amount_cents,currency,status,verified_at,created_at FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id');
        $s->execute([$tenantId,$orderId]);
        $payments=$s->fetchAll();
        $balance['payments']=$payments;

        $latestPix=null;
        for($i=count($payments)-1;$i>=0;$i--){
            $row=$payments[$i];
            if(!in_array((string)$row['provider'],['mercadopago','pagbank'],true))continue;
            $latestPix=[
                'payment_id'=>(int)$row['id'],
                'provider'=>(string)$row['provider'],
                'provider_payment_id'=>(string)($row['provider_payment_id']??''),
                'amount_cents'=>(int)$row['amount_cents'],
                'status'=>(string)$row['status'],
                'verified_at'=>$row['verified_at']??null,
                'created_at'=>$row['created_at']??null,
            ];
            break;
        }
        $balance['latest_pix']=$latestPix;
        return $balance;
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
