<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderUnitRoutingService
{
    public function pending(): array
    {
        $this->requireRoutingPermission();
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='operation')throw new RuntimeException('A triagem de Delivery é feita durante turno de Operação.');
        $s=Database::connection()->prepare('SELECT o.id,o.public_token,o.status,o.payment_status,o.total_cents,o.delivery_address,o.created_at,c.name customer_name,c.phone customer_phone FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.channel="delivery" AND o.unit_id IS NULL AND o.status NOT IN ("cancelled","completed") ORDER BY o.id');
        $s->execute([$tenantId]);return $s->fetchAll();
    }

    public function assign(int $orderId,int $unitId): array
    {
        $this->requireRoutingPermission();
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$orderId<1||$unitId<1)throw new RuntimeException('Pedido ou unidade inválidos.');
        $shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='operation')throw new RuntimeException('A triagem de Delivery é feita durante turno de Operação.');
        $allowedUnit=false;foreach((new OperatingUnitService())->availableForCurrentUser() as $unit){if((int)$unit['id']===$unitId){$allowedUnit=true;break;}}
        if(!$allowedUnit)throw new RuntimeException('Você não possui acesso à unidade escolhida.');

        $order=Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$unitId):array{
            $u=$pdo->prepare(Database::portableSql($pdo,'SELECT id,name,active FROM operating_units WHERE id=? AND tenant_id=? FOR UPDATE'));$u->execute([$unitId,$tenantId]);$unit=$u->fetch();if(!$unit||(int)$unit['active']!==1)throw new RuntimeException('Unidade indisponível.');
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($order['channel']!=='delivery')throw new RuntimeException('Somente pedido de Delivery pode ser roteado por unidade.');
            if(in_array($order['status'],['cancelled','completed'],true))throw new RuntimeException('Pedido já foi finalizado.');
            if($order['unit_id']!==null){if((int)$order['unit_id']===$unitId){$order['unit_name']=$unit['name'];return $order;}throw new RuntimeException('Este pedido já pertence a outra unidade.');}
            if($order['assigned_delivery_user_id']!==null)throw new RuntimeException('Pedido já possui entregador e não pode mudar de unidade.');
            $pdo->prepare('UPDATE orders SET unit_id=? WHERE id=? AND tenant_id=? AND unit_id IS NULL')->execute([$unitId,$orderId,$tenantId]);
            $order['unit_id']=$unitId;$order['unit_name']=$unit['name'];return $order;
        });

        Auth::audit('order.unit_assigned','order',(string)$orderId,['unit_id'=>$unitId,'source'=>'eventmenu_go_triage']);
        try{
            (new NotificationService())->publishToPermission(
                'orders.view','operation','delivery.routed','Delivery #'.$orderId.' direcionado',
                'O pedido foi direcionado para a unidade '.($order['unit_name']??('#'.$unitId)).'.','order',(string)$orderId,
                'delivery:'.$orderId.':unit:'.$unitId,'info',gmdate('Y-m-d H:i:s',time()+86400)
            );
        }catch(\Throwable){}
        return $order;
    }

    private function requireRoutingPermission(): void
    {
        if(!Auth::can('delivery.assign')&&!Auth::can('orders.manage'))throw new RuntimeException('Sua função não pode direcionar pedidos entre unidades.');
    }
}
