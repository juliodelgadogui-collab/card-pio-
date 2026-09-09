<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class HubService
{
    private const COMMAND_PERMISSIONS = [
        'print_order' => ['orders.view','orders.create','orders.manage'],
        'print_receipt' => ['payments.manage','orders.view','orders.manage'],
        'open_drawer' => ['cash.manage'],
        'tef_charge' => ['terminal.request','terminal.collect'],
        'customer_display' => ['orders.view','orders.create','orders.manage'],
        'kitchen_alert' => ['orders.kitchen','orders.dispatch','orders.manage'],
        'play_alert' => ['orders.view','orders.create','orders.manage','orders.kitchen','orders.dispatch'],
        'print_label' => ['production.print','orders.kitchen','orders.manage'],
    ];

    public function createPairingCode(int $unitId,string $desktopDeviceId):array
    {
        Auth::requirePermission('hardware.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->assertUnitAccess($unitId);
        $binding=$this->desktopBinding($tenantId,$unitId,$desktopDeviceId);
        $token=$this->base64url(random_bytes(32));$hash=hash('sha256',$token);$expires=gmdate('Y-m-d H:i:s',time()+300);
        $pdo=Database::connection();
        $pdo->prepare('UPDATE hub_pairing_codes SET status="revoked" WHERE tenant_id=? AND desktop_binding_id=? AND status="pending"')->execute([$tenantId,$binding['id']]);
        $s=$pdo->prepare('INSERT INTO hub_pairing_codes (tenant_id,unit_id,desktop_binding_id,code_hash,status,expires_at) VALUES (?,?,?,? ,"pending",?)');
        $s->execute([$tenantId,$unitId,$binding['id'],$hash,$expires]);$id=(int)$pdo->lastInsertId();
        Auth::audit('hub.pairing_created','hub_pairing_code',(string)$id,['unit_id'=>$unitId,'desktop_binding_id'=>(int)$binding['id']]);
        return ['id'=>$id,'token'=>$token,'qr'=>'EVENTMENU:HUB:'.$token,'expires_at'=>$expires,'desktop'=>['id'=>(int)$binding['id'],'label'=>$binding['device_label']??'EventMenu Desktop']];
    }

    public function claimPairing(string $rawToken,string $mobileDeviceId,string $label=''):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $token=$this->extractPairingToken($rawToken);if(strlen($token)<24)throw new RuntimeException('QR de pareamento inválido.');
        $mobileDeviceId=trim($mobileDeviceId);if(strlen($mobileDeviceId)<8)throw new RuntimeException('Celular não identificado.');
        $deviceHash=hash('sha256',$mobileDeviceId);$hash=hash('sha256',$token);$label=mb_substr(trim($label),0,190);
        return Database::transaction(function(PDO $tx)use($tenantId,$userId,$deviceHash,$hash,$label):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT hp.*,dhb.device_label,dhb.revoked_at,dhb.last_seen_at FROM hub_pairing_codes hp JOIN desktop_hardware_bindings dhb ON dhb.id=hp.desktop_binding_id AND dhb.tenant_id=hp.tenant_id WHERE hp.tenant_id=? AND hp.code_hash=? LIMIT 1 FOR UPDATE'));
            $q->execute([$tenantId,$hash]);$pair=$q->fetch();if(!$pair)throw new RuntimeException('Pareamento não encontrado.');
            if($pair['status']!=='pending')throw new RuntimeException('Este QR já foi utilizado ou revogado.');
            if(strtotime((string)$pair['expires_at'])<time()){$tx->prepare('UPDATE hub_pairing_codes SET status="expired" WHERE id=?')->execute([$pair['id']]);throw new RuntimeException('Este QR expirou. Gere outro no computador.');}
            if(!empty($pair['revoked_at']))throw new RuntimeException('O computador foi revogado.');
            $lastSeen=$pair['last_seen_at']?strtotime((string)$pair['last_seen_at']):0;if($lastSeen<(time()-90))throw new RuntimeException('O computador não está online no Hub.');
            $this->assertUnitAccess((int)$pair['unit_id']);
            $existing=$tx->prepare('SELECT id FROM hub_device_links WHERE tenant_id=? AND desktop_binding_id=? AND mobile_device_hash=? LIMIT 1');$existing->execute([$tenantId,$pair['desktop_binding_id'],$deviceHash]);$linkId=(int)$existing->fetchColumn();
            if($linkId>0){$tx->prepare('UPDATE hub_device_links SET unit_id=?,mobile_user_id=?,label=?,status="active",revoked_at=NULL,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$pair['unit_id'],$userId,$label?:null,$linkId]);}
            else{$s=$tx->prepare('INSERT INTO hub_device_links (tenant_id,unit_id,desktop_binding_id,mobile_user_id,mobile_device_hash,label,status,last_seen_at) VALUES (?,?,?,?,?,?,"active",CURRENT_TIMESTAMP)');$s->execute([$tenantId,$pair['unit_id'],$pair['desktop_binding_id'],$userId,$deviceHash,$label?:null]);$linkId=(int)$tx->lastInsertId();}
            $tx->prepare('UPDATE hub_pairing_codes SET status="claimed",claimed_by_user_id=?,claimed_device_hash=?,claimed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$userId,$deviceHash,$pair['id']]);
            Auth::audit('hub.pairing_claimed','hub_device_link',(string)$linkId,['unit_id'=>(int)$pair['unit_id'],'desktop_binding_id'=>(int)$pair['desktop_binding_id']]);
            return ['id'=>$linkId,'unit_id'=>(int)$pair['unit_id'],'desktop_binding_id'=>(int)$pair['desktop_binding_id'],'desktop_label'=>$pair['device_label']??'EventMenu Desktop','status'=>'active'];
        });
    }

    public function mobileLinks(string $mobileDeviceId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return[];$hash=hash('sha256',trim($mobileDeviceId));$pdo=Database::connection();
        $pdo->prepare('UPDATE hub_device_links SET last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND mobile_user_id=? AND mobile_device_hash=? AND status="active"')->execute([$tenantId,$userId,$hash]);
        $s=$pdo->prepare('SELECT hdl.id,hdl.unit_id,hdl.desktop_binding_id,hdl.label,hdl.status,hdl.last_seen_at,ou.name unit_name,dhb.device_label desktop_label,dhb.hardware_json,dhb.last_seen_at desktop_last_seen_at,dhb.revoked_at desktop_revoked_at FROM hub_device_links hdl JOIN desktop_hardware_bindings dhb ON dhb.id=hdl.desktop_binding_id AND dhb.tenant_id=hdl.tenant_id LEFT JOIN operating_units ou ON ou.id=hdl.unit_id AND ou.tenant_id=hdl.tenant_id WHERE hdl.tenant_id=? AND hdl.mobile_user_id=? AND hdl.mobile_device_hash=? AND hdl.status="active" ORDER BY ou.name,dhb.device_label');
        $s->execute([$tenantId,$userId,$hash]);$rows=$s->fetchAll();foreach($rows as &$row){$hardware=json_decode((string)($row['hardware_json']??''),true);$row['hardware']=is_array($hardware)?$hardware:[];unset($row['hardware_json']);$last=$row['desktop_last_seen_at']?strtotime((string)$row['desktop_last_seen_at']):0;$row['desktop_online']=empty($row['desktop_revoked_at'])&&$last>=(time()-90);}unset($row);return$rows;
    }

    public function queueCommand(int $targetBindingId,string $commandType,array $payload,string $idempotencyKey,string $mobileDeviceId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        if(!isset(self::COMMAND_PERMISSIONS[$commandType]))throw new RuntimeException('Comando do Hub inválido.');
        $this->requireAnyPermission(self::COMMAND_PERMISSIONS[$commandType]);
        $idempotencyKey=trim($idempotencyKey);if(strlen($idempotencyKey)<12)throw new RuntimeException('Chave de idempotência inválida.');
        $deviceHash=hash('sha256',trim($mobileDeviceId));$pdo=Database::connection();
        $link=$pdo->prepare('SELECT hdl.*,dhb.revoked_at,dhb.last_seen_at desktop_last_seen_at FROM hub_device_links hdl JOIN desktop_hardware_bindings dhb ON dhb.id=hdl.desktop_binding_id AND dhb.tenant_id=hdl.tenant_id WHERE hdl.tenant_id=? AND hdl.desktop_binding_id=? AND hdl.mobile_user_id=? AND hdl.mobile_device_hash=? AND hdl.status="active" LIMIT 1');
        $link->execute([$tenantId,$targetBindingId,$userId,$deviceHash]);$bound=$link->fetch();if(!$bound)throw new RuntimeException('Este celular não está vinculado ao computador selecionado.');if(!empty($bound['revoked_at']))throw new RuntimeException('O computador foi revogado.');$lastSeen=$bound['desktop_last_seen_at']?strtotime((string)$bound['desktop_last_seen_at']):0;if($lastSeen<(time()-90))throw new RuntimeException('O computador EventMenu está offline.');$this->assertUnitAccess((int)$bound['unit_id']);
        $this->validatePayload($commandType,$payload,(int)$bound['unit_id']);
        $json=json_encode($this->sanitizePayload($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$expires=gmdate('Y-m-d H:i:s',time()+120);
        $existing=$pdo->prepare('SELECT * FROM hub_commands WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotencyKey]);if($row=$existing->fetch())return$this->publicCommand($row);
        $s=$pdo->prepare('INSERT INTO hub_commands (tenant_id,unit_id,target_binding_id,requested_by_user_id,source_device_hash,command_type,payload_json,idempotency_key,status,expires_at) VALUES (?,?,?,?,?,?,?,?,"queued",?)');
        $s->execute([$tenantId,$bound['unit_id'],$targetBindingId,$userId,$deviceHash,$commandType,$json,$idempotencyKey,$expires]);$id=(int)$pdo->lastInsertId();
        Auth::audit('hub.command_queued','hub_command',(string)$id,['unit_id'=>(int)$bound['unit_id'],'target_binding_id'=>$targetBindingId,'command_type'=>$commandType]);
        return$this->commandById($id);
    }

    public function pollDesktop(string $desktopDeviceId,int $limit=20):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$hash=hash('sha256',trim($desktopDeviceId));$pdo=Database::connection();
        $b=$pdo->prepare('SELECT id,unit_id,revoked_at FROM desktop_hardware_bindings WHERE tenant_id=? AND device_hash=? LIMIT 1');$b->execute([$tenantId,$hash]);$binding=$b->fetch();if(!$binding)throw new RuntimeException('Computador não registrado no Hub.');if(!empty($binding['revoked_at']))throw new RuntimeException('Computador revogado.');
        $pdo->prepare('UPDATE hub_commands SET status="expired",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND target_binding_id=? AND status IN ("queued","claimed") AND expires_at<CURRENT_TIMESTAMP')->execute([$tenantId,$binding['id']]);
        $limit=max(1,min(50,$limit));$s=$pdo->prepare('SELECT * FROM hub_commands WHERE tenant_id=? AND target_binding_id=? AND status="queued" AND expires_at>=CURRENT_TIMESTAMP ORDER BY id LIMIT '.$limit);$s->execute([$tenantId,$binding['id']]);$rows=$s->fetchAll();foreach($rows as &$row)$row=$this->publicCommand($row);unset($row);return$rows;
    }

    public function claimCommand(int $commandId,string $desktopDeviceId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$binding=$this->bindingForDevice($tenantId,$desktopDeviceId);
        return Database::transaction(function(PDO $tx)use($tenantId,$binding,$commandId):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM hub_commands WHERE id=? AND tenant_id=? AND target_binding_id=? LIMIT 1 FOR UPDATE'));$q->execute([$commandId,$tenantId,$binding['id']]);$cmd=$q->fetch();if(!$cmd)throw new RuntimeException('Comando não encontrado.');
            if(strtotime((string)$cmd['expires_at'])<time()){$tx->prepare('UPDATE hub_commands SET status="expired",updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$commandId]);throw new RuntimeException('Comando expirado.');}
            if($cmd['status']==='queued')$tx->prepare('UPDATE hub_commands SET status="claimed",claimed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$commandId]);elseif($cmd['status']!=='claimed')return$this->publicCommand($cmd);
            $q=$tx->prepare('SELECT * FROM hub_commands WHERE id=?');$q->execute([$commandId]);return$this->publicCommand($q->fetch()?:$cmd);
        });
    }

    public function completeCommand(int $commandId,string $desktopDeviceId,bool $success,array $result=[],string $error=''):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$binding=$this->bindingForDevice($tenantId,$desktopDeviceId);$json=$result?json_encode($this->sanitizePayload($result),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;$error=mb_substr(trim($error),0,1000);
        $s=Database::connection()->prepare('UPDATE hub_commands SET status=?,result_json=?,error_message=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND target_binding_id=? AND status IN ("queued","claimed")');$s->execute([$success?'completed':'failed',$json,$success?null:($error?:'Falha no equipamento local.'),$commandId,$tenantId,$binding['id']]);if($s->rowCount()===0){$q=Database::connection()->prepare('SELECT id FROM hub_commands WHERE id=? AND tenant_id=? AND target_binding_id=?');$q->execute([$commandId,$tenantId,$binding['id']]);if(!$q->fetchColumn())throw new RuntimeException('Comando não encontrado.');}
        Auth::audit($success?'hub.command_completed':'hub.command_failed','hub_command',(string)$commandId,['target_binding_id'=>(int)$binding['id']]);return$this->commandById($commandId);
    }

    public function commandStatus(int $commandId,string $mobileDeviceId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$hash=hash('sha256',trim($mobileDeviceId));$s=Database::connection()->prepare('SELECT * FROM hub_commands WHERE id=? AND tenant_id=? AND requested_by_user_id=? AND source_device_hash=? LIMIT 1');$s->execute([$commandId,$tenantId,$userId,$hash]);$row=$s->fetch();if(!$row)throw new RuntimeException('Comando não encontrado.');return$this->publicCommand($row);
    }

    public function revokeLink(int $linkId):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$pdo=Database::connection();$s=$pdo->prepare('UPDATE hub_device_links SET status="revoked",revoked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND (mobile_user_id=? OR ?=1) AND status="active"');$admin=Auth::can('hardware.manage')?1:0;$s->execute([$linkId,$tenantId,$userId,$admin]);if($s->rowCount()!==1)throw new RuntimeException('Vínculo não encontrado ou já revogado.');Auth::audit('hub.link_revoked','hub_device_link',(string)$linkId);
    }

    private function desktopBinding(int $tenantId,int $unitId,string $deviceId):array{$row=$this->bindingForDevice($tenantId,$deviceId);if((int)$row['unit_id']!==$unitId)throw new RuntimeException('Computador pertence a outra unidade.');return$row;}
    private function bindingForDevice(int $tenantId,string $deviceId):array{$deviceId=trim($deviceId);if(strlen($deviceId)<8)throw new RuntimeException('Computador não identificado.');$s=Database::connection()->prepare('SELECT id,unit_id,device_label,revoked_at FROM desktop_hardware_bindings WHERE tenant_id=? AND device_hash=? LIMIT 1');$s->execute([$tenantId,hash('sha256',$deviceId)]);$row=$s->fetch();if(!$row)throw new RuntimeException('Computador não registrado no Hub.');if(!empty($row['revoked_at']))throw new RuntimeException('Computador revogado.');return$row;}
    private function assertUnitAccess(int $unitId):void{foreach((new OperatingUnitService())->availableForCurrentUser() as $unit)if((int)$unit['id']===$unitId)return;throw new RuntimeException('Você não possui acesso a esta unidade.');}
    private function requireAnyPermission(array $permissions):void{foreach($permissions as $permission)if(Auth::can($permission))return;throw new RuntimeException('Você não possui permissão para esta função do Hub.');}

    private function validatePayload(string $type,array $payload,int $unitId):void
    {
        if(in_array($type,['print_order','print_receipt','tef_charge','customer_display'],true)){
            $orderId=(int)($payload['order_id']??0);if($orderId<1)throw new RuntimeException('Informe o pedido.');$s=Database::connection()->prepare('SELECT id,unit_id,status,payment_status,total_cents FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$orderId,Auth::tenantId()]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if((int)($order['unit_id']??0)!==$unitId)throw new RuntimeException('Pedido e computador pertencem a unidades diferentes.');
            if($type==='tef_charge'){
                if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita nova cobrança.');
                $amount=(int)($payload['amount_cents']??0);if($amount<=0)throw new RuntimeException('Informe o valor da cobrança.');$remaining=(new PaymentService())->remaining($orderId,Auth::tenantId());if($amount>(int)$remaining['remaining_cents'])throw new RuntimeException('Valor maior que o saldo restante do pedido.');
                $paymentType=(string)($payload['payment_type']??'');if(!in_array($paymentType,['debit','credit','pix','voucher'],true))throw new RuntimeException('Tipo de pagamento inválido.');
                $terminalId=(int)($payload['terminal_config_id']??0);if($terminalId<1)throw new RuntimeException('Escolha o PINPad/TEF.');$t=Database::connection()->prepare('SELECT id FROM payment_terminal_configs WHERE id=? AND tenant_id=? AND unit_id=? AND enabled=1 LIMIT 1');$t->execute([$terminalId,Auth::tenantId(),$unitId]);if(!$t->fetchColumn())throw new RuntimeException('O PINPad/TEF não está ativo nesta unidade.');
            }
        }
    }

    private function sanitizePayload(array $payload):array
    {
        $blocked=['token','password','secret','client_secret','api_key','pfx_base64','certificate'];$out=[];foreach($payload as $key=>$value){if(in_array(strtolower((string)$key),$blocked,true))continue;if(is_scalar($value)||$value===null)$out[$key]=is_string($value)?mb_substr($value,0,2000):$value;elseif(is_array($value))$out[$key]=array_slice($this->sanitizePayload($value),0,100,true);}return$out;
    }
    private function publicCommand(array $row):array{$row['payload']=json_decode((string)($row['payload_json']??''),true)?:[];$row['result']=json_decode((string)($row['result_json']??''),true)?:[];unset($row['payload_json'],$row['result_json'],$row['source_device_hash']);return$row;}
    private function commandById(int $id):array{$s=Database::connection()->prepare('SELECT * FROM hub_commands WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$id,Auth::tenantId()]);$row=$s->fetch();if(!$row)throw new RuntimeException('Comando não encontrado.');return$this->publicCommand($row);}
    private function extractPairingToken(string $raw):string{$raw=trim($raw);if(str_starts_with(strtoupper($raw),'EVENTMENU:HUB:'))return trim(substr($raw,14));return$raw;}
    private function base64url(string $raw):string{return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
}
