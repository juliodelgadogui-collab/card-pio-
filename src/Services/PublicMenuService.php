<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;
use PDO;
use RuntimeException;

final class PublicMenuService
{
    public function create(int $tenantId,array $cart,string $customerName,string $phone,string $address,?string $tableToken=null,string $fulfillment='delivery'):array
    {
        $customerName=mb_substr(trim($customerName),0,160);$phone=mb_substr(trim($phone),0,30);$address=mb_substr(trim($address),0,1000);$tableToken=trim((string)$tableToken);$fulfillment=strtolower(trim($fulfillment));
        if($customerName==='')throw new RuntimeException('Nome é obrigatório.');
        $rows=[];foreach($cart as$row){if(!is_array($row))continue;$id=(int)($row['product_id']??0);$qty=(float)($row['qty']??$row['quantity']??0);if($id<1||!is_finite($qty)||$qty<=0)continue;$row['product_id']=$id;$row['qty']=$qty;$rows[]=$row;}if(!$rows)throw new RuntimeException('Carrinho vazio.');

        return Database::transaction(function(PDO $tx)use($tenantId,$rows,$customerName,$phone,$address,$tableToken,$fulfillment):array{
            $tenantStmt=$tx->prepare(Database::portableSql($tx,'SELECT * FROM tenants WHERE id=? AND status="active" FOR UPDATE'));$tenantStmt->execute([$tenantId]);$tenant=$tenantStmt->fetch();if(!$tenant||!TenantFeatures::menu($tenantId))throw new RuntimeException('Cardápio indisponível.');$settings=json_decode((string)($tenant['settings']??'{}'),true)?:[];
            $tableId=null;$tabId=null;$unitId=null;$channel='delivery';
            if($tableToken!==''){
                $lock=$tx->prepare(Database::portableSql($tx,'SELECT * FROM restaurant_tables WHERE tenant_id=? AND qr_token=? AND status<>"inactive" FOR UPDATE'));$lock->execute([$tenantId,$tableToken]);$table=$lock->fetch();if(!$table)throw new RuntimeException('Mesa não está disponível.');$tableId=(int)$table['id'];$unitId=isset($table['unit_id'])&&$table['unit_id']!==null?(int)$table['unit_id']:null;$channel='table';
                if($unitId){$unit=$tx->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');$unit->execute([$unitId,$tenantId]);if(!$unit->fetchColumn())throw new RuntimeException('A unidade desta mesa está inativa. Fale com a equipe.');}
                $tab=$tx->prepare(Database::portableSql($tx,'SELECT id FROM tabs WHERE tenant_id=? AND table_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$tab->execute([$tenantId,$tableId]);$tabId=$tab->fetchColumn();if($tabId===false){$tx->prepare('INSERT INTO tabs (tenant_id,table_id,label,status) VALUES (?, ?, ?, "open")')->execute([$tenantId,$tableId,'QR '.$table['name']]);$tabId=(int)$tx->lastInsertId();}
            }else{
                if(!in_array($fulfillment,['pickup','delivery'],true))throw new RuntimeException('Escolha Retirada no local ou Delivery.');$channel=$fulfillment;if($phone==='')throw new RuntimeException('Telefone é obrigatório para acompanhar o pedido.');if($channel==='delivery'&&$address==='')throw new RuntimeException('Endereço é obrigatório para delivery.');
            }

            $configured=new ConfiguredOrderService();$items=[];$subtotal=0;foreach($rows as$row){$resolved=$configured->resolveLine($tx,$tenantId,$row,true);$subtotal+=(int)$resolved['total_cents'];$items[]=$resolved;}
            $deliveryFee=$channel==='delivery'?max(0,(int)($settings['delivery_fee_cents']??0)):0;$minimum=max(0,(int)($settings['min_delivery_order_cents']??0));if($channel==='delivery'&&$subtotal<$minimum)throw new RuntimeException('Pedido mínimo para delivery: R$ '.number_format($minimum/100,2,',','.').'.');$total=$subtotal+$deliveryFee;if($total<=0)throw new RuntimeException('Pedido sem valor válido.');
            $customerId=null;if($phone!==''){$c=$tx->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? ORDER BY id DESC LIMIT 1');$c->execute([$tenantId,$phone]);$customerId=$c->fetchColumn()?:null;}if($customerId){$tx->prepare('UPDATE customers SET name=? WHERE id=? AND tenant_id=?')->execute([$customerName,$customerId,$tenantId]);}else{$c=$tx->prepare('INSERT INTO customers (tenant_id,name,phone) VALUES (?,?,?)');$c->execute([$tenantId,$customerName,$phone?:null]);$customerId=(int)$tx->lastInsertId();}
            $publicToken=bin2hex(random_bytes(20));$s=$tx->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,table_id,tab_id,channel,status,payment_status,subtotal_cents,delivery_fee_cents,total_cents,delivery_address) VALUES (?,?,?,?,?,?,?,"pending","unpaid",?,?,?,?)');$s->execute([$publicToken,$tenantId,$unitId,$customerId,$tableId,$tabId,$channel,$subtotal,$deliveryFee,$total,$channel==='delivery'?$address:null]);$orderId=(int)$tx->lastInsertId();
            $ins=$tx->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');foreach($items as$item){$ins->execute([$orderId,$item['product_id'],$item['name'],$item['unit_price_cents'],$item['quantity'],$item['total_cents']]);$orderItemId=(int)$tx->lastInsertId();$configured->persistModifiers($tx,$tenantId,$orderId,$orderItemId,$item);}if($tableId)$tx->prepare('UPDATE restaurant_tables SET status=CASE WHEN status="available" THEN "occupied" ELSE status END WHERE id=?')->execute([$tableId]);
            $expires=(new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');(new StockReservationService())->reserve($tx,$tenantId,$orderId,$items,$expires);(new OrderHistoryService())->record($tx,$tenantId,$orderId,null,'pending','public',$channel==='pickup'?'Pedido de retirada criado pelo cardápio digital.':'Pedido criado pelo cardápio digital.',null);
            return ['id'=>$orderId,'public_token'=>$publicToken,'subtotal_cents'=>$subtotal,'delivery_fee_cents'=>$deliveryFee,'total_cents'=>$total,'channel'=>$channel,'unit_id'=>$unitId,'table_id'=>$tableId,'tab_id'=>$tabId,'stock_reserved_until'=>$expires];
        });
    }
}
