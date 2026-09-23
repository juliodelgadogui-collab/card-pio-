<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Services\RuntimeStatusService;
use EventMenu\Services\SystemHealthService;
use EventMenu\Services\VerifiedBackupService;

function backup_fail(string $message): never { fwrite(STDERR, "BACKUP CI FAIL: {$message}\n"); exit(1); }
function backup_assert(bool $ok, string $message): void { if (!$ok) backup_fail($message); }

$dir=sys_get_temp_dir().'/eventmenu-backup-ci-'.bin2hex(random_bytes(4));
$mirror=sys_get_temp_dir().'/eventmenu-backup-mirror-ci-'.bin2hex(random_bytes(4));
if(!mkdir($dir,0750,true)&&!is_dir($dir))backup_fail('Não foi possível criar diretório temporário.');
if(!mkdir($mirror,0750,true)&&!is_dir($mirror))backup_fail('Não foi possível criar diretório secundário.');
putenv('BACKUP_PATH='.$dir);$_ENV['BACKUP_PATH']=$dir;
putenv('BACKUP_MIRROR_PATH='.$mirror);$_ENV['BACKUP_MIRROR_PATH']=$mirror;
putenv('BACKUP_RETENTION_DAYS=1');$_ENV['BACKUP_RETENTION_DAYS']='1';

try{
    $result=(new VerifiedBackupService())->run();
    backup_assert(!empty($result['ok']),'Serviço não retornou ok.');
    backup_assert(!empty($result['verified']),'Backup não retornou verified=true.');
    backup_assert(($result['verification']??'')==='sqlite_quick_check','Método de verificação SQLite incorreto.');
    backup_assert(($result['integrity_check']??'')==='ok','quick_check não retornou ok.');
    backup_assert((int)($result['essential_tables']??0)===3,'Tabelas essenciais não foram confirmadas.');
    backup_assert(!empty($result['mirror_configured'])&&!empty($result['mirror_verified']),'Backup secundário não foi confirmado.');

    $file=$dir.'/'.basename((string)($result['file']??''));
    $checksumFile=$dir.'/'.basename((string)($result['checksum_file']??''));
    $mirrorFile=$mirror.'/'.basename((string)($result['mirror_file']??''));
    $mirrorChecksum=$mirrorFile.'.sha256';
    backup_assert(is_file($file)&&filesize($file)>0,'Arquivo de backup não foi criado.');
    backup_assert(is_file($checksumFile)&&filesize($checksumFile)>0,'Arquivo SHA-256 não foi criado.');
    backup_assert(is_file($mirrorFile)&&filesize($mirrorFile)>0,'Cópia secundária não foi criada.');
    backup_assert(is_file($mirrorChecksum)&&filesize($mirrorChecksum)>0,'Checksum da cópia secundária não foi criado.');

    $sha=hash_file('sha256',$file);$mirrorSha=hash_file('sha256',$mirrorFile);
    backup_assert(is_string($sha)&&strlen($sha)===64,'SHA-256 do arquivo inválido.');
    backup_assert(hash_equals($sha,(string)($result['sha256']??'')),'SHA-256 retornado diverge do arquivo.');
    backup_assert(is_string($mirrorSha)&&hash_equals($sha,$mirrorSha),'Cópia secundária diverge do backup principal.');
    backup_assert(hash_equals($sha,(string)($result['mirror_sha256']??'')),'SHA-256 secundário retornado diverge.');
    $sidecar=trim((string)file_get_contents($checksumFile));backup_assert($sidecar===$sha.'  '.basename($file),'Conteúdo do arquivo SHA-256 divergente.');
    $mirrorSidecar=trim((string)file_get_contents($mirrorChecksum));backup_assert($mirrorSidecar===$sha.'  '.basename($mirrorFile),'Checksum secundário divergente.');

    $copy=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    backup_assert(strtolower(trim((string)$copy->query('PRAGMA quick_check')->fetchColumn()))==='ok','A cópia não abre com quick_check=ok.');
    backup_assert((int)$copy->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('tenants','users','orders')")->fetchColumn()===3,'A cópia não possui as tabelas essenciais.');$copy=null;

    $status=(new RuntimeStatusService())->get('backup.last_verified');backup_assert(($status['state']??null)==='ok','Runtime não registrou backup.last_verified como OK.');backup_assert(!empty($status['metadata']['verified']),'Runtime não registrou verified=true.');
    $mirrorStatus=(new RuntimeStatusService())->get('backup.last_mirror');backup_assert(($mirrorStatus['state']??null)==='ok','Runtime não registrou backup.last_mirror como OK.');backup_assert(!empty($mirrorStatus['metadata']['mirror_verified']),'Runtime não registrou mirror_verified=true.');
    $health=(new SystemHealthService())->snapshot();backup_assert(($health['checks']['backup']['state']??null)==='ok','Saúde do sistema não reconheceu backup verificado recente.');
}finally{
    foreach(glob($dir.'/*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($dir);
    foreach(glob($mirror.'/*')?:[]as$file)if(is_file($file))@unlink($file);@rmdir($mirror);
}

echo "CI verified local + secondary backup smoke OK\n";
