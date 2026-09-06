<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ConfiguredOrderService
{
    public function resolveLine(PDO $pdo,int $tenantId,array $row,bool $lock=true):array
    {
        $productId=(int)($row['product_id']??0);$qty=round((float)($row['qty']??$row['quantity']??0),3);
        if($productId<1||!is_finite($qty)||$qty<=0)throw new RuntimeException('Item do pedido inválido.');
        $sql='SELECT id,name,price_cents,track_stock,stock_qty,active FROM products WHERE id=? AND tenant_id=? AND active=1'.($lock?' FOR UPDATE':'');$sql=Database::portableSql($pdo,$sql);$p=$pdo->prepare($sql);$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Produto indisponível.');

        $rawIds=$row['option_ids']??$row['modifier_option_ids']??[];if(!is_array($rawIds))$rawIds=[];$selectedIds=array_values(array_unique(array_filter(array_map('intval',$rawIds),fn($v)=>$v>0)));sort($selectedIds);
        $g=$pdo->prepare('SELECT * FROM modifier_groups WHERE tenant_id=? AND product_id=? AND active=1 ORDER BY sort_order,id');$g->execute([$tenantId,$productId]);$groups=$g->fetchAll();$groupMap=[];foreach($groups as$group)$groupMap[(int)$group['id']]=$group;
        $options=[];$knownSelected=[];
        if($groups){$ids=array_column($groups,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare('SELECT * FROM modifier_options WHERE tenant_id=? AND active=1 AND group_id IN ('.$marks.') ORDER BY group_id,sort_order,id');$q->execute(array_merge([$tenantId],$ids));foreach($q->fetchAll() as$option){$options[(int)$option['group_id']][]=$option;if(in_array((int)$option['id'],$selectedIds,true))$knownSelected[(int)$option['id']]=$option;}}
        if(count($knownSelected)!==count($selectedIds))throw new RuntimeException('Uma opção selecionada não pertence a este produto ou está inativa.');

        $selected=[];$delta=0;$cost=0;
        foreach($groups as$group){$rows=$options[(int)$group['id']]??[];$picked=[];foreach($rows as$option)if(isset($knownSelected[(int)$option['id']]))$picked[]=$option;$count=count($picked);$min=max((int)$group['min_select'],(int)$group['required']?1:0);$max=max(1,(int)$group['max_select']);if($count<$min)throw new RuntimeException('Escolha pelo menos '.$min.' opção(ões) em “'.$group['name'].'”.');if($count>$max)throw new RuntimeException('Escolha no máximo '.$max.' opção(ões) em “'.$group['name'].'”.');foreach($picked as$option){$option['group_name']=(string)$group['name'];$selected[]=$option;$delta+=(int)$option['price_delta_cents'];$cost+=(int)$option['cost_cents'];}}
        $unit=(int)$product['price_cents']+$delta;if($unit<0)throw new RuntimeException('Configuração gerou preço inválido para '.$product['name'].'.');$total=(int)round($unit*$qty);
        return ['product_id'=>$productId,'name'=>(string)$product['name'],'base_price_cents'=>(int)$product['price_cents'],'unit_price_cents'=>$unit,'quantity'=>$qty,'total_cents'=>$total,'modifier_delta_cents'=>$delta,'modifier_cost_cents'=>$cost,'modifiers'=>$selected,'option_ids'=>$selectedIds,'track_stock'=>(int)$product['track_stock'],'stock_qty'=>(float)$product['stock_qty']];
    }

    public function persistModifiers(PDO $pdo,int $tenantId,int $orderId,int $orderItemId,array $resolved):void
    {
        $qty=(float)$resolved['quantity'];if(!$resolved['modifiers'])return;$ins=$pdo->prepare('INSERT INTO order_item_modifiers (tenant_id,order_id,order_item_id,modifier_group_id,modifier_option_id,group_name_snapshot,option_name_snapshot,quantity,unit_price_delta_cents,total_delta_cents,station_id,production_enabled,inventory_product_id,inventory_quantity,cost_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($resolved['modifiers'] as$m){$delta=(int)$m['price_delta_cents'];$ins->execute([$tenantId,$orderId,$orderItemId,(int)$m['group_id'],(int)$m['id'],(string)$m['group_name'],(string)$m['name'],$qty,$delta,(int)round($delta*$qty),!empty($m['station_id'])?(int)$m['station_id']:null,(int)$m['production_enabled'],!empty($m['inventory_product_id'])?(int)$m['inventory_product_id']:null,(float)$m['inventory_quantity'],(int)$m['cost_cents']]);}
    }

    public function catalogModifiers(PDO $pdo,int $tenantId,array $productIds):array
    {
        $productIds=array_values(array_unique(array_filter(array_map('intval',$productIds),fn($v)=>$v>0)));if(!$productIds)return [];$marks=implode(',',array_fill(0,count($productIds),'?'));$g=$pdo->prepare('SELECT * FROM modifier_groups WHERE tenant_id=? AND product_id IN ('.$marks.') AND active=1 ORDER BY product_id,sort_order,id');$g->execute(array_merge([$tenantId],$productIds));$groups=$g->fetchAll();if(!$groups)return [];$groupIds=array_column($groups,'id');$gmarks=implode(',',array_fill(0,count($groupIds),'?'));$o=$pdo->prepare('SELECT * FROM modifier_options WHERE tenant_id=? AND group_id IN ('.$gmarks.') AND active=1 ORDER BY group_id,sort_order,id');$o->execute(array_merge([$tenantId],$groupIds));$byGroup=[];foreach($o->fetchAll() as$row)$byGroup[(int)$row['group_id']][]=$row;$out=[];foreach($groups as$group){$group['options']=$byGroup[(int)$group['id']]??[];$out[(int)$group['product_id']][]=$group;}return $out;
    }
}
