<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\CustomerIdentityService;

function customer_ci_fail(string $message):never{fwrite(STDERR,"CUSTOMER CI FAIL: {$message}\n");exit(1);}
function customer_ci_assert(bool $ok,string $message):void{if(!$ok)customer_ci_fail($message);}

$pdo=Database::connection();$tenantId=(int)$pdo->query('SELECT id FROM tenants WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn();if($tenantId<1)customer_ci_fail('Empresa de teste não encontrada.');
$identity=new CustomerIdentityService();$suffix=(string)random_int(100000,999999);$phone='(21) 9'.$suffix.'-1234';$normalized=$identity->normalizePhone($phone);customer_ci_assert(strlen($normalized)>=10,'Telefone normalizado inválido.');

$first=$identity->save($pdo,$tenantId,['name'=>'Cliente Telefone CI','phone'=>$phone,'email'=>'cliente-'.$suffix.'@example.test','address'=>'Rua Teste, 10']);customer_ci_assert((int)$first['id']>0,'Cliente não foi criado.');customer_ci_assert((string)$first['phone_normalized']===$normalized,'Telefone normalizado não foi persistido.');
$found=$identity->findByPhone($pdo,$tenantId,'+55 '.$phone);customer_ci_assert(is_array($found)&&(int)$found['id']===(int)$first['id'],'Busca por variação do telefone não encontrou o mesmo cliente.');
$reused=$identity->findOrCreate($pdo,$tenantId,'Cliente Atualizado','21 '.$normalized,'Rua Teste, 10');customer_ci_assert((int)$reused['id']===(int)$first['id'],'findOrCreate criou cliente duplicado.');

$duplicateBlocked=false;try{$identity->save($pdo,$tenantId,['name'=>'Duplicado','phone'=>'21 '.$normalized]);}catch(RuntimeException){$duplicateBlocked=true;}customer_ci_assert($duplicateBlocked,'Cadastro duplicado por telefone não foi bloqueado.');
$count=$pdo->prepare('SELECT COUNT(*) FROM customers WHERE tenant_id=? AND phone_normalized=?');$count->execute([$tenantId,$normalized]);customer_ci_assert((int)$count->fetchColumn()===1,'Banco possui mais de uma identidade ativa para o mesmo telefone.');

echo "Customer identity smoke OK\n";
