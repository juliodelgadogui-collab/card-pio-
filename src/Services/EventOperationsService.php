<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class EventOperationsService
{
    public function overview(): array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!Auth::can('tickets.manage')&&!Auth::can('guests.manage')&&!Auth::can('events.manage')&&!Auth::can('events.bar')&&!Auth::can('events.promoter'))throw new RuntimeException('Acesso negado ao modo Evento.');
        $pdo=Database::connection();
        $sql='SELECT e.id,e.name,e.slug,e.venue,e.address,e.starts_at,e.ends_at,e.status,
            (SELECT COUNT(*) FROM tickets t WHERE t.tenant_id=e.tenant_id AND t.event_id=e.id AND t.status IN ("paid","checked_in")) tickets_paid,
            (SELECT COUNT(*) FROM tickets t WHERE t.tenant_id=e.tenant_id AND t.event_id=e.id AND t.status="checked_in") tickets_checked_in,
            (SELECT COUNT(*) FROM tickets t WHERE t.tenant_id=e.tenant_id AND t.event_id=e.id AND t.status="reserved") tickets_reserved,
            (SELECT COUNT(*) FROM event_guests g WHERE g.tenant_id=e.tenant_id AND g.event_id=e.id AND g.status="invited") guests_pending,
            (SELECT COUNT(*) FROM event_guests g WHERE g.tenant_id=e.tenant_id AND g.event_id=e.id AND g.status="checked_in") guests_checked_in,
            (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=e.tenant_id AND o.channel="event" AND o.payment_status="paid" AND o.id IN (SELECT DISTINCT t.order_id FROM tickets t WHERE t.tenant_id=e.tenant_id AND t.event_id=e.id AND t.order_id IS NOT NULL)) ticket_revenue_cents,
            (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=e.tenant_id AND o.event_id=e.id AND o.channel="bar" AND o.payment_status="paid") bar_revenue_cents
            FROM events e WHERE e.tenant_id=? AND e.status IN ("published","draft","closed") ORDER BY CASE WHEN e.status="published" THEN 0 WHEN e.status="draft" THEN 1 ELSE 2 END,e.starts_at DESC LIMIT 100';
        $s=$pdo->prepare($sql);$s->execute([$tenantId]);$rows=$s->fetchAll();
        foreach($rows as &$row){$row['revenue_cents']=(int)$row['ticket_revenue_cents']+(int)$row['bar_revenue_cents'];}unset($row);
        return $rows;
    }

    public function recentEntries(int $eventId): array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$eventId<1)throw new RuntimeException('Evento inválido.');
        if(!Auth::can('tickets.manage')&&!Auth::can('guests.manage')&&!Auth::can('events.manage'))throw new RuntimeException('Acesso negado.');
        $pdo=Database::connection();$e=$pdo->prepare('SELECT id,name,status FROM events WHERE id=? AND tenant_id=?');$e->execute([$eventId,$tenantId]);$event=$e->fetch();if(!$event)throw new RuntimeException('Evento não encontrado.');
        $ticket=$pdo->prepare('SELECT t.id,t.code,t.checked_in_at,c.name person_name,u.name operator_name,"ticket" entry_type,b.name detail FROM tickets t LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN users u ON u.id=t.checked_in_by LEFT JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND t.status="checked_in" ORDER BY t.checked_in_at DESC LIMIT 30');
        $ticket->execute([$tenantId,$eventId]);
        $guest=$pdo->prepare('SELECT g.id,g.checkin_code code,g.checked_in_at,g.name person_name,u.name operator_name,"guest" entry_type,CAST(g.plus_ones AS CHAR) detail FROM event_guests g LEFT JOIN users u ON u.id=g.checked_in_by WHERE g.tenant_id=? AND g.event_id=? AND g.status="checked_in" ORDER BY g.checked_in_at DESC LIMIT 30');
        $guest->execute([$tenantId,$eventId]);
        $entries=array_merge($ticket->fetchAll(),$guest->fetchAll());
        usort($entries,static fn(array $a,array $b):int=>strcmp((string)($b['checked_in_at']??''),(string)($a['checked_in_at']??'')));
        return ['event'=>$event,'entries'=>array_slice($entries,0,40)];
    }
}
