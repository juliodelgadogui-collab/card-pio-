<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Migrator
{
    public static function run(?PDO $pdo=null):array
    {
        $pdo??=Database::connection();
        if(Database::isSqlite()){
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
            return self::runSqlite($pdo);
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $dir=dirname(__DIR__,2).'/database/migrations';
        if(!is_dir($dir))return[];
        $files=glob($dir.'/*.sql')?:[];sort($files,SORT_NATURAL);
        $applied=[];

        $lockName='eventmenu:migrations:'.substr(hash('sha256',(string)env('DB_DATABASE','eventmenu')),0,24);
        $lock=$pdo->prepare('SELECT GET_LOCK(?,15)');$lock->execute([$lockName]);
        if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Não foi possível obter o lock de atualização do banco. Tente novamente.');

        try{
            foreach($files as$file){
                $name=basename($file);
                $stmt=$pdo->prepare('SELECT 1 FROM migrations WHERE migration=?');$stmt->execute([$name]);
                if($stmt->fetchColumn())continue;

                $sql=file_get_contents($file);
                if($sql===false)throw new RuntimeException('Não foi possível ler a migração '.$name);

                // MySQL executa COMMIT implícito em DDL. Não envolvemos ALTER/CREATE em
                // beginTransaction(), pois isso faria o instalador tentar commit de uma
                // transação já encerrada pelo servidor.
                try{
                    $pdo->exec($sql);
                    $stmt=$pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
                    $stmt->execute([$name]);
                    $applied[]=$name;
                }catch(Throwable $e){
                    throw new RuntimeException('Falha ao aplicar a migração '.$name.'. Como MySQL DDL não é transacional, verifique o schema antes de tentar novamente. Detalhe: '.$e->getMessage(),0,$e);
                }
            }
        }finally{
            try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable){}
        }
        return$applied;
    }

    private static function runSqlite(PDO $pdo):array
    {
        $dir=dirname(__DIR__,2).'/database/migrations';
        if(!is_dir($dir))return[];
        $files=glob($dir.'/*.sql')?:[];sort($files,SORT_NATURAL);$applied=[];
        $lockPath=dirname(__DIR__,2).'/storage/sqlite-migrations.lock';
        $handle=@fopen($lockPath,'c+');
        if($handle===false||!flock($handle,LOCK_EX))throw new RuntimeException('Não foi possível bloquear as migrations do SQLite.');
        try{
            foreach($files as$file){
                $name=basename($file);$check=$pdo->prepare('SELECT 1 FROM migrations WHERE migration=?');$check->execute([$name]);if($check->fetchColumn())continue;
                $sql=file_get_contents($file);if($sql===false)throw new RuntimeException('Não foi possível ler a migração '.$name);
                try{
                    $pdo->beginTransaction();
                    SqliteSchema::executeBatch($pdo,$sql);
                    $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
                    $pdo->commit();$applied[]=$name;
                }catch(Throwable $e){
                    if($pdo->inTransaction())$pdo->rollBack();
                    throw new RuntimeException('Falha ao aplicar a migração SQLite '.$name.': '.$e->getMessage(),0,$e);
                }
            }
        }finally{flock($handle,LOCK_UN);fclose($handle);}
        return$applied;
    }
}
