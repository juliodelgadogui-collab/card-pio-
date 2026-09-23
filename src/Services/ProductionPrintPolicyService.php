<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ProductionPrintPolicyService
{
    private const TRIGGERS=['order_created','order_confirmed','payment_confirmed','employee_accepted'];
    private const KITCHEN_TYPES=['kitchen','grill','fryer','dessert','assembly'];

    public function currentSettings():array
    {
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];
        return$this->settings(Database::connection(),$tenantId,$unitId);
    }

    public function saveCurrentSettings(bool$enabled,string$trigger,bool$cashierEnabled,?int$cashierStationId):array
    {
        Auth::requirePermission('production.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];$trigger=strtolower(trim($trigger));if(!in_array($trigger,self::TRIGGERS,true))throw new RuntimeException('Momento de impressão inválido.');
        $pdo=Database::connection();$current=$this->settings($pdo,$tenantId,$unitId);
        if($cashierEnabled){$cashierStationId=(int)$cashierStationId;if($cashierStationId<1)throw new RuntimeException('Selecione o setor/impressora do caixa.');$s=$pdo->prepare('SELECT id FROM production_stations WHERE id=? AND tenant_id=? AND unit_id=? AND active=1 AND printer_mode<>"none"');$s->execute([$cashierStationId,$tenantId,$unitId]);if(!$s->fetchColumn())throw new RuntimeException('O setor escolhido para o caixa é inválido ou não aceita impressão.');}else$cashierStationId=null;
        $reset=(int)($current['enabled']??0)!==($enabled?1:0)||(string)($current['trigger_event']??'')!==$trigger;
        $sql='UPDATE production_print_settings SET enabled=?,trigger_event=?,cashier_enabled=?,cashier_station_id=?,'.($reset?'enabled_at=CURRENT_TIMESTAMP,':'').'updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=?';
        $pdo->prepare($sql)->execute([$enabled?1:0,$trigger,$cashierEnabled?1:0,$cashierStationId,$tenantId,$unitId]);
        if(!$enabled)$pdo->prepare('UPDATE production_print_queue SET status="deferred",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=? AND queue_type="auto" AND status IN ("pending","error")')->execute([$tenantId,$unitId]);
        Auth::audit('production.print_policy_saved','operating_unit',(string)$unitId,['enabled'=>$enabled,'trigger_event'=>$trigger,'cashier_enabled'=>$cashierEnabled,'cashier_station_id'=>$cashierStationId]);
        return$this->settings($pdo,$tenantId,$unitId);
    }

    public function syncCurrentUnit(?PDO$pdo=null,?int$tenantId=null,?int$unitId=null,int$limit=120):array
    {
        $pdo??=Database::connection();$tenantId??=(int)(Auth::tenantId()??0);$result=['orders_scanned'=>0,'queued'=>0,'deferred'=>0,'legacy_upgraded'=>0];if($tenantId<1)return$result;
        if(!$unitId){try{$unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];}catch(Throwable){return$result;}}
        $settings=$this->settings($pdo,$tenantId,$unitId);$enabledAt=(string)$settings['enabled_at'];$trigger=(string)$settings['trigger_event'];
        if((int)$settings['enabled']!==1){$q=$pdo->prepare('UPDATE production_print_queue SET status="deferred",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=? AND queue_type="auto" AND status IN ("pending","error")');$q->execute([$tenantId,$unitId]);$result['deferred']=$q->rowCount();return$result;}

        $legacy=$pdo->prepare('SELECT id,order_id,station_id,status,created_at FROM production_print_queue WHERE tenant_id=? AND unit_id=? AND queue_type="auto" AND payload_json IS NULL AND status IN ("pending","error","deferred") ORDER BY id LIMIT 100');$legacy->execute([$tenantId,$unitId]);
        foreach($legacy->fetchAll(PDO::FETCH_ASSOC)as$row){$prePolicy=strcmp((string)$row['created_at'],$enabledAt)<0;$allow=$prePolicy||$this->eventSatisfied($pdo,$tenantId,(int)$row['order_id'],$trigger,$enabledAt);if(!$allow){if((string)$row['status']!=='deferred'){$pdo->prepare('UPDATE production_print_queue SET status="deferred",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([(int)$row['id'],$tenantId]);$result['deferred']++;}continue;}$pdo->prepare('UPDATE production_print_queue SET status="superseded",last_error="Fila convertida para snapshot persistente.",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([(int)$row['id'],$tenantId]);$result['queued']+=$this->queueForOrder($pdo,$tenantId,(int)$row['order_id'],$trigger,null,(int)$row['station_id'],false,$settings);$result['legacy_upgraded']++;}

        $limit=max(10,min(400,$limit));$q=$pdo->prepare('SELECT id FROM orders WHERE tenant_id=? AND unit_id=? AND status<>"draft" ORDER BY id DESC LIMIT '.$limit);$q->execute([$tenantId,$unitId]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)as$orderId){$result['orders_scanned']++;if(!$this->eventSatisfied($pdo,$tenantId,(int)$orderId,$trigger,$enabledAt))continue;$result['queued']+=$this->queueForOrder($pdo,$tenantId,(int)$orderId,$trigger,null,null,true,$settings);}
        return$result;
    }

    public function queueForOrder(PDO$pdo,int$tenantId,int$orderId,string$event,?int$requestedBy=null,?int$stationOnly=null,bool$includeCashier=true,?array$settings=null):int
    {
        $order=$this->order($pdo,$tenantId,$orderId);if(!$order||in_array((string)$order['status'],['draft','cancelled'],true))return 0;$unitId=(int)($order['unit_id']??0);if($unitId<1)return 0;$settings??=$this->settings($pdo,$tenantId,$unitId);if((int)$settings['enabled']!==1||$event!==(string)$settings['trigger_event'])return 0;
        $queued=0;foreach($this->sectorSnapshots($pdo,$tenantId,$order,false)as$sid=>$snapshot){if($stationOnly!==null&&$sid!==$stationOnly)continue;$queued+=$this->enqueue($pdo,$tenantId,$order,$snapshot,'auto',$event,$requestedBy,null,null);}
        if($includeCashier&&$stationOnly===null&&(int)($settings['cashier_enabled']??0)===1){$station=(int)($settings['cashier_station_id']??0);if($station>0&&($snapshot=$this->cashierSnapshot($pdo,$tenantId,$order,$station,false)))$queued+=$this->enqueue($pdo,$tenantId,$order,$snapshot,'auto',$event,$requestedBy,null,null);}
        return$queued;
    }

    public function reprintOrder(int$orderId,string$scope,string$reason,?int$stationId=null):array
    {
        if(!Auth::can('production.print')&&!Auth::can('production.manage')&&!Auth::can('orders.kitchen'))throw new RuntimeException('Sem permissão para reimprimir pedidos.');$tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$orderId<1)throw new RuntimeException('Pedido inválido.');$unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];
        $scope=strtolower(trim($scope));if(!in_array($scope,['complete','kitchen','bar','cashier','station'],true))throw new RuntimeException('Tipo de reimpressão inválido.');$reason=preg_replace('/\s+/u',' ',trim($reason))??'';if(mb_strlen($reason)<5)throw new RuntimeException('Informe o motivo da reimpressão.');$reason=mb_substr($reason,0,500);
        $pdo=Database::connection();$order=$this->order($pdo,$tenantId,$orderId);if(!$order||(int)($order['unit_id']??0)!==$unitId)throw new RuntimeException('Pedido não pertence à unidade atual.');$settings=$this->settings($pdo,$tenantId,$unitId);$ids=[];
        foreach($this->sectorSnapshots($pdo,$tenantId,$order,true)as$sid=>$snapshot){$type=(string)$snapshot['station']['station_type'];$include=match($scope){'complete'=>true,'kitchen'=>in_array($type,self::KITCHEN_TYPES,true),'bar'=>$type==='bar','station'=>$stationId!==null&&$sid===$stationId,default=>false};if(!$include)continue;$id=$this->enqueue($pdo,$tenantId,$order,$snapshot,'reprint','reprint',$userId,$reason,$this->latestPrinted($pdo,$tenantId,$orderId,$sid,'station'));if($id)$ids[]=$id;}
        if(in_array($scope,['complete','cashier'],true)){$cashier=(int)($settings['cashier_station_id']??0);if($cashier>0&&($snapshot=$this->cashierSnapshot($pdo,$tenantId,$order,$cashier,true))){$id=$this->enqueue($pdo,$tenantId,$order,$snapshot,'reprint','reprint',$userId,$reason,$this->latestPrinted($pdo,$tenantId,$orderId,$cashier,'cashier'));if($id)$ids[]=$id;}elseif($scope==='cashier')throw new RuntimeException('A impressora do caixa não está configurada.');}
        if(!$ids)throw new RuntimeException('Nenhuma via disponível para este pedido e tipo de reimpressão.');Auth::audit('production.order_reprint_queued','order',(string)$orderId,['scope'=>$scope,'reason'=>$reason,'queue_ids'=>$ids,'station_id'=>$stationId]);return$ids;
    }

    public function settings(PDO$pdo,int$tenantId,int$unitId):array
    {
        $q=$pdo->prepare('SELECT * FROM production_print_settings WHERE tenant_id=? AND unit_id=? LIMIT 1');$q->execute([$tenantId,$unitId]);if($row=$q->fetch(PDO::FETCH_ASSOC))return$row;$enabledAt=gmdate('Y-m-d H:i:s',time()-600);$sql=Database::portableSql($pdo,'INSERT IGNORE INTO production_print_settings (tenant_id,unit_id,enabled,trigger_event,cashier_enabled,cashier_station_id,enabled_at) VALUES (?,?,1,"order_confirmed",0,NULL,?)');$pdo->prepare($sql)->execute([$tenantId,$unitId,$enabledAt]);$q->execute([$tenantId,$unitId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Não foi possível carregar a política de impressão.');return$row;
    }

    private function eventSatisfied(PDO$pdo,int$tenantId,int$orderId,string$event,string$enabledAt):bool
    {
        $q=$pdo->prepare('SELECT status,payment_status,created_at FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);if(!$order||in_array((string)$order['status'],['draft','cancelled'],true))return false;
        if($event==='order_created')return strcmp((string)$order['created_at'],$enabledAt)>=0;
        if($event==='payment_confirmed'){if((string)$order['payment_status']!=='paid')return false;return$this->latestPaidAt($pdo,$tenantId,$orderId,$enabledAt);}
        if($event==='employee_accepted'){$h=$pdo->prepare('SELECT MAX(created_at) FROM order_status_history WHERE tenant_id=? AND order_id=? AND source="accept" AND to_status="confirmed"');$h->execute([$tenantId,$orderId]);$at=(string)($h->fetchColumn()?:'');return$at!==''&&strcmp($at,$enabledAt)>=0;}
        if($event==='order_confirmed'){if(!in_array((string)$order['status'],['confirmed','preparing','ready','served','out_for_delivery','completed'],true))return false;$h=$pdo->prepare('SELECT MAX(created_at) FROM order_status_history WHERE tenant_id=? AND order_id=? AND to_status="confirmed"');$h->execute([$tenantId,$orderId]);$at=(string)($h->fetchColumn()?:'');if($at!==''&&strcmp($at,$enabledAt)>=0)return true;if($this->latestPaidAt($pdo,$tenantId,$orderId,$enabledAt))return true;return strcmp((string)$order['created_at'],$enabledAt)>=0;}
        return false;
    }

    private function latestPaidAt(PDO$pdo,int$tenantId,int$orderId,string$enabledAt):bool{$p=$pdo->prepare('SELECT MAX(COALESCE(verified_at,created_at)) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$p->execute([$tenantId,$orderId]);$at=(string)($p->fetchColumn()?:'');return$at!==''&&strcmp($at,$enabledAt)>=0;}
    private function order(PDO$pdo,int$tenantId,int$orderId):?array{$q=$pdo->prepare('SELECT o.*,c.name customer_name,c.phone customer_phone,rt.name table_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id AND rt.tenant_id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);return$row?:null;}

    private function sectorSnapshots(PDO$pdo,int$tenantId,array$order,bool$allowManual):array
    {
        $orderId=(int)$order['id'];$unitId=(int)$order['unit_id'];$stations=[];$s=$pdo->prepare('SELECT * FROM production_stations WHERE tenant_id=? AND unit_id=? AND active=1 AND printer_mode<>"none"');$s->execute([$tenantId,$unitId]);foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){if(!$allowManual&&(string)$row['printer_mode']!=='auto')continue;$stations[(int)$row['id']]=$row;}if(!$stations)return[];$grouped=[];
        $i=$pdo->prepare('SELECT oi.id,oi.product_id,oi.name_snapshot,oi.quantity,oi.unit_price_cents,oi.total_cents,oi.notes,COALESCE(upp.station_id,pp.station_id) station_id,COALESCE(upp.production_enabled,pp.production_enabled,0) production_enabled FROM order_items oi LEFT JOIN product_unit_production_profiles upp ON upp.tenant_id=? AND upp.unit_id=? AND upp.product_id=oi.product_id LEFT JOIN product_production_profiles pp ON pp.tenant_id=? AND pp.product_id=oi.product_id WHERE oi.order_id=? ORDER BY oi.id');$i->execute([$tenantId,$unitId,$tenantId,$orderId]);foreach($i->fetchAll(PDO::FETCH_ASSOC)as$row){$sid=(int)($row['station_id']??0);if(!(int)$row['production_enabled']||!isset($stations[$sid]))continue;$grouped[$sid][]=$this->line('item',$row['id'],$row['name_snapshot'],$row['quantity'],$row['notes']??null,(int)$row['unit_price_cents'],(int)$row['total_cents']);}
        $m=$pdo->prepare('SELECT id,order_item_id,option_name_snapshot,quantity,unit_price_delta_cents,total_delta_cents,station_id,production_enabled FROM order_item_modifiers WHERE tenant_id=? AND order_id=? ORDER BY order_item_id,id');$m->execute([$tenantId,$orderId]);foreach($m->fetchAll(PDO::FETCH_ASSOC)as$row){$sid=(int)($row['station_id']??0);if(!(int)$row['production_enabled']||!isset($stations[$sid]))continue;$grouped[$sid][]=$this->line('modifier',$row['id'],$row['option_name_snapshot'],$row['quantity'],null,(int)$row['unit_price_delta_cents'],(int)$row['total_delta_cents']);}
        $out=[];foreach($grouped as$sid=>$lines)if($lines)$out[$sid]=$this->snapshot($order,$stations[$sid],$lines,'station');return$out;
    }

    private function cashierSnapshot(PDO$pdo,int$tenantId,array$order,int$stationId,bool$allowManual):?array
    {
        $q=$pdo->prepare('SELECT * FROM production_stations WHERE id=? AND tenant_id=? AND unit_id=? AND active=1 AND printer_mode<>"none" LIMIT 1');$q->execute([$stationId,$tenantId,(int)$order['unit_id']]);$station=$q->fetch(PDO::FETCH_ASSOC);if(!$station||(!$allowManual&&(string)$station['printer_mode']!=='auto'))return null;$lines=[];$items=$pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');$items->execute([(int)$order['id']]);
        foreach($items->fetchAll(PDO::FETCH_ASSOC)as$item){$lines[]=$this->line('item',$item['id'],$item['name_snapshot'],$item['quantity'],$item['notes']??null,(int)$item['unit_price_cents'],(int)$item['total_cents']);$mods=$pdo->prepare('SELECT id,option_name_snapshot,quantity,unit_price_delta_cents,total_delta_cents FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=? ORDER BY id');$mods->execute([$tenantId,(int)$order['id'],(int)$item['id']]);foreach($mods->fetchAll(PDO::FETCH_ASSOC)as$mod)$lines[]=$this->line('modifier',$mod['id'],$mod['option_name_snapshot'],$mod['quantity'],null,(int)$mod['unit_price_delta_cents'],(int)$mod['total_delta_cents']);}
        return$lines?$this->snapshot($order,$station,$lines,'cashier'):null;
    }

    private function snapshot(array$order,array$station,array$lines,string$scope):array{return['scope'=>$scope,'station'=>['id'=>(int)$station['id'],'name'=>(string)$station['name'],'station_type'=>(string)$station['station_type']],'order'=>['id'=>(int)$order['id'],'channel'=>(string)$order['channel'],'customer_name'=>(string)($order['customer_name']??''),'customer_phone'=>(string)($order['customer_phone']??''),'table_name'=>(string)($order['table_name']??''),'notes'=>(string)($order['notes']??''),'delivery_address'=>(string)($order['delivery_address']??''),'subtotal_cents'=>(int)$order['subtotal_cents'],'discount_cents'=>(int)($order['discount_cents']??0),'delivery_fee_cents'=>(int)($order['delivery_fee_cents']??0),'total_cents'=>(int)$order['total_cents'],'created_at'=>(string)$order['created_at']],'items'=>$lines];}
    private function line(string$kind,mixed$id,mixed$description,mixed$quantity,mixed$notes,int$unitPrice,int$total):array{return['id'=>(int)$id,'kind'=>$kind,'description'=>(string)$description,'quantity'=>(float)$quantity,'notes'=>$notes!==null?(string)$notes:null,'unit_price_cents'=>$unitPrice,'total_cents'=>$total,'status'=>'snapshot'];}

    private function enqueue(PDO$pdo,int$tenantId,array$order,array$snapshot,string$queueType,string$trigger,?int$userId,?string$reason,?int$reprintOf):int
    {
        $stationId=(int)$snapshot['station']['id'];$scope=(string)$snapshot['scope'];$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);$key=$queueType==='reprint'?hash('sha256','reprint|'.$tenantId.'|'.(int)$order['id'].'|'.$scope.'|'.$stationId.'|'.microtime(true).'|'.bin2hex(random_bytes(8))):hash('sha256','auto|'.$tenantId.'|'.(int)$order['id'].'|'.$trigger.'|'.$scope.'|'.$stationId.'|v2');
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO production_print_queue (tenant_id,unit_id,station_id,order_id,queue_type,trigger_event,print_scope,status,requested_by,reason,payload_hash,payload_json,idempotency_key,reprint_of) VALUES (?,?,?,?,?,?,?,"pending",?,?,?,?,?,?)');$i=$pdo->prepare($sql);$i->execute([$tenantId,(int)$order['unit_id'],$stationId,(int)$order['id'],$queueType,$trigger,$scope,$userId,$reason,$hash,$json,$key,$reprintOf]);if($i->rowCount()>0)return(int)$pdo->lastInsertId();$q=$pdo->prepare('SELECT id,status FROM production_print_queue WHERE idempotency_key=? LIMIT 1');$q->execute([$key]);$row=$q->fetch(PDO::FETCH_ASSOC);if($row&&(string)$row['status']==='deferred')$pdo->prepare('UPDATE production_print_queue SET status="pending",last_error=NULL,next_attempt_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['id']]);return 0;
    }

    private function latestPrinted(PDO$pdo,int$tenantId,int$orderId,int$stationId,string$scope):?int{$q=$pdo->prepare('SELECT id FROM production_print_queue WHERE tenant_id=? AND order_id=? AND station_id=? AND print_scope=? AND status="printed" ORDER BY id DESC LIMIT 1');$q->execute([$tenantId,$orderId,$stationId,$scope]);$id=(int)$q->fetchColumn();return$id>0?$id:null;}
}
