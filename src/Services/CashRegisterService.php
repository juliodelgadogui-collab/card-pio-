<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class CashRegisterService
{
    private const METHODS=['cash','card','pix','other'];

    public function open(int $openingBalanceCents=0,string $notes=''):array
    {
        Auth::requirePermission('payments.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();$unitId=Auth::unitId();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        $openingBalanceCents=max(0,$openingBalanceCents);
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$openingBalanceCents,$notes){
            $tenant=$pdo->prepare('SELECT id FROM tenants WHERE id=? AND status="active" LIMIT 1 FOR UPDATE');$tenant->execute([$tenantId]);if(!$tenant->fetchColumn())throw new RuntimeException('Empresa indisponível.');
            if($unitId){$u=$pdo->prepare('SELECT id FROM business_units WHERE id=? AND tenant_id=? AND status="active" LIMIT 1');$u->execute([$unitId,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Unidade indisponível para abrir caixa.');}
            $open=$pdo->prepare('SELECT id FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open" LIMIT 1 FOR UPDATE');$open->execute([$tenantId,$userId]);if($open->fetchColumn())throw new RuntimeException('Você já possui um caixa aberto.');
            $s=$pdo->prepare('INSERT INTO cash_sessions (tenant_id,unit_id,user_id,status,opening_balance_cents,opening_notes,opened_at) VALUES (?,?,?,"open",?,?,NOW())');$s->execute([$tenantId,$unitId,$userId,$openingBalanceCents,$this->note($notes)]);$id=(int)$pdo->lastInsertId();
            Auth::audit('cash.opened','cash_session',(string)$id,['opening_balance_cents'=>$openingBalanceCents,'unit_id'=>$unitId]);
            return $this->getInTransaction($pdo,$tenantId,$id);
        });
    }

    public function current():?array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();$userId=Auth::id();$unitId=Auth::unitId();if(!$tenantId||!$userId)return null;
        $sql='SELECT * FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open"'.($unitId?' AND unit_id=?':'').' ORDER BY id DESC LIMIT 1';$args=[$tenantId,$userId];if($unitId)$args[]=$unitId;
        $s=Database::connection()->prepare($sql);$s->execute($args);$row=$s->fetch();return $row?$this->withTotals($row):null;
    }

    public function addMovement(string $type,int $amountCents,string $notes=''):array
    {
        Auth::requirePermission('payments.manage');
        if(!in_array($type,['deposit','withdrawal'],true))throw new RuntimeException('Movimento de caixa inválido.');
        if($amountCents<=0)throw new RuntimeException('Informe um valor maior que zero.');
        $tenantId=Auth::tenantId();$userId=Auth::id();$unitId=Auth::unitId();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$type,$amountCents,$notes){
            $session=$this->lockCurrent($pdo,$tenantId,$userId,$unitId);
            $signed=$type==='withdrawal'?-$amountCents:$amountCents;
            $key='cash:'.$session['id'].':'.$type.':'.bin2hex(random_bytes(10));
            $pdo->prepare('INSERT INTO cash_movements (tenant_id,cash_session_id,user_id,type,method,amount_cents,notes,idempotency_key) VALUES (?,?,?,? ,"cash",?,?,?)')->execute([$tenantId,$session['id'],$userId,$type,$signed,$this->note($notes),$key]);
            Auth::audit('cash.'.$type,'cash_session',(string)$session['id'],['amount_cents'=>$signed,'unit_id'=>$unitId]);
            return $this->getInTransaction($pdo,$tenantId,(int)$session['id']);
        });
    }

    public function close(int $declaredCashCents,string $notes=''):array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();$userId=Auth::id();$unitId=Auth::unitId();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$declaredCashCents=max(0,$declaredCashCents);
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$declaredCashCents,$notes){
            $session=$this->lockCurrent($pdo,$tenantId,$userId,$unitId);$expected=$this->expectedCash($pdo,$session);$difference=$declaredCashCents-$expected;
            $pdo->prepare('UPDATE cash_sessions SET status="closed",expected_cash_cents=?,declared_cash_cents=?,difference_cents=?,closing_notes=?,closed_at=NOW() WHERE id=? AND tenant_id=? AND status="open"')->execute([$expected,$declaredCashCents,$difference,$this->note($notes),$session['id'],$tenantId]);
            Auth::audit('cash.closed','cash_session',(string)$session['id'],['expected_cash_cents'=>$expected,'declared_cash_cents'=>$declaredCashCents,'difference_cents'=>$difference,'unit_id'=>$unitId]);
            return $this->getInTransaction($pdo,$tenantId,(int)$session['id']);
        });
    }

    public function recordManualSale(PDO $pdo,int $tenantId,int $userId,int $orderId,int $amountCents,string $method,string $idempotencyKey):void
    {
        $method=strtolower(trim($method));if(!in_array($method,self::METHODS,true))throw new RuntimeException('Forma de recebimento manual inválida.');if($amountCents<=0)throw new RuntimeException('Valor manual inválido.');
        $unitId=Auth::unitId();$session=$this->lockCurrent($pdo,$tenantId,$userId,$unitId);
        $o=$pdo->prepare('SELECT unit_id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$o->execute([$orderId,$tenantId]);$orderUnit=$o->fetchColumn();if($orderUnit===false)throw new RuntimeException('Pedido não encontrado para lançamento no caixa.');
        $sessionUnit=$session['unit_id']!==null?(int)$session['unit_id']:null;$orderUnit=$orderUnit!==null?(int)$orderUnit:null;
        if($sessionUnit!==null&&$orderUnit!==$sessionUnit)throw new RuntimeException('O pedido pertence a outra unidade e não pode ser recebido neste caixa.');
        $key='manual-sale:'.$idempotencyKey;
        $check=$pdo->prepare('SELECT id FROM cash_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn())return;
        $pdo->prepare('INSERT INTO cash_movements (tenant_id,cash_session_id,user_id,order_id,type,method,amount_cents,notes,idempotency_key) VALUES (?,?,?,?,"sale",?,?,?,?)')->execute([$tenantId,$session['id'],$userId,$orderId,$method,$amountCents,'Pagamento manual do pedido #'.$orderId,$key]);
    }

    public function recordManualRefund(PDO $pdo,int $tenantId,int $userId,int $orderId,int $amountCents,string $method,string $idempotencyKey):void
    {
        $method=strtolower(trim($method));if(!in_array($method,self::METHODS,true))$method='other';if($amountCents<=0)return;
        $unitId=Auth::unitId();$session=$this->lockCurrent($pdo,$tenantId,$userId,$unitId);$key='manual-refund:'.$idempotencyKey;
        $o=$pdo->prepare('SELECT unit_id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$o->execute([$orderId,$tenantId]);$orderUnit=$o->fetchColumn();if($orderUnit===false)throw new RuntimeException('Pedido não encontrado para reembolso no caixa.');
        $sessionUnit=$session['unit_id']!==null?(int)$session['unit_id']:null;$orderUnit=$orderUnit!==null?(int)$orderUnit:null;if($sessionUnit!==null&&$orderUnit!==$sessionUnit)throw new RuntimeException('O pedido pertence a outra unidade.');
        $check=$pdo->prepare('SELECT id FROM cash_movements WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$check->execute([$tenantId,$key]);if($check->fetchColumn())return;
        $pdo->prepare('INSERT INTO cash_movements (tenant_id,cash_session_id,user_id,order_id,type,method,amount_cents,notes,idempotency_key) VALUES (?,?,?,?,"refund",?,?,?,?)')->execute([$tenantId,$session['id'],$userId,$orderId,$method,-$amountCents,'Reembolso manual do pedido #'.$orderId,$key]);
    }

    public function movements(int $sessionId):array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();$unitId=Auth::unitId();if(!$tenantId)return[];
        $scope=$pdo=Database::connection();$check=$pdo->prepare('SELECT id FROM cash_sessions WHERE id=? AND tenant_id=?'.($unitId?' AND unit_id=?':''));$args=[$sessionId,$tenantId];if($unitId)$args[]=$unitId;$check->execute($args);if(!$check->fetchColumn())return[];
        $s=$pdo->prepare('SELECT cm.*,u.name user_name FROM cash_movements cm LEFT JOIN users u ON u.id=cm.user_id WHERE cm.tenant_id=? AND cm.cash_session_id=? ORDER BY cm.id DESC LIMIT 300');$s->execute([$tenantId,$sessionId]);return $s->fetchAll();
    }

    private function lockCurrent(PDO $pdo,int $tenantId,int $userId,?int $unitId=null):array
    {
        $sql='SELECT * FROM cash_sessions WHERE tenant_id=? AND user_id=? AND status="open"'.($unitId?' AND unit_id=?':'').' ORDER BY id DESC LIMIT 1 FOR UPDATE';$args=[$tenantId,$userId];if($unitId)$args[]=$unitId;
        $s=$pdo->prepare($sql);$s->execute($args);$row=$s->fetch();if(!$row)throw new RuntimeException('Abra o caixa desta unidade antes de registrar pagamentos manuais.');return$row;
    }

    private function expectedCash(PDO $pdo,array $session):int
    {
        $s=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM cash_movements WHERE cash_session_id=? AND method="cash"');$s->execute([$session['id']]);return(int)$session['opening_balance_cents']+(int)$s->fetchColumn();
    }

    private function getInTransaction(PDO $pdo,int $tenantId,int $id):array
    {
        $s=$pdo->prepare('SELECT * FROM cash_sessions WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$id,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Caixa não encontrado.');return$this->withTotals($row,$pdo);
    }

    private function withTotals(array $session,?PDO $pdo=null):array
    {
        $pdo??=Database::connection();$s=$pdo->prepare('SELECT method,type,COALESCE(SUM(amount_cents),0) amount FROM cash_movements WHERE cash_session_id=? GROUP BY method,type');$s->execute([$session['id']]);$summary=['cash'=>0,'card'=>0,'pix'=>0,'other'=>0];foreach($s->fetchAll()as$row){$method=(string)$row['method'];if(isset($summary[$method]))$summary[$method]+=(int)$row['amount'];}$session['totals']=$summary;$session['expected_live_cents']=$session['status']==='open'?$this->expectedCash($pdo,$session):(int)($session['expected_cash_cents']??0);return$session;
    }

    private function note(string $value):?string{$value=mb_substr(trim($value),0,500);return$value!==''?$value:null;}
}
