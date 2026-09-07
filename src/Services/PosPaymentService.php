<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\Payments\PaymentProviderRegistry;
use PDO;
use RuntimeException;

final class PosPaymentService
{
    public function status(int $orderId):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $balance=(new PaymentService())->remaining($orderId,$tenantId);
        $s=Database::connection()->prepare('SELECT id,provider,amount_cents,status,verified_at,created_at,raw_payload FROM payments WHERE tenant_id=? AND order_id=? ORDER BY id');$s->execute([$tenantId,$orderId]);
        $rows=[];
        foreach($s->fetchAll() as$row){
            $raw=json_decode((string)($row['raw_payload']??''),true);$raw=is_array($raw)?$raw:[];
            $source=(string)($raw['source']??'');$method=(string)($raw['payment_method_type']??'');
            $row['payment_method']=$method!==''?$method:($row['provider']==='manual'&&str_contains($source,'cash')?'cash':'');
            $row['source']=$source;
            $row['machine_label']=(string)($raw['machine_label']??'');
            $row['transaction_reference']=(string)($raw['transaction_reference']??'');
            unset($row['raw_payload']);$rows[]=$row;
        }
        $balance['payments']=$rows;return $balance;
    }

    public function cash(int $orderId,int $amountCents,string $idempotencyKey):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('cash.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');
        $runtime=(new PaymentProviderRegistry())->runtimeOptions($tenantId);if(empty($runtime['cash_enabled']))throw new RuntimeException('Pagamento em dinheiro está desativado para esta empresa.');
        if($amountCents<=0)throw new RuntimeException('Informe um valor de parcela maior que zero.');if(strlen(trim($idempotencyKey))<12)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$amountCents,$idempotencyKey):array{
            $cash=new CashService();if(!$cash->currentSession())throw new RuntimeException('Abra o caixa antes de receber dinheiro.');
            $payments=new PaymentService();$payment=$payments->create($orderId,'manual',$idempotencyKey,$amountCents);
            $payments->confirmVerified([
                'payment_id'=>(int)$payment['id'],'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'manual',
                'provider_payment_id'=>'CASH-GO-'.$tenantId.'-'.$payment['id'],'amount_cents'=>(int)$payment['amount_cents'],
                'currency'=>'BRL','account_reference'=>'manual','source'=>'eventmenu_go_cash','payment_method_type'=>'cash',
            ]);
            $cash->recordPaidPayment((int)$payment['id'],'cash');
            Auth::audit('payment.cash_split','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>(int)$payment['amount_cents'],'user_id'=>$userId]);
            return $this->status($orderId);
        });
    }

    public function externalTerminal(int $orderId,int $amountCents,string $paymentMethod,string $machineLabel,string $transactionReference,string $idempotencyKey,string $deviceId=''):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Operador ou empresa inválidos.');
        $shift=(new WorkShiftService())->current();if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('Inicie um turno de Operação ou Pay para confirmar maquininha externa.');
        $shiftUnit=$shift['unit_id']!==null?(int)$shift['unit_id']:null;
        $runtime=(new PaymentProviderRegistry())->runtimeOptions($tenantId);if(empty($runtime['external_terminal_enabled']))throw new RuntimeException('Pagamento por maquininha externa está desativado para esta empresa.');
        $paymentMethod=strtolower(trim($paymentMethod));if(!in_array($paymentMethod,['credit','debit'],true))throw new RuntimeException('Escolha crédito ou débito.');
        if($amountCents<=0)throw new RuntimeException('Informe um valor maior que zero.');if(strlen(trim($idempotencyKey))<12)throw new RuntimeException('Chave de idempotência inválida.');
        $machineLabel=mb_substr(trim($machineLabel),0,120);$transactionReference=mb_substr(trim($transactionReference),0,190);
        if(!empty($runtime['external_terminal_reference_required'])&&$transactionReference==='')throw new RuntimeException('Informe o código/NSU da transação da maquininha.');
        if($transactionReference==='')$transactionReference='SEM-REF-'.strtoupper(bin2hex(random_bytes(6)));

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$amountCents,$paymentMethod,$machineLabel,$transactionReference,$idempotencyKey,$deviceId,$shiftUnit):array{
            $dupe=$pdo->prepare('SELECT payment_id,order_id FROM external_terminal_payments WHERE tenant_id=? AND transaction_reference=? LIMIT 1');$dupe->execute([$tenantId,$transactionReference]);$existing=$dupe->fetch();
            if($existing){if((int)$existing['order_id']!==$orderId)throw new RuntimeException('Este código/NSU já foi usado em outro pedido.');return $this->status($orderId);}

            $o=$pdo->prepare(Database::portableSql($pdo,'SELECT unit_id FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$o->execute([$orderId,$tenantId]);$unitId=$o->fetchColumn();if($unitId===false)throw new RuntimeException('Pedido não encontrado.');$orderUnit=$unitId!==null?(int)$unitId:null;
            if($shiftUnit!==null&&$orderUnit!==$shiftUnit)throw new RuntimeException('Este pedido pertence a outra unidade. Troque a unidade/turno antes de receber.');

            $payments=new PaymentService();$payment=$payments->create($orderId,'terminal_manual',$idempotencyKey,$amountCents);
            $providerPaymentId='EXT-'.hash('sha256',$tenantId.'|'.$transactionReference);
            $payments->confirmVerified([
                'payment_id'=>(int)$payment['id'],'tenant_id'=>$tenantId,'order_id'=>$orderId,'provider'=>'terminal_manual',
                'provider_payment_id'=>$providerPaymentId,'amount_cents'=>(int)$payment['amount_cents'],'currency'=>'BRL','account_reference'=>'manual_terminal',
                'source'=>'external_terminal','payment_method_type'=>$paymentMethod,'machine_label'=>$machineLabel,'transaction_reference'=>$transactionReference,
            ]);
            $pdo->prepare('INSERT INTO external_terminal_payments (tenant_id,unit_id,order_id,payment_id,user_id,device_id,payment_method,machine_label,transaction_reference,amount_cents) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$tenantId,$orderUnit,$orderId,(int)$payment['id'],$userId,$deviceId!==''?$deviceId:null,$paymentMethod,$machineLabel!==''?$machineLabel:null,$transactionReference,(int)$payment['amount_cents']]);
            Auth::audit('payment.external_terminal_confirmed','payment',(string)$payment['id'],['order_id'=>$orderId,'amount_cents'=>(int)$payment['amount_cents'],'payment_method'=>$paymentMethod,'machine_label'=>$machineLabel,'transaction_reference'=>$transactionReference,'user_id'=>$userId,'unit_id'=>$orderUnit]);
            return $this->status($orderId);
        });
    }
}
