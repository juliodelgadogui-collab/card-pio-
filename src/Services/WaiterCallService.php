<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class WaiterCallService
{
    public function createByTableToken(string $token,string $type='waiter'):int
    {
        if(!in_array($type,['waiter','bill','help'],true))$type='waiter';
        return Database::transaction(function(PDO $pdo)use($token,$type){
            $s=$pdo->prepare('SELECT id,tenant_id,unit_id,name,status FROM restaurant_tables WHERE qr_token=? LIMIT 1 FOR UPDATE');
            $s->execute([$token]);$table=$s->fetch();
            if(!$table||$table['status']==='inactive')throw new RuntimeException('Mesa indisponível.');
            TenantModuleService::requireModule((int)$table['tenant_id'],'restaurant');
            $c=$pdo->prepare('SELECT id FROM waiter_calls WHERE tenant_id=? AND table_id=? AND type=? AND status="open" LIMIT 1');
            $c->execute([$table['tenant_id'],$table['id'],$type]);$existing=$c->fetchColumn();
            if($existing)return(int)$existing;
            $pdo->prepare('INSERT INTO waiter_calls (tenant_id,table_id,type,status) VALUES (?,?,?,"open")')->execute([$table['tenant_id'],$table['id'],$type]);
            $id=(int)$pdo->lastInsertId();
            $label=match($type){'bill'=>'Pediu a conta','help'=>'Pediu ajuda',default=>'Chamou o garçom'};
            $message=(string)$table['name'].' · '.$label.'.';
            $waiters=$pdo->prepare('SELECT DISTINCT u.id FROM users u WHERE u.tenant_id=? AND u.role="waiter" AND u.status="active" AND (? IS NULL OR NOT EXISTS (SELECT 1 FROM user_units ux WHERE ux.user_id=u.id) OR EXISTS (SELECT 1 FROM user_units uu WHERE uu.user_id=u.id AND uu.unit_id=?))');
            $waiters->execute([(int)$table['tenant_id'],$table['unit_id'],$table['unit_id']]);
            $notification=new NotificationService();
            foreach($waiters->fetchAll(PDO::FETCH_COLUMN) as$userId)$notification->push((int)$table['tenant_id'],'order','Chamada de mesa',$message,'waiter_call',$id,(int)$userId);
            return$id;
        });
    }

    public function resolve(int $tenantId,int $id):void
    {
        TenantModuleService::requireModule($tenantId,'restaurant');
        if(!Auth::can('waiter.calls')&&!Auth::can('tables.manage'))throw new RuntimeException('Sem permissão para resolver chamadas.');
        $s=Database::connection()->prepare('UPDATE waiter_calls SET status="resolved",resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND tenant_id=? AND status="open"');
        $s->execute([Auth::id(),$id,$tenantId]);
        if($s->rowCount())Auth::audit('waiter_call.resolved','waiter_call',(string)$id);
    }
}
