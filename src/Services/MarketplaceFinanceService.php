<?php

declare(strict_types=1);

namespace EventMenu\Services;

use DateInterval;
use DateTimeImmutable;
use EventMenu\Core\Database;
use PDO;

final class MarketplaceFinanceService
{
    public function filters(array $input): array
    {
        $range = strtolower(trim((string)($input['range'] ?? 'month')));
        $today = new DateTimeImmutable('today');
        [$from,$to] = match($range){
            'today'=>[$today,$today],
            'yesterday'=>[$today->sub(new DateInterval('P1D')),$today->sub(new DateInterval('P1D'))],
            'week'=>[$today->modify('monday this week'),$today],
            'year'=>[$today->setDate((int)$today->format('Y'),1,1),$today],
            'custom'=>$this->customRange((string)($input['from']??''),(string)($input['to']??''),$today),
            default=>[$today->modify('first day of this month'),$today],
        };
        return [
            'range'=>$range,
            'from'=>$from->format('Y-m-d'),
            'to'=>$to->format('Y-m-d'),
            'tenant_id'=>max(0,(int)($input['tenant_id']??0)),
            'unit_id'=>max(0,(int)($input['unit_id']??0)),
            'city'=>mb_substr(trim((string)($input['city']??'')),0,120),
            'state'=>mb_substr(mb_strtoupper(trim((string)($input['state']??''))),0,2),
            'status'=>mb_substr(strtolower(trim((string)($input['status']??''))),0,30),
        ];
    }

    public function dashboard(PDO $pdo, array $filter): array
    {
        $this->reconcileSelectedTenants($pdo,$filter);
        [$where,$args]=$this->orderWhere($filter,'o');
        $today=gmdate('Y-m-d');$monthStart=gmdate('Y-m-01');
        $fixed=$pdo->prepare('SELECT SUM(CASE WHEN date(o.created_at)=? THEN 1 ELSE 0 END) today_count,SUM(CASE WHEN date(o.created_at)>=? THEN 1 ELSE 0 END) month_count FROM orders o WHERE o.order_source=?');
        $fixed->execute([$today,$monthStart,MarketplaceCommissionService::ORDER_SOURCE]);$fixedRow=$fixed->fetch()?:[];
        $orders=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN o.status NOT IN ("completed","cancelled") THEN 1 ELSE 0 END) in_progress,SUM(CASE WHEN o.status="completed" THEN 1 ELSE 0 END) completed,SUM(CASE WHEN o.status="cancelled" THEN 1 ELSE 0 END) cancelled,COALESCE(SUM(CASE WHEN o.status="completed" THEN o.total_cents ELSE 0 END),0) gmv,COALESCE(AVG(CASE WHEN o.status="completed" THEN o.total_cents END),0) avg_ticket FROM orders o JOIN marketplace_tenant_settings ms ON ms.tenant_id=o.tenant_id WHERE '.$where);
        $orders->execute($args);$orderStats=$orders->fetch()?:[];

        [$cWhere,$cArgs]=$this->commissionWhere($filter);
        $comm=$pdo->prepare('SELECT COALESCE(SUM(mc.commission_cents),0) generated,COALESCE(SUM(CASE WHEN mc.status="provisioned" THEN mc.commission_cents ELSE 0 END),0) provisioned,COALESCE(SUM(CASE WHEN mc.status="due" THEN mc.commission_cents ELSE 0 END),0) due,COALESCE(SUM(CASE WHEN mc.status="invoiced" THEN mc.commission_cents ELSE 0 END),0) invoiced,COALESCE(SUM(CASE WHEN mc.status="paid" THEN mc.commission_cents ELSE 0 END),0) received,COALESCE(SUM(CASE WHEN mc.status IN ("due","invoiced") THEN mc.commission_cents ELSE 0 END),0) receivable,COALESCE(SUM(CASE WHEN mc.status="reversed" THEN mc.commission_cents ELSE 0 END),0) reversed FROM marketplace_order_commissions mc JOIN orders o ON o.id=mc.order_id JOIN marketplace_tenant_settings ms ON ms.tenant_id=mc.tenant_id WHERE '.$cWhere);
        $comm->execute($cArgs);$commissionStats=$comm->fetch()?:[];

