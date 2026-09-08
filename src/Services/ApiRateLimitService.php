<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class ApiRateLimitService
{
    public function assertAllowed(string $bucket,string $subject,int $limit,int $windowSeconds,string $message='Muitas tentativas. Aguarde um pouco e tente novamente.'):void
    {
        $bucket=mb_substr(preg_replace('/[^a-z0-9._-]/i','',trim($bucket))?:'generic',0,60);$subject=trim($subject);if($subject==='')$subject='anonymous';$limit=max(1,min(10000,$limit));$windowSeconds=max(10,min(86400,$windowSeconds));$secret=(string)env('APP_KEY','eventmenu-rate-limit');$key=hash_hmac('sha256',$bucket.'|'.$subject,$secret);$now=time();$start=gmdate('Y-m-d H:i:s',$now);$expires=gmdate('Y-m-d H:i:s',$now+$windowSeconds);
        Database::transaction(function(PDO$pdo)use($bucket,$key,$limit,$message,$now,$start,$expires):void{
            $s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM api_rate_limits WHERE key_hash=? LIMIT 1 FOR UPDATE'));$s->execute([$key]);$row=$s->fetch();
            if(!$row){$i=$pdo->prepare('INSERT INTO api_rate_limits (key_hash,bucket,hits,window_started_at,expires_at) VALUES (?,?,1,?,?)');$i->execute([$key,$bucket,$start,$expires]);return;}
            $expiry=strtotime((string)$row['expires_at']);if($expiry===false||$expiry<=$now){$u=$pdo->prepare('UPDATE api_rate_limits SET bucket=?,hits=1,window_started_at=?,expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE key_hash=?');$u->execute([$bucket,$start,$expires,$key]);return;}
            if((int)$row['hits']>=$limit)throw new ApiRateLimitExceededException($message);$u=$pdo->prepare('UPDATE api_rate_limits SET hits=hits+1,updated_at=CURRENT_TIMESTAMP WHERE key_hash=?');$u->execute([$key]);
        });
    }

    public function requestSubject(string $extra=''):string
    {
        $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));$ua=mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT']??'')),0,180);return$ip.'|'.$ua.'|'.trim($extra);
    }

    public function cleanup():int
    {
        $s=Database::connection()->prepare('DELETE FROM api_rate_limits WHERE expires_at<CURRENT_TIMESTAMP');$s->execute();return$s->rowCount();
    }
}
