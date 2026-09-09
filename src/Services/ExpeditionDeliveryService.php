<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;

final class ExpeditionDeliveryService
{
    public function enrich(array $orders):array
    {
        if(!$orders)return $orders;
        $tenantId=Auth::tenantId();if(!$tenantId)return $orders;
        $unit=(new OperatingUnitService())->requireCurrent();$unitId=(int)$unit['id'];
        $ids=array_values(array_unique(array_filter(array_map(static fn(array$row):int=>(int)($row['id']??0),$orders))));
        if(!$ids)return $orders;
        $placeholders=implode(',',array_fill(0,count($ids),'?'));
        $sql='SELECT o.id,o.assigned_delivery_user_id,u.name delivery_name,dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at,ll.received_at location_received_at FROM orders o LEFT JOIN users u ON u.id=o.assigned_delivery_user_id AND u.tenant_id=o.tenant_id LEFT JOIN delivery_progress dp ON dp.order_id=o.id AND dp.tenant_id=o.tenant_id LEFT JOIN delivery_live_locations ll ON ll.order_id=o.id AND ll.tenant_id=o.tenant_id WHERE o.tenant_id=? AND o.unit_id=? AND o.id IN ('.$placeholders.')';
        $s=Database::connection()->prepare($sql);$s->execute(array_merge([$tenantId,$unitId],$ids));$delivery=[];foreach($s->fetchAll()as$row)$delivery[(int)$row['id']]=$row;
        foreach($orders as&$order){
            if((string)($order['channel']??'')!=='delivery')continue;
            $d=$delivery[(int)$order['id']]??[];$assigned=!empty($d['assigned_delivery_user_id']);$picked=!empty($d['picked_up_at']);$route=!empty($d['route_started_at']);$arrived=!empty($d['arrived_at']);
            $stage=!$assigned?'Sem entregador':(!$picked?'Aguardando retirada':(!$route?'Retirado':(!$arrived?'Em rota':'Chegou')));
            $order['delivery_user_id']=$assigned?(int)$d['assigned_delivery_user_id']:null;
            $order['delivery_name']=$assigned?trim((string)($d['delivery_name']??'')):'';
            $order['delivery_stage']=$stage;
            $order['delivery_picked_up']=$picked;
            $order['delivery_route_started']=$route;
            $order['delivery_arrived']=$arrived;
            $order['delivery_location_fresh']=!empty($d['location_received_at'])&&strtotime((string)$d['location_received_at'])>=time()-180;
        }unset($order);
        return $orders;
    }
}
