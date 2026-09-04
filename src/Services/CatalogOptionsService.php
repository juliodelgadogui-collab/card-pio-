<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CatalogOptionsService
{
    public function validateSelection(PDO $pdo,int $tenantId,int $productId,array $optionIds):array
    {
        $groups=$pdo->prepare('SELECT id,name,min_select,max_select FROM product_option_groups WHERE tenant_id=? AND product_id=? AND active=1 ORDER BY sort_order,id');$groups->execute([$tenantId,$productId]);$groupRows=$groups->fetchAll();
        $ids=array_values(array_unique(array_filter(array_map('intval',$optionIds),fn($v)=>$v>0)));$selected=[];$counts=[];$delta=0;
        if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare('SELECT po.id,po.group_id,po.name,po.price_delta_cents FROM product_options po JOIN product_option_groups g ON g.id=po.group_id WHERE po.tenant_id=? AND g.product_id=? AND po.active=1 AND g.active=1 AND po.id IN ('.$marks.')');$s->execute([$tenantId,$productId,...$ids]);foreach($s->fetchAll()as$o){$selected[(int)$o['id']]=$o;$counts[(int)$o['group_id']]=($counts[(int)$o['group_id']]??0)+1;$delta+=(int)$o['price_delta_cents'];}if(count($selected)!==count($ids))throw new RuntimeException('Uma opção selecionada não pertence a este produto.');}
        foreach($groupRows as$g){$count=$counts[(int)$g['id']]??0;if($count<(int)$g['min_select'])throw new RuntimeException('Selecione pelo menos '.(int)$g['min_select'].' opção(ões) em '.$g['name'].'.');if($count>(int)$g['max_select'])throw new RuntimeException('Selecione no máximo '.(int)$g['max_select'].' opção(ões) em '.$g['name'].'.');}
        return ['options'=>array_values($selected),'delta_cents'=>$delta];
    }

    public function saveGroup(int $tenantId,int $productId,array $data,?int $id=null):int
    {
        Auth::requirePermission('catalog.manage');$name=trim((string)($data['name']??''));if($name==='')throw new RuntimeException('Informe o nome do grupo.');$min=max(0,(int)($data['min_select']??0));$max=max(1,(int)($data['max_select']??1));if($min>$max)throw new RuntimeException('Mínimo não pode ser maior que o máximo.');$pdo=Database::connection();
        $p=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Produto não encontrado.');
        if($id){$s=$pdo->prepare('UPDATE product_option_groups SET name=?,min_select=?,max_select=?,sort_order=?,active=? WHERE id=? AND tenant_id=? AND product_id=?');$s->execute([$name,$min,$max,(int)($data['sort_order']??0),!empty($data['active'])?1:0,$id,$tenantId,$productId]);return$id;}
        $s=$pdo->prepare('INSERT INTO product_option_groups (tenant_id,product_id,name,min_select,max_select,sort_order,active) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$productId,$name,$min,$max,(int)($data['sort_order']??0),!empty($data['active'])?1:0]);return(int)$pdo->lastInsertId();
    }

    public function saveOption(int $tenantId,int $groupId,array $data,?int $id=null):int
    {
        Auth::requirePermission('catalog.manage');$name=trim((string)($data['name']??''));if($name==='')throw new RuntimeException('Informe o nome da opção.');$price=(int)($data['price_delta_cents']??0);$pdo=Database::connection();$g=$pdo->prepare('SELECT id FROM product_option_groups WHERE id=? AND tenant_id=?');$g->execute([$groupId,$tenantId]);if(!$g->fetchColumn())throw new RuntimeException('Grupo inválido.');
        if($id){$pdo->prepare('UPDATE product_options SET name=?,price_delta_cents=?,sort_order=?,active=? WHERE id=? AND tenant_id=? AND group_id=?')->execute([$name,$price,(int)($data['sort_order']??0),!empty($data['active'])?1:0,$id,$tenantId,$groupId]);return$id;}
        $s=$pdo->prepare('INSERT INTO product_options (tenant_id,group_id,name,price_delta_cents,sort_order,active) VALUES (?,?,?,?,?,?)');$s->execute([$tenantId,$groupId,$name,$price,(int)($data['sort_order']??0),!empty($data['active'])?1:0]);return(int)$pdo->lastInsertId();
    }

    public function setComboComponents(int $tenantId,int $comboProductId,array $components):void
    {
        Auth::requirePermission('catalog.manage');Database::transaction(function(PDO $pdo)use($tenantId,$comboProductId,$components){$p=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=? FOR UPDATE');$p->execute([$comboProductId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Combo não encontrado.');$normalized=[];foreach($components as$id=>$qty){$id=(int)$id;$qty=(float)$qty;if($id>0&&$qty>0)$normalized[$id]=$qty;}
            foreach($normalized as$id=>$qty){if($id===$comboProductId)throw new RuntimeException('Um combo não pode conter a si mesmo.');if($this->wouldCreateCycle($pdo,$tenantId,$comboProductId,$id))throw new RuntimeException('Combinação recusada: criaria um ciclo de combos.');$c=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');$c->execute([$id,$tenantId]);if(!$c->fetchColumn())throw new RuntimeException('Componente inválido.');}
            $pdo->prepare('DELETE FROM product_combo_items WHERE tenant_id=? AND combo_product_id=?')->execute([$tenantId,$comboProductId]);$ins=$pdo->prepare('INSERT INTO product_combo_items (tenant_id,combo_product_id,component_product_id,quantity) VALUES (?,?,?,?)');foreach($normalized as$id=>$qty)$ins->execute([$tenantId,$comboProductId,$id,$qty]);$pdo->prepare('UPDATE products SET product_type=? WHERE id=? AND tenant_id=?')->execute([$normalized?'combo':'single',$comboProductId,$tenantId]);Auth::audit('product.combo_updated','product',(string)$comboProductId,['components'=>$normalized]);});
    }

    public function stockRequirements(PDO $pdo,int $tenantId,int $productId,float $quantity=1,array $trail=[]):array
    {
        if(isset($trail[$productId]))throw new RuntimeException('Ciclo de combo detectado.');$trail[$productId]=true;$p=$pdo->prepare('SELECT product_type FROM products WHERE id=? AND tenant_id=?');$p->execute([$productId,$tenantId]);$type=$p->fetchColumn();if($type===false)throw new RuntimeException('Produto não encontrado.');
        if($type!=='combo')return[$productId=>$quantity];$s=$pdo->prepare('SELECT component_product_id,quantity FROM product_combo_items WHERE tenant_id=? AND combo_product_id=?');$s->execute([$tenantId,$productId]);$rows=$s->fetchAll();if(!$rows)return[$productId=>$quantity];$out=[];foreach($rows as$r){foreach($this->stockRequirements($pdo,$tenantId,(int)$r['component_product_id'],$quantity*(float)$r['quantity'],$trail)as$id=>$qty)$out[$id]=($out[$id]??0)+$qty;}return$out;
    }

    private function wouldCreateCycle(PDO $pdo,int $tenantId,int $combo,int $candidate):bool
    {
        $seen=[];$walk=function(int $id)use(&$walk,&$seen,$pdo,$tenantId,$combo):bool{if($id===$combo)return true;if(isset($seen[$id]))return false;$seen[$id]=true;$s=$pdo->prepare('SELECT component_product_id FROM product_combo_items WHERE tenant_id=? AND combo_product_id=?');$s->execute([$tenantId,$id]);foreach($s->fetchAll(PDO::FETCH_COLUMN)as$n)if($walk((int)$n))return true;return false;};return$walk($candidate);
    }
}