        $invoiceWhere=['i.status IN ("open","overdue")'];$invoiceArgs=[];if((int)$filter['tenant_id']>0){$invoiceWhere[]='i.tenant_id=?';$invoiceArgs[]=(int)$filter['tenant_id'];}
        $inv=$pdo->prepare('SELECT SUM(CASE WHEN i.status="open" THEN 1 ELSE 0 END) open_count,SUM(CASE WHEN i.status="overdue" OR (i.status="open" AND i.due_at IS NOT NULL AND i.due_at<CURRENT_TIMESTAMP) THEN 1 ELSE 0 END) overdue_count FROM platform_invoices i WHERE '.implode(' AND ',$invoiceWhere));$inv->execute($invoiceArgs);$invoiceStats=$inv->fetch()?:[];

        $companies=$pdo->query('SELECT COUNT(*) participants,SUM(CASE WHEN ms.status="active" THEN 1 ELSE 0 END) active_count,SUM(CASE WHEN ms.status="suspended" THEN 1 ELSE 0 END) suspended_count FROM marketplace_tenant_settings ms WHERE ms.participates=1')->fetch()?:[];
        $impulsiona=$pdo->query('SELECT COUNT(*) participants,COALESCE(SUM(balance_cents),0) balance FROM marketplace_promotion_accounts WHERE participates=1')->fetch()?:[];
        $ledger=$pdo->query('SELECT COALESCE(SUM(CASE WHEN entry_type="contribution" AND amount_cents>0 THEN amount_cents ELSE 0 END),0) contributed,COALESCE(SUM(CASE WHEN entry_type="eventmenu_bonus" AND amount_cents>0 THEN amount_cents ELSE 0 END),0) bonuses,COUNT(CASE WHEN entry_type="coupon" THEN 1 END) coupons_used FROM marketplace_promotion_ledger')->fetch()?:[];

