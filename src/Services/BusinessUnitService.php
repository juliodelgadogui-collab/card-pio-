<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class BusinessUnitService
{
    public function save(int $tenantId,array $data,?int $id=null):int
    {
        Auth::requirePermission('units.manage');$name=trim((string)($data['name']??''));$slug=$this->slug((string)($data['slug']??$name));if($name==='')throw new RuntimeException('Informe o nome da unidade.');
        $status=(string)($data['status']??'active');if(!in_array($status,['active','inactive','archived'],true))$status='active';
        return Database::transaction(function(PDO $pdo)use($tenantId,$data,$id,$name,$slug,$status){
            if($id){$s=$pdo->prepare('UPDATE business_units SET name=?,slug=?,address=?,city=?,phone=?,status=? WHERE id=? AND tenant_id=?');$s->execute([$name,$slug,$this->null($data['address']??null),$this->null($data['city']??null),$this->null($data['phone']??null),$status,$id,$tenantId]);if(!$s->rowCount()){ $c=$pdo->prepare('SELECT id FROM business_units WHERE id=? AND tenant_id=?');$c->execute([$id,$tenantId]);if(!$c->fetchColumn())throw new RuntimeException('Unidade não encontrada.');}Auth::audit('unit.updated','business_unit',(string)$id);return$id;}
            $s=$pdo->prepare('INSERT INTO business_units (tenant_id,name,slug,address,city,phone,status) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$name,$slug,$this->null($data['address']??null),$this->null($data['city']??null),$this->null($data['phone']??null),$status]);$new=(int)$pdo->lastInsertId();Auth::audit('unit.created','business_unit',(string)$new);return$new;
        });
    }
    public function setUserUnits(int $tenantId,int $userId,array $unitIds):void
    {
        Auth::requirePermission('users.manage');Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitIds){$u=$pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=?');$u->execute([$userId,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Usuário não encontrado.');$pdo->prepare('DELETE FROM user_units WHERE tenant_id=? AND user_id=?')->execute([$tenantId,$userId]);$ins=$pdo->prepare('INSERT INTO user_units (tenant_id,user_id,unit_id) SELECT ?,?,id FROM business_units WHERE id=? AND tenant_id=? AND status="active"');foreach(array_unique(array_map('intval',$unitIds))as$id)if($id>0)$ins->execute([$tenantId,$userId,$id,$tenantId]);Auth::audit('user.units_updated','user',(string)$userId,['units'=>$unitIds]);});
    }
    public function setPermission(int $tenantId,int $userId,string $permission,?bool $allowed):void
    {
        Auth::requirePermission('users.manage');$permission=trim($permission);if($permission==='')throw new RuntimeException('Permissão inválida.');$pdo=Database::connection();if($allowed===null){$pdo->prepare('DELETE FROM user_permissions WHERE tenant_id=? AND user_id=? AND permission_key=?')->execute([$tenantId,$userId,$permission]);}else{$pdo->prepare('INSERT INTO user_permissions (tenant_id,user_id,permission_key,allowed) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE allowed=VALUES(allowed),updated_at=CURRENT_TIMESTAMP')->execute([$tenantId,$userId,$permission,$allowed?1:0]);}Auth::audit('user.permission_updated','user',(string)$userId,['permission'=>$permission,'allowed'=>$allowed]);
    }
    private function slug(string $v):string{$v=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v)?:$v;$v=strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/','-',$v)??'','-'));return$v?:bin2hex(random_bytes(3));}
    private function null(mixed $v):?string{$s=trim((string)$v);return$s===''?null:$s;}
}
