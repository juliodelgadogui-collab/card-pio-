<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\SensitiveApiRateLimitService;

function srl_fail(string $message): never { fwrite(STDERR,"SENSITIVE RATE CI FAIL: {$message}\n"); exit(1); }
function srl_assert(bool $ok,string $message): void { if(!$ok)srl_fail($message); }

$service=new SensitiveApiRateLimitService();
$required=[
    'cancel.request','cancel.approve','cancel.reject',
    'discount.request','discount.approve','discount.reject',
    'qr.issue','qr.revoke','device.request_nfc',
    'tab.group_create','tab.group_cancel','tab.pix_create','tab.nfc_intent','tab.nfc_verify',
];
$actions=$service->actions();
foreach($required as$action){
    srl_assert(in_array($action,$actions,true),"Política ausente: {$action}");
    $policy=$service->policy($action);
    srl_assert(is_array($policy),'Política inválida: '.$action);
    srl_assert(($policy['limit']??0)>0&&($policy['window']??0)>=10,'Limite/janela inválidos: '.$action);
    srl_assert(trim((string)($policy['message']??''))!=='','Mensagem amigável ausente: '.$action);
}

$user=['tenant_id'=>987654,'id'=>456789,'token_id'=>123456];
$device='ci-sensitive-'.bin2hex(random_bytes(8));
$policy=$service->policy('device.request_nfc');
$limit=(int)($policy['limit']??0);
srl_assert($limit===5,'Limite de request_nfc mudou sem atualizar o smoke.');
for($i=0;$i<$limit;$i++)$service->assertAllowed('device.request_nfc',$user,$device);
$blocked=false;
try{$service->assertAllowed('device.request_nfc',$user,$device);}catch(ApiRateLimitExceededException){$blocked=true;}
srl_assert($blocked,'Excesso de request_nfc não gerou ApiRateLimitExceededException.');

$service->assertAllowed('device.request_nfc',$user,$device.'-other');

$unknown=false;
try{$service->assertAllowed('unknown.action',$user,$device);}catch(RuntimeException){$unknown=true;}
srl_assert($unknown,'Ação sem política não foi rejeitada.');

echo "CI sensitive API rate limit smoke OK\n";
