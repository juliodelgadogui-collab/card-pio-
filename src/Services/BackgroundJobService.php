<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class BackgroundJobService
{
    public function enqueue(string $type,array $payload=[],?int $tenantId=null,?string $dedupeKey=null,?string $runAt=null,int $maxAttempts=5):int
    {
        $type=mb_substr(trim($type),0,80);if($type==='')throw new RuntimeException('Tipo de tarefa inválido.');
        $dedupeKey=$dedupeKey!==null?mb_substr(trim($dedupeKey),0,190):null;if($dedupeKey==='')$dedupeKey=null;
        $maxAttempts=max(1,min(20,$maxAttempts));$runAt=$this->normalizeDate($runAt)??gmdate('Y-m-d H:i:s');
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $pdo=Database::connection();$sql=Database::portableSql($pdo,'INSERT IGNORE INTO background_jobs (tenant_id,type,payload,dedupe_key,status,attempts,max_attempts,run_at) VALUES (?,?,?,?,"pending",0,?,?)');$s=$pdo->prepare($sql);$s->execute([$tenantId,$type,$json,$dedupeKey,$maxAttempts,$runAt]);
        if($s->rowCount()>0)return(int)$pdo->lastInsertId();
        if($dedupeKey!==null){$q=$pdo->prepare('SELECT id FROM background_jobs WHERE dedupe_key=? LIMIT 1');$q->execute([$dedupeKey]);return(int)$q->fetchColumn();}
        return 0;
    }

    public function runBatch(int $limit=25,?string $workerId=null):array
    {
        $limit=max(1,min(100,$limit));
        $workerId=mb_substr(trim((string)$workerId),0,100)?:('php-'.getmypid().'-'.substr(bin2hex(random_bytes(5)),0,10));
        $result=['processed'=>0,'completed'=>0,'retried'=>0,'failed'=>0,'worker'=>$workerId];
        $runtime=new RuntimeStatusService();
        try{$runtime->set('queue.worker.last_run','warning','Worker em execução.',['worker'=>$workerId,'batch_limit'=>$limit,'started_at'=>gmdate('c')]);}catch(Throwable){}

        try{
            for($i=0;$i<$limit;$i++){
                $job=$this->reserveOne($workerId);if(!$job)break;$result['processed']++;
                try{$this->handle($job);$this->complete((int)$job['id']);$result['completed']++;}
                catch(Throwable $e){$retry=$this->failOrRetry($job,$e);$result[$retry?'retried':'failed']++;}
            }
        }catch(Throwable $e){
            try{$runtime->set('queue.worker.last_run','error','Worker interrompido.',['worker'=>$workerId,'processed'=>$result['processed'],'error'=>mb_substr($e->getMessage(),0,300)]);}catch(Throwable){}
            throw $e;
        }

        $state=((int)$result['retried']>0||(int)$result['failed']>0)?'warning':'ok';
        try{$runtime->set('queue.worker.last_run',$state,$state==='ok'?'Worker executado.':'Worker executado com pendências.',$result);}catch(Throwable){}
        return$result;
    }

    public function stats():array
    {
        $pdo=Database::connection();
        $stats=['pending'=>0,'processing'=>0,'retry'=>0,'failed'=>0,'completed_recent'=>0,'oldest_pending_at'=>null,'oldest_processing_at'=>null,'stale_processing'=>0];
        foreach(['pending','processing','retry','failed']as$status){$s=$pdo->prepare('SELECT COUNT(*) FROM background_jobs WHERE status=?');$s->execute([$status]);$stats[$status]=(int)$s->fetchColumn();}
        $s=$pdo->query('SELECT MIN(run_at) FROM background_jobs WHERE status IN ("pending","retry")');$v=$s->fetchColumn();$stats['oldest_pending_at']=$v!==false&&$v!==null?(string)$v:null;
        $s=$pdo->query('SELECT MIN(locked_at) FROM background_jobs WHERE status="processing" AND locked_at IS NOT NULL');$v=$s->fetchColumn();$stats['oldest_processing_at']=$v!==false&&$v!==null?(string)$v:null;
        $stale=gmdate('Y-m-d H:i:s',time()-600);$s=$pdo->prepare('SELECT COUNT(*) FROM background_jobs WHERE status="processing" AND locked_at IS NOT NULL AND locked_at<=?');$s->execute([$stale]);$stats['stale_processing']=(int)$s->fetchColumn();
        $cutoff=gmdate('Y-m-d H:i:s',time()-86400);$s=$pdo->prepare('SELECT COUNT(*) FROM background_jobs WHERE status="completed" AND completed_at>=?');$s->execute([$cutoff]);$stats['completed_recent']=(int)$s->fetchColumn();return$stats;
    }

    public function purge(int $completedDays=7,int $failedDays=30):array
    {
        $pdo=Database::connection();$completedBefore=gmdate('Y-m-d H:i:s',time()-max(1,$completedDays)*86400);$failedBefore=gmdate('Y-m-d H:i:s',time()-max(1,$failedDays)*86400);$out=[];
        $s=$pdo->prepare('DELETE FROM background_jobs WHERE status="completed" AND completed_at IS NOT NULL AND completed_at<?');$s->execute([$completedBefore]);$out['completed']=$s->rowCount();
        $s=$pdo->prepare('DELETE FROM background_jobs WHERE status="failed" AND updated_at<?');$s->execute([$failedBefore]);$out['failed']=$s->rowCount();return$out;
    }

    private function reserveOne(string $workerId):array|false
    {
        $stale=gmdate('Y-m-d H:i:s',time()-600);
        return Database::transaction(function(PDO$pdo)use($workerId,$stale):array|false{
            $sql=Database::portableSql($pdo,'SELECT * FROM background_jobs WHERE ((status IN ("pending","retry") AND run_at<=CURRENT_TIMESTAMP) OR (status="processing" AND locked_at IS NOT NULL AND locked_at<=?)) ORDER BY run_at,id LIMIT 1 FOR UPDATE');$s=$pdo->prepare($sql);$s->execute([$stale]);$job=$s->fetch();if(!$job)return false;
            $u=$pdo->prepare('UPDATE background_jobs SET status="processing",attempts=attempts+1,locked_at=CURRENT_TIMESTAMP,locked_by=?,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$workerId,$job['id']]);$job['status']='processing';$job['attempts']=(int)$job['attempts']+1;$job['locked_by']=$workerId;return$job;
        });
    }

    private function handle(array $job):void
    {
        $payload=json_decode((string)($job['payload']??'{}'),true);if(!is_array($payload))$payload=[];
        match((string)$job['type']){
            'push.notification'=>(new FcmPushService())->sendNotification((int)($payload['notification_id']??0)),
            'customer.communication'=>(new CustomerCommunicationService())->handleJob($payload),
            'backup.daily'=>(new VerifiedBackupService())->runScheduled(),
            default=>throw new RuntimeException('Tipo de tarefa não suportado: '.(string)$job['type']),
        };
    }

    private function complete(int $id):void
    {
        $s=Database::connection()->prepare('UPDATE background_jobs SET status="completed",completed_at=CURRENT_TIMESTAMP,locked_at=NULL,locked_by=NULL,last_error=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$id]);
    }

    private function failOrRetry(array $job,Throwable $error):bool
    {
        $attempts=(int)$job['attempts'];$max=max(1,(int)$job['max_attempts']);$message=mb_substr(trim($error->getMessage()),0,2000);if($message==='')$message=get_class($error);$retry=$attempts<$max;$delay=min(3600,15*(2**max(0,$attempts-1)));$runAt=gmdate('Y-m-d H:i:s',time()+$delay);
        $s=Database::connection()->prepare('UPDATE background_jobs SET status=?,run_at=?,locked_at=NULL,locked_by=NULL,last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$retry?'retry':'failed',$runAt,$message,$job['id']]);return$retry;
    }

    private function normalizeDate(?string $value):?string
    {
        $value=trim((string)$value);if($value==='')return null;$ts=strtotime($value);return$ts===false?null:gmdate('Y-m-d H:i:s',$ts);
    }
}
