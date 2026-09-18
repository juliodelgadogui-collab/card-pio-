<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryPublicTrackingService
{
    public function issue(int $orderId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId||!Auth::can('orders.delivery')||$orderId<1)throw new RuntimeException('Entrega inválida para acompanhamento.');
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT o.id,o.status,dp.route_started_at,dp.completed_at FROM orders o JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.id=? AND o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? LIMIT 1');
        $q->execute([$orderId,$tenantId,$userId]);$order=$q->fetch();
        if(!$order||empty($order['route_started_at'])||(string)$order['status']!=='out_for_delivery'||!empty($order['completed_at']))throw new RuntimeException('Inicie a rota antes de gerar o acompanhamento do cliente.');

        return Database::transaction(function(PDO $tx)use($tenantId,$orderId):array{
            $existing=$tx->prepare(Database::portableSql($tx,'SELECT * FROM delivery_tracking_links WHERE tenant_id=? AND order_id=? LIMIT 1 FOR UPDATE'));
            $existing->execute([$tenantId,$orderId]);$row=$existing->fetch();
            $now=time();
            if($row&&empty($row['revoked_at'])&&strtotime((string)$row['expires_at'])>$now){
                $token=Crypto::decrypt((string)$row['token_encrypted']);
                return ['url'=>$this->url($token),'expires_at'=>$row['expires_at']];
            }
            $token=$this->token();$hash=hash('sha256',$token);$expires=gmdate('Y-m-d H:i:s',$now+8*3600);$encrypted=Crypto::encrypt($token);
            if($row){
                $tx->prepare('UPDATE delivery_tracking_links SET token_hash=?,token_encrypted=?,expires_at=?,revoked_at=NULL,last_accessed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                    ->execute([$hash,$encrypted,$expires,$row['id'],$tenantId]);
            }else{
                $tx->prepare('INSERT INTO delivery_tracking_links (tenant_id,order_id,token_hash,token_encrypted,expires_at) VALUES (?,?,?,?,?)')
                    ->execute([$tenantId,$orderId,$hash,$encrypted,$expires]);
            }
            Auth::audit('delivery.tracking_link_issued','order',(string)$orderId,['expires_at'=>$expires]);
            return ['url'=>$this->url($token),'expires_at'=>$expires];
        });
    }

    public function publicStatus(string $token):array
    {
        $token=trim($token);if(strlen($token)<32||strlen($token)>128)throw new RuntimeException('Acompanhamento indisponível.');
        $hash=hash('sha256',$token);$pdo=Database::connection();
        $sql='SELECT l.id,l.tenant_id,l.order_id,l.expires_at,l.revoked_at,o.status,o.created_at,dp.picked_up_at,dp.route_started_at,dp.arrived_at,dp.completed_at,ll.latitude,ll.longitude,ll.accuracy_m,ll.recorded_at,ll.received_at FROM delivery_tracking_links l JOIN orders o ON o.id=l.order_id AND o.tenant_id=l.tenant_id JOIN delivery_progress dp ON dp.order_id=o.id AND dp.tenant_id=o.tenant_id LEFT JOIN delivery_live_locations ll ON ll.order_id=o.id AND ll.tenant_id=o.tenant_id WHERE l.token_hash=? LIMIT 1';
        $s=$pdo->prepare($sql);$s->execute([$hash]);$row=$s->fetch();
        if(!$row||!empty($row['revoked_at'])||strtotime((string)$row['expires_at'])<time())throw new RuntimeException('Acompanhamento indisponível ou expirado.');
        $pdo->prepare('UPDATE delivery_tracking_links SET last_accessed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$row['id']]);

        $status=(string)$row['status'];$active=$status==='out_for_delivery'&&!empty($row['route_started_at'])&&empty($row['arrived_at'])&&empty($row['completed_at']);
        $location=null;
        if($active&&$row['latitude']!==null&&$row['longitude']!==null){
            $fresh=!empty($row['received_at'])&&strtotime((string)$row['received_at'])>=time()-180;
            if($fresh)$location=[
                'latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude'],
                'accuracy_m'=>$row['accuracy_m']!==null?(float)$row['accuracy_m']:null,
                'recorded_at'=>$row['recorded_at'],'received_at'=>$row['received_at'],
            ];
        }
        $brand=(new TenantBrandService())->get((int)$row['tenant_id']);
        if(empty($brand['apply_web']))$brand=(new TenantBrandService())->defaults();
        return [
            'order_id'=>(int)$row['order_id'],'status'=>$status,'tracking_active'=>$active,
            'picked_up_at'=>$row['picked_up_at'],'route_started_at'=>$row['route_started_at'],
            'arrived_at'=>$row['arrived_at'],'completed_at'=>$row['completed_at'],
            'location'=>$location,'expires_at'=>$row['expires_at'],'brand'=>$brand,
        ];
    }

    public function revokeForOrder(int $tenantId,int $orderId):void
    {
        if($tenantId<1||$orderId<1)return;
        Database::connection()->prepare('UPDATE delivery_tracking_links SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND order_id=?')->execute([$tenantId,$orderId]);
    }

    private function token():string{return rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');}
    private function url(string$token):string
    {
        $base=rtrim((string)env('APP_URL',''),'/').'/'.trim((string)env('APP_BASE_PATH','/1'),'/');
        return $base.'/delivery-track.php?token='.rawurlencode($token);
    }
}
