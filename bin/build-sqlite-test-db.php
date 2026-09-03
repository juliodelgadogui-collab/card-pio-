<?php

declare(strict_types=1);

$target=$argv[1]??(__DIR__.'/../storage/eventmenu-test.sqlite');
$target=str_starts_with($target,'/')?$target:dirname(__DIR__).'/'.ltrim($target,'/');
@mkdir(dirname($target),0775,true);
@unlink($target);@unlink($target.'-wal');@unlink($target.'-shm');

putenv('DB_DRIVER=sqlite');putenv('DB_DATABASE='.$target);putenv('APP_ENV=testing');putenv('SESSION_SECURE=false');
$_ENV['DB_DRIVER']='sqlite';$_ENV['DB_DATABASE']=$target;$_ENV['APP_ENV']='testing';$_ENV['SESSION_SECURE']='false';
if(getenv('APP_KEY')===false){$key=bin2hex(random_bytes(32));putenv('APP_KEY='.$key);$_ENV['APP_KEY']=$key;}

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\SqliteSchema;

Database::resetForTests();
$pdo=Database::connection();
$schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
if($schema===false)throw new RuntimeException('Schema principal não encontrado.');
SqliteSchema::executeBatch($pdo,$schema);Migrator::run($pdo);

$email=mb_strtolower(trim((string)(getenv('PRESET_EMAIL')?:'admin-test@example.test')));
$password=(string)(getenv('PRESET_PASSWORD')?:'eventmenu-test');
$name=(string)(getenv('PRESET_NAME')?:'Administrador Teste');
$tenantName=(string)(getenv('PRESET_TENANT')?:'EventMenu Teste');

$pdo->beginTransaction();
$stmt=$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,?,?)');
$stmt->execute([$tenantName,'eventmenu-teste','premium','active']);$tenantId=(int)$pdo->lastInsertId();
$stmt=$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,?,?)');
$stmt->execute([$tenantId,$name,$email,password_hash($password,PASSWORD_DEFAULT),'admin','active']);
$pdo->commit();

echo "SQLite preset OK: {$target}\n";
