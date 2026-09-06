<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PurchaseService
{
    public function suppliers(bool $activeOnly=false): array
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)return[];$sql='SELECT * FROM suppliers WHERE tenant_id=?'.($activeOnly?' AND active=1':'').' ORDER BY active DESC,name';$s=Database::connection()->prepare($sql);$s->execute([$tenantId]);return$s->fetchAll();
    }

    public function saveSupplier(?int$id,string$name,string$document='',string$phone='',string$email='',string$notes='',bool$active=true): int
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$name=mb_substr(trim($name),0,160);if($name==='')throw new RuntimeException('Informe o nome do fornecedor.');$document=mb_substr(trim($document),0,40);$phone=mb_substr(trim($phone),0,40);$email=mb_strtolower(mb_substr(trim($email),0,190));$notes=mb_substr(trim($notes),0,1000);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail do fornecedor inválido.');$pdo=Database::connection();
        if($id&&$id>0){$s=$pdo->prepare('UPDATE suppliers SET name=?,document=?,phone=?,email=?,notes=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$s->execute([$name,$document?:null,$phone?:null,$email?:null,$notes?:null,$active?1:0,$id,$tenantId]);if($s->rowCount()===0){$q=$pdo->prepare('SELECT id FROM suppliers WHERE id=? AND tenant_id=?');$q->execute([$id,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Fornecedor não encontrado.');}}
        else{$s=$pdo->prepare('INSERT INTO suppliers (tenant_id,name,document,phone,email,notes,active) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$name,$document?:null,$phone?:null,$email?:null,$notes?:null,$active?1:0]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('supplier.saved','supplier',(string)$id,['name'=>$name,'active'=>$active]);return(int)$id;
    }

    public function createOrder(?int$supplierId,string$notes=''): int
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$unit=(new OperatingUnitService())->requireCurrent();$unitId=(int)$unit['id'];$notes=mb_substr(trim($notes),0,1000);$pdo=Database::connection();if($supplierId&&$supplierId>0){$s=$pdo->prepare('SELECT id FROM suppliers WHERE id=? AND tenant_id=? AND active=1');$s->execute([$supplierId,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Fornecedor inválido ou inativo.');}else$supplierId=null;
        $s=$pdo->prepare('INSERT INTO purchase_orders (tenant_id,unit_id,supplier_id,status,total_cents,notes,created_by) VALUES (?,?,?,"draft",0,?,?)');$s->execute([$tenantId,$unitId,$supplierId,$notes?:null,$userId]);$id=(int)$pdo->lastInsertId();Auth::audit('purchase.created','purchase_order',(string)$id,['unit_id'=>$unitId,'supplier_id'=>$supplierId]);return$id;
    }

    public function addItem(int$orderId,int$productId,float$purchaseQuantity,int$unitCostCents): void
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');if(!is_finite($purchaseQuantity)||$purchaseQuantity<=0)throw new RuntimeException('Quantidade de compra inválida.');if($unitCostCents<0)throw new RuntimeException('Custo de compra inválido.');
        Database::transaction(function(PDO$pdo)use($tenantId,$orderId,$productId,$purchaseQuantity,$unitCostCents):void{$order=$this->lockedOrder($pdo,$tenantId,$orderId);$this->assertCurrentUnit($order);if($order['status']!=='draft')throw new RuntimeException('Somente compras em rascunho podem ser alteradas.');$p=$pdo->prepare('SELECT id,name,track_stock,stock_unit,purchase_unit,purchase_factor FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Produto não encontrado.');if(!(int)$product['track_stock'])throw new RuntimeException('Ative o controle de estoque deste produto antes de incluí-lo na compra.');$factor=max(.000001,(float)($product['purchase_factor']??1));$stockQty=round($purchaseQuantity*$factor,6);$total=(int)round($purchaseQuantity*$unitCostCents);$existing=$pdo->prepare('SELECT id FROM purchase_order_items WHERE purchase_order_id=? AND product_id=? ORDER BY id LIMIT 1');$existing->execute([$orderId,$productId]);$itemId=(int)$existing->fetchColumn();if($itemId>0)$pdo->prepare('UPDATE purchase_order_items SET quantity=?,unit_cost_cents=?,total_cents=?,purchase_unit_snapshot=?,stock_unit_snapshot=?,purchase_factor_snapshot=?,stock_quantity=? WHERE id=?')->execute([$purchaseQuantity,$unitCostCents,$total,$product['purchase_unit']?:'un',$product['stock_unit']?:'un',$factor,$stockQty,$itemId]);else$pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id,product_id,quantity,unit_cost_cents,total_cents,purchase_unit_snapshot,stock_unit_snapshot,purchase_factor_snapshot,stock_quantity) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$orderId,$productId,$purchaseQuantity,$unitCostCents,$total,$product['purchase_unit']?:'un',$product['stock_unit']?:'un',$factor,$stockQty]);$this->recalculateTotal($pdo,$orderId);});Auth::audit('purchase.item_saved','purchase_order',(string)$orderId,['product_id'=>$productId,'purchase_quantity'=>$purchaseQuantity,'unit_cost_cents'=>$unitCostCents]);
    }

    public function removeItem(int$orderId,int$itemId): void
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)return;Database::transaction(function(PDO$pdo)use($tenantId,$orderId,$itemId):void{$order=$this->lockedOrder($pdo,$tenantId,$orderId);$this->assertCurrentUnit($order);if($order['status']!=='draft')throw new RuntimeException('Somente compras em rascunho podem ser alteradas.');$pdo->prepare('DELETE FROM purchase_order_items WHERE id=? AND purchase_order_id=?')->execute([$itemId,$orderId]);$this->recalculateTotal($pdo,$orderId);});Auth::audit('purchase.item_removed','purchase_order',(string)$orderId,['item_id'=>$itemId]);
    }

    public function markOrdered(int$orderId): void
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)return;Database::transaction(function(PDO$pdo)use($tenantId,$orderId):void{$order=$this->lockedOrder($pdo,$tenantId,$orderId);$this->assertCurrentUnit($order);if($order['status']==='ordered')return;if($order['status']!=='draft')throw new RuntimeException('Esta compra não pode ser enviada.');$q=$pdo->prepare('SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id=?');$q->execute([$orderId]);if((int)$q->fetchColumn()<1)throw new RuntimeException('Adicione pelo menos um item à compra.');$pdo->prepare('UPDATE purchase_orders SET status="ordered" WHERE id=? AND tenant_id=?')->execute([$orderId,$tenantId]);});Auth::audit('purchase.ordered','purchase_order',(string)$orderId);
    }

    public function cancel(int$orderId,string$reason=''): void
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)return;$reason=mb_substr(trim($reason),0,500);Database::transaction(function(PDO$pdo)use($tenantId,$orderId):void{$order=$this->lockedOrder($pdo,$tenantId,$orderId);$this->assertCurrentUnit($order);if($order['status']==='cancelled')return;if(!in_array($order['status'],['draft','ordered'],true))throw new RuntimeException('Compra recebida não pode ser cancelada.');$pdo->prepare('UPDATE purchase_orders SET status="cancelled" WHERE id=? AND tenant_id=?')->execute([$orderId,$tenantId]);});Auth::audit('purchase.cancelled','purchase_order',(string)$orderId,['reason'=>$reason]);
    }

    public function receive(int$orderId): void
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');Database::transaction(function(PDO$pdo)use($tenantId,$userId,$orderId):void{$order=$this->lockedOrder($pdo,$tenantId,$orderId);$this->assertCurrentUnit($order);if($order['status']==='received')return;if(!in_array($order['status'],['draft','ordered'],true))throw new RuntimeException('Esta compra não pode ser recebida.');$items=$pdo->prepare('SELECT poi.*,p.name,p.track_stock,p.average_cost_cents,p.min_stock_qty FROM purchase_order_items poi JOIN products p ON p.id=poi.product_id WHERE poi.purchase_order_id=? ORDER BY poi.id');$items->execute([$orderId]);$rows=$items->fetchAll();if(!$rows)throw new RuntimeException('Compra sem itens.');$unitId=(int)$order['unit_id'];foreach($rows as$row){if(!(int)$row['track_stock'])throw new RuntimeException('O produto '.$row['name'].' está sem controle de estoque.');$productId=(int)$row['product_id'];$factor=max(.000001,(float)($row['purchase_factor_snapshot']??1));$stockQty=(float)($row['stock_quantity']??0);if($stockQty<=0)$stockQty=(float)$row['quantity']*$factor;if($stockQty<=0)throw new RuntimeException('Quantidade convertida inválida para '.$row['name'].'.');$insert=Database::portableSql($pdo,'INSERT IGNORE INTO unit_inventory (tenant_id,unit_id,product_id,stock_qty,average_cost_cents,min_stock_qty) VALUES (?,?,?,?,?,?)');$pdo->prepare($insert)->execute([$tenantId,$unitId,$productId,0,$row['average_cost_cents']??0,$row['min_stock_qty']??0]);$inv=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM unit_inventory WHERE tenant_id=? AND unit_id=? AND product_id=? FOR UPDATE'));$inv->execute([$tenantId,$unitId,$productId]);$inventory=$inv->fetch();if(!$inventory)throw new RuntimeException('Saldo da unidade não encontrado para '.$row['name'].'.');$reserved=$pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM stock_reservations WHERE tenant_id=? AND unit_id=? AND product_id=? AND status="reserved"');$reserved->execute([$tenantId,$unitId,$productId]);$physical=max(0,(float)$inventory['stock_qty']+(float)$reserved->fetchColumn());$stockUnitCost=(int)round((int)$row['total_cents']/$stockQty);$den=$physical+$stockQty;$average=$den>0?(int)round((($physical*(int)$inventory['average_cost_cents'])+($stockQty*$stockUnitCost))/$den):$stockUnitCost;$pdo->prepare('UPDATE unit_inventory SET stock_qty=stock_qty+?,average_cost_cents=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=? AND product_id=?')->execute([$stockQty,$average,$tenantId,$unitId,$productId]);$key='purchase:'.$orderId.':item:'.$row['id'];$exists=$pdo->prepare('SELECT id FROM stock_movements WHERE tenant_id=? AND idempotency_key=?');$exists->execute([$tenantId,$key]);if(!$exists->fetchColumn())$pdo->prepare('INSERT INTO stock_movements (tenant_id,unit_id,product_id,order_id,type,quantity,idempotency_key,unit_cost_cents,total_cost_cents,reason,performed_by) VALUES (?,?,?,NULL,"in",?,?,?,?,?,?)')->execute([$tenantId,$unitId,$productId,$stockQty,$key,$stockUnitCost,(int)$row['total_cents'],'Recebimento da compra #'.$orderId,$userId]);$this->syncLegacyProduct($pdo,$tenantId,$productId);}$pdo->prepare('UPDATE purchase_orders SET status="received",received_by=?,received_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$userId,$orderId,$tenantId]);});Auth::audit('purchase.received','purchase_order',(string)$orderId);
    }

    public function orders(int$limit=100): array
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)return[];$unitId=(new OperatingUnitService())->requireCurrent()['id'];$s=Database::connection()->prepare('SELECT po.*,s.name supplier_name,u.name created_by_name,ru.name received_by_name,(SELECT COUNT(*) FROM purchase_order_items poi WHERE poi.purchase_order_id=po.id) items_count FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN users u ON u.id=po.created_by LEFT JOIN users ru ON ru.id=po.received_by WHERE po.tenant_id=? AND po.unit_id=? ORDER BY po.id DESC LIMIT '.max(1,min(300,$limit)));$s->execute([$tenantId,$unitId]);return$s->fetchAll();
    }

    public function details(int$orderId): array
    {
        Auth::requirePermission('inventory.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$s=$pdo->prepare('SELECT po.*,s.name supplier_name,ou.name unit_name FROM purchase_orders po LEFT JOIN suppliers s ON s.id=po.supplier_id JOIN operating_units ou ON ou.id=po.unit_id WHERE po.id=? AND po.tenant_id=?');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Compra não encontrada.');$this->assertCurrentUnit($order);$i=$pdo->prepare('SELECT poi.*,p.name product_name,p.sku FROM purchase_order_items poi JOIN products p ON p.id=poi.product_id WHERE poi.purchase_order_id=? ORDER BY poi.id');$i->execute([$orderId]);return['order'=>$order,'items'=>$i->fetchAll()];
    }

    private function lockedOrder(PDO$pdo,int$tenantId,int$orderId): array{$s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM purchase_orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Compra não encontrada.');return$order;}
    private function assertCurrentUnit(array$order): void{$current=(new OperatingUnitService())->requireCurrent();if((int)$order['unit_id']!==(int)$current['id'])throw new RuntimeException('Esta compra pertence a outra unidade.');}
    private function recalculateTotal(PDO$pdo,int$orderId): void{$s=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM purchase_order_items WHERE purchase_order_id=?');$s->execute([$orderId]);$pdo->prepare('UPDATE purchase_orders SET total_cents=? WHERE id=?')->execute([(int)$s->fetchColumn(),$orderId]);}
    private function syncLegacyProduct(PDO$pdo,int$tenantId,int$productId): void{$s=$pdo->prepare('SELECT COALESCE(SUM(stock_qty),0) qty,CASE WHEN COALESCE(SUM(stock_qty),0)>0 THEN CAST(ROUND(SUM(stock_qty*average_cost_cents)/SUM(stock_qty)) AS INTEGER) ELSE MAX(average_cost_cents) END avg_cost FROM unit_inventory WHERE tenant_id=? AND product_id=?');$s->execute([$tenantId,$productId]);$row=$s->fetch()?:['qty'=>0,'avg_cost'=>0];$pdo->prepare('UPDATE products SET stock_qty=?,average_cost_cents=? WHERE id=? AND tenant_id=?')->execute([(float)$row['qty'],(int)($row['avg_cost']??0),$productId,$tenantId]);}
}
