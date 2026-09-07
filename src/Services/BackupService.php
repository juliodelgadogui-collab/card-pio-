<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

final class BackupService
{
    public function runScheduled():array
    {
        $status=(new RuntimeStatusService())->get('backup.last_success');if($status&&!empty($status['checked_at'])){$ts=strtotime((string)$status['checked_at']);if($ts!==false&&$ts>time()-20*3600)return['skipped'=>true,'reason'=>'backup recente'];}
        return$this->run();
    }

    public function run():array
    {
        $started=microtime(true);$root=dirname(__DIR__,2);$dir=trim((string)env('BACKUP_PATH','storage/backups'));if(!str_starts_with($dir,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$dir))$dir=$root.'/'.ltrim($dir,'/\\');if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar a pasta de backup.');
        $runtime=new RuntimeStatusService();$runtime->set('backup.last_run','warning','Backup em execução.');
        try{$driver=Database::driver();$file=$driver==='sqlite'?$this->sqliteBackup($dir):$this->mysqlBackup($dir);$this->purgeOld($dir,max(1,(int)env('BACKUP_RETENTION_DAYS',14)));$size=is_file($file)?filesize($file):0;$meta=['driver'=>$driver,'file'=>basename($file),'bytes'=>(int)$size,'duration_ms'=>(int)round((microtime(true)-$started)*1000)];$runtime->set('backup.last_run','ok','Backup concluído.',$meta);$runtime->set('backup.last_success','ok','Último backup concluído.',$meta);return['ok'=>true]+$meta;}
        catch(Throwable $e){$runtime->set('backup.last_run','error','Falha no backup.',['error'=>mb_substr($e->getMessage(),0,300)]);throw$e;}
    }

    private function sqliteBackup(string $dir):string
    {
        $pdo=Database::connection();$target=$dir.'/eventmenu-sqlite-'.gmdate('Ymd-His').'.sqlite';if(is_file($target))@unlink($target);try{$pdo->exec('VACUUM INTO '.$pdo->quote($target));}catch(Throwable){$path=(string)env('DB_SQLITE_PATH','storage/eventmenu.sqlite');$root=dirname(__DIR__,2);if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$path))$path=$root.'/'.ltrim($path,'/\\');if(!is_file($path))throw new RuntimeException('Arquivo SQLite não encontrado para backup.');try{$pdo->exec('PRAGMA wal_checkpoint(FULL)');}catch(Throwable){}if(!copy($path,$target))throw new RuntimeException('Não foi possível copiar o banco SQLite.');}if(!is_file($target)||filesize($target)===0)throw new RuntimeException('Backup SQLite vazio.');@chmod($target,0640);return$target;
    }

    private function mysqlBackup(string $dir):string
    {
        if(!function_exists('proc_open'))throw new RuntimeException('proc_open indisponível para backup MySQL.');$bin=trim((string)env('MYSQLDUMP_BIN','mysqldump'))?:'mysqldump';$host=(string)env('DB_HOST','127.0.0.1');$port=(string)env('DB_PORT','3306');$db=(string)env('DB_DATABASE','eventmenu');$user=(string)env('DB_USERNAME','root');$pass=(string)env('DB_PASSWORD','');$target=$dir.'/eventmenu-mysql-'.gmdate('Ymd-His').'.sql';$cmd=escapeshellcmd($bin).' --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4 --host='.escapeshellarg($host).' --port='.escapeshellarg($port).' --user='.escapeshellarg($user).' '.escapeshellarg($db);$env=is_array(getenv())?getenv():[];$env['MYSQL_PWD']=$pass;$descriptors=[0=>['pipe','r'],1=>['file',$target,'wb'],2=>['pipe','w']];$process=proc_open($cmd,$descriptors,$pipes,null,$env);if(!is_resource($process))throw new RuntimeException('Não foi possível iniciar mysqldump.');fclose($pipes[0]);$stderr=stream_get_contents($pipes[2])?:'';fclose($pipes[2]);$code=proc_close($process);if($code!==0||!is_file($target)||filesize($target)===0){@unlink($target);throw new RuntimeException('mysqldump falhou: '.mb_substr(trim($stderr)?:('código '.$code),0,500));}@chmod($target,0640);return$target;
    }

    private function purgeOld(string $dir,int $days):void
    {
        $cutoff=time()-$days*86400;foreach(glob(rtrim($dir,'/\\').'/eventmenu-*')?:[]as$file){if(is_file($file)&&filemtime($file)!==false&&filemtime($file)<$cutoff)@unlink($file);}
    }
}
