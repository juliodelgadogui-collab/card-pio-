<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CatalogOptionsService;
use EventMenu\Services\MailQueueService;
use EventMenu\Services\NotificationService;
use EventMenu\Services\TenantModuleService;
use EventMenu\Services\WaiterCallService;

$pdo=Database::connection();
$suffix=bin2hex(random_bytes(5));
$fail=function(string $message):never{fwrite(STDERR,"Legacy parity integration failed: {$message}\n");exit(1);};
$assert=function(bool $condition,string $message)use($fail):void{if(!$condition)$fail($message);};
$tenantId=0;
$oldSession=$_SESSION;

try{
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?, ?,"premium","active")')->execute(['Parity CI '.$suffix,'parity-ci-'.$suffix]);
    $tenantId=(int)$pdo->lastInsertId();
    $module=$pdo->prepare('INSERT INTO tenant_modules (tenant_id,module_key,enabled) VALUES (?,?,?)');
    foreach(['menu'=>1,'delivery'=>0,'restaurant'=>1,'events'=>1,'loyalty'=>1] as$key=>$enabled)$module->execute([$tenantId,$key,$enabled]);
    TenantModuleService::clearCache();
    $assert(TenantModuleService::enabled($tenantId,'menu')===true,'menu module should be enabled');
    $assert(TenantModuleService::enabled($tenantId,'delivery')===false,'delivery module should be disabled');
    $assert(TenantModuleService::routeEnabled('delivery',$tenantId)===false,'delivery route should respect module flag');

    $pdo->prepare('INSERT INTO business_units (tenant_id,name,slug,status) VALUES (?,?,?,"active")')->execute([$tenantId,'Unidade Teste','unidade-'.$suffix]);
    $unitId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"waiter","active")')->execute([$tenantId,'Garçom CI','waiter-'.$suffix.'@example.test',password_hash('password-ci-123',PASSWORD_DEFAULT)]);
    $waiterId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_units (tenant_id,user_id,unit_id) VALUES (?,?,?)')->execute([$tenantId,$waiterId,$unitId]);
    $tableToken=bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO restaurant_tables (tenant_id,unit_id,name,seats,status,qr_token) VALUES (?,?,?,4,"available",?)')->execute([$tenantId,$unitId,'Mesa CI',$tableToken]);
    $tableId=(int)$pdo->lastInsertId();

    $calls=new WaiterCallService();
    $callId=$calls->createByTableToken($tableToken,'waiter');
    $same=$calls->createByTableToken($tableToken,'waiter');
    $assert($callId===$same,'duplicate open waiter call should be deduplicated');
    $n=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND target_user_id=? AND entity_type="waiter_call" AND entity_id=?');$n->execute([$tenantId,$waiterId,$callId]);
    $assert((int)$n->fetchColumn()===1,'waiter should receive one targeted notification');
    $_SESSION=['user_id'=>$waiterId,'tenant_id'=>$tenantId,'unit_id'=>$unitId,'role'=>'waiter','name'=>'Garçom CI'];
    $calls->resolve($tenantId,$callId);
    $s=$pdo->prepare('SELECT status,resolved_by FROM waiter_calls WHERE id=?');$s->execute([$callId]);$row=$s->fetch();
    $assert($row&&$row['status']==='resolved'&&(int)$row['resolved_by']===$waiterId,'waiter call should resolve with the authenticated waiter');

    $pdo->prepare('INSERT INTO products (tenant_id,unit_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,?,?,1000,20,1,1)')->execute([$tenantId,$unitId,'Produto CI']);
    $productId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_option_groups (tenant_id,product_id,name,min_select,max_select,active) VALUES (?,?,"Adicionais",1,2,1)')->execute([$tenantId,$productId]);
    $groupId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO product_options (tenant_id,group_id,name,price_delta_cents,active) VALUES (?,?,"Queijo",200,1),(?,?,"Bacon",300,1)')->execute([$tenantId,$groupId,$tenantId,$groupId]);
    $ids=$pdo->prepare('SELECT id FROM product_options WHERE group_id=? ORDER BY id');$ids->execute([$groupId]);$optionIds=array_map('intval',$ids->fetchAll(PDO::FETCH_COLUMN));
    $catalog=new CatalogOptionsService();$selection=$catalog->validateSelection($pdo,$tenantId,$productId,[$optionIds[0]]);
    $assert((int)$selection['delta_cents']===200,'option price delta should be recalculated by server');
    $thrown=false;try{$catalog->validateSelection($pdo,$tenantId,$productId,[]);}catch(Throwable){$thrown=true;}
    $assert($thrown,'required option group should reject empty selection');

    $notification=new NotificationService();
    $notification->pushOnce($tenantId,'stock','Estoque CI','Mensagem','product',$productId);
    $notification->pushOnce($tenantId,'stock','Estoque CI','Mensagem','product',$productId);
    $s=$pdo->prepare('SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND title="Estoque CI" AND entity_type="product" AND entity_id=? AND status="unread"');$s->execute([$tenantId,$productId]);
    $assert((int)$s->fetchColumn()===1,'pushOnce should deduplicate unread operational alerts');

    putenv('MAIL_DRIVER=log');$_ENV['MAIL_DRIVER']='log';
    $mail=new MailQueueService();$queued=$mail->queue('ci-'.$suffix.'@example.test','Teste EventMenu','Mensagem de teste','ci-mail-'.$suffix);
    $assert(is_file($queued),'mail should be queued on disk');
    $stats=$mail->process(25);
    $assert($stats['sent']>=1,'log mail driver should process queued email successfully');

    echo "Legacy parity integration OK\n";
}finally{
    $_SESSION=$oldSession;
    if($tenantId>0){try{$pdo->prepare('DELETE FROM tenants WHERE id=?')->execute([$tenantId]);}catch(Throwable){}}
}
