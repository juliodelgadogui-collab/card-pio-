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
        'ready'=>['served','out_for_delivery','completed','cancelled'],
        'served'=>['completed','cancelled'],
        'out_for_delivery'=>['completed','cancelled'],
        'completed'=>[],
        'cancelled'=>[],
    ];

    public function changeStatus(int $orderId,string $target,string $source='panel'):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido ou empresa inválidos.');$target=strtolower(trim($target));if(!array_key_exists($target,self::TRANSITIONS))throw new RuntimeException('Status inválido.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId,$target,$source):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');$current=(string)$order['status'];if($current===$target)return $order;if(!in_array($target,self::TRANSITIONS[$current]??[],true))throw new RuntimeException("Transição {$current} → {$target} não permitida.");

            if($target==='completed'&&$order['payment_status']!=='paid')throw new RuntimeException('Pedido não pago não pode ser finalizado.');
            if($target==='served'){
                if($order['channel']!=='table')throw new RuntimeException('Somente pedido de mesa pode ser marcado como servido.');
                if($current!=='ready')throw new RuntimeException('O pedido precisa estar pronto antes de ser servido.');
            }
            if($target==='cancelled'){
                $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$tenantId,$orderId]);$paidCents=(int)$paid->fetchColumn();
                if($paidCents>0||$order['payment_status']==='paid')throw new RuntimeException('Pedido possui valor já recebido. Estorne os pagamentos antes de cancelar.');
                $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$tenantId,$orderId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Há uma cobrança em processamento. Aguarde ou encerre a cobrança antes de cancelar.');
            }

            if($source==='kitchen'){
                if(!in_array($target,['preparing','ready'],true))throw new RuntimeException('A cozinha só pode iniciar preparo ou marcar como pronto.');
                if(!in_array($order['channel'],['counter','table','delivery','pickup'],true))throw new RuntimeException('Pedido não pertence à operação da cozinha.');
            }
            if($source==='delivery'){
                if(!in_array($target,['out_for_delivery','completed'],true))throw new RuntimeException('A entrega só pode retirar ou concluir o pedido.');
                if(Auth::role()==='delivery'&&(int)($order['assigned_delivery_user_id']??0)!==(int)Auth::id())throw new RuntimeException('Pedido não está atribuído a este entregador.');
                if($order['channel']!=='delivery')throw new RuntimeException('Pedido não é de delivery.');
            }
            if($source==='dispatch'){
                if($current!=='ready')throw new RuntimeException('O Balcão só pode liberar pedido pronto.');
                if($order['channel']==='table'&&$target!=='served')throw new RuntimeException('Pedido de mesa deve ser marcado como servido.');
                if(in_array($order['channel'],['counter','pickup'],true)&&$target!=='completed')throw new RuntimeException('Pedido local pronto deve ser concluído na retirada.');
                if($order['channel']==='delivery')throw new RuntimeException('Delivery deve ser atribuído a um entregador antes da saída.');
            }

            if($target==='confirmed')$pdo->prepare('UPDATE stock_reservations SET expires_at=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);
            elseif($target==='cancelled')(new StockReservationService())->release($pdo,$tenantId,$orderId);

            $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$target,$orderId,$tenantId]);
            Auth::audit('order.status','order',(string)$orderId,['from'=>$current,'to'=>$target,'source'=>$source]);$order['status']=$target;return $order;
        });
    }
}
