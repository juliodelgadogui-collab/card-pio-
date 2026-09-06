<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class OperatingUnitService
{
    private function sessionKey(int$tenantId):string{return'eventmenu_unit_'.$tenantId;}

    public function availableForCurrentUser():array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return[];$pdo=Database::connection();$this->ensurePrimaryUnit($pdo,$tenantId);$count=$pdo->prepare('SELECT COUNT(*) FROM user_unit_access WHERE tenant_id=? AND user_id=?');$count->execute([$tenantId,$userId]);$restricted=(int)$count->fetchColumn()>0;
        if($restricted){$s=$pdo->prepare('SELECT ou.id,ou.code,ou.name,ou.address,ou.active,uua.is_default FROM operating_units ou JOIN user_unit_access uua ON uua.unit_id=ou.id AND uua.tenant_id=ou.tenant_id WHERE ou.tenant_id=? AND uua.user_id=? AND ou.active=1 ORDER BY uua.is_default DESC,ou.name');$s->execute([$tenantId,$userId]);}
        else{$s=$pdo->prepare('SELECT id,code,name,address,active,0 is_default FROM operating_units WHERE tenant_id=? AND active=1 ORDER BY name');$s->execute([$tenantId]);}
        return$s->fetchAll();
    }

    public function current():?array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)return null;$units=$this->availableForCurrentUser();if(!$units)return null;
        try{$shift=(new WorkShiftService())->current();if($shift&&$shift['unit_id']!==null){foreach($units as$unit)if((int)$unit['id']===(int)$shift['unit_id'])return$unit;}}catch(\Throwable){}
        try{$s=Database::connection()->prepare('SELECT unit_id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$s->execute([$tenantId,Auth::id()]);$cashUnit=(int)$s->fetchColumn();if($cashUnit>0){foreach($units as$unit)if((int)$unit['id']===$cashUnit)return$unit;}}catch(\Throwable){}
        $selected=(int)($_SESSION[$this->sessionKey($tenantId)]??0);if($selected>0){foreach($units as$unit)if((int)$unit['id']===$selected)return$unit;unset($_SESSION[$this->sessionKey($tenantId)]);}
        foreach($units as$unit)if((int)($unit['is_default']??0)===1)return$unit;if(count($units)===1)return$units[0];return null;
    }

    public function currentId():?int{$unit=$this->current();return$unit?(int)$unit['id']:null;}
    public function requireCurrent():array{$unit=$this->current();if($unit)return$unit;$units=$this->availableForCurrentUser();if(!$units)throw new RuntimeException('Cadastre uma unidade operacional antes de continuar.');throw new RuntimeException('Escolha a unidade de operação no topo do painel antes de continuar.');}

    public function selectCurrent(int$unitId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$shift=(new WorkShiftService())->current();if($shift&&$shift['unit_id']!==null&&(int)$shift['unit_id']!==$unitId)throw new RuntimeException('Existe um turno aberto em outra unidade. Encerre o turno antes de trocar.');$cash=Database::connection()->prepare('SELECT unit_id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$cash->execute([$tenantId,Auth::id()]);$cashUnit=(int)$cash->fetchColumn();if($cashUnit>0&&$cashUnit!==$unitId)throw new RuntimeException('Seu caixa está aberto em outra unidade. Feche o caixa antes de trocar.');foreach($this->availableForCurrentUser()as$unit){if((int)$unit['id']===$unitId){$_SESSION[$this->sessionKey($tenantId)]=$unitId;Auth::audit('unit.context_selected','operating_unit',(string)$unitId);return$unit;}}throw new RuntimeException('Você não possui acesso a esta unidade.');
    }

    public function resolveForShift(?int$unitId):?array{$units=$this->availableForCurrentUser();if(!$units)return null;if($unitId===null||$unitId<1){$current=$this->current();if($current)return$current;foreach($units as$unit)if((int)($unit['is_default']??0)===1)return$unit;if(count($units)===1)return$units[0];throw new RuntimeException('Escolha a unidade antes de iniciar o turno.');}foreach($units as$unit)if((int)$unit['id']===$unitId)return$unit;throw new RuntimeException('Você não possui acesso a esta unidade.');}

    public function listAll():array{Auth::requirePermission('settings.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->ensurePrimaryUnit(Database::connection(),$tenantId);$s=Database::connection()->prepare('SELECT * FROM operating_units WHERE tenant_id=? ORDER BY active DESC,name');$s->execute([$tenantId]);return$s->fetchAll();}

    public function save(?int$id,string$name,string$code='',string$address='',bool$active=true):array
    {
        Auth::requirePermission('settings.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$name=mb_substr(trim($name),0,160);if($name==='')throw new RuntimeException('Informe o nome da unidade.');$code=$this->normalizeCode($code!==''?$code:$name);$address=mb_substr(trim($address),0,500);$pdo=Database::connection();if(!$id)(new PlanLimitService())->assertCanCreate('units',$tenantId);
        if($id&&$id>0){if(!$active)$this->assertCanDeactivate($pdo,$tenantId,$id);$s=$pdo->prepare('UPDATE operating_units SET name=?,code=?,address=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');try{$s->execute([$name,$code,$address?:null,$active?1:0,$id,$tenantId]);}catch(\Throwable){throw new RuntimeException('Já existe uma unidade com este código.');}if($s->rowCount()===0){$q=$pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=?');$q->execute([$id,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Unidade não encontrada.');}$unitId=$id;}
        else{try{$s=$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,address,active) VALUES (?,?,?,?,?)');$s->execute([$tenantId,$code,$name,$address?:null,$active?1:0]);}catch(\Throwable){throw new RuntimeException('Já existe uma unidade com este código.');}$unitId=(int)$pdo->lastInsertId();}
        Auth::audit('unit.saved','operating_unit',(string)$unitId,['name'=>$name,'code'=>$code,'active'=>$active]);$q=$pdo->prepare('SELECT * FROM operating_units WHERE id=? AND tenant_id=?');$q->execute([$unitId,$tenantId]);return$q->fetch()?:throw new RuntimeException('Falha ao salvar unidade.');
    }

    public function setUserAccess(int$userId,array$unitIds,?int$defaultUnitId=null):void
    {
        Auth::requirePermission('users.manage');$tenantId=Auth::tenantId();if(!$tenantId||$userId<1)throw new RuntimeException('Usuário inválido.');$ids=array_values(array_unique(array_filter(array_map('intval',$unitIds),fn($id)=>$id>0)));Database::transaction(function($pdo)use($tenantId,$userId,$ids,$defaultUnitId):void{$u=$pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=?');$u->execute([$userId,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Funcionário não encontrado.');if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$q=$pdo->prepare('SELECT id FROM operating_units WHERE tenant_id=? AND active=1 AND id IN ('.$marks.')');$q->execute(array_merge([$tenantId],$ids));$valid=array_map('intval',array_column($q->fetchAll(),'id'));sort($valid);$expected=$ids;sort($expected);if($valid!==$expected)throw new RuntimeException('Uma unidade selecionada é inválida ou está inativa.');if($defaultUnitId&& !in_array($defaultUnitId,$ids,true))throw new RuntimeException('A unidade padrão deve estar entre as unidades autorizadas.');}$pdo->prepare('DELETE FROM user_unit_access WHERE tenant_id=? AND user_id=?')->execute([$tenantId,$userId]);$i=$pdo->prepare('INSERT INTO user_unit_access (tenant_id,user_id,unit_id,is_default) VALUES (?,?,?,?)');foreach($ids as$unitId)$i->execute([$tenantId,$userId,$unitId,$defaultUnitId===$unitId?1:0]);});Auth::audit('user.units_updated','user',(string)$userId,['unit_ids'=>$ids,'default_unit_id'=>$defaultUnitId]);
    }

    private function ensurePrimaryUnit($pdo,int$tenantId):void{$s=$pdo->prepare('SELECT COUNT(*) FROM operating_units WHERE tenant_id=?');$s->execute([$tenantId]);if((int)$s->fetchColumn()>0)return;try{$pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,address,active) VALUES (?,"principal","Principal",NULL,1)')->execute([$tenantId]);}catch(\Throwable){}}
    private function assertCanDeactivate($pdo,int$tenantId,int$unitId):void{$checks=[['SELECT COUNT(*) FROM orders WHERE tenant_id=? AND unit_id=? AND status NOT IN ("completed","cancelled")','pedidos ativos'],['SELECT COUNT(*) FROM cash_sessions WHERE tenant_id=? AND unit_id=? AND status="open"','caixas abertos'],['SELECT COUNT(*) FROM work_shifts WHERE tenant_id=? AND unit_id=? AND status="open"','turnos abertos'],['SELECT COUNT(*) FROM restaurant_tables WHERE tenant_id=? AND unit_id=? AND status="occupied"','mesas ocupadas']];foreach($checks as[$sql,$label]){$s=$pdo->prepare($sql);$s->execute([$tenantId,$unitId]);if((int)$s->fetchColumn()>0)throw new RuntimeException('Não é possível desativar a unidade enquanto houver '.$label.'.');}}
    private function normalizeCode(string$value):string{$value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;$value=strtolower($value);$value=preg_replace('/[^a-z0-9]+/','-',$value)??'';$value=trim($value,'-');if($value==='')$value='unidade';return substr($value,0,60);}
}
