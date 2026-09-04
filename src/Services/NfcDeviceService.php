<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class NfcDeviceService
{
    private const PAYMENT_ROLES=['admin','manager','cashier'];
    private const PAIR_WINDOW_MINUTES=15;
    private const PAIR_MAX_ATTEMPTS=5;
    private const PAIR_LOCK_MINUTES=30;

    public function pair(string $identifier,?int $userId,?string $name=null,?\DateTimeImmutable $now=null):array
    {
        Auth::requirePermission('nfc.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida para NFC.');
        $fingerprint=PagBankSecurityService::deviceFingerprint($identifier);
        $legacy=PagBankSecurityService::legacyDeviceFingerprint($identifier);
        $name=mb_substr(trim((string)$name),0,120);
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));

        $result=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$name,$fingerprint,$legacy,$now){
            $tenant=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND status="active" FOR UPDATE');
            $tenant->execute([$tenantId]);
            if(!$tenant->fetchColumn())throw new RuntimeException('Empresa indisponível para pareamento NFC.');

            if($userId!==null&&$userId>0){
                $u=$pdo->prepare('SELECT role FROM users WHERE id=? AND tenant_id=? AND status="active" FOR UPDATE');
                $u->execute([$userId,$tenantId]);
                $role=$u->fetchColumn();
                if($role===false||!in_array((string)$role,self::PAYMENT_ROLES,true))throw new RuntimeException('Usuário inválido para dispositivo de pagamento.');
            }else{$userId=null;}

            $d=$pdo->prepare('SELECT * FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash IN (?,?) ORDER BY identifier_version="hmac-sha256" DESC,id DESC LIMIT 1 FOR UPDATE');
            $d->execute([$tenantId,$fingerprint,$legacy]);
            $device=$d->fetch()?:null;

            if($device&&!empty($device['pairing_locked_until'])&&new \DateTimeImmutable((string)$device['pairing_locked_until'],new \DateTimeZone('UTC'))>$now){
                return ['locked'=>true,'until'=>(string)$device['pairing_locked_until'],'id'=>(int)$device['id']];
            }

            $windowStart=$device&&!empty($device['pairing_window_started_at'])?new \DateTimeImmutable((string)$device['pairing_window_started_at'],new \DateTimeZone('UTC')):null;
            $attempts=$device?(int)$device['pairing_attempts']:0;
            if(!$windowStart||$windowStart<$now->modify('-'.self::PAIR_WINDOW_MINUTES.' minutes')){$attempts=0;$windowStart=$now;}
            $attempts++;

            if($device&&$attempts>self::PAIR_MAX_ATTEMPTS){
                $lockedUntil=$now->modify('+'.self::PAIR_LOCK_MINUTES.' minutes');
                $pdo->prepare('UPDATE nfc_devices SET pairing_attempts=?,pairing_window_started_at=?,pairing_locked_until=? WHERE id=? AND tenant_id=?')->execute([$attempts,$windowStart->format('Y-m-d H:i:s'),$lockedUntil->format('Y-m-d H:i:s'),$device['id'],$tenantId]);
                return ['locked'=>true,'until'=>$lockedUntil->format('Y-m-d H:i:s'),'id'=>(int)$device['id']];
            }

            if($device){
                $pdo->prepare('UPDATE nfc_devices SET user_id=?,device_identifier_hash=?,identifier_version="hmac-sha256",name=?,status="active",pairing_attempts=?,pairing_window_started_at=?,pairing_locked_until=NULL,paired_at=?,revoked_at=NULL,revocation_reason=NULL,revoked_by=NULL WHERE id=? AND tenant_id=?')->execute([$userId,$fingerprint,$name!==''?$name:null,$attempts,$windowStart->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s'),$device['id'],$tenantId]);
                $id=(int)$device['id'];
            }else{
                $pdo->prepare('INSERT INTO nfc_devices (tenant_id,user_id,provider,device_identifier_hash,identifier_version,name,status,pairing_attempts,pairing_window_started_at,paired_at) VALUES (?,?,"pagbank",?,"hmac-sha256",?,"active",?,?,?)')->execute([$tenantId,$userId,$fingerprint,$name!==''?$name:null,$attempts,$windowStart->format('Y-m-d H:i:s'),$now->format('Y-m-d H:i:s')]);
                $id=(int)$pdo->lastInsertId();
            }
            return ['locked'=>false,'id'=>$id,'attempts'=>$attempts];
        });

        if(!empty($result['locked'])){
            Auth::audit('nfc.pairing_locked','nfc_device',(string)$result['id'],['until'=>$result['until']]);
            throw new RuntimeException('Limite de pareamentos atingido. Tente novamente após '.$result['until'].' UTC.');
        }
        Auth::audit('nfc.paired','nfc_device',(string)$result['id'],['user_id'=>$userId,'attempts'=>$result['attempts']]);
        return $result;
    }

    public function revoke(int $deviceId,string $reason='manual'):void
    {
        Auth::requirePermission('nfc.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId)throw new RuntimeException('Empresa inválida para NFC.');
        $stmt=Database::connection()->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=NOW(),revocation_reason=?,revoked_by=? WHERE id=? AND tenant_id=? AND status<>"revoked"');
        $stmt->execute([mb_substr(trim($reason),0,190)?:'manual',$userId,$deviceId,$tenantId]);
        Auth::audit('nfc.revoked','nfc_device',(string)$deviceId,['reason'=>$reason]);
    }

    public function revokeForUserChange(PDO $pdo,int $tenantId,int $userId,string $reason):int
    {
        $stmt=$pdo->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=NOW(),revocation_reason=?,revoked_by=? WHERE tenant_id=? AND user_id=? AND status<>"revoked"');
        $stmt->execute([mb_substr(trim($reason),0,190)?:'user_changed',Auth::id(),$tenantId,$userId]);
        return $stmt->rowCount();
    }

    public function authorize(PDO $pdo,int $tenantId,int $userId,string $identifier):array
    {
        $u=$pdo->prepare('SELECT role,status FROM users WHERE id=? AND tenant_id=? LIMIT 1');
        $u->execute([$userId,$tenantId]);$user=$u->fetch();
        if(!$user||$user['status']!=='active'||!in_array((string)$user['role'],self::PAYMENT_ROLES,true))throw new RuntimeException('Usuário sem autorização para pagamento NFC.');

        $fingerprint=PagBankSecurityService::deviceFingerprint($identifier);
        $legacy=PagBankSecurityService::legacyDeviceFingerprint($identifier);
        $d=$pdo->prepare('SELECT * FROM nfc_devices WHERE tenant_id=? AND provider="pagbank" AND device_identifier_hash IN (?,?) LIMIT 1');
        $d->execute([$tenantId,$fingerprint,$legacy]);$device=$d->fetch();
        if(!$device||$device['status']!=='active')throw new RuntimeException('Dispositivo NFC não autorizado ou revogado.');
        if($device['user_id']!==null&&(int)$device['user_id']!==$userId)throw new RuntimeException('Dispositivo NFC vinculado a outro usuário.');

        if(($device['identifier_version']??'sha256')!=='hmac-sha256'||$device['device_identifier_hash']!==$fingerprint){
            $pdo->prepare('UPDATE nfc_devices SET device_identifier_hash=?,identifier_version="hmac-sha256",last_seen_at=NOW() WHERE id=? AND tenant_id=?')->execute([$fingerprint,$device['id'],$tenantId]);
            $device['device_identifier_hash']=$fingerprint;$device['identifier_version']='hmac-sha256';
        }else{$pdo->prepare('UPDATE nfc_devices SET last_seen_at=NOW() WHERE id=? AND tenant_id=?')->execute([$device['id'],$tenantId]);}
        return $device;
    }
}
