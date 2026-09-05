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
    public function checkIn(string $code):array
    {
        Auth::requirePermission('guests.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $code=$this->normalize($code);if($code==='')throw new RuntimeException('Código obrigatório.');
        $promoterId=null;
        if(Auth::role()==='promoter'){
            $p=Database::connection()->prepare('SELECT id FROM promoters WHERE tenant_id=? AND user_id=? AND active=1');$p->execute([$tenantId,Auth::id()]);$promoterId=$p->fetchColumn();if(!$promoterId)throw new RuntimeException('Promotor sem vínculo.');
        }

        return Database::transaction(function(PDO $pdo)use($tenantId,$code,$promoterId):array{
            $sql='SELECT g.*,e.status event_status,e.name event_name,t.status tenant_status FROM event_guests g JOIN events e ON e.id=g.event_id JOIN tenants t ON t.id=g.tenant_id WHERE g.tenant_id=? AND g.checkin_code=?';$args=[$tenantId,$code];if($promoterId){$sql.=' AND g.promoter_id=?';$args[]=$promoterId;}$sql.=' LIMIT 1 FOR UPDATE';
            $s=$pdo->prepare(Database::portableSql($pdo,$sql));$s->execute($args);$guest=$s->fetch();if(!$guest)throw new RuntimeException('Convidado não encontrado.');
            if($guest['tenant_status']!=='active'||!TenantFeatures::events($tenantId))throw new RuntimeException('Evento indisponível para check-in.');
            if($guest['event_status']!=='published')throw new RuntimeException('O evento não está liberado para entrada.');
            if($guest['status']==='checked_in')throw new RuntimeException('Convidado já realizou check-in.');
            if($guest['status']!=='invited')throw new RuntimeException('Convite não está válido.');
            $pdo->prepare('UPDATE event_guests SET status="checked_in",checked_in_at=CURRENT_TIMESTAMP,checked_in_by=? WHERE id=? AND tenant_id=?')->execute([Auth::id(),$guest['id'],$tenantId]);
            Auth::audit('guest.checkin','guest',(string)$guest['id'],['event_id'=>(int)$guest['event_id'],'source'=>'service']);
            $guest['status']='checked_in';return $guest;
        });
    }

    private function normalize(string $value):string
    {
        $value=trim($value);if(filter_var($value,FILTER_VALIDATE_URL)){$parts=parse_url($value);if(isset($parts['query'])){parse_str($parts['query'],$q);foreach(['code','t','token'] as $key)if(!empty($q[$key]))return trim((string)$q[$key]);}}return $value;
    }
}
