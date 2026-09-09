<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class TerminalPaymentService
{
    public function createIntent(int $orderId,int $terminalConfigId,int $amountCents,string $paymentType,int $installments,string $deviceId,string $idempotencyKey):array
    {
        Auth::requirePermission('terminal.collect');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$orderId<1||$terminalConfigId<1)throw new RuntimeException('Cobrança TEF inválida.');
        if($amountCents<=0)throw new RuntimeException('Informe um valor maior que zero.');
        if(!in_array($paymentType,['debit','credit','pix','voucher'],true))throw new RuntimeException('Tipo de pagamento TEF inválido.');
        $installments=max(1,min(24,$installments));
        if($paymentType!=='credit')$installments=1;
        $deviceId=trim($deviceId);if(strlen($deviceId)<8)throw new RuntimeException('Computador não identificado.');
        $idempotencyKey=trim($idempotencyKey);if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $tx)use($tenantId,$orderId,$terminalConfigId,$amountCents,$paymentType,$installments,$deviceId,$idempotencyKey):array{
            $existing=$tx->prepare('SELECT * FROM terminal_payment_intents WHERE tenant_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$tenantId,$idempotencyKey]);if($row=$existing->fetch())return $this->publicIntent($row);

            $o=$tx->prepare(Database::portableSql($tx,'SELECT id,unit_id,status,payment_status,total_cents FROM orders WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $o->execute([$orderId,$tenantId]);$order=$o->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita nova cobrança.');
            $balance=(new PaymentService())->remaining($orderId,$tenantId);$remaining=(int)$balance['remaining_cents'];if($amountCents>$remaining)throw new RuntimeException('Valor maior que o saldo restante do pedido.');
            $unitId=(int)($order['unit_id']??0);if($unitId<1)throw new RuntimeException('Pedido sem unidade operacional definida.');

            $t=$tx->prepare('SELECT id,unit_id,provider,enabled,integration_mode,terminal_label,pinpad_identifier,auto_capture FROM payment_terminal_configs WHERE id=? AND tenant_id=? AND enabled=1 LIMIT 1');
            $t->execute([$terminalConfigId,$tenantId]);$terminal=$t->fetch();if(!$terminal)throw new RuntimeException('Terminal TEF não está ativo.');
            if((int)$terminal['unit_id']!==$unitId)throw new RuntimeException('Terminal e pedido pertencem a unidades diferentes.');

            $deviceHash=hash('sha256',$deviceId);
            $binding=$tx->prepare('SELECT id,revoked_at FROM desktop_hardware_bindings WHERE tenant_id=? AND unit_id=? AND device_hash=? LIMIT 1');
            $binding->execute([$tenantId,$unitId,$deviceHash]);$bound=$binding->fetch();
            if(!$bound)throw new RuntimeException('Este computador precisa ser registrado para usar TEF.');
            if(!empty($bound['revoked_at']))throw new RuntimeException('Este computador foi revogado.');

            $active=$tx->prepare('SELECT id FROM terminal_payment_intents WHERE tenant_id=? AND order_id=? AND status IN ("created","processing","approved_local") AND expires_at>CURRENT_TIMESTAMP LIMIT 1');
            $active->execute([$tenantId,$orderId]);if($active->fetchColumn())throw new RuntimeException('Já existe uma cobrança TEF em andamento para este pedido.');

            $intentToken=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+300);
            $s=$tx->prepare('INSERT INTO terminal_payment_intents (tenant_id,unit_id,order_id,terminal_config_id,device_hash,provider,intent_token,idempotency_key,amount_cents,payment_type,installments,status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,"created",?)');
            $s->execute([$tenantId,$unitId,$orderId,$terminalConfigId,$deviceHash,(string)$terminal['provider'],$intentToken,$idempotencyKey,$amountCents,$paymentType,$installments,$expires]);
            $id=(int)$tx->lastInsertId();
            Auth::audit('terminal.intent_created','terminal_payment_intent',(string)$id,['order_id'=>$orderId,'unit_id'=>$unitId,'provider'=>$terminal['provider'],'amount_cents'=>$amountCents,'payment_type'=>$paymentType,'installments'=>$installments]);
            $q=$tx->prepare('SELECT * FROM terminal_payment_intents WHERE id=?');$q->execute([$id]);$row=$q->fetch()?:throw new RuntimeException('Falha ao criar intenção TEF.');
            $out=$this->publicIntent($row);$out['terminal']=$terminal;return$out;
        });
    }

    public function markProcessing(string $intentToken,string $deviceId):array
    {
        return $this->mutateLocal($intentToken,$deviceId,function(PDO $tx,array $intent):array{
            if((string)$intent['status']==='created')$tx->prepare('UPDATE terminal_payment_intents SET status="processing",updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$intent['id']]);
            return$this->reload($tx,(int)$intent['id']);
        });
    }

    public function recordLocalResult(string $intentToken,string $deviceId,bool $approved,string $providerTransactionId,string $authorizationCode,array $rawResult=[]):array
    {
        return $this->mutateLocal($intentToken,$deviceId,function(PDO $tx,array $intent)use($approved,$providerTransactionId,$authorizationCode,$rawResult):array{
            if(in_array((string)$intent['status'],['verified','failed','cancelled','expired'],true))return$intent;
            if(strtotime((string)$intent['expires_at'])<time()){
                $tx->prepare('UPDATE terminal_payment_intents SET status="expired",updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$intent['id']]);
                throw new RuntimeException('A cobrança TEF expirou.');
            }
            $providerTransactionId=mb_substr(trim($providerTransactionId),0,190);$authorizationCode=mb_substr(trim($authorizationCode),0,120);
            $status=$approved?'approved_local':'failed';
            $encrypted=$rawResult?Crypto::encrypt($rawResult):null;
            $s=$tx->prepare('UPDATE terminal_payment_intents SET status=?,provider_transaction_id=?,authorization_code=?,local_result_encrypted=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute([$status,$providerTransactionId?:null,$authorizationCode?:null,$encrypted,$intent['id']]);
            Auth::audit($approved?'terminal.local_approved':'terminal.local_failed','terminal_payment_intent',(string)$intent['id'],['order_id'=>(int)$intent['order_id'],'provider'=>$intent['provider'],'provider_transaction_id'=>$providerTransactionId?:null]);
            // approved_local deliberadamente NÃO cria/confirmar payment. A etapa de verificação do provedor fará isso.
            return$this->reload($tx,(int)$intent['id']);
        });
    }

    public function status(string $intentToken):array
    {
        Auth::requirePermission('terminal.collect');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $s=Database::connection()->prepare('SELECT * FROM terminal_payment_intents WHERE tenant_id=? AND intent_token=? LIMIT 1');$s->execute([$tenantId,trim($intentToken)]);$row=$s->fetch();if(!$row)throw new RuntimeException('Cobrança TEF não encontrada.');return$this->publicIntent($row);
    }

    private function mutateLocal(string $intentToken,string $deviceId,callable $callback):array
    {
        Auth::requirePermission('terminal.collect');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$hash=hash('sha256',trim($deviceId));
        return Database::transaction(function(PDO $tx)use($tenantId,$intentToken,$hash,$callback):array{
            $s=$tx->prepare(Database::portableSql($tx,'SELECT * FROM terminal_payment_intents WHERE tenant_id=? AND intent_token=? LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,trim($intentToken)]);$intent=$s->fetch();if(!$intent)throw new RuntimeException('Cobrança TEF não encontrada.');if(!hash_equals((string)$intent['device_hash'],$hash))throw new RuntimeException('Cobrança pertence a outro computador.');return$callback($tx,$intent);
        });
    }

    private function reload(PDO $pdo,int $id):array{$q=$pdo->prepare('SELECT * FROM terminal_payment_intents WHERE id=? LIMIT 1');$q->execute([$id]);return$q->fetch()?:throw new RuntimeException('Intenção TEF não encontrada.');}
    private function publicIntent(array $row):array{unset($row['device_hash'],$row['local_result_encrypted'],$row['verification_result_encrypted']);return$row;}
}
