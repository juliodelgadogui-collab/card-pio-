<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
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
}
