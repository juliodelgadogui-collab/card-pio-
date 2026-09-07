<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FinanceService
{
    public function syncAutomatic(?int $tenantId=null):void
    {
        $tenantId??=Auth::tenantId();if(!$tenantId)return;$pdo=Database::connection();$ledger=new FinancialLedgerService();$ledger->ensureDefaults($pdo,$tenantId);

        $orderKey=Database::isSqlite($pdo)?'("order:"||o.id||":revenue")':'CONCAT("order:",o.id,":revenue")';
        $q=$pdo->prepare('SELECT o.* FROM orders o LEFT JOIN financial_entries fe ON fe.tenant_id=o.tenant_id AND fe.idempotency_key='.$orderKey.' WHERE o.tenant_id=? AND o.payment_status="paid" AND fe.id IS NULL ORDER BY o.id LIMIT 500');$q->execute([$tenantId]);
        foreach($q->fetchAll()as$order){try{$ledger->recordOrderResult($pdo,$tenantId,$order);}catch(\Throwable){}}

        $paymentKey=Database::isSqlite($pdo)?'("payment:"||p.id||":receipt")':'CONCAT("payment:",p.id,":receipt")';
        $payments=$pdo->prepare('SELECT p.*,o.unit_id FROM payments p JOIN orders o ON o.id=p.order_id AND o.tenant_id=p.tenant_id LEFT JOIN financial_entries fe ON fe.tenant_id=p.tenant_id AND fe.idempotency_key='.$paymentKey.' WHERE p.tenant_id=? AND p.status="paid" AND fe.id IS NULL ORDER BY p.id LIMIT 500');
        $payments->execute([$tenantId]);foreach($payments->fetchAll()as$p){$raw=json_decode((string)($p['raw_payload']??'{}'),true);$verified=is_array($raw)?$raw:[];$verified['provider_payment_id']=$p['provider_payment_id']??'';try{$ledger->recordPayment($pdo,$tenantId,['id'=>(int)$p['order_id'],'unit_id'=>$p['unit_id']],$p,$verified);}catch(\Throwable){}}

        $refundKey=Database::isSqlite($pdo)?'("refund:"||r.id||":completed")':'CONCAT("refund:",r.id,":completed")';
        $refunds=$pdo->prepare('SELECT r.*,o.unit_id FROM refunds r JOIN orders o ON o.id=r.order_id AND o.tenant_id=r.tenant_id LEFT JOIN financial_entries fe ON fe.tenant_id=r.tenant_id AND fe.idempotency_key='.$refundKey.' WHERE r.tenant_id=? AND r.status="completed" AND fe.id IS NULL ORDER BY r.id LIMIT 500');
        $refunds->execute([$tenantId]);foreach($refunds->fetchAll()as$r){try{$ledger->recordRefund($pdo,$tenantId,$r,['id'=>(int)$r['order_id'],'unit_id'=>$r['unit_id']]);}catch(\Throwable){}}

        $this->syncPurchases($pdo,$tenantId);$this->syncCommissions($pdo,$tenantId);
    }

    public function dashboard(string $from,string $to,array $unitIds=[]):array
    {
        Auth::requirePermission('finance.view');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->syncAutomatic($tenantId);$ledger=new FinancialLedgerService();$summary=$ledger->summary($tenantId,$unitIds,$from,$to);$pdo=Database::connection();
        [$unitSql,$args]=$this->unitClause($unitIds);
        $q=$pdo->prepare('SELECT fe.*,fc.name category_name,fc.dre_group,fa.name account_name,ou.name unit_name,e.name event_name FROM financial_entries fe LEFT JOIN financial_categories fc ON fc.id=fe.category_id LEFT JOIN financial_accounts fa ON fa.id=fe.account_id LEFT JOIN operating_units ou ON ou.id=fe.unit_id LEFT JOIN events e ON e.id=fe.event_id WHERE fe.tenant_id=?'.$unitSql.' AND fe.competence_date BETWEEN ? AND ? ORDER BY COALESCE(fe.due_date,fe.competence_date) DESC,fe.id DESC LIMIT 300');$q->execute(array_merge([$tenantId],$args,[$from,$to]));$entries=$q->fetchAll();
        $q=$pdo->prepare('SELECT fe.*,fc.name category_name,ou.name unit_name,fa.name account_name FROM financial_entries fe LEFT JOIN financial_categories fc ON fc.id=fe.category_id LEFT JOIN operating_units ou ON ou.id=fe.unit_id LEFT JOIN financial_accounts fa ON fa.id=fe.account_id WHERE fe.tenant_id=?'.$unitSql.' AND fe.affects_cash=1 AND fe.status="open" AND fe.due_date IS NOT NULL ORDER BY fe.due_date,fe.id LIMIT 250');$q->execute(array_merge([$tenantId],$args));$open=$q->fetchAll();
        $q=$pdo->prepare('SELECT COALESCE(fe.due_date,fe.competence_date) day,fe.direction,COALESCE(SUM(fe.net_cents),0) total FROM financial_entries fe WHERE fe.tenant_id=?'.$unitSql.' AND fe.affects_cash=1 AND fe.status="open" AND fe.due_date BETWEEN ? AND ? GROUP BY COALESCE(fe.due_date,fe.competence_date),fe.direction ORDER BY day');$q->execute(array_merge([$tenantId],$args,[date('Y-m-d'),date('Y-m-d',strtotime('+30 days'))]));$forecast=$q->fetchAll();
        return ['summary'=>$summary,'entries'=>$entries,'open'=>$open,'forecast'=>$forecast,'dre'=>$this->dre($summary),'accounts'=>$this->accountBalances($tenantId,$unitIds)];
    }

    public function createEntry(array $data):int
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$direction=(string)($data['direction']??'out');$amount=(int)($data['amount_cents']??0);$description=trim((string)($data['description']??''));$competence=(string)($data['competence_date']??date('Y-m-d'));$due=trim((string)($data['due_date']??''))?:null;$status=(string)($data['status']??'open');if(!in_array($status,['open','settled'],true))$status='open';if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$competence))throw new RuntimeException('Data de competência inválida.');if($due!==null&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))throw new RuntimeException('Vencimento inválido.');
        $unitId=(int)($data['unit_id']??0)?:null;$eventId=(int)($data['event_id']??0)?:null;$categoryId=(int)($data['category_id']??0)?:null;$accountId=(int)($data['account_id']??0)?:null;
        $id=(new FinancialLedgerService())->createManual($tenantId,$unitId,$eventId,$direction,$direction==='out'?'expense':'other_income',$description,$amount,$categoryId,$accountId,$competence,$due,$status,['notes'=>(string)($data['notes']??'')]);Auth::audit('finance.entry_created','financial_entry',(string)$id,['direction'=>$direction,'amount_cents'=>$amount]);return$id;
    }

    public function settle(int $entryId,?int $accountId=null):array
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$row=(new FinancialLedgerService())->settle($tenantId,$entryId,$accountId);Auth::audit('finance.entry_settled','financial_entry',(string)$entryId,['account_id'=>$accountId]);return$row;
    }

    public function saveAccount(?int $id,string $name,string $type,string $provider,?int $unitId,int $openingBalanceCents,bool $active):int
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$name=mb_substr(trim($name),0,120);$type=strtolower(trim($type));$provider=mb_substr(strtolower(trim($provider)),0,40);if($name===''||!in_array($type,['cash','bank','gateway','wallet','other'],true))throw new RuntimeException('Conta financeira inválida.');
        $pdo=Database::connection();if($unitId){$q=$pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=?');$q->execute([$unitId,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Unidade inválida.');}
        if($id){$q=$pdo->prepare('UPDATE financial_accounts SET unit_id=?,name=?,account_type=?,provider=?,opening_balance_cents=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$q->execute([$unitId,$name,$type,$provider?:null,$openingBalanceCents,$active?1:0,$id,$tenantId]);if(!$q->rowCount()){ $e=$pdo->prepare('SELECT id FROM financial_accounts WHERE id=? AND tenant_id=?');$e->execute([$id,$tenantId]);if(!$e->fetchColumn())throw new RuntimeException('Conta não encontrada.');}}
        else{$q=$pdo->prepare('INSERT INTO financial_accounts (tenant_id,unit_id,name,account_type,provider,opening_balance_cents,active) VALUES (?,?,?,?,?,?,?)');$q->execute([$tenantId,$unitId,$name,$type,$provider?:null,$openingBalanceCents,$active?1:0]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('finance.account_saved','financial_account',(string)$id,['type'=>$type,'unit_id'=>$unitId,'active'=>$active]);return(int)$id;
    }

    public function saveFeeRule(string $provider,string $method,int $percentBps,int $fixedCents,int $days,bool $active):void
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$provider=trim(strtolower($provider));$method=trim(strtolower($method))?:'*';if($provider===''||$percentBps<0||$percentBps>10000||$fixedCents<0||$days<0||$days>365)throw new RuntimeException('Regra de taxa inválida.');$pdo=Database::connection();
        if(Database::isSqlite($pdo))$sql='INSERT INTO payment_fee_rules (tenant_id,provider,payment_method,percent_bps,fixed_cents,settlement_days,active,updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id,provider,payment_method) DO UPDATE SET percent_bps=excluded.percent_bps,fixed_cents=excluded.fixed_cents,settlement_days=excluded.settlement_days,active=excluded.active,updated_at=CURRENT_TIMESTAMP';
        else$sql='INSERT INTO payment_fee_rules (tenant_id,provider,payment_method,percent_bps,fixed_cents,settlement_days,active) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE percent_bps=VALUES(percent_bps),fixed_cents=VALUES(fixed_cents),settlement_days=VALUES(settlement_days),active=VALUES(active)';
        $pdo->prepare($sql)->execute([$tenantId,$provider,$method,$percentBps,$fixedCents,$days,$active?1:0]);Auth::audit('finance.fee_rule_saved','tenant',(string)$tenantId,['provider'=>$provider,'method'=>$method,'percent_bps'=>$percentBps,'fixed_cents'=>$fixedCents,'settlement_days'=>$days]);
    }

    public function referenceData():array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)return[];$pdo=Database::connection();(new FinancialLedgerService())->ensureDefaults($pdo,$tenantId);$q=$pdo->prepare('SELECT * FROM financial_categories WHERE tenant_id=? AND active=1 ORDER BY direction,name');$q->execute([$tenantId]);$categories=$q->fetchAll();$q=$pdo->prepare('SELECT * FROM financial_accounts WHERE tenant_id=? ORDER BY active DESC,name');$q->execute([$tenantId]);$accounts=$q->fetchAll();$q=$pdo->prepare('SELECT id,name FROM events WHERE tenant_id=? ORDER BY starts_at DESC LIMIT 100');$q->execute([$tenantId]);$events=$q->fetchAll();$q=$pdo->prepare('SELECT * FROM payment_fee_rules WHERE tenant_id=? ORDER BY provider,payment_method');$q->execute([$tenantId]);return['categories'=>$categories,'accounts'=>$accounts,'events'=>$events,'fee_rules'=>$q->fetchAll()];
    }

    public function accountBalances(int $tenantId,array $unitIds=[]):array
    {
        $pdo=Database::connection();$unitSql='';$args=[$tenantId];if($unitIds){$unitSql=' AND fa.unit_id IN ('.implode(',',array_fill(0,count($unitIds),'?')).')';$args=array_merge($args,array_map('intval',$unitIds));}$q=$pdo->prepare('SELECT fa.*,fa.opening_balance_cents+COALESCE(SUM(CASE WHEN fe.affects_cash=1 AND fe.status="settled" THEN CASE WHEN fe.direction="in" THEN fe.net_cents ELSE -fe.net_cents END ELSE 0 END),0) balance_cents FROM financial_accounts fa LEFT JOIN financial_entries fe ON fe.account_id=fa.id AND fe.tenant_id=fa.tenant_id WHERE fa.tenant_id=?'.$unitSql.' AND fa.active=1 GROUP BY fa.id ORDER BY fa.name');$q->execute($args);return$q->fetchAll();
    }

    private function syncPurchases(PDO $pdo,int $tenantId):void
    {
        $cat=$this->category($pdo,$tenantId,'Compras de estoque','out','inventory_purchase');$key=Database::isSqlite($pdo)?'("purchase:"||po.id||":received")':'CONCAT("purchase:",po.id,":received")';$q=$pdo->prepare('SELECT po.id,po.unit_id,po.supplier_id,po.total_cents,po.received_at,s.name supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN financial_entries fe ON fe.tenant_id=po.tenant_id AND fe.idempotency_key='.$key.' WHERE po.tenant_id=? AND po.status="received" AND fe.id IS NULL ORDER BY po.id LIMIT 500');$q->execute([$tenantId]);foreach($q->fetchAll()as$r){$date=substr((string)($r['received_at']?:date('Y-m-d')),0,10);$this->insertAuto($pdo,$tenantId,$r['unit_id']?:null,null,null,null,(int)$r['id'],$r['supplier_id']?:null,$cat,'out','inventory_purchase','Compra recebida'.($r['supplier_name']?' · '.$r['supplier_name']:''),(int)$r['total_cents'],'open',$date,$date,'purchase:'.$r['id'].':received',0,1);}
    }

    private function syncCommissions(PDO $pdo,int $tenantId):void
    {
        $cat=$this->category($pdo,$tenantId,'Comissões','out','commissions');$key=Database::isSqlite($pdo)?'("commission:"||pc.id)':'CONCAT("commission:",pc.id)';$q=$pdo->prepare('SELECT pc.id,pc.order_id,pc.amount_cents,pc.status,o.unit_id,o.created_at FROM promoter_commissions pc JOIN orders o ON o.id=pc.order_id AND o.tenant_id=pc.tenant_id LEFT JOIN financial_entries fe ON fe.tenant_id=pc.tenant_id AND fe.idempotency_key='.$key.' WHERE pc.tenant_id=? AND pc.status IN ("approved","paid") AND fe.id IS NULL ORDER BY pc.id LIMIT 500');$q->execute([$tenantId]);foreach($q->fetchAll()as$r){$date=substr((string)$r['created_at'],0,10);$this->insertAuto($pdo,$tenantId,$r['unit_id']?:null,$this->eventForOrder($pdo,$tenantId,(int)$r['order_id']),(int)$r['order_id'],null,null,null,$cat,'out','commission','Comissão · Pedido #'.$r['order_id'],(int)$r['amount_cents'],$r['status']==='paid'?'settled':'open',$date,$date,'commission:'.$r['id'],1,1);}
    }

    private function insertAuto(PDO $pdo,int $tenantId,?int $unitId,?int $eventId,?int $orderId,?int $paymentId,?int $purchaseId,?int $supplierId,?int $categoryId,string $direction,string $type,string $description,int $amount,string $status,string $competence,?string $due,string $key,int $affectsResult,int $affectsCash):void
    {
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_entries (tenant_id,unit_id,event_id,order_id,payment_id,purchase_order_id,supplier_id,category_id,direction,entry_type,description,gross_cents,fee_cents,net_cents,affects_result,affects_cash,status,competence_date,due_date,settled_at,idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?)');$pdo->prepare($sql)->execute([$tenantId,$unitId,$eventId,$orderId,$paymentId,$purchaseId,$supplierId,$categoryId,$direction,$type,$description,$amount,$amount,$affectsResult,$affectsCash,$status,$competence,$due,$status==='settled'?date('Y-m-d H:i:s'):null,$key]);
    }

    private function category(PDO $pdo,int $tenantId,string $name,string $direction,string $group):?int{$sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_categories (tenant_id,name,direction,dre_group) VALUES (?,?,?,?)');$pdo->prepare($sql)->execute([$tenantId,$name,$direction,$group]);$q=$pdo->prepare('SELECT id FROM financial_categories WHERE tenant_id=? AND name=? AND direction=?');$q->execute([$tenantId,$name,$direction]);$id=$q->fetchColumn();return$id===false?null:(int)$id;}
    private function eventForOrder(PDO $pdo,int $tenantId,int $orderId):?int{$q=$pdo->prepare('SELECT event_id FROM tickets WHERE tenant_id=? AND order_id=? LIMIT 1');$q->execute([$tenantId,$orderId]);$id=$q->fetchColumn();return$id===false||$id===null?null:(int)$id;}
    private function unitClause(array $ids):array{if(!$ids)return['',[]];return[' AND fe.unit_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_map('intval',$ids)];}
    private function dre(array $summary):array{$g=$summary['groups'];$gross=(int)($g['gross_revenue']??0);$discounts=(int)($g['discounts']??0);$refunds=(int)($g['refunds']??0);$netRevenue=$gross-$discounts-$refunds;$cogs=(int)($g['cogs']??0);$grossProfit=$netRevenue-$cogs;$fees=(int)($g['payment_fees']??0);$commissions=(int)($g['commissions']??0);$opex=(int)($g['operating_expense']??0);$taxes=(int)($g['taxes']??0);return['gross_revenue_cents'=>$gross,'discounts_cents'=>$discounts,'refunds_cents'=>$refunds,'net_revenue_cents'=>$netRevenue,'cogs_cents'=>$cogs,'gross_profit_cents'=>$grossProfit,'payment_fees_cents'=>$fees,'commissions_cents'=>$commissions,'operating_expense_cents'=>$opex,'taxes_cents'=>$taxes,'operating_result_cents'=>$grossProfit-$fees-$commissions-$opex-$taxes];}
}
