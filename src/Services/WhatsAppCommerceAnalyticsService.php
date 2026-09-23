<?php

declare(strict_types=1);

namespace EventMenu\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class WhatsAppCommerceAnalyticsService
{
    private const DEFAULT_TIMEZONE='America/Sao_Paulo';
    private const TIMEZONES=['America/Noronha','America/Sao_Paulo','America/Cuiaba','America/Manaus','America/Rio_Branco'];

    public function track(PDO $pdo,int $tenantId,string $eventType,?int $conversationId=null,?int $orderId=null,int $valueCents=0,array $metadata=[],string $idempotencyKey=''):bool
    {
        if($tenantId<1)return false;
        $eventType=strtolower(trim($eventType));
        if($eventType===''||!preg_match('/^[a-z0-9_.-]{2,64}$/',$eventType))return false;
        $conversationId=$conversationId&&$conversationId>0?$conversationId:null;
        $orderId=$orderId&&$orderId>0?$orderId:null;
        $valueCents=max(0,$valueCents);
        $metadataJson=null;
        if($metadata){
            try{$metadataJson=json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable){$metadataJson=null;}
            if(is_string($metadataJson)&&mb_strlen($metadataJson)>8000)$metadataJson=mb_substr($metadataJson,0,8000);
        }
        $key=trim($idempotencyKey)!==''?hash('sha256',$idempotencyKey):null;
        try{$q=$pdo->prepare('INSERT INTO whatsapp_commerce_events (tenant_id,conversation_id,order_id,event_type,value_cents,metadata,idempotency_key) VALUES (?,?,?,?,?,?,?)');$q->execute([$tenantId,$conversationId,$orderId,$eventType,$valueCents,$metadataJson,$key]);return true;}catch(Throwable){return false;}
    }

    /** @return array<string,mixed> */
    public function settings(PDO $pdo,int $tenantId):array
    {
        $timezone=self::DEFAULT_TIMEZONE;
        try{$q=$pdo->prepare('SELECT timezone FROM whatsapp_commerce_analytics_settings WHERE tenant_id=? LIMIT 1');$q->execute([$tenantId]);$value=trim((string)($q->fetchColumn()?:''));if(in_array($value,self::TIMEZONES,true))$timezone=$value;}catch(Throwable){}
        return['timezone'=>$timezone,'allowed_timezones'=>self::TIMEZONES];
    }

    /** @return array<string,mixed> */
    public function saveSettings(PDO $pdo,int $tenantId,array $input):array
    {
        if($tenantId<1)throw new \RuntimeException('Empresa inválida.');
        $timezone=trim((string)($input['timezone']??''));if(!in_array($timezone,self::TIMEZONES,true))throw new \RuntimeException('Fuso horário inválido.');
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite')$sql='INSERT INTO whatsapp_commerce_analytics_settings (tenant_id,timezone,created_at,updated_at) VALUES (?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id) DO UPDATE SET timezone=excluded.timezone,updated_at=CURRENT_TIMESTAMP';
        else $sql='INSERT INTO whatsapp_commerce_analytics_settings (tenant_id,timezone) VALUES (?,?) ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),updated_at=CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute([$tenantId,$timezone]);return$this->settings($pdo,$tenantId);
    }

