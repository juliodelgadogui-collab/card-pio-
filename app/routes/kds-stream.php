<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\OperatingUnitService;

Auth::requirePermission('orders.kitchen');
$tenantId=em_require_tenant();
$unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
if(function_exists('session_write_close'))session_write_close();
@set_time_limit(30);
$pdo=Database::connection();
$version=static function()use($pdo,$tenantId,$unitId):string{
    $s=$pdo->prepare('SELECT COUNT(*) jobs,COALESCE(MAX(j.updated_at),"") updated,COALESCE(SUM(j.change_version),0) versions,COALESCE(SUM(CASE WHEN j.status="ready" THEN 1 ELSE 0 END),0) ready FROM production_jobs j JOIN orders o ON o.id=j.order_id AND o.tenant_id=j.tenant_id WHERE j.tenant_id=? AND o.unit_id=? AND o.status<>"cancelled"');
    $s->execute([$tenantId,$unitId]);$r=$s->fetch()?:[];return hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE));
};
$baseline=$version();
echo "retry: 1500\n";
echo 'event: ready'."\n".'data: '.json_encode(['version'=>$baseline])."\n\n";@ob_flush();@flush();
for($i=0;$i<24;$i++){
    if(connection_aborted())break;
    sleep(1);$now=$version();
    if($now!==$baseline){echo 'event: change'."\n".'data: '.json_encode(['version'=>$now])."\n\n";@ob_flush();@flush();break;}
    if($i>0&&$i%8===0){echo ": keepalive\n\n";@ob_flush();@flush();}
}
exit;
