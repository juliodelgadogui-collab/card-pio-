<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderReopenService
{
    public function candidates():array
    {
        Auth::requirePermission('orders.reopen');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $shift=(new WorkShiftService())->current();
        if(!$shift||!in_array((string)$shift['mode'],['operation','pay'],true))throw new RuntimeException('A reabertura deve ser feita durante um turno de Operação ou Pay.');
        $unitId=$shift['unit_id']!==null&&$shift['unit_id']!==''?(int)$shift['unit_id']:null;$pdo=Database::connection();
        $sql='SELECT o.id,o.unit_id,o.channel,o.payment_status,o.total_cents,o.tab_id,o.updated_at,c.name customer_name,rt.name table_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id WHERE o.tenant_id=? AND o.status="completed"';$args=[$tenantId];
        if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}$sql.=' ORDER BY o.updated_at DESC,o.id DESC LIMIT 50';
        $s=$pdo->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
        foreach($rows as &$row){[$eligible,$reason]=$this->eligibility($pdo,$tenantId,$row);$row['reopen_eligible']=$eligible?1:0;$row['reopen_block_reason']=$reason;}unset($row);
        return $rows;
    }

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
            [$eligible,$blockReason]=$this->eligibility($pdo,$tenantId,$order);if(!$eligible)throw new RuntimeException($blockReason);

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

    private function eligibility(PDO $pdo,int $tenantId,array $order):array
    {
        if((string)($order['payment_status']??'')!=='paid')return [false,'Pedido finalizado sem quitação íntegra exige correção financeira antes da reabertura.'];
        if((string)($order['channel']??'')==='delivery')return [false,'Delivery concluído não é reaberto. Crie uma nova entrega para preservar rota, comissão e histórico do entregador.'];
        $orderId=(int)$order['id'];
        $paid=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$paid->execute([$tenantId,$orderId]);
        if((int)$paid->fetchColumn()!==(int)$order['total_cents'])return [false,'O total financeiro confirmado não coincide com o total do pedido.'];
        $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized")');$active->execute([$tenantId,$orderId]);
        if((int)$active->fetchColumn()>0)return [false,'Existe cobrança em processamento para este pedido.'];
        $refund=$pdo->prepare('SELECT COUNT(*) FROM refunds WHERE tenant_id=? AND order_id=? AND status IN ("requested","provider_pending","provider_succeeded","completed")');$refund->execute([$tenantId,$orderId]);
        if((int)$refund->fetchColumn()>0)return [false,'Pedido com estorno solicitado ou concluído não pode ser reaberto.'];
        if((string)($order['channel']??'')==='table'){
            $tabId=(int)($order['tab_id']??0);if($tabId<1)return [false,'Pedido de mesa sem comanda vinculada não pode ser reaberto.'];
            $tab=$pdo->prepare('SELECT status FROM tabs WHERE id=? AND tenant_id=? LIMIT 1');$tab->execute([$tabId,$tenantId]);if((string)$tab->fetchColumn()!=='open')return [false,'A comanda desta mesa já está fechada.'];
        }
        return [true,''];
    }
}
