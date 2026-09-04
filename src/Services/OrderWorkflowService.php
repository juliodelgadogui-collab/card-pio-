<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderWorkflowService
{
    private const TRANSITIONS=[
        'draft'=>['pending','cancelled'],
        'pending'=>['confirmed','cancelled'],
        'confirmed'=>['preparing','ready','cancelled'],
        'preparing'=>['ready','cancelled'],
        'ready'=>['out_for_delivery','completed','cancelled'],
        'out_for_delivery'=>['completed','cancelled'],
        'completed'=>[],
        'cancelled'=>[],
    ];

    public function transition(int $tenantId,int $orderId,string $newStatus):array
    {
        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$newStatus){
            $s=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $s->execute([$orderId,$tenantId]);
            $order=$s->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');

            $current=(string)$order['status'];
            if($current===$newStatus)return $order;
            if(!in_array($newStatus,self::TRANSITIONS[$current]??[],true))throw new RuntimeException('Transição de status inválida: '.$current.' → '.$newStatus.'.');
            if($newStatus==='completed'&&$order['payment_status']!=='paid')throw new RuntimeException('Pedido não pago não pode ser finalizado.');
            if($newStatus==='cancelled'&&$order['payment_status']==='paid')throw new RuntimeException('Pedido pago não pode ser cancelado diretamente. Faça o reembolso antes.');
            if($newStatus==='out_for_delivery'&&$order['channel']!=='delivery')throw new RuntimeException('Somente pedidos de delivery podem sair para entrega.');
            if($newStatus==='out_for_delivery'&&empty($order['assigned_delivery_user_id']))throw new RuntimeException('Atribua um entregador antes de sair para entrega.');

            if(in_array($newStatus,['preparing','ready'],true)&&!empty($order['scheduled_for'])){
                $settingsStmt=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');
                $settingsStmt->execute([$tenantId]);
                $settings=json_decode((string)($settingsStmt->fetchColumn()?:'{}'),true);if(!is_array($settings))$settings=[];
                $lead=max(0,min(1440,(int)($settings['scheduled_kds_lead_minutes']??45)));
                $scheduled=new \DateTimeImmutable((string)$order['scheduled_for'],new \DateTimeZone('UTC'));
                $earliest=$scheduled->modify('-'.$lead.' minutes');
                $now=new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
                if($now<$earliest)throw new RuntimeException('Pedido agendado ainda não entrou na janela de preparo.');
            }

            if($newStatus==='cancelled'){
                (new StockService())->reverseForOrder($pdo,$tenantId,$orderId);
                $coupon=$pdo->prepare('SELECT id,coupon_id FROM coupon_reservations WHERE tenant_id=? AND order_id=? AND status="reserved" FOR UPDATE');
                $coupon->execute([$tenantId,$orderId]);
                if($reservation=$coupon->fetch()){
                    $pdo->prepare('UPDATE coupon_reservations SET status="released" WHERE id=?')->execute([$reservation['id']]);
                    $pdo->prepare('UPDATE coupons SET reserved_count=GREATEST(0,reserved_count-1) WHERE id=? AND tenant_id=?')->execute([$reservation['coupon_id'],$tenantId]);
                }
            }

            if($order['channel']==='delivery'){
                if($newStatus==='out_for_delivery'){
                    $pdo->prepare('UPDATE orders SET status=?,delivery_started_at=COALESCE(delivery_started_at,NOW()) WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);
                    $this->deliveryEvent($pdo,$tenantId,$orderId,'out_for_delivery',['from'=>$current]);
                }elseif($newStatus==='completed'){
                    $pdo->prepare('UPDATE orders SET status=?,delivered_at=COALESCE(delivered_at,NOW()) WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);
                    $this->deliveryEvent($pdo,$tenantId,$orderId,'delivered',['from'=>$current]);
                }elseif($newStatus==='cancelled'){
                    $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);
                    $this->deliveryEvent($pdo,$tenantId,$orderId,'cancelled',['from'=>$current]);
                }else{
                    $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);
                }
            }else{
                $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);
            }

            Auth::audit('order.status','order',(string)$orderId,['from'=>$current,'to'=>$newStatus]);
            $order['status']=$newStatus;
            return $order;
        });
    }

    private function deliveryEvent(PDO $pdo,int $tenantId,int $orderId,string $event,array $metadata=[]):void
    {
        $pdo->prepare('INSERT INTO delivery_events (tenant_id,order_id,user_id,event_type,metadata) VALUES (?,?,?,?,?)')->execute([$tenantId,$orderId,Auth::id(),$event,$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]);
    }
}
