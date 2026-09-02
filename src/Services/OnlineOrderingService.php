<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class OnlineOrderingService
{
    public function status(PDO $pdo,int $tenantId,?\DateTimeImmutable $now=null):array
    {
        $s=$pdo->prepare('SELECT status,settings FROM tenants WHERE id=? LIMIT 1');
        $s->execute([$tenantId]);
        $tenant=$s->fetch();
        if(!$tenant||$tenant['status']!=='active')return ['open'=>false,'reason'=>'Empresa indisponível.'];
        $settings=json_decode((string)($tenant['settings']??'{}'),true);
        if(!is_array($settings))$settings=[];

        if(!empty($settings['delivery_paused'])){
            $reason=trim((string)($settings['delivery_pause_reason']??''));
            return ['open'=>false,'reason'=>$reason!==''?$reason:'Pedidos delivery pausados temporariamente.'];
        }

        $hours=$settings['business_hours']??null;
        if(!is_array($hours)||$hours===[])return ['open'=>true,'reason'=>null];

        $timezone=(string)($settings['timezone']??'America/Sao_Paulo');
        try{$tz=new \DateTimeZone($timezone);}catch(\Throwable){$tz=new \DateTimeZone('America/Sao_Paulo');}
        $now=($now??new \DateTimeImmutable('now',$tz))->setTimezone($tz);
        $weekday=(int)$now->format('N');

        if($this->matchesRule($now,$hours[(string)$weekday]??$hours[$weekday]??null,0))return ['open'=>true,'reason'=>null];
        $previous=$weekday===1?7:$weekday-1;
        if($this->matchesRule($now,$hours[(string)$previous]??$hours[$previous]??null,-1,true))return ['open'=>true,'reason'=>null];

        return ['open'=>false,'reason'=>'Delivery fechado neste horário.'];
    }

    public function assertDeliveryOpen(PDO $pdo,int $tenantId):void
    {
        $status=$this->status($pdo,$tenantId);
        if(!$status['open'])throw new RuntimeException((string)$status['reason']);
    }

    private function matchesRule(\DateTimeImmutable $now,mixed $rule,int $dayOffset,bool $onlyOvernight=false):bool
    {
        if(!is_array($rule)||empty($rule['enabled']))return false;
        $open=$this->validTime((string)($rule['open']??''));
        $close=$this->validTime((string)($rule['close']??''));
        if($open===null||$close===null)return false;
        $overnight=$close<=$open;
        if($onlyOvernight&&!$overnight)return false;

        $base=$now->setTime(0,0)->modify(($dayOffset>=0?'+':'').$dayOffset.' day');
        [$oh,$om]=array_map('intval',explode(':',$open));
        [$ch,$cm]=array_map('intval',explode(':',$close));
        $from=$base->setTime($oh,$om);
        $to=$base->setTime($ch,$cm);
        if($overnight)$to=$to->modify('+1 day');
        return $now>=$from&&$now<$to;
    }

    private function validTime(string $value):?string
    {
        $value=trim($value);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))return null;
        return $value;
    }
}