    /** @return array<string,mixed> */
    public function report(PDO $pdo,int $tenantId,string $period='7d',?string $customFrom=null,?string $customTo=null):array
    {
        $settings=$this->settings($pdo,$tenantId);$timezone=(string)$settings['timezone'];
        [$from,$to,$label]=$this->periodBounds($period,$customFrom,$customTo,$timezone);
        $current=$this->collect($pdo,$tenantId,$from,$to,$timezone);
        $seconds=max(1,$to->getTimestamp()-$from->getTimestamp());$previousTo=$from;$previousFrom=$from->modify('-'.$seconds.' seconds');
        $previous=$this->collect($pdo,$tenantId,$previousFrom,$previousTo,$timezone,false);
        $previousActivity=(int)$previous['funnel']['conversations']+(int)$previous['funnel']['carts_started']+(int)$previous['messages']['outbound_total'];
        $salesNow=(int)$current['sales']['paid_sales_cents'];$salesBefore=(int)$previous['sales']['paid_sales_cents'];
        $delta=null;if($previousActivity>0&&$salesBefore>0)$delta=round((($salesNow-$salesBefore)/$salesBefore)*100,1);
        $current['period']=['key'=>$period,'label'=>$label,'timezone'=>$timezone,'from_utc'=>$from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'to_utc'=>$to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'from_local'=>$from->format('Y-m-d H:i:s'),'to_local'=>$to->format('Y-m-d H:i:s')];
        $current['comparison']=['available'=>$previousActivity>0,'paid_sales_cents'=>$salesBefore,'paid_orders'=>(int)$previous['funnel']['paid_orders'],'conversations'=>(int)$previous['funnel']['conversations'],'sales_change_pct'=>$delta,'from_local'=>$previousFrom->format('Y-m-d H:i:s'),'to_local'=>$previousTo->format('Y-m-d H:i:s')];
        return$current;
    }

