<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\WhatsAppIntegrationService;
use EventMenu\Services\WhatsAppOrderReceivedSyncService;

function wac_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$env=(string)file_get_contents($root.'/.env.example');
$route=(string)file_get_contents($root.'/app/routes/whatsapp.php');
$serviceSource=(string)file_get_contents($root.'/src/Services/WhatsAppIntegrationService.php');
wac_assert(!str_contains($env,'WHATSAPP_BRIDGE_'),'O .env.example ainda expõe configuração de bridge do WhatsApp.');
wac_assert(!is_file($root.'/integrations/whatsapp-worker/server.js'),'O servidor Baileys legado ainda existe no pacote.');
wac_assert(!is_file($root.'/integrations/whatsapp-worker/package.json'),'O pacote Node do WhatsApp legado ainda existe.');
wac_assert(!str_contains($route,'value="connect"')&&!str_contains($route,'value="reconnect"')&&!str_contains($route,'value="replace"')&&!str_contains($route,'value="disconnect"'),'O painel ainda expõe ações de sessão do WhatsApp no servidor.');
wac_assert(!str_contains($serviceSource,'curl_init(')&&!str_contains($serviceSource,'WHATSAPP_BRIDGE_URL'),'WhatsAppIntegrationService ainda contém transporte de bridge no servidor.');

$pdo=new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,name TEXT,phone TEXT,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)');
$pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,customer_id INTEGER,channel TEXT NOT NULL DEFAULT 'counter',order_source TEXT NULL,status TEXT NOT NULL,payment_status TEXT NOT NULL DEFAULT 'unpaid',total_cents INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL)");
$pdo->exec((string)file_get_contents($root.'/database/sqlite/migrations/065_whatsapp_integration.sql'));
$pdo->exec("INSERT INTO tenants (id,name) VALUES (1,'Restaurante Teste')");
$pdo->exec("INSERT INTO customers (id,tenant_id,name,phone) VALUES (1,1,'Cliente Teste','22999998888')");

$integration=new WhatsAppIntegrationService();
$connection=$integration->ensureConnection($pdo,1);
wac_assert((string)$connection['provider']==='eventmenu_connect','Nova conexão não usa eventmenu_connect.');
$templates=[];foreach($integration->defaultTemplates() as$event=>$message)$templates[$event]=['enabled'=>$event==='order_received','message'=>$message];
$integration->saveConfiguration($pdo,1,true,$templates);
$pdo->exec("UPDATE whatsapp_connections SET automation_enabled_at=datetime('now','-1 minute') WHERE tenant_id=1");

$pdo->exec("INSERT INTO orders (id,tenant_id,customer_id,channel,order_source,status,total_cents) VALUES (10,1,1,'delivery','DELIVERY','pending',2590)");
$pdo->exec("INSERT INTO orders (id,tenant_id,customer_id,channel,order_source,status,total_cents) VALUES (11,1,1,'event','EVENT','pending',3000)");
$pdo->exec("INSERT INTO orders (id,tenant_id,customer_id,channel,order_source,status,total_cents) VALUES (12,1,1,'delivery','WHATSAPP','pending',3100)");
$sync=new WhatsAppOrderReceivedSyncService();
wac_assert($sync->syncForTenant($pdo,1,20)===1,'Sincronização deveria enfileirar apenas o pedido operacional elegível.');
wac_assert((int)$pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE tenant_id=1 AND order_id=10 AND event_type='order_received' AND status='desktop_queued'")->fetchColumn()===1,'Pedido recebido não entrou na fila do EventMenu Connect.');
wac_assert($sync->syncForTenant($pdo,1,20)===0,'Sincronização repetida duplicou order_received.');
wac_assert((int)$pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE order_id IN (11,12)")->fetchColumn()===0,'Evento ou pedido do próprio WhatsApp não deveria gerar order_received por este sincronizador.');

$pdo->exec("INSERT INTO orders (id,tenant_id,customer_id,channel,order_source,status,total_cents) VALUES (13,1,1,'counter','POS','confirmed',1990)");
$pdo->exec("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,idempotency_key) VALUES (1,13,'order_confirmed','5522999998888','confirmado','desktop_queued','later-event-13')");
wac_assert($sync->syncForTenant($pdo,1,20)===0,'order_received atrasado não pode entrar depois de order_confirmed.');

$processed=$integration->processOutbox($pdo,20);
wac_assert(($processed['disabled']??false)===true&&($processed['reason']??'')==='server_sending_disabled_use_eventmenu_connect','Servidor ainda tenta processar envios do WhatsApp.');
$threw=false;try{$integration->connect($pdo,1,false);}catch(RuntimeException $e){$threw=str_contains($e->getMessage(),'EventMenu Connect');}
wac_assert($threw,'Conexão pelo servidor deveria ser bloqueada.');
$threw=false;try{$integration->disconnect($pdo,1);}catch(RuntimeException $e){$threw=str_contains($e->getMessage(),'EventMenu Connect');}
wac_assert($threw,'Desconexão pelo servidor deveria ser bloqueada.');

echo "WhatsApp EventMenu Connect hardening smoke OK\n";