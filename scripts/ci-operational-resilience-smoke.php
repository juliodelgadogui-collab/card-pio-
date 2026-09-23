<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\DesktopHardwareService;
use EventMenu\Services\FcmPushService;
use EventMenu\Services\ProductionPrintRecoveryService;

function resilience_fail(string $message):never{fwrite(STDERR,"RESILIENCE CI FAIL: {$message}\n");exit(1);}
function resilience_assert(bool $ok,string $message):void{if(!$ok)resilience_fail($message);}

try{
    $pdo=Database::connection();$suffix=bin2hex(random_bytes(5));$slug='ci-resilience-'.$suffix;
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Resilience',$slug]);$tenantId=(int)$pdo->lastInsertId();
    $email='resilience-'.$suffix.'@example.test';
    $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'CI Resilience Admin',$email,password_hash('CI-Test-2026!',PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'ci-a-'.$suffix,'Unidade A']);$unitA=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,active) VALUES (?,?,?,1)')->execute([$tenantId,'ci-b-'.$suffix,'Unidade B']);$unitB=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_unit_access (tenant_id,user_id,unit_id,is_default) VALUES (?,?,?,1)')->execute([$tenantId,$userId,$unitA]);
    $_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='CI Resilience Admin';unset($_SESSION['acting_tenant_id']);

    // Push atrasado não pode reaparecer depois de vencer ou depois de o usuário já ter lido.
    $past=gmdate('Y-m-d H:i:s',time()-600);$future=gmdate('Y-m-d H:i:s',time()+600);
    $n=$pdo->prepare('INSERT INTO app_notifications (tenant_id,user_id,mode,type,priority,title,message,dedupe_key,read_at,expires_at) VALUES (?,?,"operation","ci.resilience","info","Teste","Mensagem",?,?,?)');
    $n->execute([$tenantId,$userId,'expired-'.$suffix,null,$past]);$expiredId=(int)$pdo->lastInsertId();
    $expired=(new FcmPushService())->sendNotification($expiredId);resilience_assert(($expired['expired']??false)===true,'Notificação vencida ainda seguiria para push.');
    $n->execute([$tenantId,$userId,'read-'.$suffix,gmdate('Y-m-d H:i:s'),$future]);$readId=(int)$pdo->lastInsertId();
    $read=(new FcmPushService())->sendNotification($readId);resilience_assert(($read['already_read']??false)===true,'Notificação já lida ainda seguiria para push.');

    // Hardware precisa respeitar o recorte de unidade do usuário, mesmo dentro do mesmo tenant.
    $hardware=new DesktopHardwareService();$device='ci-desktop-'.$suffix;
    $binding=$hardware->heartbeat($unitA,$device,'Desktop CI',['computer_name'=>'CI']);resilience_assert((int)$binding['unit_id']===$unitA,'Heartbeat da unidade autorizada falhou.');
    $blocked=false;try{$hardware->heartbeat($unitB,$device.'-b','Desktop indevido',['computer_name'=>'CI']);}catch(RuntimeException){$blocked=true;}
    resilience_assert($blocked,'Hardware foi registrado em unidade sem acesso do usuário.');
    $listed=$hardware->listBindings();resilience_assert(count(array_filter($listed,fn(array$row):bool=>(int)$row['unit_id']!==$unitA))===0,'Lista de hardware expôs outra unidade.');

    // Logout deve desligar diretamente o push do mesmo aparelho.
    $rawToken=bin2hex(random_bytes(32));$authDevice='ci-phone-'.$suffix;$tokenHash=hash('sha256',$rawToken);$deviceHash=hash('sha256',$authDevice);$expires=gmdate('Y-m-d H:i:s',time()+1800);
    $pdo->prepare('INSERT INTO api_tokens (tenant_id,user_id,token_hash,device_hash,device_label,expires_at) VALUES (?,?,?,?,?,?)')->execute([$tenantId,$userId,$tokenHash,$deviceHash,'CI Phone',$expires]);
    $pushToken='ci-push-'.$suffix.'-'.bin2hex(random_bytes(24));$pdo->prepare('INSERT INTO push_devices (tenant_id,user_id,device_id,platform,token_hash,push_token,active,last_seen_at) VALUES (?,?,?,"android",?,?,1,CURRENT_TIMESTAMP)')->execute([$tenantId,$userId,$authDevice,hash('sha256',$pushToken),$pushToken]);
    $_SERVER['HTTP_X_DEVICE_ID']=$authDevice;(new ApiAuthService())->revoke($rawToken);unset($_SERVER['HTTP_X_DEVICE_ID']);
    $q=$pdo->prepare('SELECT active FROM push_devices WHERE tenant_id=? AND user_id=? AND device_id=?');$q->execute([$tenantId,$userId,$authDevice]);resilience_assert((int)$q->fetchColumn()===0,'Logout deixou push ativo no aparelho.');
    $q=$pdo->prepare('SELECT revoked_at FROM api_tokens WHERE token_hash=?');$q->execute([$tokenHash]);resilience_assert((string)$q->fetchColumn()!=='','Logout não revogou token de acesso.');

    // Reserva de impressão abandonada por queda do Desktop precisa voltar para a fila ou falhar definitivamente.
    $pdo->prepare('INSERT INTO production_stations (tenant_id,unit_id,code,name,station_type,sla_minutes,active) VALUES (?,?,?,?,"kitchen",15,1)')->execute([$tenantId,$unitA,'ci-station-'.$suffix,'Cozinha CI']);$stationId=(int)$pdo->lastInsertId();
    $orderInsert=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,channel,status,payment_status,subtotal_cents,total_cents,created_by) VALUES (?,?,?,"counter","confirmed","unpaid",1000,1000,?)');
    $orderInsert->execute([bin2hex(random_bytes(20)),$tenantId,$unitA,$userId]);$orderRetry=(int)$pdo->lastInsertId();
    $orderInsert->execute([bin2hex(random_bytes(20)),$tenantId,$unitA,$userId]);$orderFailed=(int)$pdo->lastInsertId();
    $stale=gmdate('Y-m-d H:i:s',time()-900);$queue=$pdo->prepare('INSERT INTO production_print_queue (tenant_id,unit_id,station_id,order_id,queue_type,status,attempts,claimed_at,claimed_device_id,payload_hash) VALUES (?,?,?, ?,"auto","processing",?,?,?,?)');
    $queue->execute([$tenantId,$unitA,$stationId,$orderRetry,2,$stale,'desktop-dead',hash('sha256','retry-'.$suffix)]);$retryId=(int)$pdo->lastInsertId();
    $queue->execute([$tenantId,$unitA,$stationId,$orderFailed,5,$stale,'desktop-dead',hash('sha256','failed-'.$suffix)]);$failedId=(int)$pdo->lastInsertId();
    $recovered=(new ProductionPrintRecoveryService())->recover($tenantId,$unitA);resilience_assert((int)$recovered['retry']===1,'Impressão abandonada não voltou para nova tentativa.');resilience_assert((int)$recovered['failed']===1,'Impressão abandonada no limite não virou falha definitiva.');
    $q=$pdo->prepare('SELECT status,claimed_at,claimed_device_id FROM production_print_queue WHERE id=?');$q->execute([$retryId]);$row=$q->fetch();resilience_assert(($row['status']??'')==='error'&&empty($row['claimed_at'])&&empty($row['claimed_device_id']),'Reserva recuperável continuou presa.');
    $q->execute([$failedId]);$row=$q->fetch();resilience_assert(($row['status']??'')==='failed'&&empty($row['claimed_at'])&&empty($row['claimed_device_id']),'Reserva terminal continuou presa.');

    echo 'CI operational resilience smoke OK ('.Database::driver($pdo).")\n";
}catch(Throwable$e){resilience_fail($e->getMessage()."\n".$e->getTraceAsString());}