        return [
            'orders_today'=>(int)($fixedRow['today_count']??0),'orders_month'=>(int)($fixedRow['month_count']??0),
            'orders_total'=>(int)($orderStats['total']??0),'orders_in_progress'=>(int)($orderStats['in_progress']??0),'orders_completed'=>(int)($orderStats['completed']??0),'orders_cancelled'=>(int)($orderStats['cancelled']??0),'gmv_cents'=>(int)($orderStats['gmv']??0),'average_ticket_cents'=>(int)round((float)($orderStats['avg_ticket']??0)),
            'commission_generated_cents'=>(int)($commissionStats['generated']??0),'commission_provisioned_cents'=>(int)($commissionStats['provisioned']??0),'commission_due_cents'=>(int)($commissionStats['due']??0),'commission_invoiced_cents'=>(int)($commissionStats['invoiced']??0),'commission_received_cents'=>(int)($commissionStats['received']??0),'commission_receivable_cents'=>(int)($commissionStats['receivable']??0),'commission_reversed_cents'=>(int)($commissionStats['reversed']??0),
            'invoices_open'=>(int)($invoiceStats['open_count']??0),'invoices_overdue'=>(int)($invoiceStats['overdue_count']??0),
            'companies_participating'=>(int)($companies['participants']??0),'companies_active'=>(int)($companies['active_count']??0),'companies_suspended'=>(int)($companies['suspended_count']??0),
            'impulsiona_participants'=>(int)($impulsiona['participants']??0),'impulsiona_balance_cents'=>(int)($impulsiona['balance']??0),'impulsiona_contributed_cents'=>(int)($ledger['contributed']??0),'eventmenu_bonus_cents'=>(int)($ledger['bonuses']??0),'coupons_used'=>(int)($ledger['coupons_used']??0),
        ];
    }

    public function company(PDO $pdo,int $tenantId,array $filter): array
    {
        (new MarketplaceReconciliationService())->reconcileTenant($pdo,$tenantId,1000);
        $q=$pdo->prepare('SELECT t.id,t.name,t.slug,t.status,t.plan,ms.participates,ms.status marketplace_status,ms.joined_at,ms.city,ms.state,ts.status subscription_status,ts.billing_cycle,ts.custom_price_cents,p.name plan_name,p.monthly_cents,p.yearly_cents FROM tenants t LEFT JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id LEFT JOIN tenant_subscriptions ts ON ts.tenant_id=t.id LEFT JOIN saas_plans p ON p.id=ts.plan_id WHERE t.id=? LIMIT 1');$q->execute([$tenantId]);$company=$q->fetch();if(!$company)return[];
        $f=$filter;$f['tenant_id']=$tenantId;[$where,$args]=$this->orderWhere($f,'o');$o=$pdo->prepare('SELECT COUNT(*) orders_count,SUM(CASE WHEN o.status="completed" THEN 1 ELSE 0 END) completed,SUM(CASE WHEN o.status="cancelled" THEN 1 ELSE 0 END) cancelled,COALESCE(SUM(CASE WHEN o.status="completed" THEN o.total_cents ELSE 0 END),0) sold,COALESCE(AVG(CASE WHEN o.status="completed" THEN o.total_cents END),0) avg_ticket FROM orders o JOIN marketplace_tenant_settings ms ON ms.tenant_id=o.tenant_id WHERE '.$where);$o->execute($args);$company['orders']=$o->fetch()?:[];
        [$cw,$ca]=$this->commissionWhere($f);$c=$pdo->prepare('SELECT COALESCE(SUM(commission_cents),0) generated,COALESCE(SUM(CASE WHEN mc.status="invoiced" THEN commission_cents ELSE 0 END),0) invoiced,COALESCE(SUM(CASE WHEN mc.status="paid" THEN commission_cents ELSE 0 END),0) paid,COALESCE(SUM(CASE WHEN mc.status IN ("provisioned","due","invoiced") THEN commission_cents ELSE 0 END),0) pending FROM marketplace_order_commissions mc JOIN orders o ON o.id=mc.order_id JOIN marketplace_tenant_settings ms ON ms.tenant_id=mc.tenant_id WHERE '.$cw);$c->execute($ca);$company['commissions']=$c->fetch()?:[];
        $i=$pdo->prepare('SELECT SUM(CASE WHEN status="open" THEN 1 ELSE 0 END) open_count,SUM(CASE WHEN status="overdue" OR (status="open" AND due_at IS NOT NULL AND due_at<CURRENT_TIMESTAMP) THEN 1 ELSE 0 END) overdue_count FROM platform_invoices WHERE tenant_id=?');$i->execute([$tenantId]);$company['invoices']=$i->fetch()?:[];
        $p=$pdo->prepare('SELECT participates,balance_cents FROM marketplace_promotion_accounts WHERE tenant_id=?');$p->execute([$tenantId]);$company['impulsiona']=$p->fetch()?:['participates'=>0,'balance_cents'=>0];
        $l=$pdo->prepare('SELECT COALESCE(SUM(CASE WHEN entry_type="contribution" THEN amount_cents ELSE 0 END),0) contributions,COUNT(CASE WHEN entry_type="coupon" THEN 1 END) coupons,COUNT(CASE WHEN entry_type="offer" THEN 1 END) offers FROM marketplace_promotion_ledger WHERE tenant_id=?');$l->execute([$tenantId]);$company['promotion']=$l->fetch()?:[];
        try{$company['current_rule']=(new MarketplaceCommissionService())->resolveRule($pdo,$tenantId,null,null);}catch(\Throwable){$company['current_rule']=null;}
        return$company;
    }

    public function companies(PDO $pdo): array
    {
        $q=$pdo->query('SELECT t.id,t.name,t.status,ms.participates,ms.status marketplace_status,ms.joined_at,ms.city,ms.state,COALESCE(p.name,t.plan) plan_name,(SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id AND o.order_source="'.MarketplaceCommissionService::ORDER_SOURCE.'") marketplace_orders,(SELECT COALESCE(SUM(mc.commission_cents),0) FROM marketplace_order_commissions mc WHERE mc.tenant_id=t.id AND mc.status IN ("provisioned","due","invoiced")) commission_pending FROM tenants t LEFT JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id LEFT JOIN tenant_subscriptions ts ON ts.tenant_id=t.id LEFT JOIN saas_plans p ON p.id=ts.plan_id ORDER BY ms.participates DESC,t.name');
        return$q->fetchAll();
    }

    public function invoices(PDO $pdo,int $tenantId=0,int $limit=200):array
    {
        $where='1=1';$args=[];if($tenantId>0){$where='i.tenant_id=?';$args[]=$tenantId;}$q=$pdo->prepare('SELECT i.*,t.name tenant_name FROM platform_invoices i JOIN tenants t ON t.id=i.tenant_id WHERE '.$where.' ORDER BY i.period_start DESC,i.id DESC LIMIT '.max(1,min(500,$limit)));$q->execute($args);return$q->fetchAll();
    }

    private function orderWhere(array $f,string $alias):array
    {
        $w=[$alias.'.order_source=?','date('.$alias.'.created_at) BETWEEN ? AND ?'];$a=[MarketplaceCommissionService::ORDER_SOURCE,$f['from'],$f['to']];
        if((int)$f['tenant_id']>0){$w[]=$alias.'.tenant_id=?';$a[]=(int)$f['tenant_id'];}
        if((int)$f['unit_id']>0){$w[]=$alias.'.unit_id=?';$a[]=(int)$f['unit_id'];}
        if($f['city']!==''){$w[]='LOWER(ms.city)=LOWER(?)';$a[]=$f['city'];}
        if($f['state']!==''){$w[]='UPPER(ms.state)=UPPER(?)';$a[]=$f['state'];}
        if($f['status']!==''){$w[]=$alias.'.status=?';$a[]=$f['status'];}
        return[implode(' AND ',$w),$a];
    }

    private function commissionWhere(array $f):array
    {
        $w=['o.order_source=?','date(mc.created_at) BETWEEN ? AND ?'];$a=[MarketplaceCommissionService::ORDER_SOURCE,$f['from'],$f['to']];if((int)$f['tenant_id']>0){$w[]='mc.tenant_id=?';$a[]=(int)$f['tenant_id'];}if((int)$f['unit_id']>0){$w[]='mc.unit_id=?';$a[]=(int)$f['unit_id'];}if($f['city']!==''){$w[]='LOWER(ms.city)=LOWER(?)';$a[]=$f['city'];}if($f['state']!==''){$w[]='UPPER(ms.state)=UPPER(?)';$a[]=$f['state'];}if($f['status']!==''){$w[]='o.status=?';$a[]=$f['status'];}return[implode(' AND ',$w),$a];
    }

    private function reconcileSelectedTenants(PDO $pdo,array $filter):void
    {
        $service=new MarketplaceReconciliationService();if((int)$filter['tenant_id']>0){$service->reconcileTenant($pdo,(int)$filter['tenant_id'],1000);return;}$q=$pdo->query('SELECT tenant_id FROM marketplace_tenant_settings WHERE participates=1');foreach($q->fetchAll(PDO::FETCH_COLUMN)as$tenantId)$service->reconcileTenant($pdo,(int)$tenantId,500);
    }

    private function customRange(string $from,string $to,DateTimeImmutable $fallback):array
    {
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',trim($from));$b=DateTimeImmutable::createFromFormat('!Y-m-d',trim($to));if(!$a||!$b||$b<$a)return[$fallback->modify('first day of this month'),$fallback];return[$a,$b];
    }
}
