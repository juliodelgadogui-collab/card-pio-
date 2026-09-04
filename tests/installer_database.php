<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;

function installer_db_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"Installer database test failed: {$message}\n");exit(1);}}

$pdo=Database::connection();
$schema=file_get_contents(__DIR__.'/../database/schema.sql');
installer_db_assert($schema!==false,'schema.sql não encontrado');
$pdo->exec((string)$schema);
$applied=Migrator::run($pdo);
$files=glob(__DIR__.'/../database/migrations/*.sql')?:[];
$count=(int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
installer_db_assert($count===count($files),'nem todas as migrations foram registradas');
installer_db_assert(count($applied)===count($files),'primeira execução não aplicou todas as migrations');
$again=Migrator::run($pdo);
installer_db_assert($again===[],'segunda execução não foi idempotente');
installer_db_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='order_fulfillments'")->fetchColumn()===1,'tabela final de retirada não existe');
installer_db_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='nfc_payment_attempts'")->fetchColumn()===1,'tabela final NFC não existe');
echo "Installer database test OK\n";
