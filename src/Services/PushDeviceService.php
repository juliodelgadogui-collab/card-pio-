<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PushDeviceService
{
    public function register(string $deviceId,string $pushToken,string $platform='android'):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$deviceId=mb_substr(trim($deviceId),0,190);$pushToken=trim($pushToken);$platform=strtolower(trim($platform));if($deviceId===''||strlen($deviceId)<8)throw new RuntimeException('Aparelho inválido.');if(strlen($pushToken)<20||strlen($pushToken)>4096)throw new RuntimeException('Token de notificação inválido.');if(!in_array($platform,['android'],true))throw new RuntimeException('Plataforma de notificação inválida.');$hash=hash('sha256',$pushToken);
        return Database::transaction(function(PDO$pdo)use($tenantId,$userId,$deviceId,$pushToken,$platform,$hash):array{
            $pdo->prepare('UPDATE push_devices SET active=0,updated_at=CURRENT_TIMESTAMP WHERE token_hash=? AND NOT (tenant_id=? AND user_id=? AND device_id=?)')->execute([$hash,$tenantId,$userId,$deviceId]);
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT id FROM push_devices WHERE tenant_id=? AND user_id=? AND device_id=? LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$userId,$deviceId]);$id=$s->fetchColumn();
            if($id){$u=$pdo->prepare('UPDATE push_devices SET platform=?,token_hash=?,push_token=?,active=1,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$platform,$hash,$pushToken,$id]);}
            else{$i=$pdo->prepare('INSERT INTO push_devices (tenant_id,user_id,device_id,platform,token_hash,push_token,active,last_seen_at) VALUES (?,?,?,?,?,?,1,CURRENT_TIMESTAMP)');$i->execute([$tenantId,$userId,$deviceId,$platform,$hash,$pushToken]);$id=(int)$pdo->lastInsertId();}
            return['id'=>(int)$id,'device_id'=>$deviceId,'platform'=>$platform,'active'=>true];
        });
    }

    public function unregister(string $deviceId):int
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$deviceId=mb_substr(trim($deviceId),0,190);if($deviceId==='')return 0;$s=Database::connection()->prepare('UPDATE push_devices SET active=0,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND device_id=?');$s->execute([$tenantId,$userId,$deviceId]);return$s->rowCount();
    }

    public function status(string $deviceId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$s=Database::connection()->prepare('SELECT device_id,platform,active,last_seen_at,updated_at FROM push_devices WHERE tenant_id=? AND user_id=? AND device_id=? LIMIT 1');$s->execute([$tenantId,$userId,mb_substr(trim($deviceId),0,190)]);$row=$s->fetch();return['registered'=>(bool)$row,'device'=>$row?:null,'server_configured'=>(new FcmPushService())->configured()];
    }
}
