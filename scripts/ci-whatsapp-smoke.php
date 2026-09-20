<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\WhatsAppIntegrationService;

function wa_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

putenv('WHATSAPP_BRIDGE_ENABLED=false');
$_ENV['WHATSAPP_BRIDGE_ENABLED']='false';

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,name TEXT,phone TEXT,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,customer_id INTEGER,status TEXT NOT NULL,payment_status TEXT NOT NULL,total_cents INTEGER NOT NULL,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL)');
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,order_id INTEGER NOT NULL,status TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,verified_at TEXT NULL,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE)');
$pdo->exec(file_get_contents(__DIR__.'/../database/sqlite/migrations/065_whatsapp_integration.sql'));

$pdo->exec("INSERT INTO tenants (id,name) VALUES (1,'Loja Um'),(2,'Loja Dois')");
$pdo->exec("INSERT INTO customers (id,tenant_id,name,phone) VALUES (1,1,'Maria','22999998888'),(2,2,'João','22988887777')");
$pdo->exec("INSERT INTO orders (id,tenant_id,customer_id,status,payment_status,total_cents) VALUES (10,1,1,'pending','unpaid',2590),(20,2,2,'pending','unpaid',3000)");

$service=new WhatsAppIntegrationService();
$a=$service->ensureConnection($pdo,1);$b=$service->ensureConnection($pdo,2);
wa_assert(preg_match('/^[a-f0-9]{64}$/',(string)$a['session_key'])===1,'Sessão da empresa 1 inválida.');
wa_assert(preg_match('/^[a-f0-9]{64}$/',(string)$b['session_key'])===1,'Sessão da empresa 2 inválida.');
wa_assert($a['session_key']!==$b['session_key'],'Empresas não podem compartilhar a mesma sessão.');

$defaults=$service->defaultTemplates();$templates=[];
foreach($defaults as$event=>$message)$templates[$event]=['enabled'=>in_array($event,['order_received','payment_confirmed'],true),'message'=>$message];
$service->saveConfiguration($pdo,1,true,$templates);
$service->saveConfiguration($pdo,2,false,$templates);

$threw=false;try{$bad=$templates;$bad['order_received']['message']='Teste {segredo}';$service->saveConfiguration($pdo,1,true,$bad);}catch(RuntimeException){$threw=true;}
wa_assert($threw,'Variável de template não permitida deveria ser recusada.');
wa_assert($service->normalizePhone('(22) 99999-8888')==='5522999998888','Normalização de telefone BR falhou.');
wa_assert($service->normalizePhone('abc')==='','Telefone inválido deveria ser recusado.');

$id1=$service->queueOrderEvent($pdo,1,10,'order_received','history:1');$id2=$service->queueOrderEvent($pdo,1,10,'order_received','history:1');
wa_assert($id1!==null&&$id1===$id2,'Idempotência da outbox falhou.');
wa_assert((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=1 AND event_type="order_received"')->fetchColumn()===1,'Evento duplicado foi gravado.');
wa_assert($service->queueOrderEvent($pdo,2,20,'order_received','history:2')===null,'Automação desativada não pode enfileirar mensagem.');

$pdo->exec("UPDATE orders SET payment_status='paid',status='confirmed' WHERE id=10");
$pdo->exec("INSERT INTO payments (tenant_id,order_id,status,verified_at) VALUES (1,10,'paid',CURRENT_TIMESTAMP)");
wa_assert($service->syncPaymentEvents($pdo,20)>=1,'Pagamento confirmado não foi sincronizado para WhatsApp.');
wa_assert((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=1 AND event_type="payment_confirmed"')->fetchColumn()===1,'Pagamento confirmado deveria gerar apenas uma mensagem.');
$service->syncPaymentEvents($pdo,20);
wa_assert((int)$pdo->query('SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=1 AND event_type="payment_confirmed"')->fetchColumn()===1,'Sincronização repetida duplicou pagamento.');

$status=$service->status($pdo,1,false);
wa_assert(!array_key_exists('session_key',$status),'Snapshot público não pode expor session_key.');
wa_assert($status['bridge_enabled']===false,'Bridge deve ficar desligada no CI.');
$processed=$service->processOutbox($pdo,10);wa_assert(($processed['disabled']??false)===true,'Outbox não deve tentar rede com bridge desativada.');

echo "WhatsApp integration smoke OK\n";
