<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\GatewayService;
use EventMenu\Services\NfcDeviceService;
use EventMenu\Services\NfcPaymentService;
use EventMenu\Services\PagBankSecurityService;

function assert_nfc(bool $condition,string $message):void{
    if(!$condition){fwrite(STDERR,"NFC integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();$suffix=bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,? ,"premium","active")')->execute(['CI NFC','ci-nfc-'.$suffix]);$tenantId=(int)$pdo->lastInsertId();
$hash=password_hash('nfc-ci-password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Admin NFC CI','nfc-'.$suffix.'@example.com',$hash]);$userId=(int)$pdo->lastInsertId();
$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=$tenantId;$_SESSION['role']='admin';$_SESSION['name']='Admin NFC CI';

// SSRF: host arbitrário nunca pode virar API base do PagBank.
$ssrfBlocked=false;
try{PagBankSecurityService::apiBase('http://127.0.0.1:8080');}catch(RuntimeException){$ssrfBlocked=true;}
assert_nfc($ssrfBlocked,'API base arbitrária do PagBank não foi bloqueada');
assert_nfc(PagBankSecurityService::apiBase('https://sandbox.api.pagseguro.com/')==='https://sandbox.api.pagseguro.com','sandbox oficial não foi normalizado');

$gatewayConfig=[
    'token'=>'ci-pagbank-token-'.$suffix,
    'api_base'=>'https://sandbox.api.pagseguro.com',
    'tap_on_email'=>'ci@example.com',
    'tap_on_token'=>'ci-tap-on-token-'.$suffix,
    'tap_on_environment'=>'sandbox',
];
(new GatewayService())->save('pagbank','',$gatewayConfig,'',true);
$gateway=$pdo->prepare('SELECT account_reference FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1');$gateway->execute([$tenantId]);
assert_nfc((string)$gateway->fetchColumn()!=='','gateway PagBank não foi ativado');

$devices=new NfcDeviceService();
$deviceIdentifier='android-device-'.$suffix.'-primary';
$paired=$devices->pair($deviceIdentifier,$userId,'NFC CI Principal');
assert_nfc(!$paired['locked'],'dispositivo principal ficou bloqueado no primeiro pareamento');
$d=$pdo->prepare('SELECT * FROM nfc_devices WHERE id=? AND tenant_id=?');$d->execute([$paired['id'],$tenantId]);$storedDevice=$d->fetch();
assert_nfc($storedDevice&&$storedDevice['status']==='active','dispositivo principal não ficou ativo');
assert_nfc($storedDevice['identifier_version']==='hmac-sha256','identificador NFC não usa HMAC');
assert_nfc($storedDevice['device_identifier_hash']===PagBankSecurityService::deviceFingerprint($deviceIdentifier),'fingerprint NFC persistido está incorreto');
assert_nfc($storedDevice['device_identifier_hash']!==hash('sha256',$deviceIdentifier),'identificador NFC ainda usa SHA-256 simples');

// Cinco pareamentos por janela; o sexto precisa bloquear novas tentativas por 30 minutos.
$throttleIdentifier='android-device-'.$suffix.'-throttle';
$baseNow=new DateTimeImmutable('2026-09-02 16:00:00',new DateTimeZone('UTC'));
for($i=0;$i<5;$i++)$devices->pair($throttleIdentifier,$userId,'NFC CI Throttle',$baseNow->modify('+'.$i.' minute'));
$pairBlocked=false;
try{$devices->pair($throttleIdentifier,$userId,'NFC CI Throttle',$baseNow->modify('+5 minutes'));}catch(RuntimeException $e){$pairBlocked=str_contains($e->getMessage(),'Limite de pareamentos');}
assert_nfc($pairBlocked,'sexto pareamento na janela não foi bloqueado');
$locked=$pdo->prepare('SELECT pairing_locked_until,status FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=?');$locked->execute([$tenantId,PagBankSecurityService::deviceFingerprint($throttleIdentifier)]);$locked=$locked->fetch();
assert_nfc($locked&&!empty($locked['pairing_locked_until']),'bloqueio de pareamento não foi persistido');
assert_nfc($locked['status']==='active','rate limit revogou indevidamente aparelho já ativo');

$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,stock_qty,track_stock,active) VALUES (?,"Produto NFC",2000,10,1,1)')->execute([$tenantId]);$productId=(int)$pdo->lastInsertId();
$orders=new CounterOrderService();
$order1=$orders->create([$productId=>1]);
$order2=$orders->create([$productId=>1]);
$order3=$orders->create([$productId=>1]);

$responses=[];
$code1='CHAR_CI'.strtoupper(bin2hex(random_bytes(8)));
$codeBad='CHAR_BAD'.strtoupper(bin2hex(random_bytes(8)));
$legacyCode='LEGACY'.strtoupper(bin2hex(random_bytes(10)));
$responses[$code1]=['id'=>$code1,'status'=>'PAID','reference_id'=>'eventmenu:'.$tenantId.':'.$order1['order_id'],'amount'=>['value'=>2000,'currency'=>'BRL','summary'=>['paid'=>2000]]];
$responses[$codeBad]=['id'=>$codeBad,'status'=>'PAID','reference_id'=>'eventmenu:'.$tenantId.':'.$order3['order_id'],'amount'=>['value'=>1900,'currency'=>'BRL','summary'=>['paid'=>1900]]];

$fetcher=function(string $url,array $headers)use(&$responses,$legacyCode,$tenantId,$order2):array{
    if(str_contains($url,'/charges/')){
        $code=strtoupper(rawurldecode((string)basename(parse_url($url,PHP_URL_PATH)?:'')));
        if(!isset($responses[$code]))throw new RuntimeException('Mock PagBank sem resposta para charge.');
        return $responses[$code];
    }
    if(str_contains($url,'/v2/transactions/')){
        return ['transaction'=>['code'=>$legacyCode,'status'=>3,'grossAmount'=>'20.00','reference'=>'eventmenu:'.$tenantId.':'.$order2['order_id']]];
    }
    throw new RuntimeException('Mock PagBank recebeu URL inesperada: '.$url);
};
$nfc=new NfcPaymentService($fetcher);

$result=$nfc->confirm((int)$order1['order_id'],$deviceIdentifier,$code1);
assert_nfc(!empty($result['verified'])&&!$result['reused'],'primeiro pagamento NFC não foi verificado');
$o=$pdo->prepare('SELECT payment_status FROM orders WHERE id=? AND tenant_id=?');$o->execute([$order1['order_id'],$tenantId]);assert_nfc($o->fetchColumn()==='paid','pedido NFC moderno não ficou pago');
$attempt=$pdo->prepare('SELECT status,amount_cents,currency FROM nfc_payment_attempts WHERE tenant_id=? AND order_id=?');$attempt->execute([$tenantId,$order1['order_id']]);$attempt=$attempt->fetch();
assert_nfc($attempt&&$attempt['status']==='verified'&&(int)$attempt['amount_cents']===2000&&$attempt['currency']==='BRL','tentativa NFC moderna não foi conciliada');

// Retry após resposta perdida: mesmo código + mesmo pedido retorna sucesso sem nova cobrança.
$retry=$nfc->confirm((int)$order1['order_id'],$deviceIdentifier,$code1);
assert_nfc(!empty($retry['verified'])&&!empty($retry['reused']),'retry NFC idempotente não retornou sucesso reutilizado');
$count=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND provider="pagbank" AND provider_payment_id=?');$count->execute([$tenantId,$order1['order_id'],$code1]);assert_nfc((int)$count->fetchColumn()===1,'retry NFC duplicou pagamento local');

// Mesmo transactionCode nunca pode pagar outro pedido.
$reusedBlocked=false;
try{$nfc->confirm((int)$order2['order_id'],$deviceIdentifier,$code1);}catch(RuntimeException $e){$reusedBlocked=str_contains($e->getMessage(),'outro pedido');}
assert_nfc($reusedBlocked,'transactionCode foi reutilizado em outro pedido');

// Caminho legado Tap On: servidor consulta a transação e só então confirma.
$legacy=$nfc->confirm((int)$order2['order_id'],$deviceIdentifier,$legacyCode);
assert_nfc(!empty($legacy['verified']),'transactionCode legado Tap On não foi verificado no servidor');
$o->execute([$order2['order_id'],$tenantId]);assert_nfc($o->fetchColumn()==='paid','pedido NFC legado não ficou pago');

// Valor divergente deve falhar fechado e manter pedido não pago.
$valueBlocked=false;
try{$nfc->confirm((int)$order3['order_id'],$deviceIdentifier,$codeBad);}catch(RuntimeException $e){$valueBlocked=str_contains($e->getMessage(),'Valor divergente');}
assert_nfc($valueBlocked,'valor divergente do PagBank não foi bloqueado');
$o->execute([$order3['order_id'],$tenantId]);assert_nfc($o->fetchColumn()!=='paid','pedido com valor divergente ficou pago');
$badAttempt=$pdo->prepare('SELECT status FROM nfc_payment_attempts WHERE tenant_id=? AND order_id=? AND transaction_code_hash=?');$badAttempt->execute([$tenantId,$order3['order_id'],PagBankSecurityService::transactionFingerprint($codeBad)]);assert_nfc($badAttempt->fetchColumn()==='rejected','tentativa NFC divergente não ficou rejeitada');

// Bloquear usuário revoga dispositivo e impede uso subsequente.
$revoked=$devices->revokeForUserChange($pdo,$tenantId,$userId,'user_blocked');
assert_nfc($revoked>=1,'mudança de usuário não revogou dispositivo NFC');
$pdo->prepare('UPDATE users SET status="blocked" WHERE id=? AND tenant_id=?')->execute([$userId,$tenantId]);
$authorizeBlocked=false;
try{$devices->authorize($pdo,$tenantId,$userId,$deviceIdentifier);}catch(RuntimeException){$authorizeBlocked=true;}
assert_nfc($authorizeBlocked,'usuário bloqueado ainda conseguiu autorizar NFC');

echo "NFC integration OK\n";
