<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use Throwable;

final class SystemHealthService
{
    public function snapshot():array
    {
        $checks=[];$pdo=Database::connection();
        try{$pdo->query('SELECT 1')->fetchColumn();$checks['database']=$this->check('ok','Banco de dados conectado.',['driver'=>Database::driver($pdo)]);}catch(Throwable $e){$checks['database']=$this->check('error','Falha de conexão com o banco.',['error'=>$this->safe($e->getMessage())]);}

        $runtime=new RuntimeStatusService();$cron=$runtime->get('cron.last_run');$cronAge=$this->age($cron['checked_at']??null);$checks['cron']=$cron===null?$this->check('warning','Cron ainda não registrou execução.'):($cronAge>300?$this->check('warning','Cron está atrasado.',['seconds_since_run'=>$cronAge]):$this->check((string)($cron['state']??'ok'),'Cron executando.',['seconds_since_run'=>$cronAge,'last_result'=>$cron['metadata']??[]]));

        try{$queue=(new BackgroundJobService())->stats();$oldestAge=$this->age($queue['oldest_pending_at']??null);$state=(int)$queue['failed']>0?'warning':(((int)$queue['pending']+(int)$queue['retry']>100||$oldestAge>180)?'warning':'ok');$checks['queue']=$this->check($state,$state==='ok'?'Fila em dia.':'Fila precisa de atenção.',$queue+['oldest_pending_seconds'=>$oldestAge]);}catch(Throwable $e){$checks['queue']=$this->check('error','Fila indisponível.',['error'=>$this->safe($e->getMessage())]);}

        try{$pushConfigured=(new FcmPushService())->configured();$devices=(int)$pdo->query('SELECT COUNT(*) FROM push_devices WHERE active=1')->fetchColumn();$checks['push']=$this->check($pushConfigured?'ok':'warning',$pushConfigured?'Firebase Cloud Messaging configurado.':'Push instantâneo ainda sem credencial Firebase.',['active_devices'=>$devices,'configured'=>$pushConfigured]);}catch(Throwable $e){$checks['push']=$this->check('warning','Push ainda não disponível.',['error'=>$this->safe($e->getMessage())]);}

        $backup=$runtime->get('backup.last_success');$backupAge=$this->age($backup['checked_at']??null);$backupState=$backup===null||$backupAge>36*3600?'warning':'ok';$checks['backup']=$this->check($backupState,$backupState==='ok'?'Backup recente disponível.':'Backup automático ainda não está recente.',['seconds_since_backup'=>$backupAge,'last_backup'=>$backup['metadata']??null]);

        try{$g=$pdo->query('SELECT provider,COUNT(*) qty FROM payment_gateways WHERE active=1 GROUP BY provider ORDER BY provider');$gatewayRows=$g->fetchAll();$checks['gateways']=$this->check($gatewayRows?'ok':'warning',$gatewayRows?'Gateways ativos encontrados.':'Nenhum gateway de pagamento ativo.',['providers'=>$gatewayRows]);}catch(Throwable $e){$checks['gateways']=$this->check('warning','Não foi possível ler os gateways.',['error'=>$this->safe($e->getMessage())]);}

        try{$last=$pdo->query('SELECT provider,status,created_at,processed_at FROM webhook_events ORDER BY id DESC LIMIT 1')->fetch();$cutoff=gmdate('Y-m-d H:i:s',time()-86400);$s=$pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE status="failed" AND created_at>=?');$s->execute([$cutoff]);$failed=(int)$s->fetchColumn();$checks['webhooks']=$this->check($failed>0?'warning':'ok',$last?'Webhooks estão sendo registrados.':'Nenhum webhook recebido ainda.',['last'=>$last?:null,'failed_last_24h'=>$failed]);}catch(Throwable $e){$checks['webhooks']=$this->check('warning','Histórico de webhooks indisponível.',['error'=>$this->safe($e->getMessage())]);}

        $root=dirname(__DIR__,2);$storage=$root.'/storage';$writable=is_dir($storage)&&is_writable($storage);$free=@disk_free_space($storage?:$root);$checks['storage']=$this->check($writable?'ok':'error',$writable?'Armazenamento gravável.':'Pasta storage sem permissão de escrita.',['free_bytes'=>$free!==false?(int)$free:null]);

        $overall='ok';foreach($checks as$check){if($check['state']==='error'){$overall='error';break;}if($check['state']==='warning')$overall='warning';}
        return['overall'=>$overall,'checks'=>$checks,'generated_at'=>gmdate('c'),'app'=>['environment'=>(string)env('APP_ENV','production'),'debug'=>filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL),'min_app_version'=>(string)env('APP_MIN_VERSION','0'),'recommended_app_version'=>(string)env('APP_RECOMMENDED_VERSION','')]];
    }

    private function check(string$state,string$message,array$details=[]):array{return['state'=>in_array($state,['ok','warning','error','disabled'],true)?$state:'warning','message'=>$message,'details'=>$details];}
    private function age(mixed$date):int{if(!$date)return PHP_INT_MAX;$ts=strtotime((string)$date);return$ts===false?PHP_INT_MAX:max(0,time()-$ts);}
    private function safe(string$message):string{return mb_substr(preg_replace('/password|secret|token|key/i','credencial',$message)??$message,0,300);}
}
