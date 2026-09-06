<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ProductConfigurationService
{
    public function stations(?int $tenantId=null,bool $activeOnly=false):array
    {
        $tenantId??=Auth::tenantId();if(!$tenantId)return [];$sql='SELECT * FROM production_stations WHERE tenant_id=?';if($activeOnly)$sql.=' AND active=1';$sql.=' ORDER BY sort_order,name';$s=Database::connection()->prepare($sql);$s->execute([$tenantId]);return $s->fetchAll();
    }

    public function saveStation(array $data):int
    {
        Auth::requirePermission('production.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $id=(int)($data['id']??0);$name=mb_substr(trim((string)($data['name']??'')),0,140);$code=mb_substr(trim((string)($data['code']??'')),0,80);$type=strtolower(trim((string)($data['station_type']??'kitchen')));$sla=max(1,min(240,(int)($data['sla_minutes']??15)));$sort=(int)($data['sort_order']??0);$printer=strtolower(trim((string)($data['printer_mode']??'manual')));$target=mb_substr(trim((string)($data['printer_target']??'')),0,255);$active=!empty($data['active'])?1:0;
        if($name===''||$code==='')throw new RuntimeException('Nome e código da estação são obrigatórios.');if(!in_array($type,['kitchen','bar','grill','fryer','dessert','assembly','other'],true))throw new RuntimeException('Tipo de estação inválido.');if(!in_array($printer,['none','manual','auto'],true))throw new RuntimeException('Modo de impressão inválido.');
        $pdo=Database::connection();if($id){$s=$pdo->prepare('UPDATE production_stations SET code=?,name=?,station_type=?,sla_minutes=?,sort_order=?,printer_mode=?,printer_target=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$s->execute([$code,$name,$type,$sla,$sort,$printer,$target?:null,$active,$id,$tenantId]);if($s->rowCount()===0){$c=$pdo->prepare('SELECT id FROM production_stations WHERE id=? AND tenant_id=?');$c->execute([$id,$tenantId]);if(!$c->fetchColumn())throw new RuntimeException('Estação não encontrada.');}}else{$s=$pdo->prepare('INSERT INTO production_stations (tenant_id,code,name,station_type,sla_minutes,sort_order,printer_mode,printer_target,active) VALUES (?,?,?,?,?,?,?,?,?)');$s->execute([$tenantId,$code,$name,$type,$sla,$sort,$printer,$target?:null,$active]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('production.station_saved','production_station',(string)$id,['name'=>$name,'type'=>$type,'printer_mode'=>$printer]);return $id;
    }

    public function productSetup(int $productId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$productId<1)throw new RuntimeException('Produto inválido.');$pdo=Database::connection();$p=$pdo->prepare('SELECT * FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Produto não encontrado.');
        $profile=$pdo->prepare('SELECT pp.*,ps.name station_name FROM product_production_profiles pp LEFT JOIN production_stations ps ON ps.id=pp.station_id WHERE pp.tenant_id=? AND pp.product_id=?');$profile->execute([$tenantId,$productId]);$profile=$profile->fetch()?:['production_enabled'=>0,'station_id'=>null,'prep_minutes'=>null,'print_mode'=>'inherit','production_notes'=>null];
        $g=$pdo->prepare('SELECT * FROM modifier_groups WHERE tenant_id=? AND product_id=? ORDER BY sort_order,id');$g->execute([$tenantId,$productId]);$groups=$g->fetchAll();$options=[];if($groups){$ids=array_column($groups,'id');$marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare('SELECT mo.*,p.name inventory_product_name,ps.name station_name FROM modifier_options mo LEFT JOIN products p ON p.id=mo.inventory_product_id LEFT JOIN production_stations ps ON ps.id=mo.station_id WHERE mo.group_id IN ('.$marks.') ORDER BY mo.sort_order,mo.id');$q->execute($ids);foreach($q->fetchAll() as$row)$options[(int)$row['group_id']][]=$row;}
        return ['product'=>$product,'profile'=>$profile,'groups'=>$groups,'options'=>$options,'stations'=>$this->stations($tenantId,false)];
    }

    public function saveProductProfile(int $productId,array $data):void
    {
        Auth::requirePermission('catalog.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$stationId=(int)($data['station_id']??0);$enabled=!empty($data['production_enabled'])?1:0;$prep=(int)($data['prep_minutes']??0);$prep=$prep>0?min(240,$prep):null;$print=strtolower(trim((string)($data['print_mode']??'inherit')));if(!in_array($print,['inherit','none','manual','auto'],true))throw new RuntimeException('Modo de impressão inválido.');$notes=mb_substr(trim((string)($data['production_notes']??'')),0,500);
        $pdo=Database::connection();$p=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Produto inválido.');if($enabled){if(!$stationId)throw new RuntimeException('Selecione a estação de produção.');$s=$pdo->prepare('SELECT id FROM production_stations WHERE id=? AND tenant_id=? AND active=1');$s->execute([$stationId,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Estação inválida ou inativa.');}
        $c=$pdo->prepare('SELECT product_id FROM product_production_profiles WHERE tenant_id=? AND product_id=?');$c->execute([$tenantId,$productId]);if($c->fetchColumn())$pdo->prepare('UPDATE product_production_profiles SET station_id=?,production_enabled=?,prep_minutes=?,print_mode=?,production_notes=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND product_id=?')->execute([$stationId?:null,$enabled,$prep,$print,$notes?:null,$tenantId,$productId]);else$pdo->prepare('INSERT INTO product_production_profiles (tenant_id,product_id,station_id,production_enabled,prep_minutes,print_mode,production_notes) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$productId,$stationId?:null,$enabled,$prep,$print,$notes?:null]);Auth::audit('product.production_profile','product',(string)$productId,['station_id'=>$stationId?:null,'production_enabled'=>$enabled,'prep_minutes'=>$prep]);
    }

    public function saveModifierGroup(int $productId,array $data):int
    {
        Auth::requirePermission('catalog.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$id=(int)($data['id']??0);$name=mb_substr(trim((string)($data['name']??'')),0,160);$required=!empty($data['required'])?1:0;$min=max(0,(int)($data['min_select']??0));$max=max(1,(int)($data['max_select']??1));$sort=(int)($data['sort_order']??0);$active=!empty($data['active'])?1:0;if($name==='')throw new RuntimeException('Nome do grupo é obrigatório.');if($required&&$min<1)$min=1;if($min>$max)throw new RuntimeException('Mínimo de escolhas não pode ser maior que o máximo.');$pdo=Database::connection();if($id){$s=$pdo->prepare('UPDATE modifier_groups SET name=?,required=?,min_select=?,max_select=?,sort_order=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND product_id=?');$s->execute([$name,$required,$min,$max,$sort,$active,$id,$tenantId,$productId]);}else{$s=$pdo->prepare('INSERT INTO modifier_groups (tenant_id,product_id,name,required,min_select,max_select,sort_order,active) VALUES (?,?,?,?,?,?,?,?)');$s->execute([$tenantId,$productId,$name,$required,$min,$max,$sort,$active]);$id=(int)$pdo->lastInsertId();}Auth::audit('modifier.group_saved','product',(string)$productId,['group_id'=>$id]);return $id;
    }

    public function saveModifierOption(int $groupId,array $data):int
    {
        Auth::requirePermission('catalog.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$g=$pdo->prepare('SELECT id,product_id FROM modifier_groups WHERE id=? AND tenant_id=?');$g->execute([$groupId,$tenantId]);$group=$g->fetch();if(!$group)throw new RuntimeException('Grupo inválido.');$id=(int)($data['id']??0);$name=mb_substr(trim((string)($data['name']??'')),0,160);if($name==='')throw new RuntimeException('Nome do adicional é obrigatório.');$price=(int)round((float)str_replace(',','.',(string)($data['price_delta']??'0'))*100);$cost=(int)round((float)str_replace(',','.',(string)($data['cost']??'0'))*100);$inventory=(int)($data['inventory_product_id']??0);$inventoryQty=(float)str_replace(',','.',(string)($data['inventory_quantity']??'0'));if(!is_finite($inventoryQty)||$inventoryQty<0)throw new RuntimeException('Impacto no estoque inválido.');$station=(int)($data['station_id']??0);$production=!empty($data['production_enabled'])?1:0;$sort=(int)($data['sort_order']??0);$active=!empty($data['active'])?1:0;if($production&&!$station)throw new RuntimeException('Adicional enviado à produção precisa de estação.');if($inventory){$p=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');$p->execute([$inventory,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Produto de estoque do adicional é inválido.');}
        if($id){$s=$pdo->prepare('UPDATE modifier_options SET name=?,price_delta_cents=?,cost_cents=?,inventory_product_id=?,inventory_quantity=?,station_id=?,production_enabled=?,sort_order=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND group_id=?');$s->execute([$name,$price,$cost,$inventory?:null,$inventoryQty,$station?:null,$production,$sort,$active,$id,$tenantId,$groupId]);}else{$s=$pdo->prepare('INSERT INTO modifier_options (tenant_id,group_id,name,price_delta_cents,cost_cents,inventory_product_id,inventory_quantity,station_id,production_enabled,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$tenantId,$groupId,$name,$price,$cost,$inventory?:null,$inventoryQty,$station?:null,$production,$sort,$active]);$id=(int)$pdo->lastInsertId();}Auth::audit('modifier.option_saved','product',(string)$group['product_id'],['group_id'=>$groupId,'option_id'=>$id,'price_delta_cents'=>$price]);return $id;
    }

    public function validateSelections(int $productId,array $optionIds):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$optionIds=array_values(array_unique(array_filter(array_map('intval',$optionIds),fn($v)=>$v>0)));$setup=$this->productSetup($productId);$selectedByGroup=[];$selected=[];foreach($setup['groups'] as$group){$rows=$setup['options'][(int)$group['id']]??[];foreach($rows as$option){if(in_array((int)$option['id'],$optionIds,true)&&$option['active']){$selectedByGroup[(int)$group['id']][]=$option;$selected[]=$option;}}$count=count($selectedByGroup[(int)$group['id']]??[]);if($group['active']){if($count<(int)$group['min_select'])throw new RuntimeException('Escolha pelo menos '.$group['min_select'].' opção(ões) em '.$group['name'].'.');if($count>(int)$group['max_select'])throw new RuntimeException('Escolha no máximo '.$group['max_select'].' opção(ões) em '.$group['name'].'.');}}
        return $selected;
    }
}
