<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderReopenService
{
    public function reopen(int $orderId,string $reason):array
    {
        Auth::requirePermission('orders.reopen');
        $tenantId=Auth::tenantId();$userId=Auth::id();$reason=mb_substr(trim($reason),0,500);
        if(!$tenantId||!$userId||$orderId<1)throw new RuntimeException('Pedido inválido.');
        if(mb_strlen($reason)<5)throw new RuntimeException('Informe o motivo da reabertura.');

        $shift=(new WorkShiftService())->current();
        if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('A reabertura deve ser feita durante um turno de Operação ou Pay.');
        $unitId=$shift['unit_id']!==null&&$shift['unit_id']!==''?(int)$shift['unit_id']:null;

        $order=Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$reason,$unitId):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE'));
            $s->execute([$orderId,$tenantId]);$order=$s->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if($unitId!==null&&($order['unit_id']===null||(int)$order['unit_id']!==$unitId))throw new RuntimeException('Pedido pertence a outra unidade.');
            if((string)$order['status']!=='completed')throw new RuntimeException('Somente pedido finalizado pode ser reaberto. Pedido cancelado nunca é reaberto.');
            if((string)$order['payment_status']!=='paid')throw new RuntimeException('Pedido finalizado sem quitação íntegra exige correção financeira antes da reabertura.');
            if((string)$order['channel']==='delivery')throw new RuntimeException('Delivery concluído não é reaberto. Crie uma nova entrega para preservar rota, comissão e histórico do entregador.');

            $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');
            $paid->execute([$tenantId,$orderId]);$paidCents=(int)$paid->fetchColumn();
            if($paidCents!==(int)$order['total_cents'])throw new RuntimeException('O total financeiro confirmado não coincide com o total do pedido. Corrija antes de reabrir.');

            $activePayment=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');
            $activePayment->execute([$tenantId,$orderId]);
            if((int)$activePayment->fetchColumn()>0)throw new RuntimeException('Existe cobrança em processamento para este pedido.');

            $refund=$pdo->prepare('SELECT COUNT(*) FROM refunds WHERE tenant_id=? AND order_id=? AND status IN ("requested","provider_pending","provider_succeeded","completed")');
            $refund->execute([$tenantId,$orderId]);
            if((int)$refund->fetchColumn()>0)throw new RuntimeException('Pedido com estorno solicitado ou concluído não pode ser reaberto.');

            if((string)$order['channel']==='table'){
                $tabId=(int)($order['tab_id']??0);if($tabId<1)throw new RuntimeException('Pedido de mesa sem comanda vinculada não pode ser reaberto.');
                $tab=$pdo->prepare('SELECT status FROM tabs WHERE id=? AND tenant_id=? LIMIT 1');$tab->execute([$tabId,$tenantId]);
                if((string)$tab->fetchColumn()!=='open')throw new RuntimeException('A comanda desta mesa já está fechada. Reabra somente pedidos de uma comanda ainda aberta.');
            }

            $target='ready';
            $pdo->prepare('UPDATE orders SET status=? WHERE id=? AND tenant_id=?')->execute([$target,$orderId,$tenantId]);
            (new OrderHistoryService())->record($pdo,$tenantId,$orderId,'completed',$target,'reopen','Reaberto pelo gerente: '.$reason,$userId);
            Auth::audit('order.reopened','order',(string)$orderId,['from'=>'completed','to'=>$target,'reason'=>$reason,'unit_id'=>$unitId,'source'=>'eventmenu_go_manager']);
            $order['status']=$target;$order['reopen_reason']=$reason;return $order;
        });

        try{
            (new NotificationService())->publishToPermission(
                'orders.dispatch','operation','order.reopened','Pedido #'.$orderId.' reaberto',
                'Um pedido finalizado foi reaberto pelo gerente e voltou para Prontos.','order',(string)$orderId,
                'order:'.$orderId.':reopened:'.time(),'warning',gmdate('Y-m-d H:i:s',time()+86400)
            );
        }catch(\Throwable){}
        return $order;
    }
}
