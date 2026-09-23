<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class EventProfessionalService
{
    public function event(int $eventId): array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId||$eventId<1)throw new RuntimeException('Evento inválido.');
        $s=Database::connection()->prepare('SELECT e.*,t.slug tenant_slug,t.name tenant_name FROM events e JOIN tenants t ON t.id=e.tenant_id WHERE e.id=? AND e.tenant_id=?');
        $s->execute([$eventId,$tenantId]);
        $event=$s->fetch();
        if(!$event)throw new RuntimeException('Evento não encontrado.');
        return $event;
    }

    public function savePublicSettings(int $eventId,array $data): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);

        $type=mb_substr(trim((string)($data['event_type']??'general')),0,40)?:'general';
        $subtitle=mb_substr(trim((string)($data['public_subtitle']??'')),0,255);
        $capacity=(int)($data['capacity_total']??0);
        $capacity=$capacity>0?$capacity:null;
        $primary=$this->color($data['primary_color']??'');
        $secondary=$this->color($data['secondary_color']??'');
        $text=$this->color($data['text_color']??'');
        $map=mb_substr(trim((string)($data['map_url']??'')),0,700);
        $lat=$this->coordinate($data['latitude']??null,-90,90);
        $lng=$this->coordinate($data['longitude']??null,-180,180);
        $sales=!empty($data['sales_enabled'])?1:0;
        $bar=!empty($data['bar_enabled'])?1:0;
        if($map!==''&&!filter_var($map,FILTER_VALIDATE_URL))throw new RuntimeException('Link do mapa inválido.');

        $pdo=Database::connection();
        if($capacity!==null&&$this->eventOccupancy($pdo,$tenantId,$eventId)>$capacity){
            throw new RuntimeException('Capacidade não pode ser menor que ingressos, convidados e acompanhantes já comprometidos.');
        }
        $pdo->prepare('UPDATE events SET event_type=?,public_subtitle=?,capacity_total=?,primary_color=?,secondary_color=?,text_color=?,map_url=?,latitude=?,longitude=?,sales_enabled=?,bar_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$type,$subtitle?:null,$capacity,$primary,$secondary,$text,$map?:null,$lat,$lng,$sales,$bar,$eventId,$tenantId]);
        $this->audit($pdo,$tenantId,$eventId,'event.public_settings','event',(string)$eventId,['capacity_total'=>$capacity,'sales_enabled'=>$sales,'bar_enabled'=>$bar]);
        Auth::audit('event.public_settings','event',(string)$eventId,['capacity_total'=>$capacity]);
    }

    public function saveTicketType(int $eventId,array $data): int
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        $id=(int)($data['id']??0);
        $name=mb_substr(trim((string)($data['name']??'')),0,160);
        $description=mb_substr(trim((string)($data['description']??'')),0,500);
        $area=mb_substr(trim((string)($data['access_area']??'')),0,160);
        $cap=(int)($data['capacity_total']??0);
        $cap=$cap>0?$cap:null;
        $sort=(int)($data['sort_order']??0);
        $active=!empty($data['active'])?1:0;
        if($name==='')throw new RuntimeException('Nome do tipo de ingresso é obrigatório.');
        $pdo=Database::connection();
        if($id){
            if($cap!==null){
                $u=$pdo->prepare('SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id=? AND t.status IN ("reserved","paid","checked_in")');
                $u->execute([$tenantId,$eventId,$id]);
                if((int)$u->fetchColumn()>$cap)throw new RuntimeException('Capacidade do tipo não pode ser menor que ingressos já emitidos/reservados.');
            }
            $s=$pdo->prepare('UPDATE ticket_types SET name=?,description=?,access_area=?,capacity_total=?,sort_order=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND event_id=?');
            $s->execute([$name,$description?:null,$area?:null,$cap,$sort,$active,$id,$tenantId,$eventId]);
            if($s->rowCount()===0){
                $check=$pdo->prepare('SELECT id FROM ticket_types WHERE id=? AND tenant_id=? AND event_id=?');
                $check->execute([$id,$tenantId,$eventId]);
                if(!$check->fetchColumn())throw new RuntimeException('Tipo de ingresso não encontrado.');
            }
        }else{
            $s=$pdo->prepare('INSERT INTO ticket_types (tenant_id,event_id,name,description,access_area,capacity_total,sort_order,active) VALUES (?,?,?,?,?,?,?,?)');
            $s->execute([$tenantId,$eventId,$name,$description?:null,$area?:null,$cap,$sort,$active]);
            $id=(int)$pdo->lastInsertId();
        }
        $this->audit($pdo,$tenantId,$eventId,'ticket_type.saved','ticket_type',(string)$id,['name'=>$name,'capacity_total'=>$cap,'active'=>$active]);
        Auth::audit('ticket_type.saved','ticket_type',(string)$id,['event_id'=>$eventId]);
        return $id;
    }

    public function saveBatch(int $eventId,array $data): int
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);

        $batchId=max(0,(int)($data['batch_id']??0));
        $name=mb_substr(trim((string)($data['name']??'')),0,160);
        $price=$this->moneyToCents($data['price']??'0');
        $qty=(int)($data['quantity_total']??0);
        $typeId=(int)($data['ticket_type_id']??0);
        $typeId=$typeId>0?$typeId:null;
        $start=$this->dateTimeOrNull($data['sales_start']??null);
        $end=$this->dateTimeOrNull($data['sales_end']??null);
        $active=!empty($data['active'])?1:0;

        if($name==='')throw new RuntimeException('Informe o nome do lote.');
        if($price<0)throw new RuntimeException('Informe um preço válido para o lote.');
        if($qty<1)throw new RuntimeException('A quantidade do lote deve ser maior que zero.');
        if($start&&$end&&strtotime($end)<=strtotime($start))throw new RuntimeException('O fim da venda deve ser posterior ao início.');

        $id=Database::transaction(function(PDO $pdo)use($tenantId,$eventId,$batchId,$name,$price,$qty,$typeId,$start,$end,$active): int {
            $e=$pdo->prepare(Database::portableSql($pdo,'SELECT status FROM events WHERE id=? AND tenant_id=? FOR UPDATE'));
            $e->execute([$eventId,$tenantId]);
            $status=$e->fetchColumn();
            if(!$status)throw new RuntimeException('Evento inválido.');
            if($active&&in_array((string)$status,['cancelled','closed'],true))throw new RuntimeException('Evento encerrado ou cancelado não aceita lote ativo.');

            $current=null;
            if($batchId>0){
                $b=$pdo->prepare(Database::portableSql($pdo,'SELECT b.* FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=? FOR UPDATE'));
                $b->execute([$batchId,$eventId,$tenantId]);
                $current=$b->fetch();
                if(!$current)throw new RuntimeException('Lote não encontrado.');
                $committed=(int)$current['quantity_sold']+(int)$current['quantity_reserved'];
                if($qty<$committed)throw new RuntimeException('A quantidade não pode ser menor que '.$committed.' ingresso(s) já vendido(s) ou reservado(s).');
            }

            if($typeId!==null)$this->validateBatchTypeCapacity($pdo,$tenantId,$eventId,$typeId,$batchId>0?$batchId:null,$current);

            if($batchId>0){
                $pdo->prepare('UPDATE ticket_batches SET ticket_type_id=?,name=?,price_cents=?,quantity_total=?,sales_start=?,sales_end=?,active=? WHERE id=? AND event_id=?')->execute([$typeId,$name,$price,$qty,$start,$end,$active,$batchId,$eventId]);
                return $batchId;
            }
            $s=$pdo->prepare('INSERT INTO ticket_batches (event_id,ticket_type_id,name,price_cents,quantity_total,sales_start,sales_end,active) VALUES (?,?,?,?,?,?,?,?)');
            $s->execute([$eventId,$typeId,$name,$price,$qty,$start,$end,$active]);
            return (int)$pdo->lastInsertId();
        });

        $pdo=Database::connection();
        $this->audit($pdo,$tenantId,$eventId,$batchId>0?'ticket_batch.updated':'ticket_batch.created','ticket_batch',(string)$id,['name'=>$name,'quantity_total'=>$qty,'price_cents'=>$price,'ticket_type_id'=>$typeId,'active'=>$active,'sales_start'=>$start,'sales_end'=>$end]);
        Auth::audit($batchId>0?'ticket_batch.updated':'ticket_batch.created','ticket_batch',(string)$id,['event_id'=>$eventId,'quantity_total'=>$qty,'price_cents'=>$price,'active'=>$active]);
        return $id;
    }

    public function setBatchActive(int $eventId,int $batchId,bool $active): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $event=$this->event($eventId);
        if($active&&in_array((string)$event['status'],['cancelled','closed'],true))throw new RuntimeException('Evento encerrado ou cancelado não aceita lote ativo.');
        $pdo=Database::connection();
        $s=$pdo->prepare('UPDATE ticket_batches SET active=? WHERE id=? AND event_id=? AND EXISTS (SELECT 1 FROM events e WHERE e.id=ticket_batches.event_id AND e.tenant_id=?)');
        $s->execute([$active?1:0,$batchId,$eventId,$tenantId]);
        if($s->rowCount()===0){
            $c=$pdo->prepare('SELECT b.id FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=?');
            $c->execute([$batchId,$eventId,$tenantId]);
            if(!$c->fetchColumn())throw new RuntimeException('Lote não encontrado.');
        }
        $this->audit($pdo,$tenantId,$eventId,'ticket_batch.active','ticket_batch',(string)$batchId,['active'=>$active]);
        Auth::audit('ticket_batch.active','ticket_batch',(string)$batchId,['event_id'=>$eventId,'active'=>$active]);
    }

    public function duplicateBatch(int $eventId,int $batchId): int
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        $newId=Database::transaction(function(PDO $pdo)use($tenantId,$eventId,$batchId): int {
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT b.* FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=? FOR UPDATE'));
            $s->execute([$batchId,$eventId,$tenantId]);
            $b=$s->fetch();
            if(!$b)throw new RuntimeException('Lote não encontrado.');
            $name=mb_substr((string)$b['name'].' (cópia)',0,160);
            $i=$pdo->prepare('INSERT INTO ticket_batches (event_id,ticket_type_id,name,price_cents,quantity_total,sales_start,sales_end,active) VALUES (?,?,?,?,?,?,?,0)');
            $i->execute([$eventId,$b['ticket_type_id']?:null,$name,(int)$b['price_cents'],(int)$b['quantity_total'],$b['sales_start']?:null,$b['sales_end']?:null]);
            return (int)$pdo->lastInsertId();
        });
        $pdo=Database::connection();
        $this->audit($pdo,$tenantId,$eventId,'ticket_batch.duplicated','ticket_batch',(string)$newId,['source_batch_id'=>$batchId,'active'=>false]);
        Auth::audit('ticket_batch.duplicated','ticket_batch',(string)$newId,['event_id'=>$eventId,'source_batch_id'=>$batchId]);
        return $newId;
    }

    public function deleteBatch(int $eventId,int $batchId): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        Database::transaction(function(PDO $pdo)use($tenantId,$eventId,$batchId): void {
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT b.* FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=? FOR UPDATE'));
            $s->execute([$batchId,$eventId,$tenantId]);
            $b=$s->fetch();
            if(!$b)throw new RuntimeException('Lote não encontrado.');
            $t=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE batch_id=? AND event_id=? AND tenant_id=?');
            $t->execute([$batchId,$eventId,$tenantId]);
            if((int)$t->fetchColumn()>0||(int)$b['quantity_sold']>0||(int)$b['quantity_reserved']>0)throw new RuntimeException('Este lote possui histórico de ingressos e não pode ser excluído. Desative-o.');
            $pdo->prepare('DELETE FROM ticket_batches WHERE id=? AND event_id=?')->execute([$batchId,$eventId]);
        });
        $pdo=Database::connection();
        $this->audit($pdo,$tenantId,$eventId,'ticket_batch.deleted','ticket_batch',(string)$batchId,[]);
        Auth::audit('ticket_batch.deleted','ticket_batch',(string)$batchId,['event_id'=>$eventId]);
    }

    public function setBatchType(int $eventId,int $batchId,?int $typeId): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        Database::transaction(function(PDO $pdo)use($tenantId,$eventId,$batchId,$typeId): void {
            $b=$pdo->prepare(Database::portableSql($pdo,'SELECT b.* FROM ticket_batches b JOIN events e ON e.id=b.event_id WHERE b.id=? AND b.event_id=? AND e.tenant_id=? FOR UPDATE'));
            $b->execute([$batchId,$eventId,$tenantId]);
            $batch=$b->fetch();
            if(!$batch)throw new RuntimeException('Lote inválido.');
            if($typeId)$this->validateBatchTypeCapacity($pdo,$tenantId,$eventId,$typeId,$batchId,$batch);
            $pdo->prepare('UPDATE ticket_batches SET ticket_type_id=? WHERE id=? AND event_id=?')->execute([$typeId?:null,$batchId,$eventId]);
        });
        $pdo=Database::connection();
        $this->audit($pdo,$tenantId,$eventId,'ticket_batch.type','ticket_batch',(string)$batchId,['ticket_type_id'=>$typeId]);
    }

    public function linkPromoter(int $eventId,int $promoterId,?float $commissionPercent,bool $active=true): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        $pdo=Database::connection();
        $p=$pdo->prepare('SELECT id FROM promoters WHERE id=? AND tenant_id=?');
        $p->execute([$promoterId,$tenantId]);
        if(!$p->fetchColumn())throw new RuntimeException('Promotor inválido.');
        if($commissionPercent!==null&&(!is_finite($commissionPercent)||$commissionPercent<0||$commissionPercent>100))throw new RuntimeException('Comissão inválida.');
        $c=$pdo->prepare('SELECT promoter_id FROM event_promoters WHERE tenant_id=? AND event_id=? AND promoter_id=?');
        $c->execute([$tenantId,$eventId,$promoterId]);
        if($c->fetchColumn())$pdo->prepare('UPDATE event_promoters SET commission_percent=?,active=? WHERE event_id=? AND promoter_id=? AND tenant_id=?')->execute([$commissionPercent,$active?1:0,$eventId,$promoterId,$tenantId]);
        else $pdo->prepare('INSERT INTO event_promoters (tenant_id,event_id,promoter_id,commission_percent,active) VALUES (?,?,?,?,?)')->execute([$tenantId,$eventId,$promoterId,$commissionPercent,$active?1:0]);
        $this->audit($pdo,$tenantId,$eventId,'event.promoter_link','promoter',(string)$promoterId,['commission_percent'=>$commissionPercent,'active'=>$active]);
    }

    public function linkCoupon(int $eventId,int $couponId,bool $active=true): void
    {
        Auth::requirePermission('events.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $this->event($eventId);
        $pdo=Database::connection();
        $c=$pdo->prepare('SELECT id FROM coupons WHERE id=? AND tenant_id=?');
        $c->execute([$couponId,$tenantId]);
        if(!$c->fetchColumn())throw new RuntimeException('Cupom inválido.');
        $x=$pdo->prepare('SELECT coupon_id FROM event_coupons WHERE tenant_id=? AND event_id=? AND coupon_id=?');
        $x->execute([$tenantId,$eventId,$couponId]);
        if($x->fetchColumn())$pdo->prepare('UPDATE event_coupons SET active=? WHERE event_id=? AND coupon_id=? AND tenant_id=?')->execute([$active?1:0,$eventId,$couponId,$tenantId]);
        else $pdo->prepare('INSERT INTO event_coupons (tenant_id,event_id,coupon_id,active) VALUES (?,?,?,?)')->execute([$tenantId,$eventId,$couponId,$active?1:0]);
        $this->audit($pdo,$tenantId,$eventId,'event.coupon_link','coupon',(string)$couponId,['active'=>$active]);
    }

    public function dashboard(int $eventId): array
    {
        $event=$this->event($eventId);
        $tenantId=(int)$event['tenant_id'];
        $pdo=Database::connection();
        $types=$pdo->prepare('SELECT tt.*,(SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=tt.tenant_id AND t.event_id=tt.event_id AND b.ticket_type_id=tt.id AND t.status IN ("reserved","paid","checked_in")) used_count FROM ticket_types tt WHERE tt.tenant_id=? AND tt.event_id=? ORDER BY tt.sort_order,tt.id');
        $types->execute([$tenantId,$eventId]);
        $b=$pdo->prepare('SELECT b.*,tt.name ticket_type_name FROM ticket_batches b LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE b.event_id=? ORDER BY b.id');
        $b->execute([$eventId]);
        $tickets=$pdo->prepare('SELECT COUNT(*) total,SUM(CASE WHEN status IN ("paid","checked_in") THEN 1 ELSE 0 END) sold,SUM(CASE WHEN status="checked_in" THEN 1 ELSE 0 END) checkins,SUM(CASE WHEN status="reserved" THEN 1 ELSE 0 END) reserved FROM tickets WHERE tenant_id=? AND event_id=?');
        $tickets->execute([$tenantId,$eventId]);
        $ticketStats=$tickets->fetch()?:[];
        $sales=$pdo->prepare('SELECT COALESCE(SUM(o.total_cents),0) revenue,COUNT(DISTINCT o.id) orders_count FROM orders o WHERE o.tenant_id=? AND o.channel="event" AND o.payment_status="paid" AND EXISTS (SELECT 1 FROM tickets t WHERE t.order_id=o.id AND t.event_id=?)');
        $sales->execute([$tenantId,$eventId]);
        $salesStats=$sales->fetch()?:[];
        $promoters=$pdo->prepare('SELECT ep.*,p.name,p.code,p.commission_percent default_commission FROM event_promoters ep JOIN promoters p ON p.id=ep.promoter_id WHERE ep.tenant_id=? AND ep.event_id=? ORDER BY p.name');
        $promoters->execute([$tenantId,$eventId]);
        $coupons=$pdo->prepare('SELECT ec.*,c.code,c.type,c.value,c.uses_count FROM event_coupons ec JOIN coupons c ON c.id=ec.coupon_id WHERE ec.tenant_id=? AND ec.event_id=? ORDER BY c.code');
        $coupons->execute([$tenantId,$eventId]);
        $audit=$pdo->prepare('SELECT ea.*,u.name user_name FROM event_audit_events ea LEFT JOIN users u ON u.id=ea.user_id WHERE ea.tenant_id=? AND ea.event_id=? ORDER BY ea.id DESC LIMIT 100');
        $audit->execute([$tenantId,$eventId]);
        return ['event'=>$event,'types'=>$types->fetchAll(),'batches'=>$b->fetchAll(),'ticket_stats'=>$ticketStats,'sales_stats'=>$salesStats,'promoters'=>$promoters->fetchAll(),'coupons'=>$coupons->fetchAll(),'audit'=>$audit->fetchAll()];
    }

    private function validateBatchTypeCapacity(PDO $pdo,int $tenantId,int $eventId,int $typeId,?int $batchId,?array $batch): void
    {
        $t=$pdo->prepare('SELECT id,capacity_total,active FROM ticket_types WHERE id=? AND event_id=? AND tenant_id=?');
        $t->execute([$typeId,$eventId,$tenantId]);
        $type=$t->fetch();
        if(!$type)throw new RuntimeException('Tipo de ingresso inválido.');
        if(!(int)$type['active'])throw new RuntimeException('O tipo de ingresso selecionado está inativo.');
        if($type['capacity_total']===null)return;

        $sql='SELECT COUNT(*) FROM tickets t JOIN ticket_batches b ON b.id=t.batch_id WHERE t.tenant_id=? AND t.event_id=? AND b.ticket_type_id=? AND t.status IN ("reserved","paid","checked_in")';
        $args=[$tenantId,$eventId,$typeId];
        if($batchId!==null){$sql.=' AND b.id<>?';$args[]=$batchId;}
        $u=$pdo->prepare($sql);
        $u->execute($args);
        $usedOther=(int)$u->fetchColumn();
        $batchCommitted=$batch?((int)$batch['quantity_sold']+(int)$batch['quantity_reserved']):0;
        if($usedOther+$batchCommitted>(int)$type['capacity_total'])throw new RuntimeException('O tipo de ingresso escolhido não possui capacidade para os ingressos já vendidos/reservados deste lote.');
    }

    private function eventOccupancy(PDO $pdo,int $tenantId,int $eventId): int
    {
        $t=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("reserved","paid","checked_in")');
        $t->execute([$tenantId,$eventId]);
        $tickets=(int)$t->fetchColumn();
        $g=$pdo->prepare('SELECT COALESCE(SUM(1+COALESCE(plus_ones,0)),0) FROM event_guests WHERE tenant_id=? AND event_id=? AND status IN ("invited","checked_in")');
        $g->execute([$tenantId,$eventId]);
        return $tickets+(int)$g->fetchColumn();
    }

    private function moneyToCents(mixed $value): int
    {
        $raw=trim((string)$value);
        $raw=str_replace(['R$',' '],'',$raw);
        if(str_contains($raw,','))$raw=str_replace(['.',','],['','.'],$raw);
        if($raw===''||!is_numeric($raw))throw new RuntimeException('Informe um preço válido para o lote.');
        $amount=(float)$raw;
        if(!is_finite($amount)||$amount<0)throw new RuntimeException('Informe um preço válido para o lote.');
        return (int)round($amount*100);
    }

    private function dateTimeOrNull(mixed $value): ?string
    {
        $v=trim((string)$value);
        if($v==='')return null;
        $v=str_replace('T',' ',$v);
        if(strtotime($v)===false)throw new RuntimeException('Data ou horário do lote inválido.');
        return $v;
    }

    private function color(mixed $value): ?string
    {
        $v=strtolower(trim((string)$value));
        return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:null;
    }

    private function coordinate(mixed $value,float $min,float $max): ?float
    {
        $v=trim((string)$value);
        if($v==='')return null;
        $normalized=str_replace(',','.',$v);
        if(!preg_match('/^-?(?:\d+(?:\.\d+)?|\.\d+)$/',$normalized))throw new RuntimeException('Coordenada de mapa inválida.');
        $n=(float)$normalized;
        if(!is_finite($n)||$n<$min||$n>$max)throw new RuntimeException('Coordenada de mapa inválida.');
        return $n;
    }

    private function audit(PDO $pdo,int $tenantId,int $eventId,string $action,?string $entityType,?string $entityId,array $metadata): void
    {
        $pdo->prepare('INSERT INTO event_audit_events (tenant_id,event_id,user_id,action,entity_type,entity_id,metadata) VALUES (?,?,?,?,?,?,?)')->execute([$tenantId,$eventId,Auth::id(),$action,$entityType,$entityId,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
    }
}
