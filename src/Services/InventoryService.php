<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class InventoryService
{
    public function move(int $productId,string $type,float $quantity,string $reason):array
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

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$productId,$type,$quantity,$reason):array{
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$productId,$tenantId]);
            $product=$stmt->fetch();
            if(!$product) throw new RuntimeException('Produto não encontrado.');
            if(!(int)$product['track_stock']) throw new RuntimeException('Este produto não está com controle de estoque ativo.');

            $current=(float)$product['stock_qty'];
            $next=$type==='in'?$current+$quantity:$current-$quantity;
            if($next<0) throw new RuntimeException('Saída maior que o estoque disponível.');

            $pdo->prepare('UPDATE products SET stock_qty=? WHERE id=? AND tenant_id=?')->execute([$next,$productId,$tenantId]);
            $key='inventory:'.$tenantId.':'.$productId.':'.bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,NULL,?,?,?)')->execute([$tenantId,$productId,$type,$quantity,$key]);
            Auth::audit('inventory.'.$type,'product',(string)$productId,['quantity'=>$quantity,'before'=>$current,'after'=>$next,'reason'=>$reason,'user_id'=>$userId]);
            return ['product_id'=>$productId,'before'=>$current,'after'=>$next,'type'=>$type,'quantity'=>$quantity];
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
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,stock_qty,track_stock FROM products WHERE id=? AND tenant_id=? FOR UPDATE'));
            $stmt->execute([$productId,$tenantId]);
            $product=$stmt->fetch();
            if(!$product) throw new RuntimeException('Produto não encontrado.');
            if(!(int)$product['track_stock']) throw new RuntimeException('Este produto não está com controle de estoque ativo.');

            $current=(float)$product['stock_qty'];
            if(abs($current-$newQuantity)<0.000001) return ['product_id'=>$productId,'before'=>$current,'after'=>$newQuantity,'type'=>'adjustment','quantity'=>0.0];
            $delta=$newQuantity-$current;
            $pdo->prepare('UPDATE products SET stock_qty=? WHERE id=? AND tenant_id=?')->execute([$newQuantity,$productId,$tenantId]);
            $key='inventory-adjust:'.$tenantId.':'.$productId.':'.bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,NULL,"adjustment",?,?)')->execute([$tenantId,$productId,$delta,$key]);
            Auth::audit('inventory.adjustment','product',(string)$productId,['delta'=>$delta,'before'=>$current,'after'=>$newQuantity,'reason'=>$reason,'user_id'=>$userId]);
            return ['product_id'=>$productId,'before'=>$current,'after'=>$newQuantity,'type'=>'adjustment','quantity'=>$delta];
        });
    }
}
