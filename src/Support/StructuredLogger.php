<?php

declare(strict_types=1);

namespace EventMenu\Support;

final class StructuredLogger
{
    private const REDACT = ['access_token','token','secret','password','authorization','cookie','card_token','private_key','qr_code','qr_code_base64'];

    public static function write(string $level,string $module,string $code,string $message,array $context=[]):void
    {
        $level=strtoupper(trim($level));
        if(!in_array($level,['ERROR','WARNING','INFO'],true))$level='INFO';
        $entry=[
            'timestamp'=>gmdate('c'),
            'level'=>$level,
            'module'=>mb_substr(preg_replace('/[^a-z0-9._-]/i','',trim($module))?:'app',0,80),
            'code'=>mb_substr(preg_replace('/[^a-z0-9._-]/i','',trim($code))?:'event',0,100),
            'message'=>mb_substr(trim($message),0,500),
            'request_id'=>self::requestId(),
            'context'=>self::sanitize($context),
        ];
        $root=dirname(__DIR__,2);$dir=$root.'/storage/logs';
        if((is_dir($dir)||@mkdir($dir,0775,true))&&is_writable($dir)){
            @file_put_contents($dir.'/eventmenu.log',json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
        }elseif($level==='ERROR'){
            error_log('[EventMenu] '.json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }

    private static function requestId():string
    {
        $incoming=trim((string)($_SERVER['HTTP_X_REQUEST_ID']??''));
        if($incoming!==''&&preg_match('/^[a-zA-Z0-9._:-]{8,100}$/',$incoming))return$incoming;
        try{return bin2hex(random_bytes(8));}catch(\Throwable){return substr(hash('sha256',(string)microtime(true)),0,16);}
    }

    private static function sanitize(array $context):array
    {
        $out=[];
        foreach($context as$key=>$value){
            $name=strtolower((string)$key);
            if(in_array($name,self::REDACT,true)||preg_match('/(?:token|secret|password|authorization|cookie|private.?key|card.?token|qr.?code)/i',$name)){$out[$key]='[REDACTED]';continue;}
            if(is_array($value)){$out[$key]=self::sanitize($value);continue;}
            if(is_scalar($value)||$value===null)$out[$key]=is_string($value)?mb_substr($value,0,300):$value;
        }
        return$out;
    }
}
