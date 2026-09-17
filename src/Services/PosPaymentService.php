<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PosPaymentService
{
    public function status(int $orderId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        if($orderId<1)throw new RuntimeException('Pedido inválido.');

        $pdo=Database::connection();
        $o=$pdo->prepare('SELECT id,channel,assigned_delivery_user_id,unit_id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');
        $o->execute([$orderId,$tenantId]);$order=$o->fetch();
        if(!$order)throw new RuntimeException('Pedido não encontrado.');

        $shift=(new WorkShiftService())->current();
        $mode=(string)($shift['mode']??'');
        if($mode==='delivery'){
            if(!Auth::can('orders.delivery')||$order['channel']!=='delivery'||(int)($order['assigned_delivery_user_id']??0)!==$userId){
                throw new RuntimeException('Este pedido não está atribuído a você.');
            }
        }elseif(
            !Auth::can('payments.manage')&&
            !Auth::can('cash.manage')&&
            !Auth::can('orders.create')&&
            !Auth::can('orders.view')&&
            !Auth::can('orders.manage')&&
            !Auth::can('nfc.collect')
        ){
            throw new RuntimeException('Sua função não pode consultar o pagamento deste pedido.');
        }

        if($shift&&$shift['unit_id']!==null&&($order['unit_id']===null||(int)$order['unit_id']!==(int)$shift['unit_id'])){
            throw new RuntimeException('Este pedido pertence a outra unidade.');
        }

        $balance=(new PaymentService())->remaining($orderId,$tenantId);
        $s=$pdo->prepare('SELECT id,provider,amount_cents,status,verified_at,created_at FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id');
        $s->execute([$tenantId,$orderId]);
        $balance['payments']=$s->fetchAll();
        return $balance;
    }

    public function cash(int $orderId,int $amountCents,string $idempotencyKey):array
    {
        if(!Auth::can('payments.manage'))throw new RuntimeException('Sua função não pode receber pagamentos.');
        if(!Auth::can('cash.manage'))throw new RuntimeException('Sua função não pode operar o caixa.');
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
