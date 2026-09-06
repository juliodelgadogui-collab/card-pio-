<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class MobileDeviceStatusService
{
    public function current(string $deviceIdentifier,int $tokenId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();$deviceIdentifier=trim($deviceIdentifier);
        if(!$tenantId||!$userId||$tokenId<1||strlen($deviceIdentifier)<8)throw new RuntimeException('Aparelho ou sessão inválidos.');
        $pdo=Database::connection();$deviceHash=hash('sha256',$deviceIdentifier);

        $token=$pdo->prepare('SELECT id,device_label,expires_at,last_used_at,created_at FROM api_tokens WHERE id=? AND tenant_id=? AND user_id=? AND device_hash=? AND revoked_at IS NULL LIMIT 1');
        $token->execute([$tokenId,$tenantId,$userId,$deviceHash]);$session=$token->fetch();if(!$session)throw new RuntimeException('Sessão deste aparelho não encontrada.');

        $nfc=$pdo->prepare('SELECT id,user_id,provider,name,status,pairing_attempts,paired_at,revoked_at,created_at FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=? ORDER BY id DESC LIMIT 1');
        $nfc->execute([$tenantId,$deviceHash]);$nfcDevice=$nfc->fetch()?:null;
        $gateway=$pdo->prepare('SELECT active FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" LIMIT 1');$gateway->execute([$tenantId]);$pagbankActive=(int)($gateway->fetchColumn()?:0)===1;
        $nfcAllowed=Auth::can('nfc.collect');
        $nfcBound=$nfcDevice!==null&&($nfcDevice['user_id']===null||(int)$nfcDevice['user_id']===$userId);
        $tapOnReady=$nfcAllowed&&$pagbankActive&&$nfcBound&&($nfcDevice['status']??'')==='active';

        return [
            'session'=>[
                'token_id'=>(int)$session['id'],
                'device_label'=>(string)($session['device_label']??''),
                'expires_at'=>(string)$session['expires_at'],
                'last_used_at'=>(string)($session['last_used_at']??''),
                'created_at'=>(string)$session['created_at'],
            ],
            'nfc'=> $nfcDevice ? [
                'id'=>(int)$nfcDevice['id'],
                'name'=>(string)($nfcDevice['name']??''),
                'provider'=>(string)$nfcDevice['provider'],
                'status'=>(string)$nfcDevice['status'],
                'paired_at'=>(string)($nfcDevice['paired_at']??''),
                'revoked_at'=>(string)($nfcDevice['revoked_at']??''),
                'bound_to_current_user'=>$nfcBound,
            ] : null,
            'nfc_permission'=>$nfcAllowed,
            'pagbank_active'=>$pagbankActive,
            'tap_on_ready'=>$tapOnReady,
        ];
    }

    public function requestNfcAuthorization(string $deviceIdentifier,int $tokenId): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();$deviceIdentifier=trim($deviceIdentifier);
        if(!$tenantId||!$userId||$tokenId<1||strlen($deviceIdentifier)<8)throw new RuntimeException('Aparelho ou sessão inválidos.');
        if(!Auth::can('nfc.collect'))throw new RuntimeException('Seu perfil não tem permissão para receber pagamentos por aproximação.');

        $deviceHash=hash('sha256',$deviceIdentifier);$deviceId=0;$deviceName='EventMenu GO · '.mb_substr(Auth::name(),0,70);
        Database::transaction(function(PDO $tx)use($tenantId,$userId,$deviceHash,$deviceName,&$deviceId):void{
            $stmt=$tx->prepare(Database::portableSql($tx,'SELECT * FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=? ORDER BY id DESC LIMIT 1 FOR UPDATE'));
            $stmt->execute([$tenantId,$deviceHash]);$device=$stmt->fetch();

            if($device&&$device['status']==='revoked')throw new RuntimeException('Este aparelho foi revogado. Um administrador precisa liberar o dispositivo novamente.');
            if($device&&$device['status']==='active'){
                if($device['user_id']!==null&&(int)$device['user_id']!==$userId)throw new RuntimeException('Este aparelho está autorizado para outro funcionário.');
                $deviceId=(int)$device['id'];return;
            }
            if($device&&(int)$device['pairing_attempts']>=5)throw new RuntimeException('Limite de solicitações de autorização atingido. Procure um administrador.');

            if($device){
                $tx->prepare('UPDATE nfc_devices SET user_id=?,name=?,status="pending",pairing_attempts=pairing_attempts+1,revoked_at=NULL WHERE id=?')
                    ->execute([$userId,$device['name']?:$deviceName,$device['id']]);
                $deviceId=(int)$device['id'];
            }else{
                $tx->prepare('INSERT INTO nfc_devices (tenant_id,user_id,provider,device_identifier_hash,name,status,pairing_attempts) VALUES (?,?,"pagbank",?,?,"pending",1)')
                    ->execute([$tenantId,$userId,$deviceHash,$deviceName]);
                $deviceId=(int)$tx->lastInsertId();
            }
        });
        Auth::audit('nfc.authorization_requested','nfc_device',(string)$deviceId,['user_id'=>$userId]);
        return $this->current($deviceIdentifier,$tokenId);
    }
}
