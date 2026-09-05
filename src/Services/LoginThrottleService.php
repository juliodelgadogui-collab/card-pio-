<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use PDO;
use RuntimeException;

final class LoginThrottleService
{
    private const MAX_ATTEMPTS=5;
    private const WINDOW_SECONDS=900;
    private const BLOCK_SECONDS=900;

    public function key(string $identifier,string $deviceId=''):string
    {
        $identifier=mb_strtolower(trim($identifier));$deviceId=trim($deviceId);$ip=Security::clientIp();
        return hash('sha256',$identifier.'|'.$ip.'|'.($deviceId!==''?hash('sha256',$deviceId):'web'));
    }

    public function assertAllowed(string $key):void
    {
        $stmt=Database::connection()->prepare('SELECT blocked_until FROM login_throttles WHERE key_hash=? LIMIT 1');$stmt->execute([$key]);$blocked=$stmt->fetchColumn();
        if($blocked!==false&&$blocked!==null&&strtotime((string)$blocked)>time()) throw new RuntimeException('Muitas tentativas de login. Aguarde alguns minutos e tente novamente.');
    }

    public function failed(string $key):void
    {
        $now=new \DateTimeImmutable();
        Database::transaction(function(PDO $pdo)use($key,$now):void{
            $stmt=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM login_throttles WHERE key_hash=? FOR UPDATE'));$stmt->execute([$key]);$row=$stmt->fetch();
            if(!$row){$pdo->prepare('INSERT INTO login_throttles (key_hash,attempts,window_started_at,blocked_until,updated_at) VALUES (?,1,CURRENT_TIMESTAMP,NULL,CURRENT_TIMESTAMP)')->execute([$key]);return;}
            $windowStart=strtotime((string)$row['window_started_at']);$attempts=(int)$row['attempts'];
            if($windowStart===false||$windowStart<time()-self::WINDOW_SECONDS){$attempts=1;$blocked=null;$pdo->prepare('UPDATE login_throttles SET attempts=?,window_started_at=CURRENT_TIMESTAMP,blocked_until=?,updated_at=CURRENT_TIMESTAMP WHERE key_hash=?')->execute([$attempts,$blocked,$key]);return;}
            $attempts++;
            $blocked=$attempts>=self::MAX_ATTEMPTS?$now->modify('+'.self::BLOCK_SECONDS.' seconds')->format('Y-m-d H:i:s'):null;
            $pdo->prepare('UPDATE login_throttles SET attempts=?,blocked_until=?,updated_at=CURRENT_TIMESTAMP WHERE key_hash=?')->execute([$attempts,$blocked,$key]);
        });
    }

    public function succeeded(string $key):void
    {
        Database::connection()->prepare('DELETE FROM login_throttles WHERE key_hash=?')->execute([$key]);
    }

    public function cleanup():void
    {
        try{Database::connection()->exec("DELETE FROM login_throttles WHERE updated_at < datetime('now','-2 days')");}catch(\Throwable){try{Database::connection()->exec('DELETE FROM login_throttles WHERE updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 2 DAY)');}catch(\Throwable){}}
    }
}
