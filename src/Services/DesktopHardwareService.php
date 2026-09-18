<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class DesktopHardwareService
{
    public function terminalConfigs(int $unitId):array
    {
        if(!Auth::can('terminal.collect')&&!Auth::can('hardware.manage'))throw new RuntimeException('Acesso negado ao terminal de pagamento.');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$unitId<1)throw new RuntimeException('Unidade inválida.');
        $this->assertUnitAccess($unitId);
        $s=Database::connection()->prepare('SELECT id,unit_id,provider,enabled,integration_mode,terminal_label,pinpad_identifier,auto_capture,config_encrypted,created_at,updated_at FROM payment_terminal_configs WHERE tenant_id=? AND unit_id=? ORDER BY enabled DESC,id');
        $s->execute([$tenantId,$unitId]);
        $out=[];
        foreach($s->fetchAll() as $row){
            $config=[];
            if(!empty($row['config_encrypted'])){
                try{$config=Crypto::decryptJson((string)$row['config_encrypted']);}catch(\Throwable){$config=[];}
            }
            unset($row['config_encrypted']);
            // O Desktop recebe somente parâmetros de execução. Segredos de adquirente continuam no servidor.
            foreach(['secret','password','token','client_secret','api_key'] as $secretKey)unset($config[$secretKey]);
            $row['runtime_config']=$config;
            $out[]=$row;
        }
        return $out;
    }

    public function saveTerminal(array $data):array
    {
        Auth::requirePermission('hardware.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $id=(int)($data['id']??0);$unitId=(int)($data['unit_id']??0);
        if($unitId<1)throw new RuntimeException('Escolha a unidade do terminal.');
        $this->assertUnitAccess($unitId);
        $u=Database::connection()->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');$u->execute([$unitId,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Unidade inválida ou inativa.');
        $provider=(string)($data['provider']??'generic_tef');
        if(!in_array($provider,['pagbank_tef','stone_tef','sitef','generic_tef'],true))throw new RuntimeException('Provedor TEF inválido.');
        $mode=(string)($data['integration_mode']??'local_service');
        if(!in_array($mode,['dll','local_service','tcp','serial'],true))throw new RuntimeException('Modo de integração TEF inválido.');
        $enabled=!empty($data['enabled'])?1:0;
        $autoCapture=array_key_exists('auto_capture',$data)?(!empty($data['auto_capture'])?1:0):1;
        $label=mb_substr(trim((string)($data['terminal_label']??'')),0,160);
        $pinpad=mb_substr(trim((string)($data['pinpad_identifier']??'')),0,190);
        $config=is_array($data['config']??null)?$data['config']:[];
        $encrypted=$config?Crypto::encrypt($config):null;
        $pdo=Database::connection();
        if($id>0){
            $existing=$pdo->prepare('SELECT unit_id FROM payment_terminal_configs WHERE id=? AND tenant_id=? LIMIT 1');$existing->execute([$id,$tenantId]);$oldUnit=(int)$existing->fetchColumn();if($oldUnit<1)throw new RuntimeException('Terminal TEF não encontrado.');$this->assertUnitAccess($oldUnit);
            $s=$pdo->prepare('UPDATE payment_terminal_configs SET unit_id=?,provider=?,enabled=?,integration_mode=?,terminal_label=?,pinpad_identifier=?,config_encrypted=?,auto_capture=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');
            $s->execute([$unitId,$provider,$enabled,$mode,$label?:null,$pinpad?:null,$encrypted,$autoCapture,$id,$tenantId]);
        }else{
            $s=$pdo->prepare('INSERT INTO payment_terminal_configs (tenant_id,unit_id,provider,enabled,integration_mode,terminal_label,pinpad_identifier,config_encrypted,auto_capture) VALUES (?,?,?,?,?,?,?,?,?)');
            $s->execute([$tenantId,$unitId,$provider,$enabled,$mode,$label?:null,$pinpad?:null,$encrypted,$autoCapture]);
            $id=(int)$pdo->lastInsertId();
        }
        Auth::audit('desktop.terminal_saved','payment_terminal_config',(string)$id,['unit_id'=>$unitId,'provider'=>$provider,'enabled'=>(bool)$enabled]);
        foreach($this->terminalConfigs($unitId) as $row)if((int)$row['id']===$id)return $row;
        throw new RuntimeException('Falha ao carregar configuração do terminal.');
    }

    public function heartbeat(int $unitId,string $deviceId,string $deviceLabel,array $hardware):array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId||$unitId<1)throw new RuntimeException('Unidade inválida.');
        $this->assertUnitAccess($unitId);
        $u=Database::connection()->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');$u->execute([$unitId,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Unidade inválida ou inativa.');
        $deviceId=trim($deviceId);if(strlen($deviceId)<8)throw new RuntimeException('Identificação do computador inválida.');
        $hash=hash('sha256',$deviceId);$label=mb_substr(trim($deviceLabel),0,190);
        $safe=$this->sanitizeHardware($hardware);$json=json_encode($safe,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT id,unit_id,revoked_at FROM desktop_hardware_bindings WHERE tenant_id=? AND device_hash=? LIMIT 1');$q->execute([$tenantId,$hash]);$existing=$q->fetch();
        if($existing&&!empty($existing['revoked_at']))throw new RuntimeException('Este computador foi revogado pelo administrador.');
        if($existing){$oldUnit=(int)($existing['unit_id']??0);if($oldUnit>0)$this->assertUnitAccess($oldUnit);$id=(int)$existing['id'];$pdo->prepare('UPDATE desktop_hardware_bindings SET unit_id=?,device_label=?,hardware_json=?,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$unitId,$label?:null,$json,$id,$tenantId]);}
        else{$s=$pdo->prepare('INSERT INTO desktop_hardware_bindings (tenant_id,unit_id,device_hash,device_label,hardware_json,last_seen_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP)');$s->execute([$tenantId,$unitId,$hash,$label?:null,$json]);$id=(int)$pdo->lastInsertId();Auth::audit('desktop.device_registered','desktop_hardware_binding',(string)$id,['unit_id'=>$unitId,'device_label'=>$label]);}
        return ['id'=>$id,'unit_id'=>$unitId,'device_label'=>$label,'hardware'=>$safe,'revoked'=>false];
    }

    public function listBindings():array
    {
        Auth::requirePermission('hardware.manage');$tenantId=Auth::tenantId();if(!$tenantId)return[];$unitIds=array_map(static fn(array$unit):int=>(int)$unit['id'],(new OperatingUnitService())->availableForCurrentUser());if(!$unitIds)return[];$marks=implode(',',array_fill(0,count($unitIds),'?'));
        $s=Database::connection()->prepare('SELECT dhb.id,dhb.unit_id,dhb.device_label,dhb.hardware_json,dhb.last_seen_at,dhb.revoked_at,dhb.created_at,ou.name unit_name FROM desktop_hardware_bindings dhb LEFT JOIN operating_units ou ON ou.id=dhb.unit_id AND ou.tenant_id=dhb.tenant_id WHERE dhb.tenant_id=? AND dhb.unit_id IN ('.$marks.') ORDER BY dhb.revoked_at IS NULL DESC,dhb.last_seen_at DESC,dhb.id DESC');$s->execute(array_merge([$tenantId],$unitIds));$rows=$s->fetchAll();foreach($rows as &$row){$decoded=json_decode((string)($row['hardware_json']??''),true);$row['hardware']=is_array($decoded)?$decoded:[];unset($row['hardware_json']);}unset($row);return$rows;
    }

    public function revokeBinding(int $id):void
    {
        Auth::requirePermission('hardware.manage');$tenantId=Auth::tenantId();if(!$tenantId||$id<1)throw new RuntimeException('Dispositivo inválido.');$q=Database::connection()->prepare('SELECT unit_id FROM desktop_hardware_bindings WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$id,$tenantId]);$unitId=(int)$q->fetchColumn();if($unitId<1)throw new RuntimeException('Dispositivo não encontrado.');$this->assertUnitAccess($unitId);$s=Database::connection()->prepare('UPDATE desktop_hardware_bindings SET revoked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND revoked_at IS NULL');$s->execute([$id,$tenantId]);if($s->rowCount()!==1)throw new RuntimeException('Dispositivo não encontrado ou já revogado.');Auth::audit('desktop.device_revoked','desktop_hardware_binding',(string)$id);
    }

    private function assertUnitAccess(int $unitId):void
    {
        foreach((new OperatingUnitService())->availableForCurrentUser()as$unit)if((int)$unit['id']===$unitId)return;throw new RuntimeException('Você não possui acesso a esta unidade.');
    }

    private function sanitizeHardware(array $hardware):array
    {
        $out=[];
        foreach(['computer_name','windows_version','app_version','default_printer','cash_drawer','scale','barcode_scanner','customer_display','tef_provider','pinpad'] as $key){if(array_key_exists($key,$hardware))$out[$key]=is_scalar($hardware[$key])?mb_substr((string)$hardware[$key],0,500):null;}
        if(isset($hardware['printers'])&&is_array($hardware['printers']))$out['printers']=array_slice(array_values(array_filter(array_map(fn($v)=>is_scalar($v)?mb_substr((string)$v,0,190):null,$hardware['printers']))),0,30);
        return$out;
    }
}
