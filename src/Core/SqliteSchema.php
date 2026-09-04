<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;

final class SqliteSchema
{
    public static function executeBatch(PDO $pdo,string $sql):void
    {
        $sql=preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m','',$sql)??$sql;
        $sql=preg_replace('~/\*.*?\*/~s','',$sql)??$sql;
        foreach(self::splitStatements($sql) as$statement){
            foreach(self::translate($statement) as$translated){
                $translated=trim($translated);
                if($translated==='')continue;
                $pdo->exec($translated);
            }
        }
    }

    /** @return list<string> */
    private static function translate(string $statement):array
    {
        $sql=trim($statement);
        if($sql==='')return[];
        if(preg_match('/^SET\s+/i',$sql))return[];

        if(preg_match('/^CREATE\s+TABLE\b/i',$sql))return[self::translateCreateTable($sql)];
        if(preg_match('/^ALTER\s+TABLE\b/i',$sql))return self::translateAlterTable($sql);

        if(preg_match('/^DROP\s+INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+/i',$sql,$m))return['DROP INDEX IF EXISTS '.$m[1]];

        return[$sql];
    }

    private static function translateCreateTable(string $sql):string
    {
        $sql=preg_replace('/\)\s*ENGINE\s*=\s*[^\s]+.*$/is',')',$sql)??$sql;
        $sql=preg_replace('/\)\s*DEFAULT\s+CHARSET\s*=.*$/is',')',$sql)??$sql;
        $sql=preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i','',$sql)??$sql;

        if(!preg_match('/^(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?[A-Za-z0-9_]+`?)\s*\((.*)\)$/is',$sql,$m)){
            return self::normalizeTypes($sql);
        }

        $parts=self::splitTopLevel($m[2]);$out=[];
        foreach($parts as$part){
            $part=trim($part);if($part==='')continue;
            if(preg_match('/^(?:INDEX|KEY)\s+`?[A-Za-z0-9_]+`?\s*\(/i',$part))continue;
            if(preg_match('/^UNIQUE\s+KEY\s+`?[A-Za-z0-9_]+`?\s*(\(.+\))$/is',$part,$u)){$out[]='UNIQUE '.$u[1];continue;}
            $out[]=self::normalizeTypes($part);
        }
        return$m[1]." (\n  ".implode(",\n  ",$out)."\n)";
    }

    /** @return list<string> */
    private static function translateAlterTable(string $sql):array
    {
        if(!preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/is',$sql,$m))return[];
        $table=$m[1];$actions=self::splitTopLevel($m[2]);$out=[];
        foreach($actions as$action){
            $action=trim($action);if($action==='')continue;

            if(preg_match('/^ADD\s+(?:COLUMN\s+)?(.+)$/is',$action,$a)){
                $body=trim($a[1]);
                if(preg_match('/^CONSTRAINT\b/i',$body))continue;
                if(preg_match('/^UNIQUE\s+(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/is',$body,$u)){$out[]='CREATE UNIQUE INDEX IF NOT EXISTS '.$u[1].' ON '.$table.' ('.$u[2].')';continue;}
                if(preg_match('/^(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?\s*\((.+)\)$/is',$body,$i)){$out[]='CREATE INDEX IF NOT EXISTS '.$i[1].' ON '.$table.' ('.$i[2].')';continue;}
                $body=preg_replace('/\s+AFTER\s+`?[A-Za-z0-9_]+`?\s*$/i','',$body)??$body;
                $body=preg_replace('/\s+FIRST\s*$/i','',$body)??$body;
                $out[]='ALTER TABLE '.$table.' ADD COLUMN '.self::normalizeTypes($body);
                continue;
            }

            if(preg_match('/^DROP\s+(?:INDEX|KEY)\s+`?([A-Za-z0-9_]+)`?/i',$action,$d)){$out[]='DROP INDEX IF EXISTS '.$d[1];continue;}
            if(preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?/i',$action))continue;
            if(preg_match('/^ADD\s+CONSTRAINT\b/i',$action))continue;
            if(preg_match('/^DROP\s+FOREIGN\s+KEY\b/i',$action))continue;
            if(preg_match('/^RENAME\s+COLUMN\b/i',$action)){$out[]='ALTER TABLE '.$table.' '.$action;continue;}
        }
        return$out;
    }

    private static function normalizeTypes(string $sql):string
    {
        $sql=preg_replace('/\b(?:BIGINT|INT)\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','INTEGER PRIMARY KEY AUTOINCREMENT',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','INTEGER PRIMARY KEY AUTOINCREMENT',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY\b/i','INTEGER PRIMARY KEY AUTOINCREMENT',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\s+UNSIGNED\b/i','INTEGER',$sql)??$sql;
        $sql=preg_replace('/\bINT\s+UNSIGNED\b/i','INTEGER',$sql)??$sql;
        $sql=preg_replace('/\bBIGINT\b/i','INTEGER',$sql)??$sql;
        $sql=preg_replace('/\bTINYINT\s*\(\s*1\s*\)/i','INTEGER',$sql)??$sql;
        $sql=preg_replace('/\bDECIMAL\s*\([^\)]*\)/i','REAL',$sql)??$sql;
        $sql=preg_replace('/\b(?:DOUBLE|FLOAT)\b/i','REAL',$sql)??$sql;
        $sql=preg_replace('/\bENUM\s*\([^\)]*\)/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bJSON\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bLONGTEXT\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bDATETIME\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\bTIMESTAMP\b/i','TEXT',$sql)??$sql;
        $sql=preg_replace('/\s+UNSIGNED\b/i','',$sql)??$sql;
        $sql=preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i','',$sql)??$sql;
        return$sql;
    }

    /** @return list<string> */
    private static function splitStatements(string $sql):array
    {
        $out=[];$buf='';$quote=null;$escape=false;$len=strlen($sql);
        for($i=0;$i<$len;$i++){
            $ch=$sql[$i];
            if($escape){$buf.=$ch;$escape=false;continue;}
            if($quote!==null){
                $buf.=$ch;
                if($ch==='\\'){$escape=true;continue;}
                if($ch===$quote)$quote=null;
                continue;
            }
            if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}
            if($ch===';'){$trim=trim($buf);if($trim!=='')$out[]=$trim;$buf='';continue;}
            $buf.=$ch;
        }
        if(trim($buf)!=='')$out[]=trim($buf);
        return$out;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $value):array
    {
        $out=[];$buf='';$depth=0;$quote=null;$escape=false;$len=strlen($value);
        for($i=0;$i<$len;$i++){
            $ch=$value[$i];
            if($escape){$buf.=$ch;$escape=false;continue;}
            if($quote!==null){$buf.=$ch;if($ch==='\\'){$escape=true;continue;}if($ch===$quote)$quote=null;continue;}
            if($ch==="'"||$ch==='"'||$ch==='`'){$quote=$ch;$buf.=$ch;continue;}
            if($ch==='('){$depth++;$buf.=$ch;continue;}
            if($ch===')'){$depth=max(0,$depth-1);$buf.=$ch;continue;}
            if($ch===','&&$depth===0){$out[]=trim($buf);$buf='';continue;}
            $buf.=$ch;
        }
        if(trim($buf)!=='')$out[]=trim($buf);
        return$out;
    }
}