    /** @return array<string,mixed> */
    private function collect(PDO $pdo,int $tenantId,DateTimeImmutable $from,DateTimeImmutable $to,string $timezone,bool $details=true):array
    {
        $utc=new DateTimeZone('UTC');$fromDb=$from->setTimezone($utc)->format('Y-m-d H:i:s');$toDb=$to->setTimezone($utc)->format('Y-m-d H:i:s');
        $range='created_at>=? AND created_at<?';
        $conversations=$this->scalar($pdo,"SELECT COUNT(*) FROM whatsapp_conversations WHERE tenant_id=? AND {$range}",[$tenantId,$fromDb,$toDb]);
        $customers=$this->scalar($pdo,"SELECT COUNT(DISTINCT phone) FROM whatsapp_conversations WHERE tenant_id=? AND {$range}",[$tenantId,$fromDb,$toDb]);
        $inbound=$this->scalar($pdo,"SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND direction='inbound' AND {$range}",[$tenantId,$fromDb,$toDb]);
        $humanOpen=$this->scalar($pdo,"SELECT COUNT(*) FROM whatsapp_conversations WHERE tenant_id=? AND mode IN ('waiting_human','human')",[$tenantId]);

        $orderQ=$pdo->prepare("SELECT COUNT(*) carts_started,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') THEN 1 ELSE 0 END),0) finalized,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') AND payment_status='paid' THEN 1 ELSE 0 END),0) paid_orders,COALESCE(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END),0) completed_orders,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') AND payment_status='paid' THEN total_cents ELSE 0 END),0) paid_sales_cents FROM orders WHERE tenant_id=? AND order_source='WHATSAPP' AND created_at>=? AND created_at<?");
        $orderQ->execute([$tenantId,$fromDb,$toDb]);$orderStats=$orderQ->fetch(PDO::FETCH_ASSOC)?:[];
        $carts=max(0,(int)($orderStats['carts_started']??0));$finalized=max(0,(int)($orderStats['finalized']??0));$paid=max(0,(int)($orderStats['paid_orders']??0));$completed=max(0,(int)($orderStats['completed_orders']??0));$sales=max(0,(int)($orderStats['paid_sales_cents']??0));

        $outQ=$pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END),0) sent,COALESCE(SUM(CASE WHEN status IN ('desktop_queued','queued') THEN 1 ELSE 0 END),0) waiting,COALESCE(SUM(CASE WHEN status IN ('desktop_sending','sending','processing') THEN 1 ELSE 0 END),0) processing,COALESCE(SUM(CASE WHEN status IN ('desktop_failed','failed') AND attempt_count<max_attempts THEN 1 ELSE 0 END),0) retrying,COALESCE(SUM(CASE WHEN status IN ('desktop_failed','failed') AND attempt_count>=max_attempts THEN 1 ELSE 0 END),0) failed,COALESCE(SUM(attempt_count),0) attempts,MIN(CASE WHEN status<>'sent' AND NOT(status IN ('desktop_failed','failed') AND attempt_count>=max_attempts) THEN created_at ELSE NULL END) oldest_pending FROM whatsapp_outbox WHERE tenant_id=? AND created_at>=? AND created_at<?");
        $outQ->execute([$tenantId,$fromDb,$toDb]);$out=$outQ->fetch(PDO::FETCH_ASSOC)?:[];

        $abandoned=$this->scalar($pdo,"SELECT COUNT(DISTINCT order_id) FROM whatsapp_outbox WHERE tenant_id=? AND event_type='cart_abandoned' AND order_id IS NOT NULL AND created_at>=? AND created_at<?",[$tenantId,$fromDb,$toDb]);
        $remindersSent=$this->scalar($pdo,"SELECT COUNT(DISTINCT order_id) FROM whatsapp_outbox WHERE tenant_id=? AND event_type='cart_abandoned' AND status='sent' AND order_id IS NOT NULL AND created_at>=? AND created_at<?",[$tenantId,$fromDb,$toDb]);
        $recovered=$this->scalar($pdo,"SELECT COUNT(DISTINCT w.order_id) FROM whatsapp_outbox w JOIN orders o ON o.id=w.order_id AND o.tenant_id=w.tenant_id WHERE w.tenant_id=? AND w.event_type='cart_abandoned' AND w.order_id IS NOT NULL AND w.created_at>=? AND w.created_at<? AND o.status NOT IN ('draft','cancelled') AND o.updated_at>w.created_at",[$tenantId,$fromDb,$toDb]);
        $recoveredPaid=$this->scalar($pdo,"SELECT COUNT(DISTINCT w.order_id) FROM whatsapp_outbox w JOIN orders o ON o.id=w.order_id AND o.tenant_id=w.tenant_id WHERE w.tenant_id=? AND w.event_type='cart_abandoned' AND w.order_id IS NOT NULL AND w.created_at>=? AND w.created_at<? AND o.status NOT IN ('draft','cancelled') AND o.payment_status='paid' AND o.updated_at>w.created_at",[$tenantId,$fromDb,$toDb]);
        $recoveredValue=$this->scalar($pdo,"SELECT COALESCE(SUM(x.total_cents),0) FROM (SELECT DISTINCT o.id,o.total_cents FROM whatsapp_outbox w JOIN orders o ON o.id=w.order_id AND o.tenant_id=w.tenant_id WHERE w.tenant_id=? AND w.event_type='cart_abandoned' AND w.order_id IS NOT NULL AND w.created_at>=? AND w.created_at<? AND o.status NOT IN ('draft','cancelled') AND o.payment_status='paid' AND o.updated_at>w.created_at) x",[$tenantId,$fromDb,$toDb]);
        $returned=$this->scalar($pdo,"SELECT COUNT(DISTINCT w.order_id) FROM whatsapp_outbox w JOIN whatsapp_conversations c ON c.tenant_id=w.tenant_id AND (c.draft_order_id=w.order_id OR c.active_order_id=w.order_id) WHERE w.tenant_id=? AND w.event_type='cart_abandoned' AND w.order_id IS NOT NULL AND w.created_at>=? AND w.created_at<? AND EXISTS (SELECT 1 FROM whatsapp_messages m WHERE m.tenant_id=w.tenant_id AND m.conversation_id=c.id AND m.direction='inbound' AND m.created_at>w.created_at)",[$tenantId,$fromDb,$toDb]);
        $openDrafts=$this->scalar($pdo,"SELECT COUNT(*) FROM orders o WHERE o.tenant_id=? AND o.order_source='WHATSAPP' AND o.status='draft' AND o.created_at>=? AND o.created_at<? AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id)",[$tenantId,$fromDb,$toDb]);

        $repeatStarted=0;$repeatFinalized=0;$repeatCompleted=0;$repeatRevenue=0;$upsellOffers=0;$upsellAcceptedItems=0;$upsellOrders=0;$upsellPaidValue=0;$analyticsSince=null;$topUpsell=[];
        try{
            $repeatStarted=$this->eventScalar($pdo,$tenantId,$fromDb,$toDb,"event_type='repeat_order'");
            $repeatFinalized=$this->scalar($pdo,"SELECT COUNT(DISTINCT e.order_id) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='repeat_order' AND e.created_at>=? AND e.created_at<? AND o.status NOT IN ('draft','cancelled')",[$tenantId,$fromDb,$toDb]);
            $repeatCompleted=$this->scalar($pdo,"SELECT COUNT(DISTINCT e.order_id) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='repeat_order' AND e.created_at>=? AND e.created_at<? AND o.status='completed'",[$tenantId,$fromDb,$toDb]);
            $repeatRevenue=$this->scalar($pdo,"SELECT COALESCE(SUM(x.total_cents),0) FROM (SELECT DISTINCT o.id,o.total_cents FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='repeat_order' AND e.created_at>=? AND e.created_at<? AND o.status NOT IN ('draft','cancelled') AND o.payment_status='paid') x",[$tenantId,$fromDb,$toDb]);
            $upsellOffers=$this->scalar($pdo,"SELECT COUNT(DISTINCT order_id) FROM whatsapp_commerce_events WHERE tenant_id=? AND event_type='upsell_offer' AND created_at>=? AND created_at<? AND order_id IS NOT NULL",[$tenantId,$fromDb,$toDb]);
            $upsellAcceptedItems=$this->eventScalar($pdo,$tenantId,$fromDb,$toDb,"event_type='upsell_accepted'");
            $upsellOrders=$this->scalar($pdo,"SELECT COUNT(DISTINCT e.order_id) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='upsell_accepted' AND e.created_at>=? AND e.created_at<? AND o.status NOT IN ('draft','cancelled')",[$tenantId,$fromDb,$toDb]);
            $upsellPaidValue=$this->scalar($pdo,"SELECT COALESCE(SUM(e.value_cents),0) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='upsell_accepted' AND e.created_at>=? AND e.created_at<? AND o.status NOT IN ('draft','cancelled') AND o.payment_status='paid'",[$tenantId,$fromDb,$toDb]);
            if($details)$topUpsell=$this->topUpsell($pdo,$tenantId,$fromDb,$toDb);
            $min=$pdo->prepare('SELECT MIN(created_at) FROM whatsapp_commerce_events WHERE tenant_id=?');$min->execute([$tenantId]);$analyticsSince=$min->fetchColumn()?:null;
        }catch(Throwable){}

        $human=$this->humanMetrics($pdo,$tenantId,$fromDb,$toDb);
        $top=[];$daily=[];
        if($details){
            try{$q=$pdo->prepare("SELECT oi.name_snapshot name,COALESCE(SUM(oi.quantity),0) quantity,COALESCE(SUM(oi.total_cents),0) total_cents FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.tenant_id=? AND o.order_source='WHATSAPP' AND o.status NOT IN ('draft','cancelled') AND o.created_at>=? AND o.created_at<? GROUP BY oi.name_snapshot ORDER BY total_cents DESC,quantity DESC LIMIT 5");$q->execute([$tenantId,$fromDb,$toDb]);$top=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
            try{$q=$pdo->prepare("SELECT DATE(created_at) day,COUNT(*) orders_count,COALESCE(SUM(CASE WHEN payment_status='paid' AND status NOT IN ('draft','cancelled') THEN total_cents ELSE 0 END),0) paid_sales_cents FROM orders WHERE tenant_id=? AND order_source='WHATSAPP' AND created_at>=? AND created_at<? AND status NOT IN ('draft','cancelled') GROUP BY DATE(created_at) ORDER BY day ASC LIMIT 62");$q->execute([$tenantId,$fromDb,$toDb]);$daily=$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){}
        }

        return[
            'generated_at'=>gmdate('Y-m-d H:i:s'),'analytics_since'=>$analyticsSince,
            'funnel'=>['conversations'=>$conversations,'customers'=>$customers,'carts_started'=>$carts,'finalized_orders'=>$finalized,'paid_orders'=>$paid,'completed_orders'=>$completed,'conversation_to_order_pct'=>$this->rate($finalized,$conversations),'cart_to_order_pct'=>$this->rate($finalized,$carts),'order_to_paid_pct'=>$this->rate($paid,$finalized),'paid_to_completed_pct'=>$this->rate($completed,$paid)],
            'sales'=>['paid_sales_cents'=>$sales,'ticket_average_cents'=>$paid>0?(int)round($sales/$paid):0,'repeat_started'=>$repeatStarted,'repeat_finalized'=>$repeatFinalized,'repeat_completed'=>$repeatCompleted,'repeat_paid_sales_cents'=>$repeatRevenue],
            'recovery'=>['abandoned'=>$abandoned,'reminders_created'=>$abandoned,'reminders_sent'=>$remindersSent,'customers_returned'=>$returned,'recovered'=>$recovered,'recovered_paid_orders'=>$recoveredPaid,'recovered_paid_sales_cents'=>$recoveredValue,'open_drafts'=>$openDrafts,'recovery_pct'=>$this->rate($recovered,$abandoned)],
            'upsell'=>['offers'=>$upsellOffers,'accepted_items'=>$upsellAcceptedItems,'orders_with_upsell'=>$upsellOrders,'paid_value_cents'=>$upsellPaidValue,'acceptance_pct'=>$this->rate($upsellOrders,$upsellOffers),'top_products'=>$topUpsell],
            'human'=>$human,
            'messages'=>['inbound'=>$inbound,'outbound_total'=>(int)($out['total']??0),'sent'=>(int)($out['sent']??0),'waiting'=>(int)($out['waiting']??0),'processing'=>(int)($out['processing']??0),'retrying'=>(int)($out['retrying']??0),'failed'=>(int)($out['failed']??0),'attempts'=>(int)($out['attempts']??0),'oldest_pending'=>$out['oldest_pending']??null,'human_open'=>$humanOpen],
            'top_products'=>$top,'daily'=>$daily,
        ];
    }

    /** @return array<string,mixed> */
    private function humanMetrics(PDO $pdo,int $tenantId,string $from,string $to):array
    {
        $transferred=$this->scalar($pdo,"SELECT COUNT(*) FROM audit_logs WHERE tenant_id=? AND action='whatsapp.conversation_waiting_human' AND created_at>=? AND created_at<?",[$tenantId,$from,$to]);
        $assumed=$this->scalar($pdo,"SELECT COUNT(*) FROM audit_logs WHERE tenant_id=? AND action='whatsapp.support_assumed' AND created_at>=? AND created_at<?",[$tenantId,$from,$to]);
        $manual=$this->scalar($pdo,"SELECT COUNT(*) FROM audit_logs WHERE tenant_id=? AND action='whatsapp.support_message_queued' AND created_at>=? AND created_at<?",[$tenantId,$from,$to]);
        $avg=null;
        try{$q=$pdo->prepare("SELECT entity_id,action,created_at FROM audit_logs WHERE tenant_id=? AND action IN ('whatsapp.conversation_waiting_human','whatsapp.support_assumed') AND created_at>=? AND created_at<? ORDER BY created_at,id");$q->execute([$tenantId,$from,$to]);$waiting=[];$samples=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as$row){$id=(string)($row['entity_id']??'');$ts=strtotime((string)($row['created_at']??''));if($id===''||$ts===false)continue;if($row['action']==='whatsapp.conversation_waiting_human')$waiting[$id]=$ts;elseif(isset($waiting[$id])&&$ts>=$waiting[$id]){$samples[]=$ts-$waiting[$id];unset($waiting[$id]);}}if($samples)$avg=(int)round(array_sum($samples)/count($samples));}catch(Throwable){}
        return['transferred'=>$transferred,'assumed'=>$assumed,'manual_messages'=>$manual,'average_wait_seconds'=>$avg];
    }

    /** @return array<int,array<string,mixed>> */
    private function topUpsell(PDO $pdo,int $tenantId,string $from,string $to):array
    {
        $q=$pdo->prepare("SELECT e.metadata,e.value_cents,o.payment_status,o.status FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='upsell_accepted' AND e.created_at>=? AND e.created_at<?");$q->execute([$tenantId,$from,$to]);$groups=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as$row){$meta=json_decode((string)($row['metadata']??''),true);if(!is_array($meta))continue;$id=(int)($meta['product_id']??0);$name=trim((string)($meta['product_name']??''));if($id<1&&$name==='')continue;$key=$id>0?'id:'.$id:'name:'.$name;if(!isset($groups[$key]))$groups[$key]=['product_name'=>$name!==''?$name:'Produto #'.$id,'accepted'=>0,'paid_value_cents'=>0];$groups[$key]['accepted']++;if((string)$row['payment_status']==='paid'&&!in_array((string)$row['status'],['draft','cancelled'],true))$groups[$key]['paid_value_cents']+=(int)$row['value_cents'];}
        usort($groups,fn(array$a,array$b):int=>($b['paid_value_cents']<=>$a['paid_value_cents'])?:($b['accepted']<=>$a['accepted']));return array_slice($groups,0,5);
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string} */
    private function periodBounds(string $period,?string $customFrom,?string $customTo,string $timezone):array
    {
        $tz=new DateTimeZone($timezone);$now=new DateTimeImmutable('now',$tz);$today=$now->setTime(0,0,0);$period=strtolower(trim($period));
        return match($period){
            'today'=>[$today,$now,'Hoje'],
            'yesterday'=>[$today->modify('-1 day'),$today,'Ontem'],
            '30d'=>[$today->modify('-29 days'),$now,'Últimos 30 dias'],
            'month'=>[$today->modify('first day of this month'),$now,'Este mês'],
            'custom'=>$this->customBounds($customFrom,$customTo,$tz,$now),
            default=>[$today->modify('-6 days'),$now,'Últimos 7 dias'],
        };
    }

    /** @return array{0:DateTimeImmutable,1:DateTimeImmutable,2:string} */
    private function customBounds(?string $from,?string $to,DateTimeZone $tz,DateTimeImmutable $now):array
    {
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$from,$tz);$b=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$to,$tz);if(!$a||!$b||$b<$a||($b->getTimestamp()-$a->getTimestamp())>366*86400)return[$now->setTime(0,0)->modify('-6 days'),$now,'Últimos 7 dias'];$end=$b->modify('+1 day');if($end>$now)$end=$now;return[$a,$end,'Período personalizado'];
    }

    private function eventScalar(PDO $pdo,int $tenantId,string $from,string $to,string $where):int{return$this->scalar($pdo,'SELECT COUNT(*) FROM whatsapp_commerce_events WHERE tenant_id=? AND created_at>=? AND created_at<? AND '.$where,[$tenantId,$from,$to]);}
    private function scalar(PDO $pdo,string $sql,array $args):int{try{$q=$pdo->prepare($sql);$q->execute($args);return max(0,(int)($q->fetchColumn()?:0));}catch(Throwable){return 0;}}
    private function rate(int $part,int $total):float{return$total<1?0.0:round(($part/$total)*100,1);}
}
