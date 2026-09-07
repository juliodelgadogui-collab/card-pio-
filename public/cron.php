<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\MaintenanceService;
use EventMenu\Services\RuntimeStatusService;

$isCli=PHP_SAPI==='cli';
if(!$isCli){
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
    $expected=(string)env('CRON_SECRET','');$received=(string)($_SERVER['HTTP_X_CRON_SECRET']??'');
    if($expected===''||$received===''||!hash_equals($expected,$received)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Cron não autorizado.'],JSON_UNESCAPED_UNICODE);exit;}
}
$lockDir=dirname(__DIR__).'/storage';if(!is_dir($lockDir))@mkdir($lockDir,0775,true);$lockHandle=@fopen($lockDir.'/cron.lock','c+');
if($lockHandle&&!flock($lockHandle,LOCK_EX|LOCK_NB)){$payload=['ok'=>true,'skipped'=>true,'reason'=>'Cron já está em execução.','ran_at'=>gmdate('c')];if($isCli)echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;else echo json_encode($payload,JSON_UNESCAPED_UNICODE);exit;}
try{$result=(new MaintenanceService())->run();$payload=['ok'=>true,'result'=>$result,'ran_at'=>gmdate('c')];if($isCli){echo json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;}else echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){try{(new RuntimeStatusService())->set('cron.last_run','error','Falha na manutenção.',['error'=>mb_substr($e->getMessage(),0,300)]);}catch(Throwable){}if($isCli){fwrite(STDERR,'Maintenance error: '.$e->getMessage().PHP_EOL);exit(1);}http_response_code(500);echo json_encode(['ok'=>false,'error'=>filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL)?$e->getMessage():'Erro interno.'],JSON_UNESCAPED_UNICODE);}finally{if($lockHandle){@flock($lockHandle,LOCK_UN);@fclose($lockHandle);}}
