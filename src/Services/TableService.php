<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class TableService
{
    public function list():array
    {
        Auth::requirePermission('tables.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();$unitId=$this->currentUnitId();
        $sql='SELECT rt.id,rt.unit_id,rt.name,rt.seats,rt.status,rt.qr_token,t.id tab_id,t.label tab_label,t.opened_at,
            (SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tab_id=t.id AND o.status<>"cancelled") tab_total_cents,
            (SELECT COALESCE(SUM(GREATEST(0,o.total_cents-COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.tenant_id=o.tenant_id AND p.order_id=o.id AND p.status="paid"),0))),0)
             FROM orders o WHERE o.tab_id=t.id AND o.status<>"cancelled") unpaid_cents
            FROM restaurant_tables rt
            LEFT JOIN tabs t ON t.table_id=rt.id AND t.status="open"
            WHERE rt.tenant_id=?';$args=[$tenantId];
        if($unitId!==null){$sql.=' AND rt.unit_id=?';$args[]=$unitId;}
        $sql.=' ORDER BY rt.name';
        $s=$pdo->prepare(Database::portableSql($pdo,$sql));$s->execute($args);return $s->fetchAll();
    }

    public function details(int $tabId):array
    {
        Auth::requirePermission('tables.manage');
        $tenantId=Auth::tenantId();if(!$tenantId||$tabId<1)throw new RuntimeException('Comanda inválida.');$pdo=Database::connection();$unitId=$this->currentUnitId();
        $sql='SELECT t.*,rt.name table_name,rt.seats,rt.unit_id FROM tabs t JOIN restaurant_tables rt ON rt.id=t.table_id WHERE t.id=? AND t.tenant_id=?';$args=[$tabId,$tenantId];if($unitId!==null){$sql.=' AND rt.unit_id=?';$args[]=$unitId;}$sql.=' LIMIT 1';
        $t=$pdo->prepare($sql);$t->execute($args);$tab=$t->fetch();if(!$tab)throw new RuntimeException('Comanda não encontrada nesta unidade.');
        $sql='SELECT o.id,o.status,o.payment_status,o.total_cents,o.created_at,
            COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.tenant_id=o.tenant_id AND p.order_id=o.id AND p.status="paid"),0) paid_cents,
            GREATEST(0,o.total_cents-COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.tenant_id=o.tenant_id AND p.order_id=o.id AND p.status="paid"),0)) remaining_cents
            FROM orders o WHERE o.tenant_id=? AND o.tab_id=? AND o.status<>"cancelled"';$args=[$tenantId,$tabId];if($unitId!==null){$sql.=' AND o.unit_id=?';$args[]=$unitId;}$sql.=' ORDER BY o.id';
        $s=$pdo->prepare(Database::portableSql($pdo,$sql));$s->execute($args);$orders=$s->fetchAll();
        $total=0;$paid=0;$remaining=0;foreach($orders as $o){$total+=(int)$o['total_cents'];$paid+=(int)$o['paid_cents'];$remaining+=(int)$o['remaining_cents'];}
        return ['tab'=>$tab,'orders'=>$orders,'total_cents'=>$total,'paid_cents'=>$paid,'remaining_cents'=>$remaining];
    }

    public function open(int $tableId,string $label=''):array
    {
        Auth::requirePermission('tables.manage');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$label=mb_substr(trim($label),0,120);$unitId=$this->currentUnitId();
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$tableId,$label):array{
            $sql='SELECT id,name,status,unit_id FROM restaurant_tables WHERE id=? AND tenant_id=?';$args=[$tableId,$tenantId];if($unitId!==null){$sql.=' AND unit_id=?';$args[]=$unitId;}$sql.=' FOR UPDATE';
            $t=$pdo->prepare(Database::portableSql($pdo,$sql));$t->execute($args);$table=$t->fetch();if(!$table||$table['status']==='inactive')throw new RuntimeException('Mesa indisponível nesta unidade.');
            $open=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM tabs WHERE tenant_id=? AND table_id=? AND status="open" ORDER BY id DESC LIMIT 1 FOR UPDATE'));$open->execute([$tenantId,$tableId]);if($existing=$open->fetch())return $existing;
            $s=$pdo->prepare('INSERT INTO tabs (tenant_id,table_id,opened_by,label,status) VALUES (?,?,?, ?,"open")');$s->execute([$tenantId,$tableId,$userId,$label?:null]);$id=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE restaurant_tables SET status="occupied" WHERE id=? AND tenant_id=?')->execute([$tableId,$tenantId]);Auth::audit('tab.opened','tab',(string)$id,['table_id'=>$tableId,'unit_id'=>$table['unit_id']??null,'source'=>'eventmenu_go']);$q=$pdo->prepare('SELECT * FROM tabs WHERE id=?');$q->execute([$id]);return $q->fetch()?:throw new RuntimeException('Falha ao abrir comanda.');
        });
    }

    public function close(int $tabId):array
    {
        Auth::requirePermission('tables.manage');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');$unitId=$this->currentUnitId();
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$tabId):array{
            $sql='SELECT t.*,rt.unit_id FROM tabs t JOIN restaurant_tables rt ON rt.id=t.table_id WHERE t.id=? AND t.tenant_id=? AND t.status="open"';$args=[$tabId,$tenantId];if($unitId!==null){$sql.=' AND rt.unit_id=?';$args[]=$unitId;}$sql.=' FOR UPDATE';
            $s=$pdo->prepare(Database::portableSql($pdo,$sql));$s->execute($args);$tab=$s->fetch();if(!$tab)throw new RuntimeException('Comanda aberta não encontrada nesta unidade.');
            $u=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND tab_id=? AND status<>"cancelled" AND payment_status<>"paid"');$u->execute([$tenantId,$tabId]);if((int)$u->fetchColumn()>0)throw new RuntimeException('Existem pedidos não pagos nesta comanda.');
            $active=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND tab_id=? AND status IN ("confirmed","preparing","ready","out_for_delivery")');$active->execute([$tenantId,$tabId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Existem pedidos operacionais ainda não finalizados nesta comanda.');
            $pdo->prepare('UPDATE tabs SET status="closed",closed_by=?,closed_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$userId,$tabId]);if($tab['table_id'])$pdo->prepare('UPDATE restaurant_tables SET status="available" WHERE id=? AND tenant_id=?')->execute([$tab['table_id'],$tenantId]);Auth::audit('tab.closed','tab',(string)$tabId,['unit_id'=>$tab['unit_id']??null,'source'=>'eventmenu_go']);$tab['status']='closed';return $tab;
        });
    }

    private function currentUnitId():?int
    {
        $shift=(new WorkShiftService())->current();if(!$shift||$shift['unit_id']===null||$shift['unit_id']==='')return null;return (int)$shift['unit_id'];
    }
}
