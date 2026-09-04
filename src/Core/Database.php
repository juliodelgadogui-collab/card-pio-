<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function driver():string
    {
        $driver=strtolower(trim((string)env('DB_DRIVER','mysql')));
        return in_array($driver,['mysql','sqlite'],true)?$driver:'mysql';
    }

    public static function isSqlite():bool{return self::driver()==='sqlite';}

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $options=[
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if(self::isSqlite()){
            if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('A extensão pdo_sqlite do PHP é necessária para usar SQLite.');
            $db=(string)env('DB_DATABASE','storage/eventmenu-test.sqlite');
            $root=dirname(__DIR__,2);
            $path=self::sqlitePath($db,$root);
            $dir=dirname($path);
            if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar a pasta do banco SQLite.');
            self::$pdo=new CompatiblePDO('sqlite:'.$path,null,null,$options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
            return self::$pdo;
        }

        if(!extension_loaded('pdo_mysql'))throw new RuntimeException('A extensão pdo_mysql do PHP é obrigatória para usar MySQL.');
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $db = env('DB_DATABASE', 'eventmenu');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        self::$pdo = new CompatiblePDO($dsn, (string)$user, (string)$pass, $options);
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }

    public static function sqlitePath(string $database,?string $root=null):string
    {
        $database=trim($database);
        if($database==='')$database='storage/eventmenu-test.sqlite';
        if(str_contains($database,"\0"))throw new RuntimeException('Caminho SQLite inválido.');
        $root??=dirname(__DIR__,2);
        if(str_starts_with($database,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$database))return$database;
        $database=ltrim(str_replace('\\','/',$database),'/');
        if(str_contains('/'.$database.'/','/../'))throw new RuntimeException('Caminho SQLite não pode sair da pasta do EventMenu.');
        return rtrim($root,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$database);
    }

    public static function resetForTests():void{self::$pdo=null;}

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $nested = $pdo->inTransaction();
        $savepoint = $nested ? 'em_sp_'.bin2hex(random_bytes(8)) : null;

        if ($nested) {
            $pdo->exec('SAVEPOINT '.$savepoint);
        } else {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($nested && $savepoint !== null) {
                    try {
                        $pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                        $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                    } catch (Throwable) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                    }
                } else {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
    }
}
