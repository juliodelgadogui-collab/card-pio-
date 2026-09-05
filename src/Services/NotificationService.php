<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use PDO;
use RuntimeException;

final class NotificationService
{
    private const PRIORITIES=['info','success','warning','critical'];
    private const MODES=['operation','delivery','events','pay'];

    public function publishToUser(int $userId,?string $mode,string $type,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $dedupeKey=null,string $priority='info',?string $expiresAt=null):void
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->publishToUserForTenant($tenantId,$userId,$mode,$type,$title,$message,$entityType,$entityId,$dedupeKey,$priority,$expiresAt);
    }

    public function publishToUserForTenant(int $tenantId,int $userId,?string $mode,string $type,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $dedupeKey=null,string $priority='info',?string $expiresAt=null):void
    {
        if($tenantId<1||$userId<1)throw new RuntimeException('Destino de notificação inválido.');
        $pdo=Database::connection();$u=$pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=? AND status="active" LIMIT 1');$u->execute([$userId,$tenantId]);if(!$u->fetchColumn())return;
        $this->insert($pdo,$tenantId,$userId,$mode,$type,$title,$message,$entityType,$entityId,$dedupeKey,$priority,$expiresAt);
    }

    public function publishToPermission(string $permission,?string $mode,string $type,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $dedupeBase=null,string $priority='info',?string $expiresAt=null):int
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        return $this->publishToPermissionForTenant($tenantId,$permission,$mode,$type,$title,$message,$entityType,$entityId,$dedupeBase,$priority,$expiresAt);
    }

    public function publishToPermissionForTenant(int $tenantId,string $permission,?string $mode,string $type,string $title,string $message,?string $entityType=null,?string $entityId=null,?string $dedupeBase=null,string $priority='info',?string $expiresAt=null):int
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        if(!in_array($permission,PermissionCatalog::all(),true))throw new RuntimeException('Permissão de notificação inválida.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT id,role FROM users WHERE tenant_id=? AND status="active" ORDER BY id');$s->execute([$tenantId]);$count=0;
        foreach($s->fetchAll() as $user){
            $permissions=PermissionCatalog::effectiveForUser($tenantId,(int)$user['id'],(string)$user['role']);
            if(!in_array($permission,$permissions,true))continue;
            $key=$dedupeBase!==null?$dedupeBase.':user:'.$user['id']:null;
            if($this->insert($pdo,$tenantId,(int)$user['id'],$mode,$type,$title,$message,$entityType,$entityId,$key,$priority,$expiresAt))$count++;
        }
        return $count;
    }

    public function inbox(int $limit=100):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $limit=max(1,min(200,$limit));$mode=$this->currentMode();$pdo=Database::connection();
        $sql='SELECT id,mode,type,priority,title,message,entity_type,entity_id,read_at,expires_at,created_at FROM app_notifications WHERE tenant_id=? AND user_id=? AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)';$args=[$tenantId,$userId];
        if($mode!==null){$sql.=' AND (mode IS NULL OR mode=?)';$args[]=$mode;}else{$sql.=' AND mode IS NULL';}
        $sql.=' ORDER BY CASE priority WHEN "critical" THEN 0 WHEN "warning" THEN 1 WHEN "success" THEN 2 ELSE 3 END,read_at IS NOT NULL,created_at DESC LIMIT '.$limit;
        $s=$pdo->prepare($sql);$s->execute($args);$items=$s->fetchAll();$unread=0;foreach($items as $item)if(empty($item['read_at']))$unread++;
        return ['items'=>$items,'unread_count'=>$unread,'mode'=>$mode];
    }

    public function markRead(int $notificationId):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$notificationId<1)throw new RuntimeException('Notificação inválida.');
        $s=Database::connection()->prepare('UPDATE app_notifications SET read_at=COALESCE(read_at,CURRENT_TIMESTAMP) WHERE id=? AND tenant_id=? AND user_id=?');$s->execute([$notificationId,$tenantId,$userId]);
    }

    public function markAllRead():int
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$mode=$this->currentMode();$pdo=Database::connection();
        $sql='UPDATE app_notifications SET read_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND read_at IS NULL AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)';$args=[$tenantId,$userId];
        if($mode!==null){$sql.=' AND (mode IS NULL OR mode=?)';$args[]=$mode;}else{$sql.=' AND mode IS NULL';}
        $s=$pdo->prepare($sql);$s->execute($args);return $s->rowCount();
    }

    public function purgeExpired(int $tenantId):int
    {
        if($tenantId<1)return 0;$s=Database::connection()->prepare('DELETE FROM app_notifications WHERE tenant_id=? AND expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP');$s->execute([$tenantId]);return $s->rowCount();
    }

    private function insert(PDO $pdo,int $tenantId,int $userId,?string $mode,string $type,string $title,string $message,?string $entityType,?string $entityId,?string $dedupeKey,string $priority,?string $expiresAt):bool
    {
        $mode=$mode!==null?strtolower(trim($mode)):null;if($mode!==null&&!in_array($mode,self::MODES,true))throw new RuntimeException('Modo de notificação inválido.');
        $priority=strtolower(trim($priority));if(!in_array($priority,self::PRIORITIES,true))$priority='info';
        $type=mb_substr(trim($type),0,60);$title=mb_substr(trim($title),0,180);$message=mb_substr(trim($message),0,500);if($type===''||$title===''||$message==='')throw new RuntimeException('Conteúdo de notificação inválido.');
        $entityType=$entityType!==null?mb_substr(trim($entityType),0,80):null;$entityId=$entityId!==null?mb_substr(trim($entityId),0,80):null;
        $dedupeKey=mb_substr(trim($dedupeKey?:('notification:'.bin2hex(random_bytes(16)))),0,190);if($expiresAt!==null&&strtotime($expiresAt)===false)$expiresAt=null;
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO app_notifications (tenant_id,user_id,mode,type,priority,title,message,entity_type,entity_id,dedupe_key,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $s=$pdo->prepare($sql);$s->execute([$tenantId,$userId,$mode,$type,$priority,$title,$message,$entityType,$entityId,$dedupeKey,$expiresAt]);return $s->rowCount()>0;
    }

    private function currentMode():?string
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return null;$s=Database::connection()->prepare('SELECT mode FROM work_shifts WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$s->execute([$tenantId,$userId]);$mode=$s->fetchColumn();return $mode!==false?(string)$mode:null;
    }
}
