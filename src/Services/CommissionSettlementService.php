<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CommissionSettlementService
{
    public function settle(int $commissionId,int $accountId):array
    {
        Auth::requirePermission('promoters.manage');Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');if($commissionId<1||$accountId<1)throw new RuntimeException('Comissão ou conta financeira inválida.');
        $result=Database::transaction(function(PDO$pdo)use($tenantId,$commissionId,$accountId):array{
            $account=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM financial_accounts WHERE id=? AND tenant_id=? AND active=1 FOR UPDATE'));$account->execute([$accountId,$tenantId]);$accountRow=$account->fetch();if(!$accountRow)throw new RuntimeException('Conta financeira inválida ou inativa.');
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT pc.*,o.unit_id,o.created_at order_created_at FROM promoter_commissions pc JOIN orders o ON o.id=pc.order_id AND o.tenant_id=pc.tenant_id WHERE pc.id=? AND pc.tenant_id=? FOR UPDATE'));$q->execute([$commissionId,$tenantId]);$commission=$q->fetch();if(!$commission)throw new RuntimeException('Comissão não encontrada.');if(!in_array((string)$commission['status'],['approved','paid'],true))throw new RuntimeException('Esta comissão não pode ser liquidada.');
            if((string)$commission['status']==='approved')$pdo->prepare('UPDATE promoter_commissions SET status="paid" WHERE id=? AND tenant_id=? AND status="approved"')->execute([$commissionId,$tenantId]);
            $key='commission:'.$commissionId;$entry=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM financial_entries WHERE tenant_id=? AND idempotency_key=? FOR UPDATE'));$entry->execute([$tenantId,$key]);$finance=$entry->fetch();$eventId=$this->eventForOrder($pdo,$tenantId,(int)$commission['order_id']);$competence=substr((string)$commission['order_created_at'],0,10)?:date('Y-m-d');
            if(!$finance){
                $categoryId=$this->categoryId($pdo,$tenantId);$pdo->prepare('INSERT INTO financial_entries (tenant_id,unit_id,event_id,order_id,account_id,category_id,direction,entry_type,description,gross_cents,fee_cents,net_cents,affects_result,affects_cash,status,competence_date,due_date,settled_at,idempotency_key,metadata,created_by) VALUES (?,?,?,?,?, ?,"out","commission","Comissão de promotor",?,0,?,1,1,"settled",?,?,CURRENT_TIMESTAMP,?,?,?)')
                    ->execute([$tenantId,$commission['unit_id']?:null,$eventId,(int)$commission['order_id'],$accountId,$categoryId,(int)$commission['amount_cents'],(int)$commission['amount_cents'],$competence,date('Y-m-d'),$key,json_encode(['commission_id'=>$commissionId],JSON_UNESCAPED_UNICODE),Auth::id()]);$entryId=(int)$pdo->lastInsertId();
            }else{
                $entryId=(int)$finance['id'];$pdo->prepare('UPDATE financial_entries SET account_id=?,status="settled",settled_at=COALESCE(settled_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$accountId,$entryId,$tenantId]);
            }
            return['commission_id'=>$commissionId,'financial_entry_id'=>$entryId,'account_id'=>$accountId,'amount_cents'=>(int)$commission['amount_cents'],'order_id'=>(int)$commission['order_id']];
        });
        Auth::audit('commission.paid','commission',(string)$commissionId,['account_id'=>$accountId,'financial_entry_id'=>$result['financial_entry_id'],'amount_cents'=>$result['amount_cents']]);return$result;
    }

    private function categoryId(PDO$pdo,int$tenantId):?int
    {
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_categories (tenant_id,name,direction,dre_group) VALUES (?,"Comissões","out","commissions")');$pdo->prepare($sql)->execute([$tenantId]);$q=$pdo->prepare('SELECT id FROM financial_categories WHERE tenant_id=? AND name="Comissões" AND direction="out" LIMIT 1');$q->execute([$tenantId]);$id=$q->fetchColumn();return$id===false?null:(int)$id;
    }

    private function eventForOrder(PDO$pdo,int$tenantId,int$orderId):?int
    {
        $q=$pdo->prepare('SELECT event_id FROM tickets WHERE tenant_id=? AND order_id=? LIMIT 1');$q->execute([$tenantId,$orderId]);$id=$q->fetchColumn();if($id!==false&&$id!==null)return(int)$id;try{$q=$pdo->prepare('SELECT event_id FROM event_bar_orders WHERE tenant_id=? AND order_id=? LIMIT 1');$q->execute([$tenantId,$orderId]);$id=$q->fetchColumn();return$id===false||$id===null?null:(int)$id;}catch(\Throwable){return null;}
    }
}
