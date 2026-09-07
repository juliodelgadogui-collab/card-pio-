<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FinancialLedgerService
{
    private const DEFAULT_CATEGORIES=[
        ['Vendas','in','gross_revenue'],['Outras receitas','in','other_revenue'],['Descontos','out','discounts'],
        ['Taxas de pagamento','out','payment_fees'],['Estornos','out','refunds'],['CMV','out','cogs'],['Compras de estoque','out','inventory_purchase'],
        ['Comissões','out','commissions'],['Aluguel','out','operating_expense'],['Energia','out','operating_expense'],
        ['Água','out','operating_expense'],['Pessoal','out','operating_expense'],['Marketing','out','operating_expense'],
        ['Combustível','out','operating_expense'],['Manutenção','out','operating_expense'],['Impostos','out','taxes'],['Outras despesas','out','operating_expense'],
    ];

    public function ensureDefaults(PDO $pdo,int $tenantId):void
    {
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_categories (tenant_id,name,direction,dre_group) VALUES (?,?,?,?)');$stmt=$pdo->prepare($sql);
        foreach(self::DEFAULT_CATEGORIES as[$name,$direction,$group])$stmt->execute([$tenantId,$name,$direction,$group]);
    }

    public function recordOrderResult(PDO $pdo,int $tenantId,array $order):void
    {
        $this->ensureDefaults($pdo,$tenantId);$orderId=(int)$order['id'];$eventId=$this->eventForOrder($pdo,$tenantId,$orderId);$unitId=$order['unit_id']!==null?(int)$order['unit_id']:null;$date=date('Y-m-d');
        $gross=max(0,(int)$order['subtotal_cents']+(int)$order['delivery_fee_cents']+(int)($order['surcharge_cents']??0));
        if($gross>0)$this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$unitId,'event_id'=>$eventId,'order_id'=>$orderId,'category_id'=>$this->categoryId($pdo,$tenantId,'Vendas','in'),'direction'=>'in','entry_type'=>'order_revenue','description'=>'Venda · Pedido #'.$orderId,'gross_cents'=>$gross,'net_cents'=>$gross,'affects_result'=>1,'affects_cash'=>0,'status'=>'settled','competence_date'=>$date,'settled_at'=>date('Y-m-d H:i:s'),'idempotency_key'=>'order:'.$orderId.':revenue']);
        $discount=max(0,(int)$order['discount_cents']);
        if($discount>0)$this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$unitId,'event_id'=>$eventId,'order_id'=>$orderId,'category_id'=>$this->categoryId($pdo,$tenantId,'Descontos','out'),'direction'=>'out','entry_type'=>'sales_discount','description'=>'Descontos · Pedido #'.$orderId,'gross_cents'=>$discount,'net_cents'=>$discount,'affects_result'=>1,'affects_cash'=>0,'status'=>'settled','competence_date'=>$date,'settled_at'=>date('Y-m-d H:i:s'),'idempotency_key'=>'order:'.$orderId.':discount']);
        $q=$pdo->prepare('SELECT COALESCE(SUM(total_cost_cents),0) FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out"');$q->execute([$tenantId,$orderId]);$cogs=max(0,(int)$q->fetchColumn());
        if($cogs>0)$this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$unitId,'event_id'=>$eventId,'order_id'=>$orderId,'category_id'=>$this->categoryId($pdo,$tenantId,'CMV','out'),'direction'=>'out','entry_type'=>'cogs','description'=>'Custo dos itens vendidos · Pedido #'.$orderId,'gross_cents'=>$cogs,'net_cents'=>$cogs,'affects_result'=>1,'affects_cash'=>0,'status'=>'settled','competence_date'=>$date,'settled_at'=>date('Y-m-d H:i:s'),'idempotency_key'=>'order:'.$orderId.':cogs']);
    }

    public function recordPayment(PDO $pdo,int $tenantId,array $order,array $payment,array $verified):void
    {
        $this->ensureDefaults($pdo,$tenantId);$paymentId=(int)$payment['id'];$amount=(int)$payment['amount_cents'];$provider=(string)$payment['provider'];
        $source=strtolower((string)($verified['payment_method_type']??$verified['source']??''));$method=$this->paymentMethod($provider,$source);$rule=$this->feeRule($pdo,$tenantId,$provider,$method);
        $fee=min($amount,max(0,(int)round($amount*((int)$rule['percent_bps']/10000))+(int)$rule['fixed_cents']));$expectedNet=$amount-$fee;$days=max(0,(int)$rule['settlement_days']);
        $date=date('Y-m-d');$due=date('Y-m-d',strtotime('+'.$days.' days'));$status=$days===0?'settled':'open';$settled=$days===0?date('Y-m-d H:i:s'):null;
        $eventId=$this->eventForOrder($pdo,$tenantId,(int)$order['id']);$accountId=$this->accountForPayment($pdo,$tenantId,$order['unit_id']!==null?(int)$order['unit_id']:null,$provider,$method);
        $this->insert($pdo,[
            'tenant_id'=>$tenantId,'unit_id'=>$order['unit_id']?:null,'event_id'=>$eventId,'order_id'=>(int)$order['id'],'payment_id'=>$paymentId,
            'account_id'=>$accountId,'direction'=>'in','entry_type'=>'payment_receivable','description'=>'Recebível '.$provider.' · Pedido #'.$order['id'],
            'gross_cents'=>$amount,'fee_cents'=>$fee,'net_cents'=>$expectedNet,'affects_result'=>0,'affects_cash'=>1,'status'=>$status,'competence_date'=>$date,'due_date'=>$due,'settled_at'=>$settled,
            'external_reference'=>(string)($verified['provider_payment_id']??''),'idempotency_key'=>'payment:'.$paymentId.':receipt','metadata'=>['provider'=>$provider,'method'=>$method,'source'=>$source,'gross_cents'=>$amount,'expected_net_cents'=>$expectedNet],
        ]);
        if($fee>0){
            $feeCategory=$this->categoryId($pdo,$tenantId,'Taxas de pagamento','out');
            $this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$order['unit_id']?:null,'event_id'=>$eventId,'order_id'=>(int)$order['id'],'payment_id'=>$paymentId,'category_id'=>$feeCategory,'direction'=>'out','entry_type'=>'payment_fee','description'=>'Taxa '.$provider.' · Pedido #'.$order['id'],'gross_cents'=>$fee,'net_cents'=>$fee,'affects_result'=>1,'affects_cash'=>0,'status'=>$status,'competence_date'=>$date,'due_date'=>$due,'settled_at'=>$settled,'external_reference'=>(string)($verified['provider_payment_id']??''),'idempotency_key'=>'payment:'.$paymentId.':fee','metadata'=>['provider'=>$provider,'method'=>$method]]);
        }
    }

    public function recordRefund(PDO $pdo,int $tenantId,array $refund,array $order):void
    {
        $this->ensureDefaults($pdo,$tenantId);$amount=(int)$refund['amount_cents'];$category=$this->categoryId($pdo,$tenantId,'Estornos','out');
        $this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$order['unit_id']?:null,'event_id'=>$this->eventForOrder($pdo,$tenantId,(int)$order['id']),'order_id'=>(int)$order['id'],'payment_id'=>$refund['payment_id']??null,'category_id'=>$category,'direction'=>'out','entry_type'=>'refund','description'=>'Estorno do pedido #'.$order['id'],'gross_cents'=>$amount,'net_cents'=>$amount,'affects_result'=>1,'affects_cash'=>1,'status'=>'settled','competence_date'=>date('Y-m-d'),'due_date'=>date('Y-m-d'),'settled_at'=>date('Y-m-d H:i:s'),'idempotency_key'=>'refund:'.$refund['id'].':completed']);
    }

    public function createManual(int $tenantId,?int $unitId,?int $eventId,string $direction,string $entryType,string $description,int $amountCents,?int $categoryId,?int $accountId,string $competenceDate,?string $dueDate,string $status='open',array $metadata=[]):int
    {
        if(!in_array($direction,['in','out'],true)||$amountCents<=0||trim($description)==='')throw new RuntimeException('Dados financeiros inválidos.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$unitId,$eventId,$direction,$entryType,$description,$amountCents,$categoryId,$accountId,$competenceDate,$dueDate,$status,$metadata):int{
            $this->ensureDefaults($pdo,$tenantId);$key='manual:'.$tenantId.':'.bin2hex(random_bytes(12));
            return $this->insert($pdo,['tenant_id'=>$tenantId,'unit_id'=>$unitId,'event_id'=>$eventId,'account_id'=>$accountId,'category_id'=>$categoryId,'direction'=>$direction,'entry_type'=>$entryType,'description'=>$description,'gross_cents'=>$amountCents,'net_cents'=>$amountCents,'affects_result'=>1,'affects_cash'=>1,'status'=>$status,'competence_date'=>$competenceDate,'due_date'=>$dueDate,'settled_at'=>$status==='settled'?date('Y-m-d H:i:s'):null,'idempotency_key'=>$key,'metadata'=>$metadata,'created_by'=>Auth::id()]);
        });
    }

    public function settle(int $tenantId,int $entryId,?int $accountId=null):array
    {
        return Database::transaction(function(PDO $pdo)use($tenantId,$entryId,$accountId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM financial_entries WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$entryId,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Lançamento não encontrado.');if($row['status']==='cancelled')throw new RuntimeException('Lançamento cancelado não pode ser liquidado.');
            $pdo->prepare('UPDATE financial_entries SET status="settled",account_id=COALESCE(?,account_id),settled_at=COALESCE(settled_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$accountId,$entryId,$tenantId]);
            $q->execute([$entryId,$tenantId]);return $q->fetch();
        });
    }

    public function summary(int $tenantId,array $unitIds,string $from,string $to):array
    {
        $this->ensureDefaults(Database::connection(),$tenantId);$pdo=Database::connection();
        $args=[$tenantId,$from,$to];$unitSql='';if($unitIds){$unitSql=' AND fe.unit_id IN ('.implode(',',array_fill(0,count($unitIds),'?')).')';array_splice($args,1,0,$unitIds);}
        $q=$pdo->prepare('SELECT direction,dre_group,COALESCE(SUM(net_cents),0) total FROM financial_entries fe LEFT JOIN financial_categories fc ON fc.id=fe.category_id WHERE fe.tenant_id=?'.$unitSql.' AND fe.affects_result=1 AND fe.competence_date BETWEEN ? AND ? AND fe.status<>"cancelled" GROUP BY direction,dre_group');$q->execute($args);$groups=[];$income=0;$expense=0;foreach($q->fetchAll()as$r){$groups[$r['dre_group']??'uncategorized']=(int)$r['total'];if($r['direction']==='in')$income+=(int)$r['total'];else$expense+=(int)$r['total'];}
        $cashArgs=[$tenantId,$from.' 00:00:00',$to.' 23:59:59'];$cashSql='';if($unitIds){$cashSql=' AND unit_id IN ('.implode(',',array_fill(0,count($unitIds),'?')).')';array_splice($cashArgs,1,0,$unitIds);}$q=$pdo->prepare('SELECT direction,COALESCE(SUM(net_cents),0) total FROM financial_entries WHERE tenant_id=?'.$cashSql.' AND affects_cash=1 AND status="settled" AND settled_at BETWEEN ? AND ? GROUP BY direction');$q->execute($cashArgs);$cashIn=0;$cashOut=0;foreach($q->fetchAll()as$r){if($r['direction']==='in')$cashIn=(int)$r['total'];else$cashOut=(int)$r['total'];}
        $dueArgs=[$tenantId,date('Y-m-d')];$dueSql='';if($unitIds){$dueSql=' AND unit_id IN ('.implode(',',array_fill(0,count($unitIds),'?')).')';array_splice($dueArgs,1,0,$unitIds);}$q=$pdo->prepare('SELECT direction,COALESCE(SUM(net_cents),0) total FROM financial_entries WHERE tenant_id=?'.$dueSql.' AND affects_cash=1 AND status="open" AND due_date IS NOT NULL AND due_date<=? GROUP BY direction');$q->execute($dueArgs);$overdueIn=0;$overdueOut=0;foreach($q->fetchAll()as$r){if($r['direction']==='in')$overdueIn=(int)$r['total'];else$overdueOut=(int)$r['total'];}
        $forecastEnd=date('Y-m-d',strtotime('+30 days'));$fcArgs=[$tenantId,date('Y-m-d'),$forecastEnd];$fcSql='';if($unitIds){$fcSql=' AND unit_id IN ('.implode(',',array_fill(0,count($unitIds),'?')).')';array_splice($fcArgs,1,0,$unitIds);}$q=$pdo->prepare('SELECT direction,COALESCE(SUM(net_cents),0) total FROM financial_entries WHERE tenant_id=?'.$fcSql.' AND affects_cash=1 AND status="open" AND due_date BETWEEN ? AND ? GROUP BY direction');$q->execute($fcArgs);$forecastIn=0;$forecastOut=0;foreach($q->fetchAll()as$r){if($r['direction']==='in')$forecastIn=(int)$r['total'];else$forecastOut=(int)$r['total'];}
        return ['income_cents'=>$income,'expense_cents'=>$expense,'result_cents'=>$income-$expense,'groups'=>$groups,'cash_in_cents'=>$cashIn,'cash_out_cents'=>$cashOut,'cash_change_cents'=>$cashIn-$cashOut,'overdue_receivable_cents'=>$overdueIn,'overdue_payable_cents'=>$overdueOut,'forecast_in_30d_cents'=>$forecastIn,'forecast_out_30d_cents'=>$forecastOut,'forecast_change_30d_cents'=>$forecastIn-$forecastOut];
    }

    private function insert(PDO $pdo,array $d):int
    {
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_entries (tenant_id,unit_id,event_id,order_id,payment_id,purchase_order_id,supplier_id,account_id,category_id,direction,entry_type,description,gross_cents,fee_cents,net_cents,affects_result,affects_cash,status,competence_date,due_date,settled_at,external_reference,idempotency_key,metadata,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $pdo->prepare($sql)->execute([$d['tenant_id'],$d['unit_id']??null,$d['event_id']??null,$d['order_id']??null,$d['payment_id']??null,$d['purchase_order_id']??null,$d['supplier_id']??null,$d['account_id']??null,$d['category_id']??null,$d['direction'],$d['entry_type'],$d['description'],$d['gross_cents'],$d['fee_cents']??0,$d['net_cents'],$d['affects_result']??1,$d['affects_cash']??0,$d['status']??'open',$d['competence_date'],$d['due_date']??null,$d['settled_at']??null,$d['external_reference']??null,$d['idempotency_key'],!empty($d['metadata'])?json_encode($d['metadata'],JSON_UNESCAPED_UNICODE):null,$d['created_by']??null]);
        $id=(int)$pdo->lastInsertId();if($id>0)return$id;$q=$pdo->prepare('SELECT id FROM financial_entries WHERE tenant_id=? AND idempotency_key=?');$q->execute([$d['tenant_id'],$d['idempotency_key']]);return(int)$q->fetchColumn();
    }

    private function feeRule(PDO $pdo,int $tenantId,string $provider,string $method):array
    {
        $q=$pdo->prepare('SELECT percent_bps,fixed_cents,settlement_days FROM payment_fee_rules WHERE tenant_id=? AND provider=? AND active=1 AND payment_method IN (?,"*") ORDER BY CASE WHEN payment_method=? THEN 0 ELSE 1 END LIMIT 1');$q->execute([$tenantId,$provider,$method,$method]);return $q->fetch()?:['percent_bps'=>0,'fixed_cents'=>0,'settlement_days'=>0];
    }

    private function accountForPayment(PDO $pdo,int $tenantId,?int $unitId,string $provider,string $method):int
    {
        $type=$provider==='manual'?'cash':'gateway';$name=$provider==='manual'?'Caixa físico':ucfirst($provider).' · '.strtoupper($method);$q=$pdo->prepare('SELECT id FROM financial_accounts WHERE tenant_id=? AND account_type=? AND ((unit_id IS NULL AND ? IS NULL) OR unit_id=?) AND COALESCE(provider,"")=? AND active=1 LIMIT 1');$q->execute([$tenantId,$type,$unitId,$unitId,$provider]);$id=$q->fetchColumn();if($id!==false)return(int)$id;
        $pdo->prepare('INSERT INTO financial_accounts (tenant_id,unit_id,name,account_type,provider) VALUES (?,?,?,?,?)')->execute([$tenantId,$unitId,$name,$type,$provider]);return(int)$pdo->lastInsertId();
    }

    private function categoryId(PDO $pdo,int $tenantId,string $name,string $direction):?int{$q=$pdo->prepare('SELECT id FROM financial_categories WHERE tenant_id=? AND name=? AND direction=? LIMIT 1');$q->execute([$tenantId,$name,$direction]);$id=$q->fetchColumn();return$id===false?null:(int)$id;}
    private function eventForOrder(PDO $pdo,int $tenantId,int $orderId):?int{$q=$pdo->prepare('SELECT event_id FROM tickets WHERE tenant_id=? AND order_id=? LIMIT 1');$q->execute([$tenantId,$orderId]);$id=$q->fetchColumn();if($id!==false&&$id!==null)return(int)$id;try{$q=$pdo->prepare('SELECT event_id FROM event_bar_orders WHERE tenant_id=? AND order_id=? LIMIT 1');$q->execute([$tenantId,$orderId]);$id=$q->fetchColumn();return$id===false||$id===null?null:(int)$id;}catch(\Throwable){return null;}}
    private function paymentMethod(string $provider,string $source):string{if($provider==='manual')return'cash';if(str_contains($source,'pix'))return'pix';if(str_contains($source,'debit'))return'debit';if(str_contains($source,'credit'))return'credit';if(str_contains($source,'nfc')||str_contains($source,'card'))return'card';return$provider;}
}
