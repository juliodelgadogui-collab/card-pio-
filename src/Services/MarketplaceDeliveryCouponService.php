<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class MarketplaceDeliveryCouponService
{
    public function syncCoupon(PDO $pdo,int $marketplaceCouponId):array
    {
        $q=$pdo->prepare('SELECT * FROM marketplace_delivery_coupons WHERE id=? LIMIT 1');
        $q->execute([$marketplaceCouponId]);
        $definition=$q->fetch();
        if(!$definition)throw new RuntimeException('Cupom Delivery não encontrado.');

        $targetIds=(int)$definition['active']===1?$this->targetTenantIds($pdo,$definition):[];
        $targetSet=array_fill_keys($targetIds,true);

        $map=$pdo->prepare('SELECT mt.tenant_id,mt.coupon_id,c.code FROM marketplace_delivery_coupon_targets mt LEFT JOIN coupons c ON c.id=mt.coupon_id WHERE mt.marketplace_coupon_id=?');
        $map->execute([$marketplaceCouponId]);
        $existingByTenant=[];
        foreach($map->fetchAll()as$row)$existingByTenant[(int)$row['tenant_id']]=$row;

        $applied=0;$deactivated=0;$conflicts=[];
        foreach($existingByTenant as$tenantId=>$row){
            if(!isset($targetSet[$tenantId])){
                $pdo->prepare('UPDATE coupons SET active=0 WHERE id=? AND tenant_id=?')->execute([(int)$row['coupon_id'],$tenantId]);
                $deactivated++;
            }
        }

        foreach($targetIds as$tenantId){
            if(isset($existingByTenant[$tenantId])){
                $couponId=(int)$existingByTenant[$tenantId]['coupon_id'];
                $exists=$pdo->prepare('SELECT id FROM coupons WHERE id=? AND tenant_id=? LIMIT 1');
                $exists->execute([$couponId,$tenantId]);
                if($exists->fetchColumn()){
                    $this->updateTenantCoupon($pdo,$couponId,$tenantId,$definition);
                    $applied++;
                    continue;
                }
                $pdo->prepare('DELETE FROM marketplace_delivery_coupon_targets WHERE marketplace_coupon_id=? AND tenant_id=?')->execute([$marketplaceCouponId,$tenantId]);
            }

            $collision=$pdo->prepare('SELECT c.id,c.active,t.name FROM coupons c JOIN tenants t ON t.id=c.tenant_id WHERE c.tenant_id=? AND UPPER(c.code)=UPPER(?) LIMIT 1');
            $collision->execute([$tenantId,(string)$definition['code']]);
            if($row=$collision->fetch()){
                $conflicts[]=['tenant_id'=>$tenantId,'tenant_name'=>(string)$row['name'],'coupon_id'=>(int)$row['id'],'active'=>(bool)$row['active']];
                continue;
            }

            $couponId=$this->createTenantCoupon($pdo,$tenantId,$definition);
            $pdo->prepare('INSERT INTO marketplace_delivery_coupon_targets (marketplace_coupon_id,tenant_id,coupon_id) VALUES (?,?,?)')->execute([$marketplaceCouponId,$tenantId,$couponId]);
            $applied++;
        }

        return ['targets'=>count($targetIds),'applied'=>$applied,'deactivated'=>$deactivated,'conflicts'=>$conflicts];
    }

    /**
     * Materializa um cupom do Super ADM somente para a empresa que está usando
     * o DELYVRE. Assim o APK não depende de alguém clicar manualmente em
     * "Sincronizar empresas" depois que uma loja entra no marketplace.
     */
    public function ensureForTenantCode(PDO $pdo,int $tenantId,string $code):?int
    {
        $code=mb_strtoupper(trim($code));
        if($tenantId<1||$code==='')return null;

        $q=$pdo->prepare('SELECT * FROM marketplace_delivery_coupons WHERE UPPER(code)=? AND active=1 LIMIT 1');
        $q->execute([$code]);
        $definition=$q->fetch();
        if(!$definition)return null;

        if(!in_array($tenantId,$this->targetTenantIds($pdo,$definition),true))return null;

        $map=$pdo->prepare('SELECT coupon_id FROM marketplace_delivery_coupon_targets WHERE marketplace_coupon_id=? AND tenant_id=? LIMIT 1');
        $map->execute([(int)$definition['id'],$tenantId]);
        $mappedId=(int)($map->fetchColumn()?:0);
        if($mappedId>0){
            $exists=$pdo->prepare('SELECT id FROM coupons WHERE id=? AND tenant_id=? LIMIT 1');
            $exists->execute([$mappedId,$tenantId]);
            if($exists->fetchColumn()){
                $this->updateTenantCoupon($pdo,$mappedId,$tenantId,$definition);
                return $mappedId;
            }
            $pdo->prepare('DELETE FROM marketplace_delivery_coupon_targets WHERE marketplace_coupon_id=? AND tenant_id=?')->execute([(int)$definition['id'],$tenantId]);
        }

        // Um cupom criado pelo próprio restaurante com o mesmo código continua
        // tendo prioridade e nunca é sobrescrito pelo cupom da plataforma.
        $local=$pdo->prepare('SELECT id,active FROM coupons WHERE tenant_id=? AND UPPER(code)=? LIMIT 1');
        $local->execute([$tenantId,$code]);
        if($row=$local->fetch())return (int)$row['active']===1?(int)$row['id']:null;

        try{
            $couponId=$this->createTenantCoupon($pdo,$tenantId,$definition);
            $pdo->prepare('INSERT INTO marketplace_delivery_coupon_targets (marketplace_coupon_id,tenant_id,coupon_id) VALUES (?,?,?)')->execute([(int)$definition['id'],$tenantId,$couponId]);
            return $couponId;
        }catch(\Throwable $e){
            // Outra requisição pode ter materializado o mesmo cupom entre a
            // consulta e o INSERT. Nesse caso, reutiliza o vencedor.
            $winner=$pdo->prepare('SELECT id FROM coupons WHERE tenant_id=? AND UPPER(code)=? AND active=1 LIMIT 1');
            $winner->execute([$tenantId,$code]);
            $id=(int)($winner->fetchColumn()?:0);
            if($id>0)return$id;
            throw$e;
        }
    }

    public function syncAll(PDO $pdo):array
    {
        $ids=$pdo->query('SELECT id FROM marketplace_delivery_coupons ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $result=['coupons'=>0,'applied'=>0,'deactivated'=>0,'conflicts'=>0];
        foreach($ids as$id){
            $r=$this->syncCoupon($pdo,(int)$id);$result['coupons']++;$result['applied']+=(int)$r['applied'];$result['deactivated']+=(int)$r['deactivated'];$result['conflicts']+=count($r['conflicts']);
        }
        return$result;
    }

    public function targetTenantIds(PDO $pdo,array $definition):array
    {
        $scope=(string)($definition['scope_type']??'default');
        $sql='SELECT DISTINCT t.id FROM tenants t JOIN marketplace_tenant_settings m ON m.tenant_id=t.id WHERE t.status="active" AND m.participates=1 AND m.status="active"';
        $args=[];
        if($scope==='tenant'){$sql.=' AND t.id=?';$args[]=(int)($definition['tenant_id']??0);}
        elseif($scope==='city'){$sql.=' AND LOWER(TRIM(m.city))=LOWER(TRIM(?))';$args[]=(string)($definition['city']??'');}
        elseif($scope==='state'){$sql.=' AND UPPER(TRIM(m.state))=UPPER(TRIM(?))';$args[]=(string)($definition['state']??'');}
        elseif($scope==='plan'){$sql.=' AND t.plan=?';$args[]=(string)($definition['plan_code']??'');}
        elseif($scope!=='default')throw new RuntimeException('Abrangência de cupom inválida.');
        $sql.=' ORDER BY t.id';$q=$pdo->prepare($sql);$q->execute($args);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function managedCouponId(PDO $pdo,int $tenantCouponId):?int
    {
        try{$q=$pdo->prepare('SELECT marketplace_coupon_id FROM marketplace_delivery_coupon_targets WHERE coupon_id=? LIMIT 1');$q->execute([$tenantCouponId]);$id=$q->fetchColumn();return$id!==false?(int)$id:null;}catch(\Throwable){return null;}
    }

    private function createTenantCoupon(PDO $pdo,int $tenantId,array $definition):int
    {
        $insert=$pdo->prepare('INSERT INTO coupons (tenant_id,code,type,value,max_discount_cents,min_order_cents,max_uses,starts_at,ends_at,active) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([
            $tenantId,(string)$definition['code'],(string)$definition['type'],(int)$definition['value'],
            $definition['max_discount_cents']!==null?(int)$definition['max_discount_cents']:null,(int)$definition['min_order_cents'],
            $definition['max_uses_per_tenant']!==null?(int)$definition['max_uses_per_tenant']:null,$definition['starts_at']?:null,$definition['ends_at']?:null,1,
        ]);
        return(int)$pdo->lastInsertId();
    }

    private function updateTenantCoupon(PDO $pdo,int $couponId,int $tenantId,array $definition):void
    {
        $q=$pdo->prepare('UPDATE coupons SET code=?,type=?,value=?,max_discount_cents=?,min_order_cents=?,max_uses=?,starts_at=?,ends_at=?,active=? WHERE id=? AND tenant_id=?');
        $q->execute([
            (string)$definition['code'],(string)$definition['type'],(int)$definition['value'],
            $definition['max_discount_cents']!==null?(int)$definition['max_discount_cents']:null,(int)$definition['min_order_cents'],
            $definition['max_uses_per_tenant']!==null?(int)$definition['max_uses_per_tenant']:null,$definition['starts_at']?:null,$definition['ends_at']?:null,(int)$definition['active'],
            $couponId,$tenantId,
        ]);
    }
}
