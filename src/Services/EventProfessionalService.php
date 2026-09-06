<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class EventProfessionalService
{
    public function event(int $eventId):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$eventId<1)throw new RuntimeException('Evento inválido.');$s=Database::connection()->prepare('SELECT * FROM events WHERE id=? AND tenant_id=?');$s->execute([$eventId,$tenantId]);$event=$s->fetch();if(!$event)throw new RuntimeException('Evento não encontrado.');return $event;
    }

    public function savePublicSettings(int $eventId,array $data):void
    {
        Auth::requirePermission('events.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->event($eventId);
        $type=mb_substr(trim((string)($data['event_type']??'general')),0,40)?:'general';$subtitle=mb_substr(trim((string)($data['public_subtitle']??'')),0,255);$capacity=(int)($data['capacity_total']??0);$capacity=$capacity>0?$capacity:null;$primary=$this->color($data['primary_color']??'');$secondary=$this->color($data['secondary_color']??'');$text=$this->color($data['text_color']??'');$map=mb_substr(trim((string)($data['map_url']??'')),0,700);$lat=$this->coordinate($data['latitude']??null,-90,90);$lng=$this->coordinate($data['longitude']??null,-180,180);$sales=!empty($data['sales_enabled'])?1:0;$bar=!empty($data['bar_enabled'])?1:0;
        if($map!==''&&!filter_var($map,FILTER_VALIDATE_URL))throw new RuntimeException('Link do mapa inválido.');
        $pdo=Database::connection();if($capacity!==null){$used=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');$used->execute([$tenantId,$eventId]);if((int)$used->fetchColumn()>$capacity)throw new RuntimeException('Capacidade não pode ser menor que ingressos já vendidos/reservados.');}
        $pdo->prepare('UPDATE events SET event_type=?,public_subtitle=?,capacity_total=?,primary_color=?,secondary_color=?,text_color=?,map_url=?,latitude=?,longitude=?,sales_enabled=?,bar_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$type,$subtitle?:null,$capacity,$primary,$secondary,$text,$map?:null,$lat,$lng,$sales,$bar,$eventId,$tenantId]);$this->audit($pdo,$tenantId,$eventId,'event.public_settings','event',(string)$eventId,['capacity_total'=>$capacity,'sales_enabled'=>$sales,'bar_enabled'=>$bar]);Auth::audit('event.public_settings','event',(string)$eventId,['capacity_total'=>$capacity]);
    }

    public function saveTicketType(int $eventId,array $data):int
    {
        Auth::requirePermission('events.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->event($eventId);$id=(int)($data['id']??0);$name=mb_substr(trim((string)($data['name']??'')),0,160);$description=mb_substr(trim((string)($data['description']??'')),0,500);$area=mb_substr(trim((string)($data['access_area']??'')),0,160);$cap=(int)($data['capacity_total']??0);$cap=$cap>0?$cap:null;$sort=(int)($data['sort_order']??0);$active=!empty($data['active'])?1:0;if($name==='')throw new RuntimeException('Nome do tipo de ingresso é obrigatório.');$pdo=Database::connection();if($id){if($cap!==null){$u=$pdo->prepare('SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id=? AND t.status IN ("reserved","paid","checked_in")');$u->execute([$tenantId,$eventId,$id]);if((int)$u->fetchColumn()>$cap)throw new RuntimeException('Capacidade do tipo não pode ser menor que ingressos já emitidos/reservados.');}$s=$pdo->prepare('UPDATE ticket_types SET name=?,description=?,access_area=?,capacity_total=?,sort_order=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND event_id=?');$s->execute([$name,$description?:null,$area?:null,$cap,$sort,$active,$id,$tenantId,$eventId]);}else{$s=$pdo->prepare('INSERT INTO ticket_types (tenant_id,event_id,name,description,access_area,capacity_total,sort_order,active) VALUES (?,?,?,?,?,?,?,?)');$s->execute([$tenantId,$eventId,$name,$description?:null,$area?:null,$cap,$sort,$active]);$id=(int)$pdo->lastInsertId();}$this->audit($pdo,$tenantId,$eventId,'ticket_type.saved','ticket_type',(string)$id,['name'=>$name,'capacity_total'=>$cap]);Auth::audit('ticket_type.saved','ticket_type',(string)$id,['event_id'=>$eventId]);return $id;
    }

    public function setBatchType(int $eventId,int $batchId,?int $typeId):void
    {
        Auth::requirePermission('events.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$b=$pdo->prepare('SELECT b.id FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=?');$b->execute([$batchId,$eventId,$tenantId]);if(!$b->fetchColumn())throw new RuntimeException('Lote inválido.');if($typeId){$t=$pdo->prepare('SELECT id FROM ticket_types WHERE id=? AND event_id=? AND tenant_id=?');$t->execute([$typeId,$eventId,$tenantId]);if(!$t->fetchColumn())throw new RuntimeException('Tipo de ingresso inválido.');}$pdo->prepare('UPDATE ticket_batches SET ticket_type_id=? WHERE id=? AND event_id=?')->execute([$typeId?:null,$batchId,$eventId]);$this->audit($pdo,$tenantId,$eventId,'ticket_batch.type','ticket_batch',(string)$batchId,['ticket_type_id'=>$typeId]);
    }

    public function linkPromoter(int $eventId,int $promoterId,?float $commissionPercent,bool $active=true):void
    {
        Auth::requirePermission('events.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$p=$pdo->prepare('SELECT id FROM promoters WHERE id=? AND tenant_id=?');$p->execute([$promoterId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Promotor inválido.');if($commissionPercent!==null&&(!is_finite($commissionPercent)||$commissionPercent<0||$commissionPercent>100))throw new RuntimeException('Comissão inválida.');$c=$pdo->prepare('SELECT promoter_id FROM event_promoters WHERE event_id=? AND promoter_id=?');$c->execute([$eventId,$promoterId]);if($c->fetchColumn())$pdo->prepare('UPDATE event_promoters SET commission_percent=?,active=? WHERE event_id=? AND promoter_id=? AND tenant_id=?')->execute([$commissionPercent,$active?1:0,$eventId,$promoterId,$tenantId]);else$pdo->prepare('INSERT INTO event_promoters (tenant_id,event_id,promoter_id,commission_percent,active) VALUES (?,?,?,?,?)')->execute([$tenantId,$eventId,$promoterId,$commissionPercent,$active?1:0]);$this->audit($pdo,$tenantId,$eventId,'event.promoter_link','promoter',(string)$promoterId,['commission_percent'=>$commissionPercent,'active'=>$active]);
    }

    public function linkCoupon(int $eventId,int $couponId,bool $active=true):void
    {
        Auth::requirePermission('events.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$c=$pdo->prepare('SELECT id FROM coupons WHERE id=? AND tenant_id=?');$c->execute([$couponId,$tenantId]);if(!$c->fetchColumn())throw new RuntimeException('Cupom inválido.');$x=$pdo->prepare('SELECT coupon_id FROM event_coupons WHERE event_id=? AND coupon_id=?');$x->execute([$eventId,$couponId]);if($x->fetchColumn())$pdo->prepare('UPDATE event_coupons SET active=? WHERE event_id=? AND coupon_id=? AND tenant_id=?')->execute([$active?1:0,$eventId,$couponId,$tenantId]);else$pdo->prepare('INSERT INTO event_coupons (tenant_id,event_id,coupon_id,active) VALUES (?,?,?,?)')->execute([$tenantId,$eventId,$couponId,$active?1:0]);$this->audit($pdo,$tenantId,$eventId,'event.coupon_link','coupon',(string)$couponId,['active'=>$active]);
    }

    public function dashboard(int $eventId):array
    {
        $event=$this->event($eventId);$tenantId=(int)$event['tenant_id'];$pdo=Database::connection();$types=$pdo->prepare('SELECT tt.*,(SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.event_id=tt.event_id AND b.ticket_type_id=tt.id AND t.status IN ("reserved","paid","checked_in")) used_count FROM ticket_types tt WHERE tt.tenant_id=? AND tt.event_id=? ORDER BY tt.sort_order,tt.id');$types->execute([$tenantId,$eventId]);$b=$pdo->prepare('SELECT b.*,tt.name ticket_type_name FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.event_id=? ORDER BY b.id');$b->execute([$eventId]);$tickets=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN status IN ("paid","checked_in") THEN 1 ELSE 0 END) sold,SUM(CASE WHEN status="checked_in" THEN 1 ELSE 0 END) checkins,SUM(CASE WHEN status="reserved" THEN 1 ELSE 0 END) reserved FROM tickets WHERE tenant_id=? AND event_id=?');$tickets->execute([$tenantId,$eventId]);$ticketStats=$tickets->fetch()?:[];$sales=$pdo->prepare('SELECT COALESCE(SUM(o.total_cents),0) revenue,COUNT(DISTINCT o.id) orders_count FROM orders o WHERE o.tenant_id=? AND o.channel="event" AND o.payment_status="paid" AND EXISTS (SELECT 1 FROM tickets t WHERE t.order_id=o.id AND t.event_id=?)');$sales->execute([$tenantId,$eventId]);$salesStats=$sales->fetch()?:[];$promoters=$pdo->prepare('SELECT ep.*,p.name,p.code,p.commission_percent default_commission FROM event_promoters ep JOIN promoters p ON p.id=ep.promoter_id WHERE ep.tenant_id=? AND ep.event_id=? ORDER BY p.name');$promoters->execute([$tenantId,$eventId]);$coupons=$pdo->prepare('SELECT ec.*,c.code,c.type,c.value,c.uses_count FROM event_coupons ec JOIN coupons c ON c.id=ec.coupon_id WHERE ec.tenant_id=? AND ec.event_id=? ORDER BY c.code');$coupons->execute([$tenantId,$eventId]);$audit=$pdo->prepare('SELECT ea.*,u.name user_name FROM event_audit_events ea LEFT JOIN users u ON u.id=ea.user_id WHERE ea.tenant_id=? AND ea.event_id=? ORDER BY ea.id DESC LIMIT 100');$audit->execute([$tenantId,$eventId]);return ['event'=>$event,'types'=>$types->fetchAll(),'batches'=>$b->fetchAll(),'ticket_stats'=>$ticketStats,'sales_stats'=>$salesStats,'promoters'=>$promoters->fetchAll(),'coupons'=>$coupons->fetchAll(),'audit'=>$audit->fetchAll()];
    }

    private function color(mixed $value):?string{$v=strtolower(trim((string)$value));return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:null;}
    private function coordinate(mixed $value,float $min,float $max):?float{$v=trim((string)$value);if($v==='')return null;$n=(float)str_replace(',','.',$v);if(!is_finite($n)||$n<$min||$n>$max)throw new RuntimeException('Coordenada de mapa inválida.');return $n;}
    private function audit(PDO $pdo,int $tenantId,int $eventId,string $action,?string $entityType,?string $entityId,array $metadata):void{$pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,Auth::id(),$action,$entityType,$entityId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);}
}
