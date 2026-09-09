<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class InventorySnapshotService
{
    public function snapshot(bool $lowOnly=false):array
    {
        Auth::requirePermission('inventory.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $unit=(new OperatingUnitService())->requireCurrent();
        $unitId=(int)$unit['id'];
        $pdo=Database::connection();

        $summarySql='SELECT COUNT(*) controlled,
            COALESCE(SUM(CASE WHEN COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0) THEN 1 ELSE 0 END),0) low,
            COALESCE(SUM(CASE WHEN COALESCE(ui.stock_qty,0)<=0 THEN 1 ELSE 0 END),0) zero,
            COALESCE((SELECT SUM(sr.quantity) FROM stock_reservations sr JOIN products rp ON rp.id=sr.product_id AND rp.tenant_id=sr.tenant_id WHERE sr.tenant_id=? AND sr.unit_id=? AND sr.status="reserved" AND rp.track_stock=1),0) reserved_qty
            FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.track_stock=1';
        $summaryStmt=$pdo->prepare($summarySql);$summaryStmt->execute([$tenantId,$unitId,$unitId,$tenantId]);$summary=$summaryStmt->fetch()?:['controlled'=>0,'low'=>0,'zero'=>0,'reserved_qty'=>0];

        $sql='SELECT p.id,p.name,p.sku,p.stock_unit,p.active,COALESCE(ui.stock_qty,0) available_qty,COALESCE(ui.min_stock_qty,p.min_stock_qty,0) min_stock_qty,COALESCE((SELECT SUM(sr.quantity) FROM stock_reservations sr WHERE sr.tenant_id=p.tenant_id AND sr.unit_id=? AND sr.product_id=p.id AND sr.status="reserved"),0) reserved_qty FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.track_stock=1';
        $args=[$unitId,$unitId,$tenantId];
        if($lowOnly)$sql.=' AND COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0)';
        $sql.=' ORDER BY CASE WHEN COALESCE(ui.stock_qty,0)<=0 THEN 0 WHEN COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0) THEN 1 ELSE 2 END,p.name LIMIT 500';
        $s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
        foreach($rows as&$row){
            $available=(float)$row['available_qty'];$minimum=(float)$row['min_stock_qty'];$row['physical_qty']=$available+(float)$row['reserved_qty'];
            $row['stock_status']=$available<=0?'zero':($available<=$minimum?'low':'ok');
        }unset($row);
        return ['unit'=>['id'=>$unitId,'name'=>(string)$unit['name']],'summary'=>[
            'controlled'=>(int)$summary['controlled'],'low'=>(int)$summary['low'],'zero'=>(int)$summary['zero'],'reserved_qty'=>(float)$summary['reserved_qty']
        ],'products'=>$rows];
    }
}
