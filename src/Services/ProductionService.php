<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ProductionService
{
    private const TRANSITIONS=[
        'received'=>['preparing','cancelled'],
        'preparing'=>['ready','cancelled'],
        'ready'=>['expedited','cancelled'],
        'expedited'=>['delivered'],
        'delivered'=>[],
        'cancelled'=>[],
    ];

    public function ensureOrderJobs(PDO $pdo,int $tenantId,int $orderId,?int $userId=null):int
    {
        $o=$pdo->prepare('SELECT id,channel,status FROM orders WHERE id=? AND tenant_id=?');$o->execute([$orderId,$tenantId]);$order=$o->fetch();
        if(!$order||!in_array((string)$order['channel'],['counter','table','delivery','pickup','event_bar'],true))return 0;
        if(in_array((string)$order['status'],['draft','pending','cancelled'],true))return 0;
        $created=0;

        $items=$pdo->prepare('SELECT oi.id,oi.name_snapshot,oi.quantity,pp.station_id,pp.production_enabled,COALESCE(pp.prep_minutes,ps.sla_minutes,15) prep_minutes FROM order_items oi LEFT JOIN product_production_profiles pp ON pp.product_id=oi.product_id AND pp.tenant_id=? LEFT JOIN production_stations ps ON ps.id=pp.station_id WHERE oi.order_id=?');
        $items->execute([$tenantId,$orderId]);
        foreach($items->fetchAll() as $item){
            if(!(int)($item['production_enabled']??0)||!(int)($item['station_id']??0))continue;
            $check=$pdo->prepare('SELECT id FROM production_jobs WHERE tenant_id=? AND order_id=? AND order_item_id=? AND kind="item" LIMIT 1');$check->execute([$tenantId,$orderId,$item['id']]);if($check->fetchColumn())continue;
            $ins=$pdo->prepare('INSERT INTO production_jobs (tenant_id,order_id,order_item_id,station_id,kind,description,quantity,status,prep_minutes) VALUES (?,?,?,?,"item",?,? ,"received",?)');
            $ins->execute([$tenantId,$orderId,$item['id'],$item['station_id'],$item['name_snapshot'],$item['quantity'],max(1,(int)$item['prep_minutes'])]);$jobId=(int)$pdo->lastInsertId();$created++;
            $this->logEvent($pdo,$tenantId,$orderId,$jobId,$userId,'received',null,'received','Item enviado para produção.',[]);
        }

        $mods=$pdo->prepare('SELECT m.id,m.option_name_snapshot,m.quantity,m.station_id,m.production_enabled,COALESCE(ps.sla_minutes,15) prep_minutes FROM order_item_modifiers m LEFT JOIN production_stations ps ON ps.id=m.station_id WHERE m.tenant_id=? AND m.order_id=?');
        $mods->execute([$tenantId,$orderId]);
        foreach($mods->fetchAll() as $mod){
            if(!(int)$mod['production_enabled']||!(int)$mod['station_id'])continue;
            $check=$pdo->prepare('SELECT id FROM production_jobs WHERE tenant_id=? AND order_id=? AND order_item_modifier_id=? AND kind="modifier" LIMIT 1');$check->execute([$tenantId,$orderId,$mod['id']]);if($check->fetchColumn())continue;
            $pdo->prepare('INSERT INTO production_jobs (tenant_id,order_id,order_item_modifier_id,station_id,kind,description,quantity,status,prep_minutes) VALUES (?,?,?,?,"modifier",?,? ,"received",?)')->execute([$tenantId,$orderId,$mod['id'],$mod['station_id'],$mod['option_name_snapshot'],$mod['quantity'],max(1,(int)$mod['prep_minutes'])]);$jobId=(int)$pdo->lastInsertId();$created++;
            $this->logEvent($pdo,$tenantId,$orderId,$jobId,$userId,'received',null,'received','Adicional enviado para produção.',[]);
        }
        return $created;
    }

    public function ensureActiveOrders():int
    {
        $tenantId=Auth::tenantId();if(!$tenantId)return 0;
        return Database::transaction(function(PDO $pdo)use($tenantId):int{
            $s=$pdo->prepare('SELECT id FROM orders WHERE tenant_id=? AND channel IN ("counter","table","delivery","pickup","event_bar") AND status IN ("confirmed","preparing","ready","served","out_for_delivery") ORDER BY id DESC LIMIT 250');$s->execute([$tenantId]);$count=0;foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id)$count+=$this->ensureOrderJobs($pdo,$tenantId,(int)$id,Auth::id());return $count;
        });
    }

    public function board():array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->ensureActiveOrders();$pdo=Database::connection();
        $s=$pdo->prepare('SELECT * FROM production_stations WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$s->execute([$tenantId]);$stations=$s->fetchAll();
        $jobs=$pdo->prepare('SELECT j.*,ps.name station_name,ps.station_type,o.channel,o.status order_status,o.notes order_notes,o.created_at order_created_at,rt.name table_name,c.name customer_name,CAST((julianday(CURRENT_TIMESTAMP)-julianday(j.received_at))*1440 AS INTEGER) elapsed_minutes FROM production_jobs j JOIN production_stations ps ON ps.id=j.station_id JOIN orders o ON o.id=j.order_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE j.tenant_id=? AND j.status IN ("received","preparing","ready") AND o.status<>"cancelled" ORDER BY ps.sort_order,j.received_at,j.id');
        try{$jobs->execute([$tenantId]);$rows=$jobs->fetchAll();}catch(\Throwable){
            $jobs=$pdo->prepare('SELECT j.*,ps.name station_name,ps.station_type,o.channel,o.status order_status,o.notes order_notes,o.created_at order_created_at,rt.name table_name,c.name customer_name,TIMESTAMPDIFF(MINUTE,j.received_at,NOW()) elapsed_minutes FROM production_jobs j JOIN production_stations ps ON ps.id=j.station_id JOIN orders o ON o.id=j.order_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE j.tenant_id=? AND j.status IN ("received","preparing","ready") AND o.status<>"cancelled" ORDER BY ps.sort_order,j.received_at,j.id');$jobs->execute([$tenantId]);$rows=$jobs->fetchAll();
        }
        foreach($rows as &$row)$row['delayed']=(int)$row['elapsed_minutes']>(int)$row['prep_minutes']&&$row['status']!=='ready';unset($row);
        return ['stations'=>$stations,'jobs'=>$rows];
    }

    public function expedition():array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$this->ensureActiveOrders();$pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.id,o.channel,o.status,o.created_at,rt.name table_name,c.name customer_name,COUNT(j.id) jobs_total,SUM(CASE WHEN j.status IN ("ready","expedited","delivered") THEN 1 ELSE 0 END) jobs_ready,SUM(CASE WHEN j.status="cancelled" THEN 1 ELSE 0 END) jobs_cancelled,MIN(j.received_at) first_received_at FROM orders o JOIN production_jobs j ON j.order_id=o.id AND j.tenant_id=o.tenant_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.tenant_id=? AND o.status NOT IN ("completed","cancelled") GROUP BY o.id,o.channel,o.status,o.created_at,rt.name,c.name ORDER BY first_received_at,o.id');$s->execute([$tenantId]);
        $orders=$s->fetchAll();foreach($orders as &$row){$active=max(0,(int)$row['jobs_total']-(int)$row['jobs_cancelled']);$row['all_ready']=$active>0&&(int)$row['jobs_ready']>=$active;}unset($row);return $orders;
    }

    public function changeJobStatus(int $jobId,string $target,string $reason=''):array
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');
        if(!Auth::can('orders.kitchen')&&!Auth::can('orders.dispatch'))throw new RuntimeException('Sem permissão para operar produção.');$target=strtolower(trim($target));
        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$jobId,$target,$reason):array{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT j.*,o.status order_status FROM production_jobs j JOIN orders o ON o.id=j.order_id WHERE j.id=? AND j.tenant_id=? FOR UPDATE'));$s->execute([$jobId,$tenantId]);$job=$s->fetch();if(!$job)throw new RuntimeException('Item de produção não encontrado.');$current=(string)$job['status'];
            if($current===$target)return $job;if(!in_array($target,self::TRANSITIONS[$current]??[],true))throw new RuntimeException('Transição de produção inválida: '.$current.' → '.$target.'.');
            if($target==='expedited'&&!Auth::can('orders.dispatch'))throw new RuntimeException('Somente expedição pode liberar item pronto.');
            $columns=['preparing'=>'started_at','ready'=>'ready_at','expedited'=>'expedited_at','delivered'=>'delivered_at','cancelled'=>'cancelled_at'];$column=$columns[$target]??null;
            $sql='UPDATE production_jobs SET status=?,updated_at=CURRENT_TIMESTAMP'.($column?', '.$column.'=CURRENT_TIMESTAMP':'').' WHERE id=? AND tenant_id=?';$pdo->prepare($sql)->execute([$target,$jobId,$tenantId]);
            $this->logEvent($pdo,$tenantId,(int)$job['order_id'],$jobId,$userId,'status',$current,$target,mb_substr(trim($reason),0,500),[]);
            Auth::audit('production.status','production_job',(string)$jobId,['order_id'=>(int)$job['order_id'],'from'=>$current,'to'=>$target,'reason'=>$reason]);$job['status']=$target;return $job;
        });
    }

    public function markOrderExpedited(int $orderId):void
    {
        Auth::requirePermission('orders.dispatch');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        Database::transaction(function(PDO $pdo)use($tenantId,$orderId):void{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT id,status FROM production_jobs WHERE tenant_id=? AND order_id=? AND status<>"cancelled" FOR UPDATE'));$s->execute([$tenantId,$orderId]);$jobs=$s->fetchAll();if(!$jobs)throw new RuntimeException('Pedido sem itens de produção.');foreach($jobs as $j)if(!in_array($j['status'],['ready','expedited','delivered'],true))throw new RuntimeException('Ainda existem itens não prontos neste pedido.');
            foreach($jobs as $j)if($j['status']==='ready'){$pdo->prepare('UPDATE production_jobs SET status="expedited",expedited_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$j['id']]);$this->logEvent($pdo,$tenantId,$orderId,(int)$j['id'],Auth::id(),'status','ready','expedited','Pedido liberado pela expedição.',[]);}
            Auth::audit('production.order_expedited','order',(string)$orderId);
        });
    }

    public function logPrint(int $stationId,int $orderId,string $type='manual',string $reason=''):int
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida.');if(!Auth::can('orders.kitchen')&&!Auth::can('orders.dispatch'))throw new RuntimeException('Sem permissão para imprimir produção.');
        $type=strtolower(trim($type));if(!in_array($type,['auto','manual','reprint'],true))throw new RuntimeException('Tipo de impressão inválido.');$reason=mb_substr(trim($reason),0,500);if($type==='reprint'&&$reason==='')throw new RuntimeException('Reimpressão exige motivo.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT j.id,j.description,j.quantity,j.status FROM production_jobs j WHERE j.tenant_id=? AND j.station_id=? AND j.order_id=? ORDER BY j.id');$s->execute([$tenantId,$stationId,$orderId]);$jobs=$s->fetchAll();if(!$jobs)throw new RuntimeException('Não há itens deste pedido nesta estação.');$hash=hash('sha256',json_encode($jobs,JSON_UNESCAPED_UNICODE));
        $i=$pdo->prepare('INSERT INTO production_prints (tenant_id,station_id,order_id,print_type,printed_by,reason,payload_hash) VALUES (?,?,?,?,?,?,?)');$i->execute([$tenantId,$stationId,$orderId,$type,$userId,$reason?:null,$hash]);$id=(int)$pdo->lastInsertId();Auth::audit('production.print','order',(string)$orderId,['station_id'=>$stationId,'print_type'=>$type,'reason'=>$reason,'print_id'=>$id]);return $id;
    }

    public function recordOrderChange(PDO $pdo,int $tenantId,int $orderId,string $reason,array $payload=[]):void
    {
        $s=$pdo->prepare('SELECT id,status FROM production_jobs WHERE tenant_id=? AND order_id=? AND status NOT IN ("delivered","cancelled")');$s->execute([$tenantId,$orderId]);foreach($s->fetchAll() as $job){$pdo->prepare('UPDATE production_jobs SET change_version=change_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$job['id']]);$this->logEvent($pdo,$tenantId,$orderId,(int)$job['id'],Auth::id(),'change',(string)$job['status'],(string)$job['status'],$reason,$payload);}
    }

    private function logEvent(PDO $pdo,int $tenantId,int $orderId,?int $jobId,?int $userId,string $type,?string $from,?string $to,string $reason,array $payload):void
    {
        $pdo->prepare('INSERT INTO production_events (tenant_id,order_id,job_id,user_id,event_type,from_status,to_status,reason,payload_json) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$tenantId,$orderId,$jobId,$userId,$type,$from,$to,$reason?:null,$payload?json_encode($payload,JSON_UNESCAPED_UNICODE):null]);
    }
}
