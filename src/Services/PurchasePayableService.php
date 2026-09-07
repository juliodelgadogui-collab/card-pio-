<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PurchasePayableService
{
    private const METHODS=['pix','boleto','transfer','card','cash','other'];

    public function saveTerms(int $orderId,string $paymentMethod,?string $firstDueDate,int $installments,int $intervalDays):array
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $paymentMethod=strtolower(trim($paymentMethod));if(!in_array($paymentMethod,self::METHODS,true))throw new RuntimeException('Forma de pagamento inválida.');
        $installments=max(1,min(48,$installments));$intervalDays=max(0,min(365,$intervalDays));$firstDueDate=$this->normalizeDate($firstDueDate);
        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$paymentMethod,$firstDueDate,$installments,$intervalDays):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM purchase_orders WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$orderId,$tenantId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Compra não encontrada.');
            $this->assertCurrentUnit($order);if($order['status']==='cancelled')throw new RuntimeException('Compra cancelada não pode ser alterada.');if($order['status']==='received'&&!empty($order['finance_generated_at']))throw new RuntimeException('As parcelas desta compra já foram geradas no Financeiro.');
            $pdo->prepare('UPDATE purchase_orders SET payment_method=?,first_due_date=?,installments_count=?,installment_interval_days=? WHERE id=? AND tenant_id=?')->execute([$paymentMethod,$firstDueDate,$installments,$intervalDays,$orderId,$tenantId]);
            $q->execute([$orderId,$tenantId]);$updated=$q->fetch();Auth::audit('purchase.payment_terms_saved','purchase_order',(string)$orderId,['payment_method'=>$paymentMethod,'first_due_date'=>$firstDueDate,'installments_count'=>$installments,'interval_days'=>$intervalDays]);return$updated?:$order;
        });
    }

    public function generateForOrder(int $orderId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');return Database::transaction(fn(PDO$pdo):array=>$this->generateForOrderInTransaction($pdo,$tenantId,$orderId));
    }

    public function generateForOrderInTransaction(PDO $pdo,int $tenantId,int $orderId):array
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT po.*,s.name supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id WHERE po.id=? AND po.tenant_id=? FOR UPDATE'));$q->execute([$orderId,$tenantId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Compra não encontrada.');
        if((string)$order['status']!=='received')return[];$total=max(0,(int)$order['total_cents']);if($total<=0)return[];
        $count=max(1,min(48,(int)($order['installments_count']??1)));$interval=max(0,min(365,(int)($order['installment_interval_days']??30)));
        $receivedDate=substr((string)($order['received_at']?:date('Y-m-d')),0,10);$first=$this->normalizeDate($order['first_due_date']??null)?:$receivedDate;
        $method=in_array((string)($order['payment_method']??'other'),self::METHODS,true)?(string)$order['payment_method']:'other';
        $categoryId=$this->categoryId($pdo,$tenantId);$base=intdiv($total,$count);$remainder=$total%$count;$rows=[];
        for($n=1;$n<=$count;$n++){
            $amount=$base+($n<=$remainder?1:0);$due=date('Y-m-d',strtotime($first.' +'.(($n-1)*$interval).' days'));
            $existing=$pdo->prepare('SELECT * FROM purchase_payment_installments WHERE purchase_order_id=? AND installment_no=?');$existing->execute([$orderId,$n]);$inst=$existing->fetch();
            if(!$inst){$pdo->prepare('INSERT INTO purchase_payment_installments (tenant_id,purchase_order_id,installment_no,amount_cents,due_date,status) VALUES (?,?,?,?,?,"open")')->execute([$tenantId,$orderId,$n,$amount,$due]);$instId=(int)$pdo->lastInsertId();}
            else{$instId=(int)$inst['id'];if(!$inst['financial_entry_id'])$pdo->prepare('UPDATE purchase_payment_installments SET amount_cents=?,due_date=? WHERE id=?')->execute([$amount,$due,$instId]);}
            $key=$n===1?'purchase:'.$orderId.':received':'purchase:'.$orderId.':installment:'.$n;
            $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_entries (tenant_id,unit_id,purchase_order_id,supplier_id,category_id,direction,entry_type,description,gross_cents,fee_cents,net_cents,affects_result,affects_cash,status,competence_date,due_date,idempotency_key,metadata,created_by) VALUES (?,?,?,?,?,"out","inventory_purchase_payable",?,?,0,?,0,1,"open",?,?,?, ?,?)');
            $description='Compra #'.$orderId.' · parcela '.$n.'/'.$count.(!empty($order['supplier_name'])?' · '.$order['supplier_name']:'');
            $pdo->prepare($sql)->execute([$tenantId,$order['unit_id']?:null,$orderId,$order['supplier_id']?:null,$categoryId,$description,$amount,$amount,$receivedDate,$due,$key,json_encode(['payment_method'=>$method,'installment_no'=>$n,'installments_count'=>$count],JSON_UNESCAPED_UNICODE),Auth::id()]);
            $f=$pdo->prepare('SELECT id,status,settled_at FROM financial_entries WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$f->execute([$tenantId,$key]);$entry=$f->fetch();if($entry){$pdo->prepare('UPDATE purchase_payment_installments SET financial_entry_id=?,status=?,settled_at=? WHERE id=?')->execute([(int)$entry['id'],$entry['status']==='settled'?'paid':'open',$entry['settled_at']??null,$instId]);}
            $rows[]=['installment_no'=>$n,'amount_cents'=>$amount,'due_date'=>$due,'financial_entry_id'=>$entry?(int)$entry['id']:null];
        }
        $pdo->prepare('UPDATE purchase_orders SET finance_generated_at=COALESCE(finance_generated_at,CURRENT_TIMESTAMP) WHERE id=? AND tenant_id=?')->execute([$orderId,$tenantId]);
        return$rows;
    }

    public function installments(int $orderId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)return[];$q=Database::connection()->prepare('SELECT i.*,fe.status financial_status,fe.settled_at financial_settled_at,fa.name account_name FROM purchase_payment_installments i LEFT JOIN financial_entries fe ON fe.id=i.financial_entry_id LEFT JOIN financial_accounts fa ON fa.id=fe.account_id WHERE i.tenant_id=? AND i.purchase_order_id=? ORDER BY i.installment_no');$q->execute([$tenantId,$orderId]);return$q->fetchAll();
    }

    public function syncReceivedPurchases(int $tenantId,int $limit=200):int
    {
        $pdo=Database::connection();$q=$pdo->prepare('SELECT id FROM purchase_orders WHERE tenant_id=? AND status="received" AND finance_generated_at IS NULL ORDER BY id LIMIT '.max(1,min(500,$limit)));$q->execute([$tenantId]);$count=0;foreach($q->fetchAll()as$row){try{Database::transaction(function(PDO$tx)use($tenantId,$row):void{$this->generateForOrderInTransaction($tx,$tenantId,(int)$row['id']);});$count++;}catch(\Throwable){}}return$count;
    }

    private function categoryId(PDO $pdo,int $tenantId):?int
    {
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_categories (tenant_id,name,direction,dre_group) VALUES (?,"Compras de estoque","out","inventory_purchase")');$pdo->prepare($sql)->execute([$tenantId]);$q=$pdo->prepare('SELECT id FROM financial_categories WHERE tenant_id=? AND name="Compras de estoque" AND direction="out" LIMIT 1');$q->execute([$tenantId]);$id=$q->fetchColumn();return$id===false?null:(int)$id;
    }

    private function normalizeDate(mixed $value):?string
    {
        $date=trim((string)$value);if($date==='')return null;if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||strtotime($date)===false)throw new RuntimeException('Data de vencimento inválida.');return$date;
    }

    private function assertCurrentUnit(array $order):void
    {
        $current=(new OperatingUnitService())->requireCurrent();if((int)$order['unit_id']!==(int)$current['id'])throw new RuntimeException('Esta compra pertence a outra unidade.');
    }
}
