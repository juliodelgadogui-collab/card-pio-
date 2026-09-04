<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\SqliteSchema;

function sqlite_assert(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"SQLite installation failed: {$message}\n");exit(1);}
}

sqlite_assert(Database::isSqlite(),'DB_DRIVER não selecionou sqlite');
$pdo=Database::connection();
$schema=file_get_contents(__DIR__.'/../database/schema.sql');
sqlite_assert($schema!==false,'schema.sql não encontrado');
SqliteSchema::executeBatch($pdo,(string)$schema);
$applied=Migrator::run($pdo);

$required=['tenants','users','products','orders','order_items','payments','restaurant_tables','tabs','tickets','refunds','delivery_zones','cash_sessions','nfc_devices','tenant_modules','business_units','notifications','waiter_calls','product_option_groups','order_item_options','stock_reservations'];
$tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
foreach($required as$table)sqlite_assert(in_array($table,$tables,true),'tabela ausente: '.$table);

$columns=[];foreach($pdo->query('PRAGMA table_info(orders)')->fetchAll()as$row)$columns[]=$row['name'];
foreach(['public_token','unit_id','scheduled_for','fulfillment_token','fulfillment_status','cancel_reason']as$column)sqlite_assert(in_array($column,$columns,true),'orders sem coluna '.$column);

$columns=[];foreach($pdo->query('PRAGMA table_info(products)')->fetchAll()as$row)$columns[]=$row['name'];
foreach(['unit_id','product_type','promo_price_cents','min_stock_qty','preparation_minutes','badge','featured']as$column)sqlite_assert(in_array($column,$columns,true),'products sem coluna '.$column);

sqlite_assert((int)$pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn()>=10,'migrations não foram registradas');
echo 'SQLite installation OK · migrations '.count($applied)."\n";
