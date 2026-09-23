<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use Throwable;

final class WhatsAppCommerceAnalyticsService
{
    private const PERIODS=[1,7,30,90];

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
        try{
            $q=$pdo->prepare('INSERT INTO whatsapp_commerce_events (tenant_id,conversation_id,order_id,event_type,value_cents,metadata,idempotency_key) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$tenantId,$conversationId,$orderId,$eventType,$valueCents,$metadataJson,$key]);
            return true;
        }catch(Throwable){return false;}
    }

    /** @return array<string,mixed> */
    public function report(PDO $pdo,int $tenantId,int $days=7):array
    {
        $days=in_array($days,self::PERIODS,true)?$days:7;
        $from=gmdate('Y-m-d H:i:s',time()-($days*86400));

        $conversations=$this->scalar($pdo,'SELECT COUNT(*) FROM whatsapp_conversations WHERE tenant_id=? AND created_at>=?',[$tenantId,$from]);
        $inbound=$this->scalar($pdo,"SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=? AND direction='inbound' AND created_at>=?",[$tenantId,$from]);
        $humanOpen=$this->scalar($pdo,"SELECT COUNT(*) FROM whatsapp_conversations WHERE tenant_id=? AND mode IN ('waiting_human','human')",[$tenantId]);

        $orderQ=$pdo->prepare("SELECT COUNT(*) carts_started,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') THEN 1 ELSE 0 END),0) orders_placed,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') AND payment_status='paid' THEN 1 ELSE 0 END),0) paid_orders,COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') AND payment_status='paid' THEN total_cents ELSE 0 END),0) paid_sales_cents FROM orders WHERE tenant_id=? AND order_source='WHATSAPP' AND created_at>=?");
        $orderQ->execute([$tenantId,$from]);$orderStats=$orderQ->fetch(PDO::FETCH_ASSOC)?:[];
        $carts=max(0,(int)($orderStats['carts_started']??0));$orders=max(0,(int)($orderStats['orders_placed']??0));$paid=max(0,(int)($orderStats['paid_orders']??0));$sales=max(0,(int)($orderStats['paid_sales_cents']??0));

        $outQ=$pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END),0) sent,COALESCE(SUM(CASE WHEN status='desktop_failed' AND attempt_count>=max_attempts THEN 1 ELSE 0 END),0) terminal_failed FROM whatsapp_outbox WHERE tenant_id=? AND created_at>=?");
        $outQ->execute([$tenantId,$from]);$out=$outQ->fetch(PDO::FETCH_ASSOC)?:[];$outTotal=max(0,(int)($out['total']??0));$sent=max(0,(int)($out['sent']??0));$failed=max(0,(int)($out['terminal_failed']??0));$pending=max(0,$outTotal-$sent-$failed);

        $abandonedReminders=$this->scalar($pdo,"SELECT COUNT(DISTINCT order_id) FROM whatsapp_outbox WHERE tenant_id=? AND event_type='cart_abandoned' AND order_id IS NOT NULL AND created_at>=?",[$tenantId,$from]);
        $recovered=$this->scalar($pdo,"SELECT COUNT(DISTINCT w.order_id) FROM whatsapp_outbox w JOIN orders o ON o.id=w.order_id AND o.tenant_id=w.tenant_id WHERE w.tenant_id=? AND w.event_type='cart_abandoned' AND w.created_at>=? AND o.status NOT IN ('draft','cancelled')",[$tenantId,$from]);
        $openDrafts=$this->scalar($pdo,"SELECT COUNT(*) FROM orders o WHERE o.tenant_id=? AND o.order_source='WHATSAPP' AND o.status='draft' AND o.created_at>=? AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id=o.id)",[$tenantId,$from]);

        $repeatStarted=0;$repeatFinalized=0;$upsellOffers=0;$upsellAcceptedItems=0;$upsellOrders=0;$upsellPaidValue=0;$analyticsSince=null;
        try{
            $repeatStarted=$this->eventScalar($pdo,$tenantId,$from,"event_type='repeat_order'");
            $repeatFinalized=$this->scalar($pdo,"SELECT COUNT(DISTINCT e.order_id) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='repeat_order' AND e.created_at>=? AND o.status NOT IN ('draft','cancelled')",[$tenantId,$from]);
            $upsellOffers=$this->scalar($pdo,"SELECT COUNT(DISTINCT order_id) FROM whatsapp_commerce_events WHERE tenant_id=? AND event_type='upsell_offer' AND created_at>=? AND order_id IS NOT NULL",[$tenantId,$from]);
            $upsellAcceptedItems=$this->eventScalar($pdo,$tenantId,$from,"event_type='upsell_accepted'");
            $upsellOrders=$this->scalar($pdo,"SELECT COUNT(DISTINCT e.order_id) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='upsell_accepted' AND e.created_at>=? AND o.status NOT IN ('draft','cancelled')",[$tenantId,$from]);
            $upsellPaidValue=$this->scalar($pdo,"SELECT COALESCE(SUM(e.value_cents),0) FROM whatsapp_commerce_events e JOIN orders o ON o.id=e.order_id AND o.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_type='upsell_accepted' AND e.created_at>=? AND o.status NOT IN ('draft','cancelled') AND o.payment_status='paid'",[$tenantId,$from]);
            $min=$pdo->prepare('SELECT MIN(created_at) FROM whatsapp_commerce_events WHERE tenant_id=?');$min->execute([$tenantId]);$analyticsSince=$min->fetchColumn()?:null;
        }catch(Throwable){}

        $top=[];
        try{
            $q=$pdo->prepare("SELECT oi.name_snapshot name,COALESCE(SUM(oi.quantity),0) quantity,COALESCE(SUM(oi.total_cents),0) total_cents FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.tenant_id=? AND o.order_source='WHATSAPP' AND o.status NOT IN ('draft','cancelled') AND o.created_at>=? GROUP BY oi.name_snapshot ORDER BY total_cents DESC,quantity DESC LIMIT 5");
            $q->execute([$tenantId,$from]);$top=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }catch(Throwable){}

        $daily=[];
        try{
            $q=$pdo->prepare("SELECT DATE(created_at) day,COUNT(*) orders_count,COALESCE(SUM(CASE WHEN payment_status='paid' AND status NOT IN ('draft','cancelled') THEN total_cents ELSE 0 END),0) paid_sales_cents FROM orders WHERE tenant_id=? AND order_source='WHATSAPP' AND created_at>=? AND status NOT IN ('draft','cancelled') GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 14");
            $q->execute([$tenantId,$from]);$daily=array_reverse($q->fetchAll(PDO::FETCH_ASSOC)?:[]);
        }catch(Throwable){}

        return[
            'period_days'=>$days,'from'=>$from,'generated_at'=>gmdate('Y-m-d H:i:s'),'analytics_since'=>$analyticsSince,
            'funnel'=>[
                'conversations'=>$conversations,'carts_started'=>$carts,'orders_placed'=>$orders,'paid_orders'=>$paid,
                'conversation_to_order_pct'=>$this->rate($orders,$conversations),'cart_to_order_pct'=>$this->rate($orders,$carts),'order_to_paid_pct'=>$this->rate($paid,$orders),
            ],
            'sales'=>['paid_sales_cents'=>$sales,'ticket_average_cents'=>$paid>0?(int)round($sales/$paid):0,'repeat_started'=>$repeatStarted,'repeat_finalized'=>$repeatFinalized],
            'recovery'=>['open_drafts'=>$openDrafts,'reminders'=>$abandonedReminders,'recovered'=>$recovered,'recovery_pct'=>$this->rate($recovered,$abandonedReminders)],
            'upsell'=>['offers'=>$upsellOffers,'accepted_items'=>$upsellAcceptedItems,'orders_with_upsell'=>$upsellOrders,'paid_value_cents'=>$upsellPaidValue,'acceptance_pct'=>$this->rate($upsellOrders,$upsellOffers)],
            'messages'=>['inbound'=>$inbound,'outbound_total'=>$outTotal,'sent'=>$sent,'pending'=>$pending,'failed'=>$failed,'human_open'=>$humanOpen],
            'top_products'=>$top,'daily'=>$daily,
        ];
    }

    private function eventScalar(PDO $pdo,int $tenantId,string $from,string $where):int
    {
        return$this->scalar($pdo,'SELECT COUNT(*) FROM whatsapp_commerce_events WHERE tenant_id=? AND created_at>=? AND '.$where,[$tenantId,$from]);
    }

    private function scalar(PDO $pdo,string $sql,array $args):int
    {
        try{$q=$pdo->prepare($sql);$q->execute($args);return max(0,(int)($q->fetchColumn()?:0));}catch(Throwable){return 0;}
    }

    private function rate(int $part,int $total):float
    {
        if($total<1)return 0.0;return round(($part/$total)*100,1);
    }
}
