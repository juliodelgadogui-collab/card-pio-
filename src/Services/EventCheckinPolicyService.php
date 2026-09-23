<?php

declare(strict_types=1);

namespace EventMenu\Services;

use DateTimeImmutable;
use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class EventCheckinPolicyService
{
    public function settings(PDO $pdo,int $tenantId,int $eventId,array $event=[]): array
    {
        $s=$pdo->prepare('SELECT enabled,starts_at,ends_at FROM event_checkin_settings WHERE tenant_id=? AND event_id=? LIMIT 1');
        $s->execute([$tenantId,$eventId]);
        $row=$s->fetch()?:[];
        $eventStarts=trim((string)($event['starts_at']??''));
        $eventEnds=trim((string)($event['ends_at']??''));
        $starts=trim((string)($row['starts_at']??''));
        $ends=trim((string)($row['ends_at']??''));
        if($starts===''&&$eventStarts!=='')$starts=(new DateTimeImmutable($eventStarts))->modify('-6 hours')->format('Y-m-d H:i:s');
        if($ends===''){
            if($eventEnds!=='')$ends=(new DateTimeImmutable($eventEnds))->modify('+6 hours')->format('Y-m-d H:i:s');
            elseif($eventStarts!=='')$ends=(new DateTimeImmutable($eventStarts))->modify('+18 hours')->format('Y-m-d H:i:s');
        }
        return ['enabled'=>array_key_exists('enabled',$row)?(bool)$row['enabled']:true,'starts_at'=>$starts?:null,'ends_at'=>$ends?:null,'custom'=>(bool)$row];
    }

    public function assertOpen(PDO $pdo,int $tenantId,array $event): array
    {
        if((string)($event['status']??'')!=='published')throw new RuntimeException('O evento não está liberado para entrada.');
        $settings=$this->settings($pdo,$tenantId,(int)$event['id'],$event);
        if(!$settings['enabled'])throw new RuntimeException('O check-in deste evento está desativado.');
        $now=new DateTimeImmutable('now');
        if($settings['starts_at']&&$now<new DateTimeImmutable((string)$settings['starts_at']))throw new RuntimeException('Check-in ainda não liberado para este evento.');
        if($settings['ends_at']&&$now>new DateTimeImmutable((string)$settings['ends_at']))throw new RuntimeException('Check-in encerrado para este evento.');
        return $settings;
    }

    public function save(int $eventId,array $data): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$eventId<1)throw new RuntimeException('Evento inválido.');
        $pdo=Database::connection();
        $e=$pdo->prepare('SELECT id,starts_at,ends_at FROM events WHERE id=? AND tenant_id=? LIMIT 1');
        $e->execute([$eventId,$tenantId]);
        if(!$e->fetch())throw new RuntimeException('Evento não encontrado.');
        $enabled=!empty($data['checkin_enabled'])?1:0;
        $starts=$this->dateOrNull($data['checkin_starts_at']??null);
        $ends=$this->dateOrNull($data['checkin_ends_at']??null);
        if($starts&&$ends&&strtotime($ends)<=strtotime($starts))throw new RuntimeException('O fim do check-in deve ser posterior ao início.');
        $u=$pdo->prepare('UPDATE event_checkin_settings SET enabled=?,starts_at=?,ends_at=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND event_id=?');
        $u->execute([$enabled,$starts,$ends,$tenantId,$eventId]);
        if($u->rowCount()===0){
            $c=$pdo->prepare('SELECT event_id FROM event_checkin_settings WHERE tenant_id=? AND event_id=?');
            $c->execute([$tenantId,$eventId]);
            if(!$c->fetchColumn())$pdo->prepare('INSERT INTO event_checkin_settings (event_id,tenant_id,enabled,starts_at,ends_at) VALUES (?,?,?,?,?)')->execute([$eventId,$tenantId,$enabled,$starts,$ends]);
        }
        try{$pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,Auth::id(),'event.checkin_settings','event',(string)$eventId,json_encode(['enabled'=>(bool)$enabled,'starts_at'=>$starts,'ends_at'=>$ends],JSON_UNESCAPED_UNICODE)]);}catch(\Throwable){}
        Auth::audit('event.checkin_settings','event',(string)$eventId,['enabled'=>(bool)$enabled,'starts_at'=>$starts,'ends_at'=>$ends]);
    }

    private function dateOrNull(mixed $value): ?string
    {
        $v=trim((string)$value);
        if($v==='')return null;
        $v=str_replace('T',' ',$v);
        if(strtotime($v)===false)throw new RuntimeException('Data ou horário de check-in inválido.');
        return $v;
    }
}
