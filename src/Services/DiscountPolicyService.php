<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class DiscountPolicyService
{
    public function get(?int $tenantId=null):array
    {
        $tenantId??=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT * FROM discount_policies WHERE tenant_id=?');$q->execute([$tenantId]);$row=$q->fetch();
        if($row)return $row;
        try{$pdo->prepare('INSERT INTO discount_policies (tenant_id) VALUES (?)')->execute([$tenantId]);}catch(\Throwable){}
        $q->execute([$tenantId]);return $q->fetch()?:['tenant_id'=>$tenantId,'cashier_auto_bps'=>500,'manager_auto_bps'=>2000,'admin_auto_bps'=>10000,'require_reason'=>1,'allow_percentage'=>1];
    }

    public function save(int $cashierBps,int $managerBps,int $adminBps,bool $requireReason,bool $allowPercentage):array
    {
        Auth::requirePermission('discounts.approve');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        foreach([$cashierBps,$managerBps,$adminBps] as$b)if($b<0||$b>10000)throw new RuntimeException('Limite percentual inválido.');
        if($cashierBps>$managerBps||$managerBps>$adminBps)throw new RuntimeException('Os limites devem crescer de Caixa para Gerente e Administrador.');
        $pdo=Database::connection();
        $driver=Database::driver($pdo);
        if($driver==='sqlite'){
            $pdo->prepare('INSERT INTO discount_policies (tenant_id,cashier_auto_bps,manager_auto_bps,admin_auto_bps,require_reason,allow_percentage,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id) DO UPDATE SET cashier_auto_bps=excluded.cashier_auto_bps,manager_auto_bps=excluded.manager_auto_bps,admin_auto_bps=excluded.admin_auto_bps,require_reason=excluded.require_reason,allow_percentage=excluded.allow_percentage,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP')
                ->execute([$tenantId,$cashierBps,$managerBps,$adminBps,$requireReason?1:0,$allowPercentage?1:0,Auth::id()]);
        }else{
            $pdo->prepare('INSERT INTO discount_policies (tenant_id,cashier_auto_bps,manager_auto_bps,admin_auto_bps,require_reason,allow_percentage,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE cashier_auto_bps=VALUES(cashier_auto_bps),manager_auto_bps=VALUES(manager_auto_bps),admin_auto_bps=VALUES(admin_auto_bps),require_reason=VALUES(require_reason),allow_percentage=VALUES(allow_percentage),updated_by=VALUES(updated_by)')
                ->execute([$tenantId,$cashierBps,$managerBps,$adminBps,$requireReason?1:0,$allowPercentage?1:0,Auth::id()]);
        }
        Auth::audit('discount.policy_updated','tenant',(string)$tenantId,['cashier_bps'=>$cashierBps,'manager_bps'=>$managerBps,'admin_bps'=>$adminBps]);
        return $this->get($tenantId);
    }

    public function autoLimitBps(string $role,array $policy):int
    {
        return match($role){'admin','super_admin'=>(int)$policy['admin_auto_bps'],'manager'=>(int)$policy['manager_auto_bps'],'cashier'=>(int)$policy['cashier_auto_bps'],default=>0};
    }
}
