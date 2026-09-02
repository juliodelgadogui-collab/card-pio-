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
            $s=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            $current=(string)$order['status'];if($current===$newStatus)return $order;if(!in_array($newStatus,self::TRANSITIONS[$current]??[],true))throw new RuntimeException('Transição de status inválida: '.$current.' → '.$newStatus.'.');
            if($newStatus==='completed'&&$order['payment_status']!=='paid')throw new RuntimeException('Pedido não pago não pode ser finalizado.');
            if($newStatus==='cancelled'&&$order['payment_status']==='paid')throw new RuntimeException('Pedido pago não pode ser cancelado diretamente. Faça o reembolso antes.');
            if($newStatus==='out_for_delivery'&&$order['channel']!=='delivery')throw new RuntimeException('Somente pedidos de delivery podem sair para entrega.');
            $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$newStatus,$orderId,$tenantId]);Auth::audit('order.status','order',(string)$orderId,['from'=>$current,'to'=>$newStatus]);$order['status']=$newStatus;return $order;
        });
    }
}
