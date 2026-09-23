<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ProductionDesktopService
{
    public function board():array
    {
        $this->requireOperation();
        if(!Auth::can('orders.kitchen')&&!Auth::can('orders.dispatch')&&!Auth::can('production.print')&&!Auth::can('production.manage'))throw new RuntimeException('Sem permissão para acompanhar a produção.');
        return(new ProductionService())->board();
    }

    public function expedition():array
    {
        $this->requireOperation();
        if(!Auth::can('orders.dispatch')&&!Auth::can('orders.kitchen')&&!Auth::can('production.print')&&!Auth::can('production.manage'))throw new RuntimeException('Sem permissão para acompanhar a expedição.');
        return(new ProductionService())->expedition();
    }

    public function changeJobStatus(int$jobId,string$status,string$reason=''):array
    {
        $this->requireOperation();return(new ProductionService())->changeJobStatus($jobId,$status,$reason);
    }

    public function expediteOrder(int$orderId):void
    {
        $this->requireOperation();(new ProductionService())->markOrderExpedited($orderId);
    }

    public function claimPrint(string$deviceId):?array
    {
        $this->requirePrint();$tenantId=Auth::tenantId();if(!$tenantId)return null;$deviceId=$this->device($deviceId);$unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];
        return Database::transaction(function(PDO$pdo)use($tenantId,$unitId,$deviceId):?array{
            $sql=Database::portableSql($pdo,'SELECT q.*,ps.name station_name,ps.station_type,ps.printer_target,ps.desktop_device_id,o.channel,o.status order_status,o.notes order_notes,o.created_at order_created_at,o.subtotal_cents,o.discount_cents,o.delivery_fee_cents,o.total_cents,o.delivery_address,rt.name table_name,c.name customer_name,c.phone customer_phone FROM production_print_queue q JOIN production_stations ps ON ps.id=q.station_id AND ps.tenant_id=q.tenant_id JOIN orders o ON o.id=q.order_id AND o.tenant_id=q.tenant_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id AND rt.tenant_id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id WHERE q.tenant_id=? AND q.unit_id=? AND ps.unit_id=? AND ps.active=1 AND q.status IN ("pending","error") AND q.attempts<5 AND (q.next_attempt_at IS NULL OR q.next_attempt_at<=CURRENT_TIMESTAMP) AND (ps.desktop_device_id IS NULL OR ps.desktop_device_id="" OR ps.desktop_device_id=?) ORDER BY q.created_at,q.id LIMIT 1 FOR UPDATE');
            $q=$pdo->prepare($sql);$q->execute([$tenantId,$unitId,$unitId,$deviceId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
            if((string)$row['order_status']==='cancelled'){$pdo->prepare('UPDATE production_print_queue SET status="failed",last_error="Pedido cancelado antes da impressão.",failed_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$row['id'],$tenantId]);return null;}
            $pdo->prepare('UPDATE production_print_queue SET status="processing",attempts=attempts+1,claimed_at=CURRENT_TIMESTAMP,claimed_device_id=?,last_error=NULL WHERE id=? AND tenant_id=?')->execute([$deviceId,$row['id'],$tenantId]);$row['status']='processing';$row['attempts']=(int)$row['attempts']+1;

            $items=[];$snapshot=[];$raw=trim((string)($row['payload_json']??''));if($raw!==''){$decoded=json_decode($raw,true);if(is_array($decoded))$snapshot=$decoded;}
            if(is_array($snapshot['items']??null)&&$snapshot['items']){
                $items=array_values($snapshot['items']);$order=is_array($snapshot['order']??null)?$snapshot['order']:[];
                $row['channel']=(string)($order['channel']??$row['channel']);$row['order_notes']=(string)($order['notes']??$row['order_notes']);$row['order_created_at']=(string)($order['created_at']??$row['order_created_at']);$row['table_name']=(string)($order['table_name']??$row['table_name']);$row['customer_name']=(string)($order['customer_name']??$row['customer_name']);$row['customer_phone']=(string)($order['customer_phone']??$row['customer_phone']);$row['delivery_address']=(string)($order['delivery_address']??$row['delivery_address']);$row['subtotal_cents']=(int)($order['subtotal_cents']??$row['subtotal_cents']);$row['discount_cents']=(int)($order['discount_cents']??$row['discount_cents']);$row['delivery_fee_cents']=(int)($order['delivery_fee_cents']??$row['delivery_fee_cents']);$row['total_cents']=(int)($order['total_cents']??$row['total_cents']);
            }else{
                $j=$pdo->prepare('SELECT j.id,j.kind,j.description,j.quantity,j.status,j.prep_minutes,j.received_at,NULL notes,0 unit_price_cents,0 total_cents FROM production_jobs j WHERE j.tenant_id=? AND j.order_id=? AND j.station_id=? AND j.status<>"cancelled" ORDER BY j.id');$j->execute([$tenantId,$row['order_id'],$row['station_id']]);$items=$j->fetchAll(PDO::FETCH_ASSOC);
            }
            if(!$items){$pdo->prepare('UPDATE production_print_queue SET status="failed",last_error="Nenhum item disponível no snapshot de impressão.",failed_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$row['id'],$tenantId]);return null;}
            unset($row['payload_json'],$row['idempotency_key']);
            return['queue'=>$row,'items'=>$items,'printer_target'=>(string)($row['printer_target']??''),'device_id'=>$deviceId,'unit_id'=>$unitId];
        });
    }

    public function completePrint(int$queueId,string$deviceId,bool$success,string$error=''):array
    {
        $this->requirePrint();$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$queueId<1)throw new RuntimeException('Impressão inválida.');$deviceId=$this->device($deviceId);$error=mb_substr(trim($error),0,1000);
        return Database::transaction(function(PDO$pdo)use($tenantId,$userId,$queueId,$deviceId,$success,$error):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT q.*,ps.name station_name,o.unit_id,o.status order_status FROM production_print_queue q JOIN production_stations ps ON ps.id=q.station_id AND ps.tenant_id=q.tenant_id JOIN orders o ON o.id=q.order_id AND o.tenant_id=q.tenant_id WHERE q.id=? AND q.tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$queueId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Item da fila de impressão não encontrado.');
            if((string)$row['status']==='printed')return$this->publicQueue($row);if((string)$row['status']!=='processing')throw new RuntimeException('Esta impressão não está reservada para conclusão.');if(!hash_equals((string)($row['claimed_device_id']??''),$deviceId))throw new RuntimeException('A impressão foi reservada por outro computador.');
            if($success){
                $pdo->prepare('UPDATE production_print_queue SET status="printed",printed_at=CURRENT_TIMESTAMP,last_error=NULL,next_attempt_at=NULL,claimed_at=NULL,claimed_device_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$queueId,$tenantId]);
                $printType=(string)$row['queue_type']==='reprint'?'reprint':'auto';$payloadHash=(string)($row['payload_hash']??'');if($payloadHash==='')$payloadHash=hash('sha256',(string)$row['id']);
                $ins=$pdo->prepare('INSERT INTO production_prints (tenant_id,unit_id,station_id,order_id,print_type,printed_by,reason,payload_hash) VALUES (?,?,?,?,?,?,?,?)');$ins->execute([$tenantId,$row['unit_id'],$row['station_id'],$row['order_id'],$printType,$userId,$row['reason']?:null,$payloadHash]);
                Auth::audit('production.desktop_printed','order',(string)$row['order_id'],['queue_id'=>$queueId,'station_id'=>(int)$row['station_id'],'scope'=>(string)($row['print_scope']??'station'),'queue_type'=>(string)$row['queue_type'],'device_id'=>$deviceId]);
            }else{
                if($error==='')$error='Falha ao imprimir no Windows.';$attempts=(int)$row['attempts'];$terminal=$attempts>=5;$status=$terminal?'failed':'error';$delay=max(15,min(180,$attempts*20));
                if(Database::isSqlite($pdo)){$next=$terminal?null:gmdate('Y-m-d H:i:s',time()+$delay);$pdo->prepare('UPDATE production_print_queue SET status=?,last_error=?,failed_at=CURRENT_TIMESTAMP,next_attempt_at=?,claimed_at=NULL,claimed_device_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$status,$error,$next,$queueId,$tenantId]);}
                else{$pdo->prepare('UPDATE production_print_queue SET status=?,last_error=?,failed_at=CURRENT_TIMESTAMP,next_attempt_at='.($terminal?'NULL':'DATE_ADD(CURRENT_TIMESTAMP,INTERVAL '.$delay.' SECOND)').',claimed_at=NULL,claimed_device_id=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$status,$error,$queueId,$tenantId]);}
                Auth::audit('production.desktop_print_failed','order',(string)$row['order_id'],['queue_id'=>$queueId,'station_id'=>(int)$row['station_id'],'device_id'=>$deviceId,'attempts'=>$attempts]);
            }
            $r=$pdo->prepare('SELECT * FROM production_print_queue WHERE id=? AND tenant_id=?');$r->execute([$queueId,$tenantId]);return$this->publicQueue($r->fetch(PDO::FETCH_ASSOC)?:$row);
        });
    }

    public function retryPrint(int$queueId,string$reason):array
    {
        $this->requirePrint();$tenantId=Auth::tenantId();if(!$tenantId||$queueId<1)throw new RuntimeException('Impressão inválida.');$reason=preg_replace('/\s+/u',' ',trim($reason))??'';if(mb_strlen($reason)<5)throw new RuntimeException('Informe o motivo da nova tentativa.');$reason=mb_substr($reason,0,500);
        return Database::transaction(function(PDO$pdo)use($tenantId,$queueId,$reason):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM production_print_queue WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$queueId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Impressão não encontrada.');if(!in_array((string)$row['status'],['failed','error'],true))throw new RuntimeException('Somente impressão com falha pode ser reenfileirada.');$pdo->prepare('UPDATE production_print_queue SET status="pending",attempts=0,claimed_at=NULL,claimed_device_id=NULL,last_error=NULL,next_attempt_at=NULL,failed_at=NULL,reason=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$reason,$queueId,$tenantId]);Auth::audit('production.print_requeued','production_print_queue',(string)$queueId,['reason'=>$reason]);$q->execute([$queueId,$tenantId]);return$this->publicQueue($q->fetch(PDO::FETCH_ASSOC)?:$row);});
    }

    public function bindStationPrinter(int$stationId,string$deviceId,string$printerTarget,bool$automatic):array
    {
        Auth::requirePermission('production.manage');$tenantId=Auth::tenantId();if(!$tenantId||$stationId<1)throw new RuntimeException('Estação inválida.');$unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];$deviceId=trim($deviceId)===''?null:$this->device($deviceId);$printerTarget=mb_substr(trim($printerTarget),0,255);if($automatic&&$printerTarget==='')throw new RuntimeException('Escolha a impressora da estação antes de ativar impressão automática.');$mode=$automatic?'auto':'manual';$pdo=Database::connection();$s=$pdo->prepare('UPDATE production_stations SET printer_mode=?,printer_target=?,desktop_device_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND unit_id=?');$s->execute([$mode,$printerTarget?:null,$deviceId,$stationId,$tenantId,$unitId]);if($s->rowCount()===0){$q=$pdo->prepare('SELECT id FROM production_stations WHERE id=? AND tenant_id=? AND unit_id=?');$q->execute([$stationId,$tenantId,$unitId]);if(!$q->fetchColumn())throw new RuntimeException('Estação não encontrada nesta unidade.');}Auth::audit('production.station_printer_bound','production_station',(string)$stationId,['unit_id'=>$unitId,'device_id'=>$deviceId,'printer_target'=>$printerTarget,'automatic'=>$automatic]);$q=$pdo->prepare('SELECT * FROM production_stations WHERE id=? AND tenant_id=?');$q->execute([$stationId,$tenantId]);return$q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Falha ao recarregar estação.');
    }

    private function requireOperation():void{$shift=(new WorkShiftService())->current();if(!$shift||$shift['mode']!=='operation')throw new RuntimeException('Use a produção durante um turno de Operação.');}
    private function requirePrint():void{$this->requireOperation();if(!Auth::can('production.print')&&!Auth::can('orders.kitchen')&&!Auth::can('orders.dispatch'))throw new RuntimeException('Sem permissão para imprimir produção.');}
    private function device(string$value):string{$value=mb_substr(trim($value),0,190);if($value==='')throw new RuntimeException('Computador não identificado.');return$value;}
    private function publicQueue(array$row):array{unset($row['payload_hash'],$row['payload_json'],$row['idempotency_key']);return$row;}
}
