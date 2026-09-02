<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class NotificationService
{
    public function push(int $tenantId,string $type,string $title,string $message,?string $entityType=null,?int $entityId=null,?int $targetUserId=null):void
    {
        $allowed=['info','success','warning','error','order','payment','stock','event'];if(!in_array($type,$allowed,true))$type='info';
        try{$s=Database::connection()->prepare('INSERT INTO notifications (tenant_id,target_user_id,type,title,message,entity_type,entity_id) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$targetUserId,$type,mb_substr($title,0,160),$message,$entityType,$entityId]);}catch(\Throwable){}
    }
    public function unread(int $tenantId,?int $userId=null,int $limit=30):array
    {
        $pdo=Database::connection();$limit=max(1,min(100,$limit));$sql='SELECT * FROM notifications WHERE tenant_id=? AND status="unread" AND (target_user_id IS NULL';$args=[$tenantId];
        if($userId!==null){$sql.=' OR target_user_id=?';$args[]=$userId;}$sql.=') ORDER BY created_at DESC LIMIT '.$limit;$s=$pdo->prepare($sql);$s->execute($args);return$s->fetchAll();
    }
    public function markRead(int $tenantId,int $id,?int $userId=null):void
    {
        $sql='UPDATE notifications SET status="read",read_at=UTC_TIMESTAMP() WHERE id=? AND tenant_id=?';$args=[$id,$tenantId];if($userId!==null){$sql.=' AND (target_user_id IS NULL OR target_user_id=?)';$args[]=$userId;}Database::connection()->prepare($sql)->execute($args);
    }
    public function markAllRead(int $tenantId,?int $userId=null):void
    {
        $sql='UPDATE notifications SET status="read",read_at=UTC_TIMESTAMP() WHERE tenant_id=? AND status="unread"';$args=[$tenantId];if($userId!==null){$sql.=' AND (target_user_id IS NULL OR target_user_id=?)';$args[]=$userId;}Database::connection()->prepare($sql)->execute($args);
    }
}
