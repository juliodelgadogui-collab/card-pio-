<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryLocationService
{
    public function record(array $data,string $deviceId):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId||!Auth::can('orders.delivery'))throw new RuntimeException('Acesso negado ao rastreamento de entrega.');
        $shift=(new WorkShiftService())->current();
        if(!$shift||($shift['mode']??'')!=='delivery'||($shift['status']??'')!=='open')throw new RuntimeException('O GPS só é aceito durante um turno Delivery ativo.');

        $orderId=(int)($data['order_id']??0);
        if($orderId<1)throw new RuntimeException('Pedido de entrega inválido.');
        $lat=$this->number($data['latitude']??null,'latitude');
        $lng=$this->number($data['longitude']??null,'longitude');
        if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)throw new RuntimeException('Coordenadas inválidas.');
        $accuracy=$this->optionalNumber($data['accuracy_m']??null);
        if($accuracy!==null&&($accuracy<0||$accuracy>500))throw new RuntimeException('Precisão do GPS insuficiente para registrar a rota.');
        $speed=$this->optionalNumber($data['speed_mps']??null);if($speed!==null&&($speed<0||$speed>100))$speed=null;
        $heading=$this->optionalNumber($data['heading_degrees']??null);if($heading!==null&&($heading<0||$heading>360))$heading=null;
        $provider=mb_substr(trim((string)($data['provider']??'')),0,30);
        $recorded=$this->clientTimestamp((string)($data['recorded_at']??''));
        $deviceId=mb_substr(trim($deviceId),0,190);if($deviceId==='')throw new RuntimeException('Dispositivo não identificado.');
        $deviceHash=hash('sha256',$tenantId.'|'.$userId.'|'.$deviceId);
        $unitId=$shift['unit_id']!==null?(int)$shift['unit_id']:null;

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$shift,$unitId,$orderId,$lat,$lng,$accuracy,$speed,$heading,$provider,$recorded,$deviceHash):array{
            $order=$pdo->prepare(Database::portableSql($pdo,'SELECT o.id,o.status,o.unit_id,dp.route_started_at,dp.arrived_at,dp.completed_at FROM orders o JOIN delivery_progress dp ON dp.tenant_id=o.tenant_id AND dp.order_id=o.id WHERE o.id=? AND o.tenant_id=? AND o.channel="delivery" AND o.assigned_delivery_user_id=? FOR UPDATE'));
            $order->execute([$orderId,$tenantId,$userId]);$row=$order->fetch();
            if(!$row)throw new RuntimeException('Pedido não está atribuído a este entregador.');
            $orderUnit=$row['unit_id']!==null?(int)$row['unit_id']:null;
            if($orderUnit!==$unitId)throw new RuntimeException('Pedido pertence a outra unidade.');
            if((string)$row['status']!=='out_for_delivery'||empty($row['route_started_at'])||!empty($row['arrived_at'])||!empty($row['completed_at']))throw new RuntimeException('O GPS só é registrado enquanto a rota estiver em andamento.');

            $last=$pdo->prepare('SELECT recorded_at,latitude,longitude FROM delivery_live_locations WHERE tenant_id=? AND delivery_user_id=? LIMIT 1');
            $last->execute([$tenantId,$userId]);$previous=$last->fetch();
            if($previous&&!empty($previous['recorded_at'])){
                $lastTs=strtotime((string)$previous['recorded_at']);$nowTs=strtotime($recorded);
                if($lastTs!==false&&$nowTs!==false&&$nowTs<$lastTs-2)throw new RuntimeException('Posição antiga descartada.');
            }

            if(Database::isSqlite($pdo)){
                $sql='INSERT INTO delivery_live_locations (tenant_id,delivery_user_id,shift_id,unit_id,order_id,latitude,longitude,accuracy_m,speed_mps,heading_degrees,recorded_at,received_at,device_id_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?) ON CONFLICT(tenant_id,delivery_user_id) DO UPDATE SET shift_id=excluded.shift_id,unit_id=excluded.unit_id,order_id=excluded.order_id,latitude=excluded.latitude,longitude=excluded.longitude,accuracy_m=excluded.accuracy_m,speed_mps=excluded.speed_mps,heading_degrees=excluded.heading_degrees,recorded_at=excluded.recorded_at,received_at=CURRENT_TIMESTAMP,device_id_hash=excluded.device_id_hash';
            }else{
                $sql='INSERT INTO delivery_live_locations (tenant_id,delivery_user_id,shift_id,unit_id,order_id,latitude,longitude,accuracy_m,speed_mps,heading_degrees,recorded_at,received_at,device_id_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,?) ON DUPLICATE KEY UPDATE shift_id=VALUES(shift_id),unit_id=VALUES(unit_id),order_id=VALUES(order_id),latitude=VALUES(latitude),longitude=VALUES(longitude),accuracy_m=VALUES(accuracy_m),speed_mps=VALUES(speed_mps),heading_degrees=VALUES(heading_degrees),recorded_at=VALUES(recorded_at),received_at=CURRENT_TIMESTAMP,device_id_hash=VALUES(device_id_hash)';
            }
            $pdo->prepare($sql)->execute([$tenantId,$userId,(int)$shift['id'],$unitId,$orderId,$lat,$lng,$accuracy,$speed,$heading,$recorded,$deviceHash]);

            $writeHistory=true;
            if($previous&&!empty($previous['recorded_at'])){$a=strtotime((string)$previous['recorded_at']);$b=strtotime($recorded);if($a!==false&&$b!==false&&$b-$a<10)$writeHistory=false;}
            if($writeHistory){
                $pdo->prepare('INSERT INTO delivery_location_events (tenant_id,delivery_user_id,shift_id,unit_id,order_id,latitude,longitude,accuracy_m,speed_mps,heading_degrees,provider,device_id_hash,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$tenantId,$userId,(int)$shift['id'],$unitId,$orderId,$lat,$lng,$accuracy,$speed,$heading,$provider?:null,$deviceHash,$recorded]);
            }
            return ['tracking'=>true,'order_id'=>$orderId,'recorded_at'=>$recorded,'stored_history'=>$writeHistory];
        });
    }

    public function liveForManager():array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        if(!Auth::can('delivery.assign')&&!Auth::can('reports.view'))throw new RuntimeException('Sem permissão para acompanhar entregadores.');
        $shift=(new WorkShiftService())->current();$unitId=$shift&&$shift['unit_id']!==null?(int)$shift['unit_id']:(new OperatingUnitService())->currentId();
        if(!$unitId)throw new RuntimeException('Escolha uma unidade para acompanhar as entregas.');
        $pdo=Database::connection();
        $sql='SELECT l.delivery_user_id,u.name delivery_name,l.order_id,l.latitude,l.longitude,l.accuracy_m,l.speed_mps,l.heading_degrees,l.recorded_at,l.received_at,o.status order_status,o.delivery_address,c.name customer_name,c.phone customer_phone,dp.route_started_at,dp.arrived_at FROM delivery_live_locations l JOIN users u ON u.id=l.delivery_user_id AND u.tenant_id=l.tenant_id JOIN orders o ON o.id=l.order_id AND o.tenant_id=l.tenant_id JOIN delivery_progress dp ON dp.order_id=o.id AND dp.tenant_id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE l.tenant_id=? AND l.unit_id=? AND o.status="out_for_delivery" AND dp.arrived_at IS NULL AND l.received_at>=? ORDER BY l.received_at DESC';
        $cutoff=gmdate('Y-m-d H:i:s',time()-180);
        $s=$pdo->prepare($sql);$s->execute([$tenantId,$unitId,$cutoff]);$rows=$s->fetchAll();
        foreach($rows as &$row){$row['fresh']=strtotime((string)$row['received_at'])!==false&&strtotime((string)$row['received_at'])>=time()-45;}unset($row);
        return $rows;
    }

    public function stopForOrder(int $tenantId,int $userId,int $orderId):void
    {
        if($tenantId<1||$userId<1||$orderId<1)return;
        Database::connection()->prepare('DELETE FROM delivery_live_locations WHERE tenant_id=? AND delivery_user_id=? AND order_id=?')->execute([$tenantId,$userId,$orderId]);
    }

    public function purgeHistory():int
    {
        $days=max(1,min(90,(int)env('DELIVERY_LOCATION_RETENTION_DAYS','14')));
        $pdo=Database::connection();$cutoff=gmdate('Y-m-d H:i:s',time()-($days*86400));
        $s=$pdo->prepare('DELETE FROM delivery_location_events WHERE received_at<?');$s->execute([$cutoff]);
        $pdo->prepare('DELETE FROM delivery_live_locations WHERE received_at<?')->execute([gmdate('Y-m-d H:i:s',time()-86400)]);
        return $s->rowCount();
    }

    private function clientTimestamp(string$value):string
    {
        $timestamp=strtotime(trim($value));if($timestamp===false)throw new RuntimeException('Horário da posição inválido.');
        if(abs(time()-$timestamp)>300)throw new RuntimeException('Horário da posição fora da janela permitida.');
        return gmdate('Y-m-d H:i:s',$timestamp);
    }
    private function number(mixed$value,string$label):float{if(!is_numeric($value))throw new RuntimeException(ucfirst($label).' inválida.');return(float)$value;}
    private function optionalNumber(mixed$value):?float{return$value===null||$value===''?null:(is_numeric($value)?(float)$value:null);}
}
