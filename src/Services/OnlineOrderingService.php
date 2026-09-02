<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class OnlineOrderingService
{
    public function status(PDO $pdo,int $tenantId,?\DateTimeImmutable $now=null):array
    {
        return $this->statusAt($pdo,$tenantId,'delivery',$now);
    }

    public function statusAt(PDO $pdo,int $tenantId,string $channel,?\DateTimeImmutable $when=null):array
    {
        if(!in_array($channel,['delivery','pickup'],true))return ['open'=>false,'reason'=>'Canal de pedido inválido.'];
        $s=$pdo->prepare('SELECT status,settings FROM tenants WHERE id=? LIMIT 1');
        $s->execute([$tenantId]);
        $tenant=$s->fetch();
        if(!$tenant||$tenant['status']!=='active')return ['open'=>false,'reason'=>'Empresa indisponível.'];
        $settings=json_decode((string)($tenant['settings']??'{}'),true);
        if(!is_array($settings))$settings=[];

        if($channel==='pickup'&&array_key_exists('pickup_enabled',$settings)&&empty($settings['pickup_enabled'])){
            return ['open'=>false,'reason'=>'Retirada no local indisponível.'];
        }

        $pauseKey=$channel==='delivery'?'delivery_paused':'pickup_paused';
        $reasonKey=$channel==='delivery'?'delivery_pause_reason':'pickup_pause_reason';
        if(!empty($settings[$pauseKey])){
            $reason=trim((string)($settings[$reasonKey]??''));
            return ['open'=>false,'reason'=>$reason!==''?$reason:($channel==='delivery'?'Pedidos delivery pausados temporariamente.':'Pedidos para retirada pausados temporariamente.')];
        }

        $hours=$settings['business_hours']??null;
        if(!is_array($hours)||$hours===[])return ['open'=>true,'reason'=>null];

        $tz=$this->timezone($settings);
        $when=($when??new \DateTimeImmutable('now',$tz))->setTimezone($tz);
        $weekday=(int)$when->format('N');

        if($this->matchesRule($when,$hours[(string)$weekday]??$hours[$weekday]??null,0))return ['open'=>true,'reason'=>null];
        $previous=$weekday===1?7:$weekday-1;
        if($this->matchesRule($when,$hours[(string)$previous]??$hours[$previous]??null,-1,true))return ['open'=>true,'reason'=>null];

        return ['open'=>false,'reason'=>$channel==='delivery'?'Delivery fechado neste horário.':'Retirada fechada neste horário.'];
    }

    public function assertDeliveryOpen(PDO $pdo,int $tenantId):void
    {
        $this->assertChannelOpen($pdo,$tenantId,'delivery');
    }

    public function assertChannelOpen(PDO $pdo,int $tenantId,string $channel,?\DateTimeImmutable $when=null):void
    {
        $status=$this->statusAt($pdo,$tenantId,$channel,$when);
        if(!$status['open'])throw new RuntimeException((string)$status['reason']);
    }

    public function settings(PDO $pdo,int $tenantId,bool $forUpdate=false):array
    {
        $sql='SELECT status,settings FROM tenants WHERE id=?'.($forUpdate?' FOR UPDATE':'').' LIMIT 1';
        $s=$pdo->prepare($sql);$s->execute([$tenantId]);$tenant=$s->fetch();
        if(!$tenant||$tenant['status']!=='active')throw new RuntimeException('Empresa indisponível.');
        $settings=json_decode((string)($tenant['settings']??'{}'),true);
        return is_array($settings)?$settings:[];
    }

    public function timezone(array $settings):\DateTimeZone
    {
        $timezone=(string)($settings['timezone']??'America/Sao_Paulo');
        try{return new \DateTimeZone($timezone);}catch(\Throwable){return new \DateTimeZone('America/Sao_Paulo');}
    }

    private function matchesRule(\DateTimeImmutable $when,mixed $rule,int $dayOffset,bool $onlyOvernight=false):bool
    {
        if(!is_array($rule)||empty($rule['enabled']))return false;
        $open=$this->validTime((string)($rule['open']??''));
        $close=$this->validTime((string)($rule['close']??''));
        if($open===null||$close===null)return false;
        $overnight=$close<=$open;
        if($onlyOvernight&&!$overnight)return false;

        $base=$when->setTime(0,0)->modify(($dayOffset>=0?'+':'').$dayOffset.' day');
        [$oh,$om]=array_map('intval',explode(':',$open));
        [$ch,$cm]=array_map('intval',explode(':',$close));
        $from=$base->setTime($oh,$om);
        $to=$base->setTime($ch,$cm);
        if($overnight)$to=$to->modify('+1 day');
        return $when>=$from&&$when<$to;
    }

    private function validTime(string $value):?string
    {
        $value=trim($value);
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value))return null;
        return $value;
    }
}
