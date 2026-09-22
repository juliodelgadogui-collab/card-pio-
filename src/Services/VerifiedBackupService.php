<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;
use Throwable;

final class VerifiedBackupService
{
    public function runScheduled(): array
    {
        $verified=(new RuntimeStatusService())->get('backup.last_verified');
        if($verified&&($verified['state']??null)==='ok'&&!empty($verified['checked_at'])){
            $ts=strtotime((string)$verified['checked_at']);
            if($ts!==false&&$ts>time()-20*3600)return ['skipped'=>true,'reason'=>'backup verificado recente'];
        }
        return $this->run();
    }

    public function run(): array
    {
        $runtime=new RuntimeStatusService();$file=null;$localVerified=false;$localMeta=[];
        try{
            $result=(new BackupService())->run();
            $file=$this->backupFile((string)($result['file']??''));
            $verification=$this->verify((string)($result['driver']??''),$file);
            $localMeta=$result+$verification+['verified'=>true];$localVerified=true;
            $runtime->set('backup.last_verification','ok','Integridade do backup confirmada.',$localMeta);
            $runtime->set('backup.last_verified','ok','Último backup verificado e disponível.',$localMeta);

            $mirror=$this->mirror($file,$verification['sha256']);
            if(!empty($mirror['mirror_configured']))$runtime->set('backup.last_mirror','ok','Segunda cópia do backup verificada.',$mirror);
            else $runtime->set('backup.last_mirror','warning','Backup secundário não configurado.',['mirror_configured'=>false]);
            return $localMeta+$mirror;
        }catch(Throwable $e){
            if($localVerified){
                $runtime->set('backup.last_mirror','error','Falha ao manter a segunda cópia do backup.',['mirror_configured'=>true,'local_backup_preserved'=>true,'file'=>$file!==null?basename($file):null,'error'=>$this->safe($e->getMessage())]);
                throw $e;
            }
            if($file!==null){
                @unlink($file.'.sha256');@unlink($file);
                $runtime->set('backup.last_run','error','Backup descartado por falha na verificação.',['file'=>basename($file),'verified'=>false,'error'=>$this->safe($e->getMessage())]);
            }
            $runtime->set('backup.last_verification','error',$file!==null?'O backup não passou na verificação e foi descartado.':'O backup falhou antes da etapa de verificação.',['verified'=>false,'error'=>$this->safe($e->getMessage())]);
            throw $e;
        }
    }

    /** @return array{verification:string,sha256:string,checksum_file:string,integrity_check?:string,essential_tables?:int} */
    private function verify(string $driver,string $file):array
    {
        if(!is_file($file)||(int)filesize($file)<1)throw new RuntimeException('Arquivo de backup ausente ou vazio.');
        $details=$driver==='sqlite'?$this->verifySqlite($file):['verification'=>'dump_exit_and_checksum'];
        $hash=hash_file('sha256',$file);if($hash===false||!preg_match('/^[a-f0-9]{64}$/',$hash))throw new RuntimeException('Não foi possível calcular o SHA-256 do backup.');
        $checksum=$file.'.sha256';if(file_put_contents($checksum,$hash.'  '.basename($file).PHP_EOL,LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar o checksum do backup.');@chmod($checksum,0640);
        return $details+['sha256'=>$hash,'checksum_file'=>basename($checksum)];
    }

    /** @return array{verification:string,integrity_check:string,essential_tables:int} */
    private function verifySqlite(string $file):array
    {
        $backup=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $integrity=strtolower(trim((string)$backup->query('PRAGMA quick_check')->fetchColumn()));if($integrity!=='ok')throw new RuntimeException('SQLite quick_check retornou: '.($integrity!==''?$integrity:'sem resposta').'.');
        $essential=(int)$backup->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('tenants','users','orders')")->fetchColumn();if($essential!==3)throw new RuntimeException('Backup SQLite não contém todas as tabelas essenciais do EventMenu.');
        return ['verification'=>'sqlite_quick_check','integrity_check'=>'ok','essential_tables'=>$essential];
    }

    private function mirror(string $file,string $expectedHash):array
    {
        $configured=trim((string)env('BACKUP_MIRROR_PATH',''));
        if($configured==='')return ['mirror_configured'=>false,'mirror_verified'=>false];
        $root=dirname(__DIR__,2);$dir=$configured;
        if(!str_starts_with($dir,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$dir))$dir=$root.'/'.ltrim($dir,'/\\');
        if(!is_dir($dir)&&!@mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível preparar a pasta da cópia secundária do backup.');
        if(!is_writable($dir))throw new RuntimeException('A pasta da cópia secundária do backup não possui permissão de gravação.');
        $target=rtrim($dir,'/\\').'/'.basename($file);$tmp=$target.'.tmp-'.bin2hex(random_bytes(4));
        if(!@copy($file,$tmp)){@unlink($tmp);throw new RuntimeException('Falha ao copiar o arquivo para o backup secundário.');}
        $copiedHash=hash_file('sha256',$tmp);if($copiedHash===false||!hash_equals($expectedHash,$copiedHash)){@unlink($tmp);throw new RuntimeException('A cópia secundária do backup não passou na verificação SHA-256.');}
        if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Não foi possível finalizar a cópia secundária do backup.');}
        @chmod($target,0640);
        $checksum=$target.'.sha256';if(file_put_contents($checksum,$expectedHash.'  '.basename($target).PHP_EOL,LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar o checksum da cópia secundária do backup.');@chmod($checksum,0640);
        return ['mirror_configured'=>true,'mirror_verified'=>true,'mirror_file'=>basename($target),'mirror_sha256'=>$expectedHash];
    }

    private function backupFile(string $basename):string
    {
        if($basename===''||basename($basename)!==$basename)throw new RuntimeException('Nome do arquivo de backup inválido.');
        $root=dirname(__DIR__,2);$dir=trim((string)env('BACKUP_PATH','storage/backups'));if(!str_starts_with($dir,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$dir))$dir=$root.'/'.ltrim($dir,'/\\');return rtrim($dir,'/\\').'/'.$basename;
    }

    private function safe(string $message):string{return mb_substr(preg_replace('/password|secret|token|key/i','credencial',$message)??$message,0,300);}
}
