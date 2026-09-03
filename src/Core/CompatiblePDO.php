<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use PDOStatement;

/**
 * Mantém o código operacional compartilhado entre MySQL (produção) e
 * SQLite (modo de teste). A camada só traduz construções SQL usadas pelo
 * EventMenu que possuem equivalentes seguros no SQLite.
 */
final class CompatiblePDO extends PDO
{
    private bool $sqliteMode=false;

    public function __construct(string $dsn,?string $username=null,?string $password=null,?array $options=null)
    {
        parent::__construct($dsn,$username,$password,$options??[]);
        $this->sqliteMode=str_starts_with(strtolower($dsn),'sqlite:');
        if($this->sqliteMode)$this->registerSqliteFunctions();
    }

    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return parent::prepare($this->normalize($query),$options);
    }

    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {
        $query=$this->normalize($query);
        if($fetchMode===null)return parent::query($query);
        return parent::query($query,$fetchMode,...$fetchModeArgs);
    }

    public function exec(string $statement):int|false
    {
        return parent::exec($this->normalize($statement));
    }

    public function isSqliteMode():bool{return$this->sqliteMode;}

    private function normalize(string $sql):string
    {
        if(!$this->sqliteMode)return$sql;

        if(preg_match('/^\s*SELECT\s+@@(?:session\.)?time_zone\s*;?\s*$/i',$sql))return "SELECT '+00:00'";

        $sql=preg_replace('/\s+FOR\s+UPDATE\b/i','',$sql)??$sql;
        $sql=preg_replace('/\s+LOCK\s+IN\s+SHARE\s+MODE\b/i','',$sql)??$sql;
        $sql=preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i','INSERT OR IGNORE INTO',$sql)??$sql;
        // MySQL <=> é igualdade null-safe. SQLite usa IS com a mesma finalidade.
        $sql=str_replace('<=>',' IS ',$sql);

        // MySQL: DATE_ADD(x, INTERVAL 15 MINUTE) / DATE_SUB(...)
        $sql=preg_replace_callback(
            '/DATE_(ADD|SUB)\(\s*([^,]+?)\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY|WEEK)\s*\)/i',
            static function(array$m):string{
                $sign=strtoupper($m[1])==='ADD'?'+':'-';
                $unit=strtolower($m[4]);if($unit==='week'){$amount=(int)$m[3]*7;$unit='day';}else$amount=(int)$m[3];
                return"DATETIME({$m[2]}, '{$sign}{$amount} {$unit}s')";
            },$sql
        )??$sql;

        // GROUP_CONCAT do MySQL aceita ORDER BY ... SEPARATOR dentro da função.
        $sql=preg_replace_callback(
            '/GROUP_CONCAT\((.*?)\s+ORDER\s+BY\s+.*?\s+SEPARATOR\s+((?:"[^"]*")|(?:\'[^\']*\'))\)/is',
            static fn(array$m):string=>'GROUP_CONCAT('.trim($m[1]).', '.$m[2].')',
            $sql
        )??$sql;
        $sql=preg_replace_callback(
            '/GROUP_CONCAT\((.*?)\s+SEPARATOR\s+((?:"[^"]*")|(?:\'[^\']*\'))\)/is',
            static fn(array$m):string=>'GROUP_CONCAT('.trim($m[1]).', '.$m[2].')',
            $sql
        )??$sql;

        // UPSERT do MySQL -> UPSERT do SQLite. O alvo pode ser inferido pelo índice UNIQUE.
        if(preg_match('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)$/is',$sql,$match,PREG_OFFSET_CAPTURE)){
            $offset=$match[0][1];$updates=$match[1][0];
            $updates=preg_replace('/\bVALUES\s*\(\s*([A-Za-z0-9_]+)\s*\)/i','excluded.$1',$updates)??$updates;
            $sql=substr($sql,0,$offset).' ON CONFLICT DO UPDATE SET '.$updates;
        }

        return$sql;
    }

    private function registerSqliteFunctions():void
    {
        if(!method_exists($this,'sqliteCreateFunction'))return;
        $this->sqliteCreateFunction('NOW',static fn():string=>gmdate('Y-m-d H:i:s'),0);
        $this->sqliteCreateFunction('UTC_TIMESTAMP',static fn():string=>gmdate('Y-m-d H:i:s'),0);
        $this->sqliteCreateFunction('CONCAT',static function(mixed ...$args):string{return implode('',array_map(static fn($v)=>(string)($v??''),$args));},-1);
        $this->sqliteCreateFunction('FIELD',static function(mixed $needle,mixed ...$values):int{foreach($values as$i=>$value)if((string)$needle===(string)$value)return$i+1;return 0;},-1);
        $this->sqliteCreateFunction('GREATEST',static function(mixed ...$values):mixed{$values=array_values(array_filter($values,static fn($v)=>$v!==null));return$values?max($values):null;},-1);
        $this->sqliteCreateFunction('LEAST',static function(mixed ...$values):mixed{$values=array_values(array_filter($values,static fn($v)=>$v!==null));return$values?min($values):null;},-1);
        $this->sqliteCreateFunction('IF',static fn(mixed $condition,mixed $yes,mixed $no):mixed=>$condition?$yes:$no,3);
    }
}
