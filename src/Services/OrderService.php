<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderService
{
    private const TRANSITIONS=[
        'draft'=>['pending','confirmed','cancelled'],
        'pending'=>['confirmed','cancelled'],
        'confirmed'=>['preparing','ready','cancelled'],
        'preparing'=>['ready','cancelled'],
        'ready'=>['out_for_delivery','completed','cancelled'],
        'out_for_delivery'=>['completed','cancelled'],
        'completed'=>[],
        'cancelled'=>[],
    ];

    public function changeStatus(int $orderId,string $target,string $source='panel'):array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido ou empresa inválidos.');
        $target=strtolower(trim($target));
        if(!array_key_exists($target,self::TRANSITIONS))throw new RuntimeException('Status inválido.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$target,$source):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            $current=(string)$order['status'];
            if($current===$target)return $order;
            if(!in_array($target,self::TRANSITIONS[$current]??[],true))throw new RuntimeException("Transição {$current} → {$target} não permitida.");

            if($target==='completed'&&$order['payment_status']!=='paid')throw new RuntimeException('Pedido não pago não pode ser finalizado.');
            if($target==='cancelled'&&$order['payment_status']==='paid')throw new RuntimeException('Pedido pago exige estorno antes do cancelamento.');

            if($source==='kitchen'){
                if(!in_array($target,['preparing','ready'],true))throw new RuntimeException('A cozinha só pode iniciar preparo ou marcar como pronto.');
                if(!in_array($order['channel'],['counter','table','delivery','pickup'],true))throw new RuntimeException('Pedido não pertence à operação da cozinha.');
            }

            if($source==='delivery'){
                if(!in_array($target,['out_for_delivery','completed'],true))throw new RuntimeException('A entrega só pode retirar ou concluir o pedido.');
                if(Auth::role()==='delivery'&&(int)($order['assigned_delivery_user_id']??0)!==(int)Auth::id())throw new RuntimeException('Pedido não está atribuído a este entregador.');
                if($order['channel']!=='delivery')throw new RuntimeException('Pedido não é de delivery.');
            }

            $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$target,$orderId,$tenantId]);
            Auth::audit('order.status','order',(string)$orderId,['from'=>$current,'to'=>$target,'source'=>$source]);
            $order['status']=$target;
            return $order;
        });
    }
}
