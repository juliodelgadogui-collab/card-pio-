<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;

final class RuntimeStatusService
{
    public function set(string $key,string $state='ok',?string $message=null,array $metadata=[]):void
    {
        $key=mb_substr(preg_replace('/[^a-z0-9._-]/i','',trim($key))?:'runtime',0,100);$state=in_array($state,['ok','warning','error','disabled'],true)?$state:'ok';$message=$message!==null?mb_substr(trim($message),0,500):null;$json=$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null;
        Database::transaction(function(PDO$pdo)use($key,$state,$message,$json):void{$s=$pdo->prepare(Database::portableSql($pdo,'SELECT status_key FROM system_runtime_status WHERE status_key=? LIMIT 1 FOR UPDATE'));$s->execute([$key]);if($s->fetchColumn()){$u=$pdo->prepare('UPDATE system_runtime_status SET state=?,message=?,metadata=?,checked_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE status_key=?');$u->execute([$state,$message,$json,$key]);}else{$i=$pdo->prepare('INSERT INTO system_runtime_status (status_key,state,message,metadata,checked_at) VALUES (?,?,?,?,CURRENT_TIMESTAMP)');$i->execute([$key,$state,$message,$json]);}});
    }

    public function get(string $key):?array
    {
        $s=Database::connection()->prepare('SELECT * FROM system_runtime_status WHERE status_key=? LIMIT 1');$s->execute([$key]);$row=$s->fetch();if(!$row)return null;$row['metadata']=$this->decode($row['metadata']??null);return$row;
    }

    public function all():array
    {
        $s=Database::connection()->query('SELECT * FROM system_runtime_status ORDER BY status_key');$rows=$s->fetchAll();foreach($rows as&$row)$row['metadata']=$this->decode($row['metadata']??null);unset($row);return$rows;
    }

    private function decode(mixed $value):array{$data=json_decode((string)$value,true);return is_array($data)?$data:[];}
}
