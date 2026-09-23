<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use EventMenu\Services\OrderPaymentPreferenceService;

function pref_fail(string $message):never{fwrite(STDERR,"PAYMENT PREF CI FAIL: {$message}\n");exit(1);}
function pref_assert(bool $ok,string $message):void{if(!$ok)pref_fail($message);}

$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,FOREIGN KEY(tenant_id) REFERENCES tenants(id))');
$pdo->exec('CREATE TABLE order_payment_preferences (order_id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,method TEXT NOT NULL,provider TEXT NULL,change_for_cents INTEGER NULL,source TEXT NOT NULL DEFAULT "unknown",created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,FOREIGN KEY(tenant_id) REFERENCES tenants(id) ON DELETE CASCADE)');
$pdo->exec('INSERT INTO tenants(id) VALUES (1),(2)');$pdo->exec('INSERT INTO orders(id,tenant_id) VALUES (10,1),(20,2)');

$service=new OrderPaymentPreferenceService();
$cash=$service->set($pdo,1,10,'cash',null,5000,'web');pref_assert($cash['method']==='cash','Dinheiro não foi persistido.');pref_assert($cash['provider']===null,'Dinheiro não pode possuir provedor.');pref_assert($cash['change_for_cents']===5000,'Troco não foi persistido.');
$pix=$service->set($pdo,1,10,'pix','efi',null,'delivery_app');pref_assert($pix['method']==='pix'&&$pix['provider']==='efi','PIX Efí não substituiu a preferência anterior.');pref_assert($pix['change_for_cents']===null,'Troco antigo vazou para PIX.');
$card=$service->set($pdo,1,10,'card_debit','mercadopago',null,'web');pref_assert($card['method']==='card_debit'&&$card['provider']==='mercadopago','Débito não foi persistido.');
pref_assert($service->get($pdo,2,10)===null,'Preferência vazou entre tenants.');
$invalid=false;try{$service->set($pdo,1,10,'boleto',null,null,'web');}catch(RuntimeException){$invalid=true;}pref_assert($invalid,'Método inválido foi aceito.');
$providerInvalid=false;try{$service->set($pdo,1,10,'pix','unknown-bank',null,'web');}catch(RuntimeException){$providerInvalid=true;}pref_assert($providerInvalid,'Provedor inválido foi aceito.');
$foreignOrder=false;try{$service->set($pdo,1,20,'cash',null,null,'web');}catch(RuntimeException){$foreignOrder=true;}pref_assert($foreignOrder,'Pedido de outro tenant foi aceito.');

echo "CI order payment preference smoke OK\n";
