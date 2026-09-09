<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryTrackingService
{
    public function update(int $orderId, float $lat, float $lng, ?float $accuracy, ?float $speed, ?float $bearing, ?string $capturedAt = null): array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId||!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado ao rastreamento.');
        if($orderId<=0||$lat < -90||$lat > 90||$lng < -180||$lng > 180)throw new RuntimeException('Localização inválida.');
        if($accuracy!==null&&($accuracy<0||$accuracy>5000))$accuracy=null;
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT id,status,assigned_delivery_user_id FROM orders WHERE tenant_id=? AND id=? AND channel="delivery" LIMIT 1');$s->execute([$tenantId,$orderId]);$order=$s->fetch();
        if(!$order||(int)$order['assigned_delivery_user_id']!==$userId)throw new RuntimeException('Pedido não está atribuído a este entregador.');
        if($order['status']!=='out_for_delivery')throw new RuntimeException('Rastreamento permitido somente durante a rota.');
        $captured=$this->normalizeCapturedAt($capturedAt);
        Database::transaction(function(PDO $tx)use($tenantId,$orderId,$userId,$lat,$lng,$accuracy,$speed,$bearing,$captured):void{
            $driver=(string)$tx->getAttribute(PDO::ATTR_DRIVER_NAME);
            if($driver==='sqlite'){
                $sql='INSERT INTO delivery_live_locations (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,captured_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id,order_id) DO UPDATE SET delivery_user_id=excluded.delivery_user_id,latitude=excluded.latitude,longitude=excluded.longitude,accuracy_m=excluded.accuracy_m,speed_mps=excluded.speed_mps,bearing_deg=excluded.bearing_deg,captured_at=excluded.captured_at,updated_at=CURRENT_TIMESTAMP';
            }else{
                $sql='INSERT INTO delivery_live_locations (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,captured_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE delivery_user_id=VALUES(delivery_user_id),latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_m=VALUES(accuracy_m),speed_mps=VALUES(speed_mps),bearing_deg=VALUES(bearing_deg),captured_at=VALUES(captured_at),updated_at=CURRENT_TIMESTAMP';
            }
            $tx->prepare($sql)->execute([$tenantId,$orderId,$userId,$lat,$lng,$accuracy,$speed,$bearing,$captured]);
            $last=$tx->prepare('SELECT captured_at FROM delivery_location_history WHERE tenant_id=? AND order_id=? ORDER BY id DESC LIMIT 1');$last->execute([$tenantId,$orderId]);$lastAt=$last->fetchColumn();
            if(!$lastAt||time()-strtotime((string)$lastAt)>=20){$tx->prepare('INSERT INTO delivery_location_history (tenant_id,order_id,delivery_user_id,latitude,longitude,accuracy_m,speed_mps,bearing_deg,captured_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$tenantId,$orderId,$userId,$lat,$lng,$accuracy,$speed,$bearing,$captured]);}
        });
        return ['order_id'=>$orderId,'latitude'=>$lat,'longitude'=>$lng,'captured_at'=>$captured];
    }

    public function activeForManager(): array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado.');
        $s=Database::connection()->prepare('SELECT l.order_id,l.delivery_user_id,l.latitude,l.longitude,l.accuracy_m,l.speed_mps,l.bearing_deg,l.captured_at,l.updated_at,u.name delivery_name,c.name customer_name,o.delivery_address FROM delivery_live_locations l JOIN orders o ON o.tenant_id=l.tenant_id AND o.id=l.order_id LEFT JOIN users u ON u.id=l.delivery_user_id LEFT JOIN customers c ON c.id=o.customer_id WHERE l.tenant_id=? AND o.status="out_for_delivery" ORDER BY l.updated_at DESC');$s->execute([$tenantId]);return $s->fetchAll();
    }

    public function latestPublic(string $token): ?array
    {
        if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;
        $s=Database::connection()->prepare('SELECT l.latitude,l.longitude,l.accuracy_m,l.captured_at,o.status,o.id order_id FROM delivery_tracking_tokens t JOIN orders o ON o.tenant_id=t.tenant_id AND o.id=t.order_id LEFT JOIN delivery_live_locations l ON l.tenant_id=o.tenant_id AND l.order_id=o.id WHERE t.token_hash=? AND t.expires_at>CURRENT_TIMESTAMP LIMIT 1');$s->execute([hash('sha256',$token)]);$row=$s->fetch();return $row?:null;
    }

    public function publicToken(int $tenantId,int $orderId): string
    {
        $pdo=Database::connection();$token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expires=date('Y-m-d H:i:s',time()+86400);
        $pdo->prepare('DELETE FROM delivery_tracking_tokens WHERE tenant_id=? AND order_id=?')->execute([$tenantId,$orderId]);
        $pdo->prepare('INSERT INTO delivery_tracking_tokens (tenant_id,order_id,token_hash,expires_at) VALUES (?,?,?,?)')->execute([$tenantId,$orderId,$hash,$expires]);return $token;
    }

    private function normalizeCapturedAt(?string $value): string
    {
        $ts=$value?strtotime($value):false;if($ts===false||abs(time()-$ts)>300)$ts=time();return gmdate('Y-m-d H:i:s',$ts);
    }
}
