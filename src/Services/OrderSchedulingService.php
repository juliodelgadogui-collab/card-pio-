<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class OrderSchedulingService
{
    public function reserve(PDO $pdo,int $tenantId,string $channel,?string $requested):array
    {
        if(!in_array($channel,['delivery','pickup'],true))throw new RuntimeException('Canal inválido para agendamento.');
        $ordering=new OnlineOrderingService();
        $settings=$ordering->settings($pdo,$tenantId,true);
        $requested=trim((string)$requested);

        if($requested===''){
            $ordering->assertChannelOpen($pdo,$tenantId,$channel);
            return ['scheduled_for'=>null,'slot_minutes'=>null,'label'=>'O mais rápido possível'];
        }

        if(empty($settings['scheduling_enabled']))throw new RuntimeException('Agendamento de pedidos não está habilitado.');
        $slot=$this->slotMinutes($settings);
        $target=$this->parseLocal($requested,$ordering->timezone($settings));
        $this->validateTarget($pdo,$tenantId,$channel,$target,$settings,$ordering,$slot);
        $utc=$target->setTimezone(new \DateTimeZone('UTC'));

        $capacity=max(0,min(500,(int)($settings['max_orders_per_slot']??0)));
        if($capacity>0){
            $s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND channel=? AND scheduled_for=? AND status<>"cancelled"');
            $s->execute([$tenantId,$channel,$utc->format('Y-m-d H:i:s')]);
            if((int)$s->fetchColumn()>=$capacity)throw new RuntimeException('Este horário acabou de atingir a capacidade máxima. Escolha outro horário.');
        }

        return [
            'scheduled_for'=>$utc->format('Y-m-d H:i:s'),
            'slot_minutes'=>$slot,
            'label'=>$target->format('d/m/Y H:i'),
        ];
    }

    public function availableSlots(PDO $pdo,int $tenantId,string $channel,int $limit=40):array
    {
        if(!in_array($channel,['delivery','pickup'],true))return [];
        $ordering=new OnlineOrderingService();
        try{$settings=$ordering->settings($pdo,$tenantId,false);}catch(\Throwable){return [];}
        if(empty($settings['scheduling_enabled']))return [];
        if($channel==='pickup'&&array_key_exists('pickup_enabled',$settings)&&empty($settings['pickup_enabled']))return [];

        $tz=$ordering->timezone($settings);
        $slot=$this->slotMinutes($settings);
        $lead=max(0,min(1440,(int)($settings['scheduling_lead_minutes']??30)));
        $days=max(1,min(60,(int)($settings['scheduling_days_ahead']??7)));
        $capacity=max(0,min(500,(int)($settings['max_orders_per_slot']??0)));
        $now=new \DateTimeImmutable('now',$tz);
        $cursor=$this->ceilToSlot($now->modify('+'.$lead.' minutes'),$slot);
        $end=$now->modify('+'.$days.' days')->setTime(23,59,59);
        $limit=max(1,min(120,$limit));
        $slots=[];

        while($cursor<=$end&&count($slots)<$limit){
            $availability=$ordering->statusAt($pdo,$tenantId,$channel,$cursor);
            if($availability['open']){
                $utc=$cursor->setTimezone(new \DateTimeZone('UTC'));
                $remaining=null;
                if($capacity>0){
                    $s=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND channel=? AND scheduled_for=? AND status<>"cancelled"');
                    $s->execute([$tenantId,$channel,$utc->format('Y-m-d H:i:s')]);
                    $used=(int)$s->fetchColumn();
                    $remaining=max(0,$capacity-$used);
                }
                if($remaining===null||$remaining>0){
                    $slots[]=[
                        'value'=>$cursor->format('Y-m-d\TH:i'),
                        'label'=>$this->slotLabel($cursor,$now),
                        'remaining'=>$remaining,
                    ];
                }
            }
            $cursor=$cursor->modify('+'.$slot.' minutes');
        }
        return $slots;
    }

    private function validateTarget(PDO $pdo,int $tenantId,string $channel,\DateTimeImmutable $target,array $settings,OnlineOrderingService $ordering,int $slot):void
    {
        $tz=$target->getTimezone();
        $now=new \DateTimeImmutable('now',$tz);
        $lead=max(0,min(1440,(int)($settings['scheduling_lead_minutes']??30)));
        $days=max(1,min(60,(int)($settings['scheduling_days_ahead']??7)));
        if($target<$now->modify('+'.$lead.' minutes'))throw new RuntimeException('O horário escolhido não respeita a antecedência mínima.');
        if($target>$now->modify('+'.$days.' days')->setTime(23,59,59))throw new RuntimeException('O horário escolhido está fora da janela de agendamento.');
        $minutes=((int)$target->format('H'))*60+(int)$target->format('i');
        if($minutes%$slot!==0)throw new RuntimeException('Horário fora da grade de agendamento.');
        $ordering->assertChannelOpen($pdo,$tenantId,$channel,$target);
    }

    private function parseLocal(string $value,\DateTimeZone $tz):\DateTimeImmutable
    {
        $dt=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
        $errors=\DateTimeImmutable::getLastErrors();
        if(!$dt||($errors!==false&&(($errors['warning_count']??0)>0||($errors['error_count']??0)>0)))throw new RuntimeException('Horário de agendamento inválido.');
        return $dt;
    }

    private function slotMinutes(array $settings):int
    {
        $slot=(int)($settings['scheduling_slot_minutes']??30);
        return in_array($slot,[10,15,20,30,45,60],true)?$slot:30;
    }

    private function ceilToSlot(\DateTimeImmutable $dt,int $slot):\DateTimeImmutable
    {
        $totalMinutes=((int)$dt->format('H'))*60+(int)$dt->format('i');
        $seconds=(int)$dt->format('s');
        $remainder=$totalMinutes%$slot;
        $add=$remainder===0&&$seconds===0?0:$slot-$remainder;
        $rounded=$dt->modify('+'.$add.' minutes');
        return $rounded->setTime((int)$rounded->format('H'),(int)$rounded->format('i'),0);
    }

    private function slotLabel(\DateTimeImmutable $slot,\DateTimeImmutable $now):string
    {
        $date=$slot->format('Y-m-d');
        $today=$now->format('Y-m-d');
        $tomorrow=$now->modify('+1 day')->format('Y-m-d');
        $prefix=$date===$today?'Hoje':($date===$tomorrow?'Amanhã':$slot->format('d/m'));
        return $prefix.' · '.$slot->format('H:i');
    }
}
