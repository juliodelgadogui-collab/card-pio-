<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class TabSplitPaymentService
{
    private const SPLITS=['value','percentage','person','product'];
    private const METHODS=['cash','pix','nfc'];

    public function account(int $tabId):array
    {
        Auth::requirePermission('tables.manage');
        $tenantId=Auth::tenantId();if(!$tenantId||$tabId<1)throw new RuntimeException('Comanda inválida.');$pdo=Database::connection();
        $tab=(new TableService())->details($tabId);
        $items=$pdo->prepare('SELECT oi.id order_item_id,oi.order_id,oi.product_id,oi.name_snapshot,oi.unit_price_cents,oi.quantity,oi.total_cents,o.status order_status,
            CASE WHEN EXISTS(SELECT 1 FROM payment_group_items pgi JOIN payment_groups pg ON pg.id=pgi.payment_group_id WHERE pgi.tenant_id=o.tenant_id AND pgi.order_item_id=oi.id AND pg.status IN ("created","pending","paid","attention")) THEN 1 ELSE 0 END split_used
            FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.tenant_id=? AND o.tab_id=? AND o.status<>"cancelled" ORDER BY o.id,oi.id');
        $items->execute([$tenantId,$tabId]);$tab['items']=$items->fetchAll();
        $open=$pdo->prepare('SELECT id,method,split_type,amount_cents,status,created_at FROM payment_groups WHERE tenant_id=? AND tab_id=? AND status IN ("created","pending") ORDER BY id DESC LIMIT 1');$open->execute([$tenantId,$tabId]);$tab['open_group']=$open->fetch()?:null;
        return $tab;
    }

    public function create(int $tabId,string $splitType,string $method,array $options,string $idempotencyKey):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('tables.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $splitType=strtolower(trim($splitType));$method=strtolower(trim($method));$idempotencyKey=trim($idempotencyKey);
        if(!in_array($splitType,self::SPLITS,true)||!in_array($method,self::METHODS,true))throw new RuntimeException('Forma de divisão/pagamento inválida.');
        if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');
        if($method==='cash')Auth::requirePermission('cash.manage');
        if($method==='nfc')Auth::requirePermission('nfc.collect');
        $provider=$method==='cash'?'manual':'pagbank';

        $groupId=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$tabId,$splitType,$method,$provider,$options,$idempotencyKey):int{
            $existing=$pdo->prepare('SELECT id FROM payment_groups WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotencyKey]);if($id=$existing->fetchColumn())return (int)$id;
            $tab=$pdo->prepare(Database::portableSql($pdo,'SELECT t.id,t.status,t.table_id FROM tabs t WHERE t.id=? AND t.tenant_id=? FOR UPDATE'));$tab->execute([$tabId,$tenantId]);$tabRow=$tab->fetch();if(!$tabRow||$tabRow['status']!=='open')throw new RuntimeException('A comanda precisa estar aberta.');
            $open=$pdo->prepare(Database::portableSql($pdo,'SELECT id FROM payment_groups WHERE tenant_id=? AND tab_id=? AND status IN ("created","pending") ORDER BY id DESC LIMIT 1 FOR UPDATE'));$open->execute([$tenantId,$tabId]);if($open->fetchColumn())throw new RuntimeException('Já existe uma divisão/cobrança em andamento nesta comanda. Finalize ou cancele antes de iniciar outra.');

            $orders=$this->orderBalances($pdo,$tenantId,$tabId,true);if(!$orders)throw new RuntimeException('A comanda não possui saldo a receber.');$remainingTotal=array_sum(array_column($orders,'remaining_cents'));if($remainingTotal<=0)throw new RuntimeException('Comanda já está integralmente paga.');
            [$allocations,$itemRows,$target,$meta]=$this->buildPlan($pdo,$tenantId,$tabId,$splitType,$options,$orders,$remainingTotal);
            if($target<=0||!$allocations||array_sum($allocations)!==$target)throw new RuntimeException('Plano de divisão inválido.');

            foreach($allocations as $orderId=>$amount){
                $busy=$pdo->prepare(Database::portableSql($pdo,'SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") ORDER BY id DESC LIMIT 1 FOR UPDATE'));$busy->execute([$tenantId,$orderId]);if($busy->fetchColumn())throw new RuntimeException('O pedido #'.$orderId.' já possui uma cobrança em andamento.');
                $row=$orders[$orderId]??null;if(!$row||$amount<1||$amount>(int)$row['remaining_cents'])throw new RuntimeException('A alocação ultrapassa o saldo do pedido #'.$orderId.'.');
            }

            $insert=$pdo->prepare('INSERT INTO payment_groups (tenant_id,tab_id,user_id,provider,method,split_type,idempotency_key,amount_cents,currency,status,metadata) VALUES (?,?,?,?,?,?,?,?,"BRL","created",?)');
            $insert->execute([$tenantId,$tabId,$userId,$provider,$method,$splitType,$idempotencyKey,$target,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$groupId=(int)$pdo->lastInsertId();
            $pay=$pdo->prepare('INSERT INTO payments (tenant_id,order_id,payment_group_id,provider,idempotency_key,amount_cents,currency,status) VALUES (?,?,?,?,?,?,"BRL","authorized")');
            $alloc=$pdo->prepare('INSERT INTO payment_group_allocations (tenant_id,payment_group_id,order_id,payment_id,amount_cents,metadata) VALUES (?,?,?,?,?,?)');
            foreach($allocations as $orderId=>$amount){
                $key='group:'.$groupId.':order:'.$orderId;$pay->execute([$tenantId,$orderId,$groupId,$provider,$key,$amount]);$paymentId=(int)$pdo->lastInsertId();
                $alloc->execute([$tenantId,$groupId,$orderId,$paymentId,$amount,null]);$pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([$orderId,$tenantId]);
            }
            if($itemRows){$pi=$pdo->prepare('INSERT INTO payment_group_items (tenant_id,payment_group_id,order_item_id,amount_cents) VALUES (?,?,?,?)');foreach($itemRows as $item)$pi->execute([$tenantId,$groupId,$item['id'],$item['amount_cents']]);}
            Auth::audit('tab.payment_group_created','payment_group',(string)$groupId,['tab_id'=>$tabId,'split_type'=>$splitType,'method'=>$method,'amount_cents'=>$target,'allocations'=>$allocations]);
            return $groupId;
        });

        if($method==='cash')$this->settleCash($groupId);
        return $this->status($groupId);
    }

    public function settleCash(int $groupId):array
    {
        Auth::requirePermission('payments.manage');Auth::requirePermission('cash.manage');
        $group=$this->loadOwnedGroup($groupId);if($group['provider']!=='manual'||$group['method']!=='cash')throw new RuntimeException('Este grupo não é pagamento em dinheiro.');
        if($group['status']==='paid')return $this->status($groupId);if($group['status']!=='created')throw new RuntimeException('Grupo não pode ser recebido em dinheiro neste estado.');
        if(!(new CashService())->currentSession())throw new RuntimeException('Abra o caixa antes de receber dinheiro.');
        $allocations=$this->allocations($groupId);
        foreach($allocations as $a){
            (new PaymentService())->confirmVerified(['payment_id'=>(int)$a['payment_id'],'tenant_id'=>(int)$group['tenant_id'],'order_id'=>(int)$a['order_id'],'provider'=>'manual','provider_payment_id'=>'GROUP-CASH-'.$groupId.'-'.$a['payment_id'],'amount_cents'=>(int)$a['amount_cents'],'currency'=>'BRL','account_reference'=>'manual','source'=>'tab_split_cash','payment_group_id'=>$groupId]);
            (new CashService())->recordPaidPayment((int)$a['payment_id'],'cash');
        }
        Database::connection()->prepare('UPDATE payment_groups SET status="paid",provider_payment_id=?,verified_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="created"')->execute(['CASH-GROUP-'.$groupId,$groupId,$group['tenant_id']]);
        Auth::audit('tab.payment_group_paid','payment_group',(string)$groupId,['method'=>'cash','amount_cents'=>(int)$group['amount_cents']]);return $this->status($groupId);
    }

    public function markPending(int $groupId,string $providerPaymentId,array $raw=[]):array
    {
        $group=$this->loadOwnedGroup($groupId);if($group['provider']!=='pagbank')throw new RuntimeException('Grupo não usa PagBank.');if(!in_array($group['status'],['created','pending'],true))throw new RuntimeException('Grupo não aceita cobrança eletrônica.');
        $providerPaymentId=trim($providerPaymentId);if($providerPaymentId==='')throw new RuntimeException('Identificador do provedor ausente.');
        Database::connection()->prepare('UPDATE payment_groups SET status="pending",provider_payment_id=?,raw_payload=? WHERE id=? AND tenant_id=? AND status IN ("created","pending")')->execute([$providerPaymentId,$raw?json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$groupId,$group['tenant_id']]);return $this->status($groupId);
    }

    public function settlePagBank(int $groupId,string $providerTransactionId,int $amountCents,string $accountReference,string $rawStatus,array $rawPayload=[]):array
    {
        $group=$this->loadOwnedGroup($groupId);if($group['provider']!=='pagbank')throw new RuntimeException('Grupo não usa PagBank.');if(!in_array($group['status'],['created','pending','attention'],true))return $this->status($groupId);
        if($amountCents!==(int)$group['amount_cents'])throw new RuntimeException('Valor confirmado pelo PagBank diverge do grupo.');
        $pdo=Database::connection();$gw=$pdo->prepare('SELECT account_reference FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$gw->execute([$group['tenant_id']]);$expected=$gw->fetchColumn();if($expected===false||!hash_equals((string)$expected,(string)$accountReference))throw new RuntimeException('Conta PagBank divergente.');
        $dupe=$pdo->prepare('SELECT id FROM payment_groups WHERE tenant_id=? AND provider="pagbank" AND provider_payment_id=? AND id<>? LIMIT 1');$dupe->execute([$group['tenant_id'],$providerTransactionId,$groupId]);if($dupe->fetchColumn())throw new RuntimeException('Transação PagBank já vinculada a outro grupo.');
        $pdo->prepare('UPDATE payment_groups SET status="pending",provider_payment_id=?,raw_payload=? WHERE id=? AND tenant_id=?')->execute([$providerTransactionId,json_encode($rawPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$groupId,$group['tenant_id']]);
        $attention=false;foreach($this->allocations($groupId) as $a){
            $synthetic=$providerTransactionId.':G'.$groupId.':P'.$a['payment_id'];
            (new PaymentService())->confirmVerified(['payment_id'=>(int)$a['payment_id'],'tenant_id'=>(int)$group['tenant_id'],'order_id'=>(int)$a['order_id'],'provider'=>'pagbank','provider_payment_id'=>$synthetic,'amount_cents'=>(int)$a['amount_cents'],'currency'=>'BRL','account_reference'=>$accountReference,'raw_status'=>$rawStatus,'source'=>'tab_split_'.$group['method'],'payment_group_id'=>$groupId,'group_provider_payment_id'=>$providerTransactionId]);
            $s=$pdo->prepare('SELECT status FROM payments WHERE id=?');$s->execute([$a['payment_id']]);if($s->fetchColumn()!=='paid')$attention=true;
        }
        $status=$attention?'attention':'paid';$pdo->prepare('UPDATE payment_groups SET status=?,verified_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$status,$groupId,$group['tenant_id']]);
        if($attention){try{(new NotificationService())->publishToPermissionForTenant((int)$group['tenant_id'],'refunds.manage',null,'payment.group_attention','Divisão exige conferência','Uma cobrança de comanda foi confirmada pelo PagBank, mas nem todas as alocações puderam ser aplicadas. Grupo #'.$groupId.'.','payment_group',(string)$groupId,'payment-group:'.$groupId.':attention','warning',gmdate('Y-m-d H:i:s',time()+604800));}catch(\Throwable){}}
        Auth::audit('tab.payment_group_verified','payment_group',(string)$groupId,['provider'=>'pagbank','provider_transaction_id'=>$providerTransactionId,'status'=>$status]);return $this->status($groupId);
    }

    public function cancel(int $groupId):array
    {
        $group=$this->loadOwnedGroup($groupId);if($group['status']==='cancelled')return $this->status($groupId);if($group['status']!=='created'||$group['provider_payment_id'])throw new RuntimeException('Cobrança já enviada ao provedor não pode ser cancelada localmente.');
        Database::transaction(function(PDO $pdo)use($group,$groupId):void{
            $pdo->prepare('UPDATE payment_groups SET status="cancelled" WHERE id=? AND tenant_id=? AND status="created"')->execute([$groupId,$group['tenant_id']]);$pdo->prepare('UPDATE payments SET status="cancelled" WHERE tenant_id=? AND payment_group_id=? AND status="authorized"')->execute([$group['tenant_id'],$groupId]);
            foreach($this->allocations($groupId) as $a){$paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$group['tenant_id'],$a['order_id']]);$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([(int)$paid->fetchColumn()>0?'pending':'unpaid',$a['order_id'],$group['tenant_id']]);}
        });Auth::audit('tab.payment_group_cancelled','payment_group',(string)$groupId,[]);return $this->status($groupId);
    }

    public function status(int $groupId):array
    {
        $group=$this->loadOwnedGroup($groupId);$group['amount_cents']=(int)$group['amount_cents'];$group['allocations']=$this->allocations($groupId);
        if($group['tab_id']){$account=(new TableService())->details((int)$group['tab_id']);$group['tab_remaining_cents']=(int)$account['remaining_cents'];}
        return $group;
    }

    private function buildPlan(PDO $pdo,int $tenantId,int $tabId,string $splitType,array $options,array $orders,int $remainingTotal):array
    {
        $allocations=[];$items=[];$meta=[];$target=0;
        if($splitType==='value'){$target=(int)($options['amount_cents']??0);$meta=['amount_cents'=>$target];}
        elseif($splitType==='percentage'){$percentage=(float)str_replace(',','.',(string)($options['percentage']??0));if($percentage<=0||$percentage>100)throw new RuntimeException('Percentual deve ser maior que 0 e no máximo 100.');$target=$percentage>=100?$remainingTotal:max(1,(int)round($remainingTotal*($percentage/100)));$meta=['percentage'=>$percentage,'base_remaining_cents'=>$remainingTotal];}
        elseif($splitType==='person'){$people=(int)($options['people_remaining']??0);if($people<1||$people>100)throw new RuntimeException('Informe quantas pessoas ainda vão dividir o saldo.');$target=(int)ceil($remainingTotal/$people);$meta=['people_remaining'=>$people,'base_remaining_cents'=>$remainingTotal];}
        else{
            $raw=$options['item_ids']??[];if(!is_array($raw)||!$raw)throw new RuntimeException('Selecione ao menos um produto.');$ids=array_values(array_unique(array_filter(array_map('intval',$raw),static fn(int $id):bool=>$id>0)));if(!$ids)throw new RuntimeException('Itens inválidos.');
            $marks=implode(',',array_fill(0,count($ids),'?'));$sql='SELECT oi.id,oi.order_id,oi.name_snapshot,oi.total_cents FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.tenant_id=? AND o.tab_id=? AND o.status<>"cancelled" AND oi.id IN ('.$marks.')';$s=$pdo->prepare($sql);$s->execute(array_merge([$tenantId,$tabId],$ids));$found=$s->fetchAll();if(count($found)!==count($ids))throw new RuntimeException('Um item selecionado não pertence a esta comanda.');
            foreach($found as $item){$used=$pdo->prepare('SELECT 1 FROM payment_group_items pgi JOIN payment_groups pg ON pg.id=pgi.payment_group_id WHERE pgi.tenant_id=? AND pgi.order_item_id=? AND pg.status IN ("created","pending","paid","attention") LIMIT 1');$used->execute([$tenantId,$item['id']]);if($used->fetchColumn())throw new RuntimeException('O item '.$item['name_snapshot'].' já foi usado em outra divisão por produto.');$amount=(int)$item['total_cents'];$allocations[(int)$item['order_id']]=($allocations[(int)$item['order_id']]??0)+$amount;$target+=$amount;$items[]=['id'=>(int)$item['id'],'amount_cents'=>$amount];}
            foreach($allocations as $orderId=>$amount)if($amount>(int)($orders[$orderId]['remaining_cents']??0))throw new RuntimeException('Os produtos selecionados do pedido #'.$orderId.' ultrapassam o saldo atual. Use divisão por valor.');
            $meta=['item_ids'=>$ids];return [$allocations,$items,$target,$meta];
        }
        if($target<1||$target>$remainingTotal)throw new RuntimeException('Valor da divisão deve estar entre R$ 0,01 e o saldo da comanda.');$left=$target;
        foreach($orders as $orderId=>$row){if($left<=0)break;$amount=min($left,(int)$row['remaining_cents']);if($amount>0){$allocations[(int)$orderId]=$amount;$left-=$amount;}}
        if($left!==0)throw new RuntimeException('Não foi possível distribuir o valor entre os pedidos.');return [$allocations,$items,$target,$meta];
    }

    private function orderBalances(PDO $pdo,int $tenantId,int $tabId,bool $onlyOpen):array
    {
        $sql='SELECT o.id,o.total_cents,o.payment_status,o.status,COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.tenant_id=o.tenant_id AND p.order_id=o.id AND p.status="paid"),0) paid_cents FROM orders o WHERE o.tenant_id=? AND o.tab_id=? AND o.status<>"cancelled" ORDER BY o.id';$s=$pdo->prepare($sql);$s->execute([$tenantId,$tabId]);$out=[];foreach($s->fetchAll() as $row){$remaining=max(0,(int)$row['total_cents']-(int)$row['paid_cents']);if($onlyOpen&&$remaining<=0)continue;$row['remaining_cents']=$remaining;$out[(int)$row['id']]=$row;}return $out;
    }

    private function allocations(int $groupId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)return [];$s=Database::connection()->prepare('SELECT a.id,a.order_id,a.payment_id,a.amount_cents,p.status payment_status,o.total_cents,o.payment_status order_payment_status FROM payment_group_allocations a JOIN payments p ON p.id=a.payment_id JOIN orders o ON o.id=a.order_id WHERE a.tenant_id=? AND a.payment_group_id=? ORDER BY a.id');$s->execute([$tenantId,$groupId]);return $s->fetchAll();
    }

    private function loadOwnedGroup(int $groupId):array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();if(!$tenantId||$groupId<1)throw new RuntimeException('Grupo inválido.');$s=Database::connection()->prepare('SELECT * FROM payment_groups WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$groupId,$tenantId]);$group=$s->fetch();if(!$group)throw new RuntimeException('Grupo de pagamento não encontrado.');return $group;
    }
}
