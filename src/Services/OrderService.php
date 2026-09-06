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

    public function changeStatus(int$orderId,string$target,string$source='panel'):array
    {
        $tenantId=Auth::tenantId();if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido ou empresa inválidos.');$target=strtolower(trim($target));if(!array_key_exists($target,self::TRANSITIONS))throw new RuntimeException('Status inválido.');
        $result=Database::transaction(function(PDO$pdo)use($tenantId,$orderId,$target,$source):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$orderId,$tenantId]);$order=$s->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(in_array($source,['panel','accept','kitchen','dispatch'],true)){$unitId=(new OperatingUnitService())->currentId();if($unitId&&$order['unit_id']!==null&&(int)$order['unit_id']!==$unitId)throw new RuntimeException('Este pedido pertence a outra unidade operacional.');}
            $current=(string)$order['status'];if($current===$target)return$order;if(!in_array($target,self::TRANSITIONS[$current]??[],true))throw new RuntimeException("Transição {$current} → {$target} não permitida.");
            if($target==='completed'){if($order['payment_status']!=='paid')throw new RuntimeException('Pedido não pago não pode ser finalizado.');if(in_array((string)$order['channel'],['counter','pickup'],true)&&$source!=='fulfillment')$this->assertPickupFullyFulfilled($pdo,$tenantId,$orderId);}
            if($target==='served'){if($order['channel']!=='table')throw new RuntimeException('Somente pedido de mesa pode ser marcado como servido.');if($current!=='ready')throw new RuntimeException('O pedido precisa estar pronto antes de ser servido.');}
            if($target==='cancelled'){if($source==='panel'&&!Auth::can('cancellations.approve'))throw new RuntimeException('Solicite o cancelamento para autorização do Gerente/ADM.');$paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$tenantId,$orderId]);$paidCents=(int)$paid->fetchColumn();if($paidCents>0||$order['payment_status']==='paid')throw new RuntimeException('Pedido possui valor já recebido. Estorne os pagamentos antes de cancelar.');$active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$tenantId,$orderId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Há uma cobrança em processamento. Aguarde ou encerre a cobrança antes de cancelar.');}
            if($source==='accept'){if(!Auth::can('orders.dispatch')&&!Auth::can('orders.manage'))throw new RuntimeException('Sua função não pode aceitar pedidos.');if($current!=='pending'||$target!=='confirmed')throw new RuntimeException('Somente pedido novo pendente pode ser aceito.');if(!in_array($order['channel'],['counter','table','delivery','pickup'],true))throw new RuntimeException('Pedido não pertence à operação de atendimento.');}
            if($source==='kitchen'){if(!in_array($target,['preparing','ready'],true))throw new RuntimeException('A cozinha só pode iniciar preparo ou marcar como pronto.');if(!in_array($order['channel'],['counter','table','delivery','pickup'],true))throw new RuntimeException('Pedido não pertence à operação da cozinha.');}
            if($source==='delivery'){if(!Auth::can('orders.delivery'))throw new RuntimeException('Sua conta não possui permissão de entrega.');if(!in_array($target,['out_for_delivery','completed'],true))throw new RuntimeException('A entrega só pode retirar ou concluir o pedido.');if((int)($order['assigned_delivery_user_id']??0)!==(int)Auth::id())throw new RuntimeException('Pedido não está atribuído a este entregador.');if($order['channel']!=='delivery')throw new RuntimeException('Pedido não é de delivery.');$shift=(new WorkShiftService())->current();if($shift&&$shift['unit_id']!==null&&$order['unit_id']!==null&&(int)$shift['unit_id']!==(int)$order['unit_id'])throw new RuntimeException('A entrega pertence a outra unidade.');}
            if($source==='dispatch'){if($current!=='ready')throw new RuntimeException('O Balcão só pode liberar pedido pronto.');if($order['channel']==='table'&&$target!=='served')throw new RuntimeException('Pedido de mesa deve ser marcado como servido.');if(in_array($order['channel'],['counter','pickup'],true)&&$target!=='completed')throw new RuntimeException('Pedido local pronto deve ser concluído na retirada.');if($order['channel']==='delivery')throw new RuntimeException('Delivery deve ser atribuído a um entregador antes da saída.');}
            if($target==='confirmed')$pdo->prepare('UPDATE stock_reservations SET expires_at=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId,$orderId]);elseif($target==='cancelled')(new StockReservationService())->release($pdo,$tenantId,$orderId);
            $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$target,$orderId,$tenantId]);(new OrderHistoryService())->record($pdo,$tenantId,$orderId,$current,$target,$source,$this->historyNote($target,$source));Auth::audit('order.status','order',(string)$orderId,['from'=>$current,'to'=>$target,'source'=>$source,'unit_id'=>$order['unit_id']??null]);$order['status']=$target;return$order;
        });$this->publishOperationalNotification($result,$target);return$result;
    }

    private function assertPickupFullyFulfilled(PDO$pdo,int$tenantId,int$orderId):void
    {
        $s=$pdo->prepare('SELECT oi.id,oi.name_snapshot,oi.quantity,COALESCE((SELECT SUM(f.quantity) FROM order_item_fulfillments f WHERE f.tenant_id=? AND f.order_item_id=oi.id),0)-COALESCE((SELECT SUM(c.quantity) FROM order_item_fulfillment_corrections c WHERE c.tenant_id=? AND c.order_item_id=oi.id),0) fulfilled_quantity FROM order_items oi WHERE oi.order_id=? ORDER BY oi.id');$s->execute([$tenantId,$tenantId,$orderId]);$pending=[];foreach($s->fetchAll()as$item){$remaining=max(0.0,(float)$item['quantity']-max(0.0,(float)$item['fulfilled_quantity']));if($remaining>.0005)$pending[]=$item['name_snapshot'].' (falta '.$this->formatQty($remaining).')';}if($pending)throw new RuntimeException('Ainda há itens não entregues. Use Retirada / QR: '.implode(', ',array_slice($pending,0,4)).(count($pending)>4?'…':''));
    }

    private function formatQty(float$qty):string{if(abs($qty-round($qty))<.0005)return(string)(int)round($qty);return rtrim(rtrim(number_format($qty,3,',','.'),'0'),',');}
    private function historyNote(string$target,string$source):string{if($source==='accept'&&$target==='confirmed')return'Pedido aceito pela operação.';return match($target){'preparing'=>'Preparação iniciada.','ready'=>'Pedido marcado como pronto.','served'=>'Pedido servido na mesa.','out_for_delivery'=>'Pedido retirado pelo entregador e colocado em rota.','completed'=>'Pedido concluído.','cancelled'=>'Pedido cancelado.',default=>'Status do pedido atualizado.'};}
    private function publishOperationalNotification(array$order,string$target):void
    {
        if(!in_array((string)($order['channel']??''),['counter','table','delivery','pickup'],true))return;try{$notifications=new NotificationService();$id=(int)$order['id'];$unitId=!empty($order['unit_id'])?(int)$order['unit_id']:null;$expires=gmdate('Y-m-d H:i:s',time()+86400);if($target==='confirmed')$notifications->publishToPermission('orders.kitchen','operation','order.new','Novo pedido #'.$id,'Um novo pedido confirmado entrou na fila da cozinha.','order',(string)$id,'order:'.$id.':kitchen-confirmed','info',$expires,$unitId);if($target==='ready')$notifications->publishToPermission('orders.dispatch','operation','order.ready','Pedido #'.$id.' pronto','A cozinha marcou o pedido como pronto para despacho.','order',(string)$id,'order:'.$id.':ready-dispatch','success',$expires,$unitId);}catch(\Throwable){}
    }
}
