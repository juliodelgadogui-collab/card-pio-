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
        $sql='SELECT p.id,p.name,p.sku,p.stock_unit,p.active,COALESCE(ui.stock_qty,0) available_qty,COALESCE(ui.min_stock_qty,p.min_stock_qty,0) min_stock_qty,COALESCE((SELECT SUM(sr.quantity) FROM stock_reservations sr WHERE sr.tenant_id=p.tenant_id AND sr.unit_id=? AND sr.product_id=p.id AND sr.status="reserved"),0) reserved_qty FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.track_stock=1';
        $args=[$unitId,$unitId,$tenantId];
        if($lowOnly)$sql.=' AND COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0)';
        $sql.=' ORDER BY CASE WHEN COALESCE(ui.stock_qty,0)<=0 THEN 0 WHEN COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0) THEN 1 ELSE 2 END,p.name LIMIT 500';
        $s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
        $low=0;$zero=0;$reserved=0.0;
        foreach($rows as&$row){
            $available=(float)$row['available_qty'];$minimum=(float)$row['min_stock_qty'];$row['physical_qty']=$available+(float)$row['reserved_qty'];
            $row['stock_status']=$available<=0?'zero':($available<=$minimum?'low':'ok');
            if($row['stock_status']==='zero')$zero++;
            if($row['stock_status']!=='ok')$low++;
            $reserved+=(float)$row['reserved_qty'];
        }unset($row);
        if($lowOnly){
            $count=$pdo->prepare('SELECT COUNT(*) FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.track_stock=1');$count->execute([$unitId,$tenantId]);$controlled=(int)$count->fetchColumn();
        }else{$controlled=count($rows);}
        return ['unit'=>['id'=>$unitId,'name'=>(string)$unit['name']],'summary'=>['controlled'=>$controlled,'low'=>$low,'zero'=>$zero,'reserved_qty'=>$reserved],'products'=>$rows];
    }
}
