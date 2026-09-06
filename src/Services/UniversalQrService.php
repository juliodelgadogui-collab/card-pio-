<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use PDO;
use RuntimeException;

final class UniversalQrService
{
    private const TYPES=['employee','delivery_user','customer','event','device'];

    public function issue(string $type,int $entityId,string $label='',?int $ttlHours=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $type=strtolower(trim($type));if(!in_array($type,self::TYPES,true)||$entityId<1)throw new RuntimeException('Tipo de QR inválido.');
        $this->assertCanIssue($type,$entityId,$tenantId,$userId);
        $label=mb_substr(trim($label),0,160);$expiresAt=$ttlHours&&$ttlHours>0?(new \DateTimeImmutable('+'.min($ttlHours,8760).' hours'))->format('Y-m-d H:i:s'):null;
        $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);
        Database::transaction(function(PDO $pdo)use($tenantId,$userId,$type,$entityId,$label,$expiresAt,$hash):void{
            $pdo->prepare('UPDATE entity_qr_tokens SET status="revoked" WHERE tenant_id=? AND entity_type=? AND entity_id=? AND status="active"')->execute([$tenantId,$type,$entityId]);
            $pdo->prepare('INSERT INTO entity_qr_tokens (tenant_id,entity_type,entity_id,token_hash,label,status,expires_at,created_by) VALUES (?,?,?,?,?,"active",?,?)')->execute([$tenantId,$type,$entityId,$hash,$label?:null,$expiresAt,$userId]);
        });
        Auth::audit('qr.issued','entity_qr',$type.':'.$entityId,['type'=>$type,'expires_at'=>$expiresAt]);
        return ['type'=>$type,'entity_id'=>$entityId,'label'=>$label,'payload'=>'EVENTMENU:QR:'.$raw,'expires_at'=>$expiresAt];
    }

    public function revoke(string $type,int $entityId):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$type=strtolower(trim($type));
        if(!in_array($type,self::TYPES,true)||$entityId<1)throw new RuntimeException('QR inválido.');$this->assertCanIssue($type,$entityId,$tenantId,$userId);
        Database::connection()->prepare('UPDATE entity_qr_tokens SET status="revoked" WHERE tenant_id=? AND entity_type=? AND entity_id=? AND status="active"')->execute([$tenantId,$type,$entityId]);
        Auth::audit('qr.revoked','entity_qr',$type.':'.$entityId,['type'=>$type]);
    }

    public function resolve(string $value):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$raw=$this->extract($value);if($raw==='')throw new RuntimeException('QR EventMenu inválido.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT * FROM entity_qr_tokens WHERE tenant_id=? AND token_hash=? AND status="active" LIMIT 1');$s->execute([$tenantId,hash('sha256',$raw)]);$token=$s->fetch();
        if(!$token)throw new RuntimeException('QR revogado, expirado ou não pertence a esta empresa.');if($token['expires_at']&&strtotime((string)$token['expires_at'])<time()){ $pdo->prepare('UPDATE entity_qr_tokens SET status="revoked" WHERE id=?')->execute([$token['id']]);throw new RuntimeException('Este QR expirou.');}
        $type=(string)$token['entity_type'];$entityId=(int)$token['entity_id'];$data=$this->entityData($pdo,$tenantId,$type,$entityId);
        $pdo->prepare('UPDATE entity_qr_tokens SET last_used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$token['id']]);
        return ['type'=>$type,'entity_id'=>$entityId,'label'=>(string)($token['label']??''),'data'=>$data];
    }

    private function assertCanIssue(string $type,int $entityId,int $tenantId,int $userId):void
    {
        $pdo=Database::connection();
        if(in_array($type,['employee','delivery_user'],true)){
            if($entityId!==$userId&&!Auth::can('users.manage')&&!Auth::can('delivery.assign'))throw new RuntimeException('Você não pode gerar QR de outro funcionário.');
            $s=$pdo->prepare('SELECT id,role,status FROM users WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);$user=$s->fetch();if(!$user||$user['status']!=='active')throw new RuntimeException('Funcionário inválido.');
            if($type==='delivery_user'){$permissions=PermissionCatalog::effectiveForUser($tenantId,$entityId,(string)$user['role']);if(!in_array('orders.delivery',$permissions,true))throw new RuntimeException('Este funcionário não possui permissão de Delivery.');}
            return;
        }
        if($type==='customer'){
            if(!Auth::can('customers.manage'))throw new RuntimeException('Acesso negado ao QR de cliente.');$s=$pdo->prepare('SELECT id FROM customers WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Cliente não encontrado.');return;
        }
        if($type==='event'){
            if(!Auth::can('events.manage')&&!Auth::can('tickets.manage')&&!Auth::can('guests.manage'))throw new RuntimeException('Acesso negado ao QR de evento.');$s=$pdo->prepare('SELECT id FROM events WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Evento não encontrado.');return;
        }
        if($type==='device'){
            Auth::requirePermission('nfc.manage');$s=$pdo->prepare('SELECT id FROM nfc_devices WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Dispositivo não encontrado.');return;
        }
    }

    private function entityData(PDO $pdo,int $tenantId,string $type,int $entityId):array
    {
        if(in_array($type,['employee','delivery_user'],true)){
            if(!Auth::can('delivery.assign')&&!Auth::can('orders.dispatch')&&!Auth::can('users.manage')&&$entityId!==Auth::id())throw new RuntimeException('Sua função não pode consultar funcionários por QR.');
            $s=$pdo->prepare('SELECT id,name,email,role,status FROM users WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);$user=$s->fetch();if(!$user||$user['status']!=='active')throw new RuntimeException('Funcionário indisponível.');
            $shift=$pdo->prepare('SELECT id,mode,started_at FROM work_shifts WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$shift->execute([$tenantId,$entityId]);$open=$shift->fetch();
            if($type==='delivery_user'){$permissions=PermissionCatalog::effectiveForUser($tenantId,$entityId,(string)$user['role']);if(!in_array('orders.delivery',$permissions,true))throw new RuntimeException('Funcionário não está autorizado para Delivery.');}
            return ['id'=>(int)$user['id'],'name'=>(string)$user['name'],'email'=>(string)$user['email'],'role'=>(string)$user['role'],'status'=>(string)$user['status'],'shift'=>$open?:null];
        }
        if($type==='customer'){
            if(!Auth::can('customers.manage')&&!Auth::can('orders.create'))throw new RuntimeException('Acesso negado ao cliente.');$s=$pdo->prepare('SELECT id,name,phone,email,points FROM customers WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Cliente não encontrado.');return $row;
        }
        if($type==='event'){
            if(!Auth::can('events.manage')&&!Auth::can('tickets.manage')&&!Auth::can('guests.manage')&&!Auth::can('events.bar'))throw new RuntimeException('Acesso negado ao evento.');$s=$pdo->prepare('SELECT id,name,status,venue,address,starts_at,ends_at FROM events WHERE id=? AND tenant_id=?');$s->execute([$entityId,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Evento não encontrado.');return $row;
        }
        if($type==='device'){
            Auth::requirePermission('nfc.manage');$s=$pdo->prepare('SELECT d.id,d.provider,d.name,d.status,d.paired_at,d.revoked_at,u.name user_name FROM nfc_devices d LEFT JOIN users u ON u.id=d.user_id WHERE d.id=? AND d.tenant_id=?');$s->execute([$entityId,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Dispositivo não encontrado.');return $row;
        }
        throw new RuntimeException('Tipo de QR não suportado.');
    }

    private function extract(string $value):string
    {
        $value=trim($value);if(preg_match('/EVENTMENU:QR:([a-f0-9]{64})/i',$value,$m))return strtolower($m[1]);if(preg_match('/^[a-f0-9]{64}$/i',$value))return strtolower($value);
        if(filter_var($value,FILTER_VALIDATE_URL)){$parts=parse_url($value);if(isset($parts['query'])){parse_str($parts['query'],$q);foreach(['qr','token','t'] as $key){$candidate=(string)($q[$key]??'');if(preg_match('/^[a-f0-9]{64}$/i',$candidate))return strtolower($candidate);}}}
        return '';
    }
}
