<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class RefundService
{
    public function requestFull(int $paymentId, string $reason): array
    {
        Auth::requirePermission('refunds.manage');
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId) throw new RuntimeException('Empresa ou usuário inválido.');
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < 5) throw new RuntimeException('Informe o motivo do estorno.');

        $payment = $this->loadPayment($tenantId, $paymentId);
        $this->preflight($payment, $userId);
        $key = 'full-refund:' . $tenantId . ':' . $paymentId;

        $refund = Database::transaction(function (PDO $pdo) use ($tenantId, $userId, $payment, $reason, $key): array {
            $find = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM refunds WHERE tenant_id=? AND payment_id=? FOR UPDATE'));
            $find->execute([$tenantId, $payment['id']]);
            if ($existing = $find->fetch()) return $existing;

            $stmt = $pdo->prepare('INSERT INTO refunds (tenant_id,payment_id,order_id,requested_by,provider,amount_cents,currency,status,reason,idempotency_key) VALUES (?,?,?,?,?,?,?,"requested",?,?)');
            $stmt->execute([$tenantId, $payment['id'], $payment['order_id'], $userId, $payment['provider'], $payment['amount_cents'], $payment['currency'], $reason, $key]);
            $id = (int)$pdo->lastInsertId();
            Auth::audit('refund.requested', 'refund', (string)$id, ['payment_id' => (int)$payment['id'], 'order_id' => (int)$payment['order_id'], 'duplicate_payment' => $payment['status']==='duplicate_paid']);
            $s = $pdo->prepare('SELECT * FROM refunds WHERE id=?');$s->execute([$id]);
            return $s->fetch() ?: throw new RuntimeException('Falha ao criar solicitação de estorno.');
        });

        if ($refund['status'] === 'completed') return $refund;
        if ($refund['status'] === 'provider_succeeded') return $this->finalize((int)$refund['id']);
        if ($refund['status'] === 'provider_pending') return $this->reconcile((int)$refund['id']);

        try {
            $result = (new ProviderRefundService())->refund($payment, $key);
            $status = $result['completed'] ? 'provider_succeeded' : 'provider_pending';
            $pdo = Database::connection();
            $stmt = $pdo->prepare('UPDATE refunds SET provider_refund_id=?,status=?,provider_payload=?,error_message=NULL,provider_succeeded_at=CASE WHEN ?="provider_succeeded" THEN CURRENT_TIMESTAMP ELSE provider_succeeded_at END WHERE id=? AND tenant_id=?');
            $stmt->execute([(string)($result['provider_refund_id'] ?? ''), $status, json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE), $status, $refund['id'], $tenantId]);
            if ($status === 'provider_succeeded') return $this->finalize((int)$refund['id']);
            $s=$pdo->prepare('SELECT * FROM refunds WHERE id=?');$s->execute([$refund['id']]);return $s->fetch();
        } catch (Throwable $e) {
            Database::connection()->prepare('UPDATE refunds SET status="failed",error_message=? WHERE id=? AND tenant_id=?')->execute([mb_substr($e->getMessage(),0,1000),$refund['id'],$tenantId]);
            Auth::audit('refund.failed','refund',(string)$refund['id'],['error'=>$e->getMessage()]);
            throw $e;
        }
    }

    public function reconcile(int $refundId): array
    {
        Auth::requirePermission('refunds.manage');
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa inválida.');
        $pdo = Database::connection();
        $s=$pdo->prepare('SELECT * FROM refunds WHERE id=? AND tenant_id=?');$s->execute([$refundId,$tenantId]);$refund=$s->fetch();
        if(!$refund) throw new RuntimeException('Estorno não encontrado.');
        if($refund['status']==='completed') return $refund;
        if($refund['status']==='provider_succeeded') return $this->finalize($refundId);
        if($refund['status']!=='provider_pending') throw new RuntimeException('Este estorno não está aguardando confirmação do provedor.');
        $payment=$this->loadPayment($tenantId,(int)$refund['payment_id']);
        $result=(new ProviderRefundService())->check($refund,$payment);
        $pdo->prepare('UPDATE refunds SET provider_payload=?,status=?,provider_succeeded_at=CASE WHEN ?="provider_succeeded" THEN CURRENT_TIMESTAMP ELSE provider_succeeded_at END WHERE id=? AND tenant_id=?')->execute([
            json_encode($result['payload']??[],JSON_UNESCAPED_UNICODE),$result['completed']?'provider_succeeded':'provider_pending',$result['completed']?'provider_succeeded':'provider_pending',$refundId,$tenantId
        ]);
        return $result['completed']?$this->finalize($refundId):$refund;
    }

    private function loadPayment(int $tenantId,int $paymentId): array
    {
        $stmt=Database::connection()->prepare('SELECT p.*,o.status order_status,o.payment_status order_payment_status,o.customer_id,o.coupon_id,o.promoter_id FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.tenant_id=? LIMIT 1');
        $stmt->execute([$paymentId,$tenantId]);$payment=$stmt->fetch();
        if(!$payment) throw new RuntimeException('Pagamento não encontrado.');
        return $payment;
    }

    private function preflight(array $payment,int $userId): void
    {
        $duplicate=$payment['status']==='duplicate_paid';
        if(!$duplicate&&($payment['status']!=='paid'||$payment['order_payment_status']!=='paid')) throw new RuntimeException('Somente pagamento integralmente confirmado pode ser estornado.');
        if($duplicate){
            if($payment['provider']==='manual') throw new RuntimeException('Pagamento duplicado manual exige conferência do caixa.');
            return;
        }
        if($payment['order_status']==='cancelled') throw new RuntimeException('Pedido já está cancelado.');
        $pdo=Database::connection();
        $t=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND order_id=? AND status="checked_in"');$t->execute([$payment['tenant_id'],$payment['order_id']]);
        if((int)$t->fetchColumn()>0) throw new RuntimeException('Há ingresso já utilizado neste pedido. O estorno automático foi bloqueado.');
        $c=$pdo->prepare('SELECT COUNT(*) FROM promoter_commissions WHERE tenant_id=? AND order_id=? AND status="paid"');$c->execute([$payment['tenant_id'],$payment['order_id']]);
        if((int)$c->fetchColumn()>0) throw new RuntimeException('A comissão deste pedido já foi paga. Regularize a comissão antes do estorno.');
        if($payment['provider']==='manual'){
            $cash=$pdo->prepare('SELECT id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1');$cash->execute([$payment['tenant_id'],$userId]);
            if(!$cash->fetchColumn()) throw new RuntimeException('Abra seu turno de caixa antes de devolver um pagamento em dinheiro.');
        }
    }

    private function finalize(int $refundId): array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        return Database::transaction(function(PDO $pdo)use($refundId,$tenantId):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM refunds WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$refundId,$tenantId]);$refund=$s->fetch();
            if(!$refund)throw new RuntimeException('Estorno não encontrado.');
            if($refund['status']==='completed')return $refund;
            if($refund['status']!=='provider_succeeded')throw new RuntimeException('O provedor ainda não confirmou o estorno.');
            $p=$pdo->prepare(Database::portableSql($pdo,'SELECT p.*,o.customer_id,o.coupon_id,o.promoter_id,o.payment_status order_payment_status FROM payments p JOIN orders o ON o.id=p.order_id WHERE p.id=? AND p.tenant_id=? FOR UPDATE'));$p->execute([$refund['payment_id'],$tenantId]);$payment=$p->fetch();
            if(!$payment)throw new RuntimeException('Pagamento do estorno não encontrado.');

            // Cobrança duplicada nunca gerou efeitos de negócio; ao estornar, não altera o pedido vencedor.
            if($payment['status']==='duplicate_paid'){
                $pdo->prepare('UPDATE payments SET status="refunded" WHERE id=? AND tenant_id=?')->execute([$refund['payment_id'],$tenantId]);
                $pdo->prepare('UPDATE refunds SET status="completed",completed_at=CURRENT_TIMESTAMP,error_message=NULL WHERE id=? AND tenant_id=?')->execute([$refundId,$tenantId]);
                Auth::audit('refund.duplicate_completed','refund',(string)$refundId,['payment_id'=>(int)$refund['payment_id'],'order_id'=>(int)$refund['order_id'],'amount_cents'=>(int)$refund['amount_cents']]);
                $s=$pdo->prepare('SELECT * FROM refunds WHERE id=?');$s->execute([$refundId]);return $s->fetch();
            }

            $checked=$pdo->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND order_id=? AND status="checked_in"');$checked->execute([$tenantId,$refund['order_id']]);if((int)$checked->fetchColumn()>0)throw new RuntimeException('Ingresso utilizado impede a conclusão do estorno.');
            $paidCommission=$pdo->prepare('SELECT COUNT(*) FROM promoter_commissions WHERE tenant_id=? AND order_id=? AND status="paid"');$paidCommission->execute([$tenantId,$refund['order_id']]);if((int)$paidCommission->fetchColumn()>0)throw new RuntimeException('Comissão paga impede a conclusão automática do estorno.');

            $stocks=$pdo->prepare('SELECT product_id,SUM(quantity) qty FROM stock_movements WHERE tenant_id=? AND order_id=? AND type="out" GROUP BY product_id');$stocks->execute([$tenantId,$refund['order_id']]);
            foreach($stocks->fetchAll() as $row){$key='refund:'.$refundId.':product:'.$row['product_id'];$ins=$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO stock_movements (tenant_id,product_id,order_id,type,quantity,idempotency_key) VALUES (?,?,?,"reversal",?,?)'));$ins->execute([$tenantId,$row['product_id'],$refund['order_id'],$row['qty'],$key]);if($ins->rowCount()===1)$pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=? AND tenant_id=?')->execute([$row['qty'],$row['product_id'],$tenantId]);}

            $tickets=$pdo->prepare(Database::portableSql($pdo,'SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND order_id=? AND status="paid" GROUP BY batch_id FOR UPDATE'));$tickets->execute([$tenantId,$refund['order_id']]);
            foreach($tickets->fetchAll() as $row){$pdo->prepare(Database::portableSql($pdo,'UPDATE ticket_batches SET quantity_sold=GREATEST(0,quantity_sold-?) WHERE id=?'))->execute([(int)$row['qty'],$row['batch_id']]);}
            $pdo->prepare('UPDATE tickets SET status="refunded" WHERE tenant_id=? AND order_id=? AND status="paid"')->execute([$tenantId,$refund['order_id']]);

            if(!empty($payment['coupon_id'])){$r=$pdo->prepare('SELECT id FROM coupon_redemptions WHERE tenant_id=? AND order_id=? LIMIT 1');$r->execute([$tenantId,$refund['order_id']]);if($r->fetchColumn())$pdo->prepare(Database::portableSql($pdo,'UPDATE coupons SET uses_count=GREATEST(0,uses_count-1) WHERE id=? AND tenant_id=?'))->execute([$payment['coupon_id'],$tenantId]);}

            if(!empty($payment['customer_id'])){$earn=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM customer_points_movements WHERE tenant_id=? AND customer_id=? AND order_id=? AND type="earn"');$earn->execute([$tenantId,$payment['customer_id'],$refund['order_id']]);$points=(int)$earn->fetchColumn();if($points>0){$key='refund:'.$refundId.':points';$ins=$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO customer_points_movements (tenant_id,customer_id,order_id,points,type,idempotency_key) VALUES (?,?,?, ?,"reversal",?)'));$ins->execute([$tenantId,$payment['customer_id'],$refund['order_id'],-$points,$key]);if($ins->rowCount()===1)$pdo->prepare('UPDATE customers SET points=points-? WHERE id=? AND tenant_id=?')->execute([$points,$payment['customer_id'],$tenantId]);}}

            $pdo->prepare('UPDATE promoter_commissions SET status="cancelled" WHERE tenant_id=? AND order_id=? AND status IN ("pending","approved")')->execute([$tenantId,$refund['order_id']]);

            if($payment['provider']==='manual'){$userId=(int)$refund['requested_by'];$cs=$pdo->prepare(Database::portableSql($pdo,'SELECT id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$cs->execute([$tenantId,$userId]);$cashSessionId=$cs->fetchColumn();if(!$cashSessionId)throw new RuntimeException('O caixa que solicitou a devolução em dinheiro não está mais aberto.');$key='refund:'.$refundId.':cash';$ins=$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO cash_movements (tenant_id,cash_session_id,user_id,order_id,payment_id,type,method,direction,amount_cents,notes,idempotency_key) VALUES (?,?,?,?,?,"refund","cash","out",?,?,?)'));$ins->execute([$tenantId,$cashSessionId,$userId,$refund['order_id'],$refund['payment_id'],$refund['amount_cents'],'Estorno integral #'.$refundId,$key]);}

            $pdo->prepare('UPDATE payments SET status="refunded" WHERE id=? AND tenant_id=?')->execute([$refund['payment_id'],$tenantId]);
            $pdo->prepare('UPDATE orders SET payment_status="refunded",status="cancelled" WHERE id=? AND tenant_id=?')->execute([$refund['order_id'],$tenantId]);
            $pdo->prepare('UPDATE refunds SET status="completed",completed_at=CURRENT_TIMESTAMP,error_message=NULL WHERE id=? AND tenant_id=?')->execute([$refundId,$tenantId]);
            Auth::audit('refund.completed','refund',(string)$refundId,['payment_id'=>(int)$refund['payment_id'],'order_id'=>(int)$refund['order_id'],'amount_cents'=>(int)$refund['amount_cents']]);
            $s=$pdo->prepare('SELECT * FROM refunds WHERE id=?');$s->execute([$refundId]);return $s->fetch();
        });
    }
}
