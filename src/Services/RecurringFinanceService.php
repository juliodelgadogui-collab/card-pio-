<?php

declare(strict_types=1);

namespace EventMenu\Services;

use DateTimeImmutable;
use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class RecurringFinanceService
{
    private const FREQUENCIES=['daily','weekly','monthly','yearly'];

    public function save(?int$id,string$direction,string$description,int$amountCents,string$frequency,int$intervalCount,string$nextDueDate,?string$endDate,?int$unitId,?int$eventId,?int$accountId,?int$categoryId,bool$active):int
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $direction=strtolower(trim($direction));$frequency=strtolower(trim($frequency));$description=mb_substr(trim($description),0,255);$intervalCount=max(1,min(60,$intervalCount));
        if(!in_array($direction,['in','out'],true)||!in_array($frequency,self::FREQUENCIES,true)||$description===''||$amountCents<=0)throw new RuntimeException('Recorrência financeira inválida.');
        $nextDueDate=$this->date($nextDueDate)??throw new RuntimeException('Informe o próximo vencimento.');$endDate=$this->date($endDate);if($endDate!==null&&$endDate<$nextDueDate)throw new RuntimeException('A data final deve ser posterior ao primeiro vencimento.');
        $anchorDay=(int)substr($nextDueDate,8,2);$pdo=Database::connection();
        if($unitId){$q=$pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=?');$q->execute([$unitId,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Unidade inválida.');}
        if($eventId){$q=$pdo->prepare('SELECT id FROM events WHERE id=? AND tenant_id=?');$q->execute([$eventId,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Evento inválido.');}
        if($accountId){$q=$pdo->prepare('SELECT id FROM financial_accounts WHERE id=? AND tenant_id=?');$q->execute([$accountId,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Conta inválida.');}
        if($categoryId){$q=$pdo->prepare('SELECT direction FROM financial_categories WHERE id=? AND tenant_id=?');$q->execute([$categoryId,$tenantId]);$catDir=$q->fetchColumn();if($catDir===false)throw new RuntimeException('Categoria inválida.');if((string)$catDir!==$direction)throw new RuntimeException('A categoria não corresponde ao tipo do lançamento.');}
        if($id){$q=$pdo->prepare('UPDATE financial_recurring_templates SET unit_id=?,event_id=?,account_id=?,category_id=?,direction=?,description=?,amount_cents=?,frequency=?,interval_count=?,anchor_day=?,next_due_date=?,end_date=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$q->execute([$unitId,$eventId,$accountId,$categoryId,$direction,$description,$amountCents,$frequency,$intervalCount,$anchorDay,$nextDueDate,$endDate,$active?1:0,$id,$tenantId]);if(!$q->rowCount()){ $e=$pdo->prepare('SELECT id FROM financial_recurring_templates WHERE id=? AND tenant_id=?');$e->execute([$id,$tenantId]);if(!$e->fetchColumn())throw new RuntimeException('Recorrência não encontrada.');}}
        else{$q=$pdo->prepare('INSERT INTO financial_recurring_templates (tenant_id,unit_id,event_id,account_id,category_id,direction,description,amount_cents,frequency,interval_count,anchor_day,next_due_date,end_date,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute([$tenantId,$unitId,$eventId,$accountId,$categoryId,$direction,$description,$amountCents,$frequency,$intervalCount,$anchorDay,$nextDueDate,$endDate,$active?1:0,Auth::id()]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('finance.recurring_saved','financial_recurring_template',(string)$id,['direction'=>$direction,'amount_cents'=>$amountCents,'frequency'=>$frequency,'interval_count'=>$intervalCount,'next_due_date'=>$nextDueDate,'active'=>$active]);return(int)$id;
    }

    public function templates():array
    {
        Auth::requirePermission('finance.view');$tenantId=Auth::tenantId();if(!$tenantId)return[];$q=Database::connection()->prepare('SELECT r.*,fc.name category_name,fa.name account_name,ou.name unit_name,e.name event_name FROM financial_recurring_templates r LEFT JOIN financial_categories fc ON fc.id=r.category_id LEFT JOIN financial_accounts fa ON fa.id=r.account_id LEFT JOIN operating_units ou ON ou.id=r.unit_id LEFT JOIN events e ON e.id=r.event_id WHERE r.tenant_id=? ORDER BY r.active DESC,r.next_due_date,r.id');$q->execute([$tenantId]);return$q->fetchAll();
    }

    public function setActive(int$id,bool$active):void
    {
        Auth::requirePermission('finance.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$q=Database::connection()->prepare('UPDATE financial_recurring_templates SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$q->execute([$active?1:0,$id,$tenantId]);if(!$q->rowCount())throw new RuntimeException('Recorrência não encontrada ou já estava neste estado.');Auth::audit('finance.recurring_status','financial_recurring_template',(string)$id,['active'=>$active]);
    }

    public function generateThrough(int$tenantId,string$throughDate):int
    {
        $through=$this->date($throughDate)??throw new RuntimeException('Horizonte financeiro inválido.');$pdo=Database::connection();$generated=0;$guard=0;
        while($guard++<1000){
            $q=$pdo->prepare('SELECT id FROM financial_recurring_templates WHERE tenant_id=? AND active=1 AND next_due_date<=? AND (end_date IS NULL OR next_due_date<=end_date) ORDER BY next_due_date,id LIMIT 50');$q->execute([$tenantId,$through]);$ids=array_map('intval',array_column($q->fetchAll(),'id'));if(!$ids)break;
            foreach($ids as$id){
                try{$did=Database::transaction(fn(PDO$tx):bool=>$this->generateOne($tx,$tenantId,$id,$through));if($did)$generated++;}catch(\Throwable){}
            }
        }
        return$generated;
    }

    private function generateOne(PDO$pdo,int$tenantId,int$id,string$through):bool
    {
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM financial_recurring_templates WHERE id=? AND tenant_id=? FOR UPDATE'));$q->execute([$id,$tenantId]);$r=$q->fetch();if(!$r||(int)$r['active']!==1)return false;$due=(string)$r['next_due_date'];if($due>$through)return false;if($r['end_date']!==null&&$due>(string)$r['end_date']){$pdo->prepare('UPDATE financial_recurring_templates SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$id]);return false;}
        $key='recurring:'.$id.':'.$due;$sql=Database::portableSql($pdo,'INSERT IGNORE INTO financial_entries (tenant_id,unit_id,event_id,account_id,category_id,direction,entry_type,description,gross_cents,fee_cents,net_cents,affects_result,affects_cash,status,competence_date,due_date,idempotency_key,metadata,created_by) VALUES (?,?,?,?,?,?,?,?,?,0,?,1,1,"open",?,?,?,?,?)');
        $pdo->prepare($sql)->execute([$tenantId,$r['unit_id']?:null,$r['event_id']?:null,$r['account_id']?:null,$r['category_id']?:null,$r['direction'],'recurring',$r['description'],(int)$r['amount_cents'],(int)$r['amount_cents'],$due,$due,$key,json_encode(['template_id'=>$id,'frequency'=>$r['frequency'],'interval_count'=>(int)$r['interval_count']],JSON_UNESCAPED_UNICODE),$r['created_by']?:null]);
        $next=$this->nextDate($due,(string)$r['frequency'],(int)$r['interval_count'],(int)($r['anchor_day']??0));$stillActive=$r['end_date']===null||$next<=(string)$r['end_date'];$pdo->prepare('UPDATE financial_recurring_templates SET next_due_date=?,active=?,last_generated_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$next,$stillActive?1:0,$id]);return true;
    }

    private function nextDate(string$current,string$frequency,int$interval,int$anchorDay):string
    {
        $d=new DateTimeImmutable($current);return match($frequency){'daily'=>$d->modify('+'.$interval.' day')->format('Y-m-d'),'weekly'=>$d->modify('+'.$interval.' week')->format('Y-m-d'),'monthly'=>$this->addMonths($d,$interval,$anchorDay),'yearly'=>$d->modify('+'.$interval.' year')->format('Y-m-d'),default=>throw new RuntimeException('Frequência inválida.')};
    }

    private function addMonths(DateTimeImmutable$d,int$months,int$anchorDay):string
    {
        $target=$d->modify('first day of this month')->modify('+'.$months.' month');$last=(int)$target->format('t');$day=max(1,min($last,$anchorDay>0?$anchorDay:(int)$d->format('d')));return$target->setDate((int)$target->format('Y'),(int)$target->format('m'),$day)->format('Y-m-d');
    }

    private function date(mixed$value):?string
    {
        $v=trim((string)$value);if($v==='')return null;if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)||strtotime($v)===false)throw new RuntimeException('Data inválida.');return$v;
    }
}
