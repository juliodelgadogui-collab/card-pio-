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
            $openPayment=$tx->prepare(Database::portableSql($tx,'SELECT id,provider FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE'));
            $openPayment->execute([$tenantId,$orderId]);if($openPayment->fetch())throw new RuntimeException('Já existe outra cobrança pendente para este pedido.');

            $intentToken=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+300);
            $paymentKey='tef:'.$tenantId.':'.$intentToken;
            $paymentInsert=$tx->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,"tef",?,?,"BRL","pending")');
            $paymentInsert->execute([$tenantId,$orderId,$paymentKey,$amountCents]);$paymentId=(int)$tx->lastInsertId();
            $tx->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([$orderId,$tenantId]);

            $s=$tx->prepare('INSERT INTO terminal_payment_intents (tenant_id,unit_id,order_id,payment_id,terminal_config_id,device_hash,provider,intent_token,idempotency_key,amount_cents,payment_type,installments,status,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,"created",?)');
            $s->execute([$tenantId,$unitId,$orderId,$paymentId,$terminalConfigId,$deviceHash,(string)$terminal['provider'],$intentToken,$idempotencyKey,$amountCents,$paymentType,$installments,$expires]);
            $id=(int)$tx->lastInsertId();
            Auth::audit('terminal.intent_created','terminal_payment_intent',(string)$id,['order_id'=>$orderId,'payment_id'=>$paymentId,'unit_id'=>$unitId,'provider'=>$terminal['provider'],'amount_cents'=>$amountCents,'payment_type'=>$paymentType,'installments'=>$installments]);
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
                if(!empty($intent['payment_id']))$tx->prepare('UPDATE payments SET status="failed",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status IN ("created","pending","authorized")')->execute([$intent['payment_id'],$intent['tenant_id']]);
                throw new RuntimeException('A cobrança TEF expirou.');
            }
            $providerTransactionId=mb_substr(trim($providerTransactionId),0,190);$authorizationCode=mb_substr(trim($authorizationCode),0,120);
            $status=$approved?'approved_local':'failed';
            $encrypted=$rawResult?Crypto::encrypt($rawResult):null;
            $s=$tx->prepare('UPDATE terminal_payment_intents SET status=?,provider_transaction_id=?,authorization_code=?,local_result_encrypted=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $s->execute([$status,$providerTransactionId?:null,$authorizationCode?:null,$encrypted,$intent['id']]);
            if(!$approved&&!empty($intent['payment_id']))$tx->prepare('UPDATE payments SET status="failed",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status IN ("created","pending","authorized")')->execute([$intent['payment_id'],$intent['tenant_id']]);
            Auth::audit($approved?'terminal.local_approved':'terminal.local_failed','terminal_payment_intent',(string)$intent['id'],['order_id'=>(int)$intent['order_id'],'payment_id'=>(int)($intent['payment_id']??0),'provider'=>$intent['provider'],'provider_transaction_id'=>$providerTransactionId?:null]);
            // approved_local deliberadamente NÃO confirma payment. Somente confirmProviderVerified(),
            // chamado por um adaptador que verificou a transação no provedor, pode liquidar o pedido.
            return$this->reload($tx,(int)$intent['id']);
        });
    }

    /**
     * Entrada interna para adaptadores de provedor. Não é exposta diretamente em api-desktop.php/api-hub.php.
     * O adaptador deve consultar o provedor/adquirente e entregar aqui somente dados já verificados.
     */
    public function confirmProviderVerified(int $tenantId,string $intentToken,array $verification):array
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        foreach(['terminal_provider','provider_transaction_id','amount_cents','currency','account_reference'] as $key)if(!array_key_exists($key,$verification))throw new RuntimeException('Verificação TEF incompleta.');
        $provider=strtolower(trim((string)$verification['terminal_provider']));$providerTransactionId=trim((string)$verification['provider_transaction_id']);
        if($providerTransactionId==='')throw new RuntimeException('Transação do provedor não identificada.');

        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT tpi.*,ptc.config_encrypted FROM terminal_payment_intents tpi JOIN payment_terminal_configs ptc ON ptc.id=tpi.terminal_config_id AND ptc.tenant_id=tpi.tenant_id WHERE tpi.tenant_id=? AND tpi.intent_token=? LIMIT 1');
        $q->execute([$tenantId,trim($intentToken)]);$intent=$q->fetch();if(!$intent)throw new RuntimeException('Cobrança TEF não encontrada.');
        if((string)$intent['status']==='verified')return$this->publicIntent($intent);
        if(in_array((string)$intent['status'],['failed','cancelled','expired'],true))throw new RuntimeException('Esta cobrança TEF não pode mais ser confirmada.');
        if(strtotime((string)$intent['expires_at'])<time())throw new RuntimeException('A cobrança TEF expirou antes da confirmação.');
        if(!hash_equals(strtolower((string)$intent['provider']),$provider))throw new RuntimeException('Provedor TEF divergente.');
        if((int)$verification['amount_cents']!==(int)$intent['amount_cents'])throw new RuntimeException('Valor TEF divergente.');
        if(strtoupper((string)$verification['currency'])!=='BRL')throw new RuntimeException('Moeda TEF divergente.');
        if(!empty($intent['provider_transaction_id'])&&!hash_equals((string)$intent['provider_transaction_id'],$providerTransactionId))throw new RuntimeException('Identificador da transação TEF divergente.');
        if(empty($intent['payment_id']))throw new RuntimeException('Cobrança TEF sem pagamento contábil vinculado.');

        $config=[];if(!empty($intent['config_encrypted'])){try{$config=Crypto::decryptJson((string)$intent['config_encrypted']);}catch(\Throwable){$config=[];}}
        $expectedAccount=trim((string)($config['account_reference']??''));
        if($expectedAccount!==''&&!hash_equals($expectedAccount,trim((string)$verification['account_reference'])))throw new RuntimeException('Conta do estabelecimento divergente na verificação TEF.');

        $paymentProviderId=$provider.':'.$providerTransactionId;
        (new PaymentService())->confirmVerified([
            'tenant_id'=>$tenantId,
            'order_id'=>(int)$intent['order_id'],
            'payment_id'=>(int)$intent['payment_id'],
            'provider'=>'tef',
            'provider_payment_id'=>$paymentProviderId,
            'amount_cents'=>(int)$intent['amount_cents'],
            'currency'=>'BRL',
            'account_reference'=>'terminal:'.(int)$intent['terminal_config_id'],
            'source'=>'tef:'.$provider,
            'terminal_provider'=>$provider,
            'terminal_transaction_id'=>$providerTransactionId,
            'external_account_reference'=>(string)$verification['account_reference'],
        ]);

        return Database::transaction(function(PDO $tx)use($tenantId,$intent,$verification,$providerTransactionId):array{
            $lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM terminal_payment_intents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$lock->execute([$intent['id'],$tenantId]);$current=$lock->fetch();if(!$current)throw new RuntimeException('Cobrança TEF não encontrada.');
            if((string)$current['status']!=='verified'){
                $proof=$verification;$proof['provider_transaction_id']=$providerTransactionId;
                $tx->prepare('UPDATE terminal_payment_intents SET status="verified",provider_transaction_id=?,verification_result_encrypted=?,verified_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$providerTransactionId,Crypto::encrypt($proof),$current['id'],$tenantId]);
                Auth::audit('terminal.verified','terminal_payment_intent',(string)$current['id'],['order_id'=>(int)$current['order_id'],'payment_id'=>(int)$current['payment_id'],'provider'=>$current['provider'],'provider_transaction_id'=>$providerTransactionId]);
            }
            return$this->reload($tx,(int)$current['id']);
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
    private function publicIntent(array $row):array{unset($row['device_hash'],$row['local_result_encrypted'],$row['verification_result_encrypted'],$row['config_encrypted']);return$row;}
}
