<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\TableService;

function table_ci_fail(string $message):never{fwrite(STDERR,"TABLE TENANT CI FAIL: {$message}\n");exit(1);}
function table_ci_assert(bool $ok,string $message):void{if(!$ok)table_ci_fail($message);}

try{
    $pdo=Database::connection();
    $suffix=bin2hex(random_bytes(5));

    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Table CI A','table-ci-a-'.$suffix]);
    $tenantA=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Table CI B','table-ci-b-'.$suffix]);
    $tenantB=(int)$pdo->lastInsertId();
    table_ci_assert($tenantA>0&&$tenantB>0&&$tenantA!==$tenantB,'Tenants de teste não foram criados.');

    $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantA,'Table Admin A','table-a-'.$suffix.'@example.test','x']);
    $userA=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantB,'Table Admin B','table-b-'.$suffix.'@example.test','x']);
    $userB=(int)$pdo->lastInsertId();

    $_SESSION['user_id']=$userA;$_SESSION['tenant_id']=$tenantA;$_SESSION['role']='admin';$_SESSION['name']='Table Admin A';unset($_SESSION['acting_tenant_id']);

    $pdo->prepare('INSERT INTO restaurant_tables (tenant_id,name,seats,status,qr_token) VALUES (?,"Mesa A",4,"available",?)')->execute([$tenantA,bin2hex(random_bytes(20))]);
    $tableA=(int)$pdo->lastInsertId();
    $service=new TableService();
    $tab=$service->open($tableA,'Conta CI');
    $tabId=(int)$tab['id'];
    table_ci_assert($tabId>0,'Comanda legítima não foi aberta.');

    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,channel,status,payment_status,subtotal_cents,total_cents,tab_id,created_by) VALUES (?, ?, "table", "completed", "paid", 1000, 1000, ?, ?)')->execute([bin2hex(random_bytes(20)),$tenantA,$tabId,$userA]);
    $legitOrder=(int)$pdo->lastInsertId();
    table_ci_assert($legitOrder>0,'Pedido legítimo da comanda não foi criado.');

    // Dado propositalmente inconsistente: pedido do tenant B referencia a comanda do tenant A.
    // A listagem da mesa A jamais pode somar esse valor.
    $pdo->prepare('INSERT INTO orders (public_token,tenant_id,channel,status,payment_status,subtotal_cents,total_cents,tab_id,created_by) VALUES (?, ?, "table", "completed", "paid", 9900, 9900, ?, ?)')->execute([bin2hex(random_bytes(20)),$tenantB,$tabId,$userB]);

    $rows=$service->list();
    $row=null;foreach($rows as$candidate)if((int)$candidate['id']===$tableA){$row=$candidate;break;}
    table_ci_assert(is_array($row),'Mesa legítima desapareceu da listagem.');
    table_ci_assert((int)$row['tab_id']===$tabId,'Comanda aberta não foi vinculada à mesa legítima.');
    table_ci_assert((int)$row['tab_total_cents']===1000,'Pedido de outro tenant contaminou o total da comanda.');
    table_ci_assert((int)$row['unpaid_cents']===0,'Saldo da comanda ficou incorreto.');

    $details=$service->details($tabId);
    table_ci_assert((int)$details['total_cents']===1000,'Detalhe da comanda somou pedido de outro tenant.');
    table_ci_assert(count($details['orders'])===1,'Detalhe da comanda expôs pedido de outro tenant.');

    // Outro dado propositalmente inconsistente: tab do tenant A apontando para mesa do tenant B.
    $pdo->prepare('INSERT INTO restaurant_tables (tenant_id,name,seats,status,qr_token) VALUES (?,"Mesa B",4,"available",?)')->execute([$tenantB,bin2hex(random_bytes(20))]);
    $tableB=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO tabs (tenant_id,table_id,opened_by,label,status) VALUES (?,?,?,?,"open")')->execute([$tenantA,$tableB,$userA,'Comanda corrompida']);
    $corruptTab=(int)$pdo->lastInsertId();
    $blocked=false;try{$service->details($corruptTab);}catch(RuntimeException){$blocked=true;}
    table_ci_assert($blocked,'Comanda apontando para mesa de outro tenant foi exposta.');

    $closed=$service->close($tabId);
    table_ci_assert(($closed['status']??'')==='closed','Comanda legítima não foi fechada.');
    $secondBlocked=false;try{$service->close($tabId);}catch(RuntimeException){$secondBlocked=true;}
    table_ci_assert($secondBlocked,'Fechamento repetido da mesma comanda foi aceito.');
    $q=$pdo->prepare('SELECT status FROM restaurant_tables WHERE id=? AND tenant_id=?');$q->execute([$tableA,$tenantA]);
    table_ci_assert((string)$q->fetchColumn()==='available','Mesa não voltou a disponível após fechamento.');

    echo "CI table tenant smoke OK\n";
}catch(Throwable $e){
    table_ci_fail($e->getMessage()."\n".$e->getTraceAsString());
}
