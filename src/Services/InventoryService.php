<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class InventoryService
{
    public function move(int $productId,string $type,float $quantity,string $reason,?int $unitCostCents=null):array
    {
        Auth::requirePermission('inventory.manage');
        $tenantId=Auth::tenantId();
        $userId=Auth::id();
        if(!$tenantId||!$userId) throw new RuntimeException('Operador ou empresa inválidos.');
        $type=strtolower(trim($type));
        if(!in_array($type,['in','out'],true)) throw new RuntimeException('Tipo de movimentação inválido.');
        if(!is_finite($quantity)||$quantity<=0) throw new RuntimeException('Quantidade inválida.');
        $reason=mb_substr(trim($reason),0,500);
        if($reason==='') throw new RuntimeException('Informe o motivo da movimentação.');
        if($unitCostCents!==null&&$unitCostCents<0)throw new RuntimeException('Custo unitário inválido.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$productId,$type,$quantity,$reason,$unitCostCents):array{
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock,average_cost_cents FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$productId,$tenantId]);
            $product=$stmt->fetch();
            if(!$product) throw new RuntimeException('Produto não encontrado.');
            if(!(int)$product['track_stock']) throw new RuntimeException('Este produto não está com controle de estoque ativo.');

            $current=(float)$product['stock_qty'];
            $next=$type==='in'?$current+$quantity:$current-$quantity;
            if($next<0) throw new RuntimeException('Saída maior que o estoque disponível.');
            $average=(int)$product['average_cost_cents'];
            if($type==='in'&&$unitCostCents!==null){
                $r=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_reservations WHERE tenant_id=? AND product_id=? AND status="reserved"');$r->execute([$tenantId,$productId]);$reserved=(float)$r->fetchColumn();$physical=max(0.0,$current+$reserved);$den=$physical+$quantity;$average=$den>0?(int)round((($physical*(int)$product['average_cost_cents'])+($quantity*$unitCostCents))/$den):$unitCostCents;
            }

            $pdo->prepare('UPDATE products SET stock_qty=?,average_cost_cents=? WHERE id=? AND tenant_id=?')->execute([$next,$average,$productId,$tenantId]);
            $key='inventory:'.$tenantId.':'.$productId.':'.bin2hex(random_bytes(16));
            $movementCost=$type==='in'?($unitCostCents??$average):$average;$totalCost=(int)round($movementCost*$quantity);
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key,unit_cost_cents,total_cost_cents) VALUES (?,?,NULL,?,?,?,?,?)')->execute([$tenantId,$productId,$type,$quantity,$key,$movementCost,$totalCost]);
            Auth::audit('inventory.'.$type,'product',(string)$productId,['quantity'=>$quantity,'before'=>$current,'after'=>$next,'reason'=>$reason,'user_id'=>$userId,'unit_cost_cents'=>$movementCost,'average_cost_cents'=>$average]);
            return ['product_id'=>$productId,'before'=>$current,'after'=>$next,'type'=>$type,'quantity'=>$quantity,'average_cost_cents'=>$average];
        });
    }

    public function setStock(int $productId,float $newQuantity,string $reason):array
    {
        Auth::requirePermission('inventory.manage');
        $tenantId=Auth::tenantId();
        $userId=Auth::id();
        if(!$tenantId||!$userId) throw new RuntimeException('Operador ou empresa inválidos.');
        if(!is_finite($newQuantity)||$newQuantity<0) throw new RuntimeException('Novo saldo inválido.');
        $reason=mb_substr(trim($reason),0,500);
        if($reason==='') throw new RuntimeException('Informe o motivo do ajuste.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$productId,$newQuantity,$reason):array{
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock,average_cost_cents FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$productId,$tenantId]);
            $product=$stmt->fetch();
            if(!$product) throw new RuntimeException('Produto não encontrado.');
            if(!(int)$product['track_stock']) throw new RuntimeException('Este produto não está com controle de estoque ativo.');

            $current=(float)$product['stock_qty'];
            if(abs($current-$newQuantity)<0.000001) return ['product_id'=>$productId,'before'=>$current,'after'=>$newQuantity,'type'=>'adjustment','quantity'=>0.0];
            $delta=$newQuantity-$current;
            $pdo->prepare('UPDATE products SET stock_qty=? WHERE id=? AND tenant_id=?')->execute([$newQuantity,$productId,$tenantId]);
            $key='inventory-adjust:'.$tenantId.':'.$productId.':'.bin2hex(random_bytes(16));$average=(int)$product['average_cost_cents'];
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key,unit_cost_cents,total_cost_cents) VALUES (?,?,NULL,"adjustment",?,?,?,?)')->execute([$tenantId,$productId,$delta,$key,$average,(int)round($average*$delta)]);
            Auth::audit('inventory.adjustment','product',(string)$productId,['delta'=>$delta,'before'=>$current,'after'=>$newQuantity,'reason'=>$reason,'user_id'=>$userId,'average_cost_cents'=>$average]);
            return ['product_id'=>$productId,'before'=>$current,'after'=>$newQuantity,'type'=>'adjustment','quantity'=>$delta];
        });
    }
}
