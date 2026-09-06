<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use PDO;
use RuntimeException;

final class WorkShiftService
{
    private const MODES=['operation','delivery','events','pay'];

    public function current():?array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)return null;
        $s=Database::connection()->prepare('SELECT ws.*,ou.name unit_name,ou.code unit_code FROM work_shifts ws LEFT JOIN operating_units ou ON ou.id=ws.unit_id AND ou.tenant_id=ws.tenant_id WHERE ws.tenant_id=? AND ws.user_id=? AND ws.status="open" ORDER BY ws.id DESC LIMIT 1');$s->execute([$tenantId,$userId]);$row=$s->fetch();return $row?:null;
    }

    public function open(string $mode,string $deviceId='',string $notes='',?int $unitId=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$mode=strtolower(trim($mode));if(!in_array($mode,self::MODES,true))throw new RuntimeException('Modo de turno inválido.');
        $allowed=PermissionCatalog::modesForPermissions(Auth::effectivePermissions());if(!in_array($mode,$allowed,true))throw new RuntimeException('Sua conta não possui permissão para este modo.');
        $unit=(new OperatingUnitService())->resolveForShift($unitId);$resolvedUnitId=$unit?(int)$unit['id']:null;
        $deviceHash=$deviceId!==''?hash('sha256',$deviceId):null;$notes=mb_substr(trim($notes),0,500);
        $pdo=Database::connection();$commissionBps=0;$commissionFixed=0;
        if($mode==='delivery'){
            $cq=$pdo->prepare('SELECT delivery_commission_bps,delivery_commission_fixed_cents FROM users WHERE id=? AND tenant_id=? LIMIT 1');$cq->execute([$userId,$tenantId]);if($commission=$cq->fetch()){$commissionBps=max(0,(int)($commission['delivery_commission_bps']??0));$commissionFixed=max(0,(int)($commission['delivery_commission_fixed_cents']??0));}
        }
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$mode,$deviceHash,$notes,$resolvedUnitId,$commissionBps,$commissionFixed):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT ws.*,ou.name unit_name,ou.code unit_code FROM work_shifts ws LEFT JOIN operating_units ou ON ou.id=ws.unit_id AND ou.tenant_id=ws.tenant_id WHERE ws.tenant_id=? AND ws.user_id=? AND ws.status="open" ORDER BY ws.id DESC LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$userId]);if($existing=$s->fetch())return $existing;
            $i=$pdo->prepare('INSERT INTO work_shifts (tenant_id,user_id,unit_id,mode,status,device_hash,opening_notes,delivery_commission_bps,delivery_commission_fixed_cents) VALUES (?, ?, ?, ?,"open",?,?,?,?)');$i->execute([$tenantId,$userId,$resolvedUnitId,$mode,$deviceHash,$notes?:null,$commissionBps,$commissionFixed]);$id=(int)$pdo->lastInsertId();Auth::audit('work_shift.opened','work_shift',(string)$id,['mode'=>$mode,'unit_id'=>$resolvedUnitId,'delivery_commission_bps'=>$commissionBps,'delivery_commission_fixed_cents'=>$commissionFixed]);$q=$pdo->prepare('SELECT ws.*,ou.name unit_name,ou.code unit_code FROM work_shifts ws LEFT JOIN operating_units ou ON ou.id=ws.unit_id AND ou.tenant_id=ws.tenant_id WHERE ws.id=?');$q->execute([$id]);return $q->fetch()?:throw new RuntimeException('Falha ao iniciar turno.');
        });
    }

    public function close(string $notes=''):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$notes=mb_substr(trim($notes),0,500);
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$notes):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM work_shifts WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$userId]);$shift=$s->fetch();if(!$shift)throw new RuntimeException('Não há turno operacional aberto.');
            if($shift['mode']==='delivery'){
                $pending=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND assigned_delivery_user_id=? AND status IN ("ready","out_for_delivery")');$pending->execute([$tenantId,$userId]);if((int)$pending->fetchColumn()>0)throw new RuntimeException('Finalize ou transfira suas entregas antes de encerrar o turno.');
                $cash=(new DeliveryCashService())->outstanding((int)$shift['id']);if((int)$cash['outstanding_cents']>0)throw new RuntimeException('Entregue R$ '.number_format($cash['outstanding_cents']/100,2,',','.').' ao caixa e aguarde a confirmação antes de encerrar o turno.');
            }
            $pdo->prepare('UPDATE work_shifts SET status="closed",closing_notes=?,ended_at=CURRENT_TIMESTAMP WHERE id=? AND status="open"')->execute([$notes?:null,$shift['id']]);$shift['status']='closed';$shift['closing_notes']=$notes?:null;$shift['ended_at']=gmdate('Y-m-d H:i:s');Auth::audit('work_shift.closed','work_shift',(string)$shift['id'],['mode'=>$shift['mode'],'unit_id'=>$shift['unit_id']??null]);return $shift;
        });
    }

    public function summary(?int $shiftId=null):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$pdo=Database::connection();
        if($shiftId){$s=$pdo->prepare('SELECT ws.*,u.name user_name,ou.name unit_name,ou.code unit_code FROM work_shifts ws JOIN users u ON u.id=ws.user_id LEFT JOIN operating_units ou ON ou.id=ws.unit_id WHERE ws.id=? AND ws.tenant_id=?');$s->execute([$shiftId,$tenantId]);}
        else{$s=$pdo->prepare('SELECT ws.*,u.name user_name,ou.name unit_name,ou.code unit_code FROM work_shifts ws JOIN users u ON u.id=ws.user_id LEFT JOIN operating_units ou ON ou.id=ws.unit_id WHERE ws.tenant_id=? AND ws.user_id=? ORDER BY ws.id DESC LIMIT 1');$s->execute([$tenantId,$userId]);}
        $shift=$s->fetch();if(!$shift)return ['shift'=>null,'movements'=>[],'by_method'=>[],'orders'=>[]];
        if((int)$shift['user_id']!==$userId&&!in_array(Auth::role(),['admin','manager','super_admin'],true))throw new RuntimeException('Acesso negado ao turno de outro funcionário.');

        $m=$pdo->prepare('SELECT * FROM work_shift_movements WHERE tenant_id=? AND work_shift_id=? ORDER BY id DESC');$m->execute([$tenantId,$shift['id']]);$movements=$m->fetchAll();
        $b=$pdo->prepare('SELECT method,direction,COUNT(*) qty,COALESCE(SUM(amount_cents),0) total_cents FROM work_shift_movements WHERE tenant_id=? AND work_shift_id=? GROUP BY method,direction ORDER BY method,direction');$b->execute([$tenantId,$shift['id']]);$byMethod=$b->fetchAll();

        $electronic=$pdo->prepare('SELECT c.method,"in" direction,COUNT(*) qty,COALESCE(SUM(p.amount_cents),0) total_cents FROM payment_collection_contexts c JOIN payments p ON p.id=c.payment_id AND p.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.work_shift_id=? AND p.status="paid" GROUP BY c.method ORDER BY c.method');
        $electronic->execute([$tenantId,$shift['id']]);foreach($electronic->fetchAll() as $row)$byMethod[]=$row;

        $end=$shift['ended_at']?:gmdate('Y-m-d H:i:s');$o=$pdo->prepare('SELECT COUNT(*) qty,COALESCE(SUM(total_cents),0) total_cents FROM orders WHERE tenant_id=? AND (created_by=? OR assigned_delivery_user_id=?) AND created_at>=? AND created_at<=?');$o->execute([$tenantId,$shift['user_id'],$shift['user_id'],$shift['started_at'],$end]);
        $result=['shift'=>$shift,'movements'=>$movements,'by_method'=>$byMethod,'orders'=>$o->fetch()?:['qty'=>0,'total_cents'=>0]];
        if($shift['mode']==='delivery'){
            $result['delivery_cash']=(new DeliveryCashService())->outstanding((int)$shift['id']);
            $sql='SELECT COUNT(*) qty,COALESCE(SUM(o.total_cents),0) revenue_cents FROM delivery_progress dp JOIN orders o ON o.id=dp.order_id AND o.tenant_id=dp.tenant_id WHERE dp.tenant_id=? AND dp.delivery_user_id=? AND dp.completed_at IS NOT NULL AND dp.completed_at>=? AND dp.completed_at<=? AND o.status="completed" AND o.payment_status="paid"';$args=[$tenantId,$shift['user_id'],$shift['started_at'],$end];
            if($shift['unit_id']!==null){$sql.=' AND o.unit_id=?';$args[]=$shift['unit_id'];}
            $cq=$pdo->prepare($sql);$cq->execute($args);$delivery=$cq->fetch()?:['qty'=>0,'revenue_cents'=>0];$qty=(int)$delivery['qty'];$revenue=(int)$delivery['revenue_cents'];$bps=max(0,(int)($shift['delivery_commission_bps']??0));$fixed=max(0,(int)($shift['delivery_commission_fixed_cents']??0));$percentPart=(int)round($revenue*$bps/10000);$fixedPart=$qty*$fixed;
            $result['delivery_commission']=['deliveries'=>$qty,'revenue_cents'=>$revenue,'percent_bps'=>$bps,'fixed_per_delivery_cents'=>$fixed,'percent_part_cents'=>$percentPart,'fixed_part_cents'=>$fixedPart,'commission_cents'=>$percentPart+$fixedPart];
        }
        return $result;
    }

    public function recordMovement(PDO $pdo,int $tenantId,int $userId,int $orderId,?int $paymentId,string $type,string $method,string $direction,int $amountCents,string $idempotencyKey,string $notes=''):void
    {
        $s=$pdo->prepare(Database::portableSql($pdo,'SELECT id FROM work_shifts WHERE tenant_id=? AND user_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$s->execute([$tenantId,$userId]);$shiftId=$s->fetchColumn();if(!$shiftId)return;
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO work_shift_movements (tenant_id,work_shift_id,user_id,order_id,payment_id,type,method,direction,amount_cents,notes,idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$pdo->prepare($sql)->execute([$tenantId,$shiftId,$userId,$orderId,$paymentId,$type,$method,$direction,$amountCents,$notes?:null,$idempotencyKey]);
    }
}
