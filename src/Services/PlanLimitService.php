<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class PlanLimitService
{
    public function current(?int $tenantId=null): ?array
    {
        $tenantId ??= Auth::tenantId();
        if(!$tenantId) return null;
        try{
            $s=Database::connection()->prepare('SELECT p.*,ts.status subscription_status,ts.billing_cycle,ts.custom_price_cents FROM tenant_subscriptions ts JOIN saas_plans p ON p.id=ts.plan_id WHERE ts.tenant_id=? LIMIT 1');
            $s->execute([$tenantId]);
            $row=$s->fetch();
            if(!$row) return null;
            $row['limits']=json_decode((string)($row['limits_json']??'{}'),true)?:[];
            $row['features']=json_decode((string)($row['features_json']??'[]'),true)?:[];
            return $row;
        }catch(\Throwable){return null;}
    }

    public function usage(?int $tenantId=null): array
    {
        $tenantId ??= Auth::tenantId();
        if(!$tenantId) return ['users'=>0,'units'=>0,'products'=>0];
        $pdo=Database::connection();
        $queries=[
            'users'=>'SELECT COUNT(*) FROM users WHERE tenant_id=? AND role<>"super_admin"',
            'units'=>'SELECT COUNT(*) FROM operating_units WHERE tenant_id=? AND active=1',
            'products'=>'SELECT COUNT(*) FROM products WHERE tenant_id=? AND active=1',
        ];
        $usage=[];
        foreach($queries as$key=>$sql){$s=$pdo->prepare($sql);$s->execute([$tenantId]);$usage[$key]=(int)$s->fetchColumn();}
        return $usage;
    }

    public function assertCanCreate(string $resource,?int $tenantId=null,int $extra=1): void
    {
        $tenantId ??= Auth::tenantId();
        if(!$tenantId) throw new RuntimeException('Empresa inválida.');
        if(!in_array($resource,['users','units','products'],true)) return;
        $plan=$this->current($tenantId);
        if(!$plan || ($plan['subscription_status']??'active')!=='active') return;
        $limit=max(0,(int)($plan['limits'][$resource]??0));
        if($limit===0) return;
        $usage=$this->usage($tenantId);
        if(($usage[$resource]??0)+max(1,$extra)>$limit){
            $labels=['users'=>'usuários','units'=>'unidades','products'=>'produtos'];
            throw new RuntimeException('Seu plano '.$plan['name'].' permite até '.$limit.' '.$labels[$resource].'. Altere o plano para continuar.');
        }
    }

    public function summary(?int $tenantId=null): array
    {
        $plan=$this->current($tenantId);$usage=$this->usage($tenantId);
        if(!$plan) return ['plan'=>null,'usage'=>$usage,'limits'=>[]];
        $limits=$plan['limits'];$remaining=[];
        foreach(['users','units','products'] as$key){$limit=max(0,(int)($limits[$key]??0));$remaining[$key]=$limit===0?null:max(0,$limit-($usage[$key]??0));}
        return ['plan'=>$plan,'usage'=>$usage,'limits'=>$limits,'remaining'=>$remaining];
    }
}
