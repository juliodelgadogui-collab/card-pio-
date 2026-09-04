<?php

declare(strict_types=1);

namespace EventMenu\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use Throwable;

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

        // MySQL: TIMESTAMPDIFF(DAY, inicio, fim). O primeiro argumento é uma
        // palavra-chave sem aspas no MySQL; no SQLite precisamos convertê-lo
        // para texto antes de chamar a função de compatibilidade.
        $sql=preg_replace_callback(
            '/TIMESTAMPDIFF\(\s*(SECOND|MINUTE|HOUR|DAY|WEEK|MONTH|YEAR)\s*,\s*([^,]+?)\s*,\s*([^\)]+?)\s*\)/i',
            static fn(array$m):string=>"EM_TIMESTAMPDIFF('".strtoupper($m[1])."', {$m[2]}, {$m[3]})",
            $sql
        )??$sql;

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
        $this->sqliteCreateFunction('CURDATE',static fn():string=>gmdate('Y-m-d'),0);
        $this->sqliteCreateFunction('CURTIME',static fn():string=>gmdate('H:i:s'),0);

        $this->sqliteCreateFunction('YEAR',static fn(mixed $v):?int=>self::datePart($v,'Y'),1);
        $this->sqliteCreateFunction('MONTH',static fn(mixed $v):?int=>self::datePart($v,'n'),1);
        $this->sqliteCreateFunction('DAY',static fn(mixed $v):?int=>self::datePart($v,'j'),1);
        $this->sqliteCreateFunction('DAYOFMONTH',static fn(mixed $v):?int=>self::datePart($v,'j'),1);
        $this->sqliteCreateFunction('HOUR',static fn(mixed $v):?int=>self::datePart($v,'G'),1);
        $this->sqliteCreateFunction('MINUTE',static fn(mixed $v):?int=>self::datePart($v,'i'),1);
        $this->sqliteCreateFunction('SECOND',static fn(mixed $v):?int=>self::datePart($v,'s'),1);
        $this->sqliteCreateFunction('WEEKDAY',static function(mixed $v):?int{$n=self::datePart($v,'N');return$n===null?null:$n-1;},1);
        $this->sqliteCreateFunction('DAYOFWEEK',static function(mixed $v):?int{$n=self::datePart($v,'w');return$n===null?null:$n+1;},1);

        $this->sqliteCreateFunction('DATE_FORMAT',static function(mixed $value,mixed $format):?string{
            $date=self::toDate($value);if($date===null)return null;
            $php=self::mysqlDateFormatToPhp((string)$format);
            return$date->format($php);
        },2);
        $this->sqliteCreateFunction('DATEDIFF',static function(mixed $end,mixed $start):?int{
            $a=self::toDate($end);$b=self::toDate($start);if($a===null||$b===null)return null;
            $a=$a->setTime(0,0);$b=$b->setTime(0,0);
            return(int)$b->diff($a)->format('%r%a');
        },2);
        $this->sqliteCreateFunction('EM_TIMESTAMPDIFF',static function(mixed $unit,mixed $start,mixed $end):?int{
            $a=self::toDate($start);$b=self::toDate($end);if($a===null||$b===null)return null;
            $seconds=$b->getTimestamp()-$a->getTimestamp();
            return match(strtoupper((string)$unit)){
                'SECOND'=>$seconds,
                'MINUTE'=>(int)($seconds/60),
                'HOUR'=>(int)($seconds/3600),
                'DAY'=>(int)($seconds/86400),
                'WEEK'=>(int)($seconds/604800),
                'MONTH'=>(int)(($b->format('Y')-$a->format('Y'))*12+($b->format('n')-$a->format('n'))),
                'YEAR'=>(int)($b->format('Y')-$a->format('Y')),
                default=>null,
            };
        },3);
        $this->sqliteCreateFunction('UNIX_TIMESTAMP',static function(mixed $value=null):?int{
            if($value===null)return time();$d=self::toDate($value);return$d?->getTimestamp();
        },-1);
        $this->sqliteCreateFunction('FROM_UNIXTIME',static function(mixed $value,mixed $format=null):?string{
            if(!is_numeric($value))return null;$d=(new DateTimeImmutable('@'.(int)$value))->setTimezone(new DateTimeZone('UTC'));
            return$format===null?$d->format('Y-m-d H:i:s'):$d->format(self::mysqlDateFormatToPhp((string)$format));
        },-1);

        $this->sqliteCreateFunction('CONCAT',static function(mixed ...$args):string{return implode('',array_map(static fn($v)=>(string)($v??''),$args));},-1);
        $this->sqliteCreateFunction('CONCAT_WS',static function(mixed $separator,mixed ...$args):string{
            $parts=array_map(static fn($v)=>(string)$v,array_values(array_filter($args,static fn($v)=>$v!==null)));
            return implode((string)$separator,$parts);
        },-1);
        $this->sqliteCreateFunction('FIELD',static function(mixed $needle,mixed ...$values):int{foreach($values as$i=>$value)if((string)$needle===(string)$value)return$i+1;return 0;},-1);
        $this->sqliteCreateFunction('FIND_IN_SET',static function(mixed $needle,mixed $set):int{
            if($set===null)return 0;foreach(explode(',',(string)$set)as$i=>$value)if((string)$needle===$value)return$i+1;return 0;
        },2);
        $this->sqliteCreateFunction('GREATEST',static function(mixed ...$values):mixed{$values=array_values(array_filter($values,static fn($v)=>$v!==null));return$values?max($values):null;},-1);
        $this->sqliteCreateFunction('LEAST',static function(mixed ...$values):mixed{$values=array_values(array_filter($values,static fn($v)=>$v!==null));return$values?min($values):null;},-1);
        $this->sqliteCreateFunction('IF',static fn(mixed $condition,mixed $yes,mixed $no):mixed=>$condition?$yes:$no,3);
    }

    private static function toDate(mixed $value):?DateTimeImmutable
    {
        if($value===null||$value==='')return null;
        try{
            if(is_numeric($value))return(new DateTimeImmutable('@'.(int)$value))->setTimezone(new DateTimeZone('UTC'));
            return new DateTimeImmutable((string)$value,new DateTimeZone('UTC'));
        }catch(Throwable){return null;}
    }

    private static function datePart(mixed $value,string $format):?int
    {
        $date=self::toDate($value);return$date===null?null:(int)$date->format($format);
    }

    private static function mysqlDateFormatToPhp(string $format):string
    {
        return strtr($format,[
            '%Y'=>'Y','%y'=>'y','%m'=>'m','%c'=>'n','%d'=>'d','%e'=>'j',
            '%H'=>'H','%h'=>'h','%I'=>'h','%i'=>'i','%s'=>'s','%S'=>'s','%p'=>'A',
            '%M'=>'F','%b'=>'M','%W'=>'l','%a'=>'D','%%'=>'%',
        ]);
    }
}
