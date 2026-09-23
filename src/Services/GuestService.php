<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;
use PDO;
use RuntimeException;

final class GuestService
{
    public function checkIn(string $code,?int $expectedEventId=null):array
    {
        Auth::requirePermission('guests.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!$expectedEventId||$expectedEventId<1)throw new RuntimeException('Selecione o evento antes de validar o convidado.');
        $code=$this->normalize($code);if($code==='')throw new RuntimeException('Código obrigatório.');
        $promoterId=null;
        if(Auth::role()==='promoter'){
            $p=Database::connection()->prepare('SELECT id FROM promoters WHERE tenant_id=? AND user_id=? AND active=1');$p->execute([$tenantId,Auth::id()]);$promoterId=$p->fetchColumn();if(!$promoterId)throw new RuntimeException('Promotor sem vínculo.');
        }

        return Database::transaction(function(PDO $pdo)use($tenantId,$code,$promoterId,$expectedEventId):array{
            $sql='SELECT g.*,e.status event_status,e.name event_name,e.starts_at event_starts_at,e.ends_at event_ends_at,t.status tenant_status FROM event_guests g JOIN events e ON e.id=g.event_id AND e.tenant_id=g.tenant_id JOIN tenants t ON t.id=g.tenant_id WHERE g.tenant_id=? AND g.checkin_code=?';$args=[$tenantId,$code];if($promoterId){$sql.=' AND g.promoter_id=?';$args[]=$promoterId;}$sql.=' LIMIT 1 FOR UPDATE';
            $s=$pdo->prepare(Database::portableSql($pdo,$sql));$s->execute($args);$guest=$s->fetch();if(!$guest)throw new RuntimeException('Convidado não encontrado.');
            if((int)$guest['event_id']!==$expectedEventId){$this->eventAudit($pdo,$tenantId,(int)$guest['event_id'],'guest.checkin_blocked','guest',(string)$guest['id'],['reason'=>'wrong_event','expected_event_id'=>$expectedEventId]);throw new RuntimeException('Este convite pertence a outro evento.');}
            if($guest['tenant_status']!=='active'||!TenantFeatures::events($tenantId))throw new RuntimeException('Evento indisponível para check-in.');
            (new EventCheckinPolicyService())->assertOpen($pdo,$tenantId,['id'=>(int)$guest['event_id'],'status'=>(string)$guest['event_status'],'starts_at'=>(string)$guest['event_starts_at'],'ends_at'=>$guest['event_ends_at']]);
            if($guest['status']==='checked_in'){$this->eventAudit($pdo,$tenantId,(int)$guest['event_id'],'guest.checkin_duplicate','guest',(string)$guest['id'],[]);throw new RuntimeException('Convidado já realizou check-in.');}
            if($guest['status']!=='invited')throw new RuntimeException('Convite não está válido.');
            $update=$pdo->prepare('UPDATE event_guests SET status="checked_in",checked_in_at=CURRENT_TIMESTAMP,checked_in_by=? WHERE id=? AND tenant_id=? AND event_id=? AND status="invited"');
            $update->execute([Auth::id(),$guest['id'],$tenantId,$expectedEventId]);
            if($update->rowCount()!==1){
                $fresh=$pdo->prepare('SELECT status FROM event_guests WHERE id=? AND tenant_id=? AND event_id=? LIMIT 1');$fresh->execute([$guest['id'],$tenantId,$expectedEventId]);$status=(string)($fresh->fetchColumn()?:'');
                if($status==='checked_in'){$this->eventAudit($pdo,$tenantId,(int)$guest['event_id'],'guest.checkin_duplicate','guest',(string)$guest['id'],['reason'=>'atomic_transition_lost']);throw new RuntimeException('Convidado já realizou check-in.');}
                throw new RuntimeException('Convite não está válido.');
            }
            Auth::audit('guest.checkin','guest',(string)$guest['id'],['event_id'=>(int)$guest['event_id'],'source'=>'service','plus_ones'=>(int)($guest['plus_ones']??0)]);
            $this->eventAudit($pdo,$tenantId,(int)$guest['event_id'],'guest.checkin','guest',(string)$guest['id'],['plus_ones'=>(int)($guest['plus_ones']??0)]);
            $guest['status']='checked_in';return $guest;
        });
    }

    private function normalize(string $value):string
    {
        $value=trim($value);if(filter_var($value,FILTER_VALIDATE_URL)){$parts=parse_url($value);if(isset($parts['query'])){parse_str($parts['query'],$q);foreach(['code','t','token'] as $key)if(!empty($q[$key]))return trim((string)$q[$key]);}}return $value;
    }

    private function eventAudit(PDO $pdo,int $tenantId,int $eventId,string $action,?string $entityType,?string $entityId,array $metadata):void
    {
        try{$pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,Auth::id(),$action,$entityType,$entityId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}catch(\Throwable){}
    }
}
