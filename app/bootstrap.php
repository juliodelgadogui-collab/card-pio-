<?php

declare(strict_types=1);

function load_env(string $path):void
{
    if(!is_file($path))return;
    foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        $line=trim($line);
        if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$raw]=explode('=',$line,2);
        $key=trim($key);
        if(!preg_match('/^[A-Z0-9_]+$/i',$key))continue;
        $raw=trim($raw);
        $value=$raw;
        if(strlen($raw)>=2&&$raw[0]==='"'&&substr($raw,-1)==='"'){
            $decoded=json_decode($raw,true);
            if(is_string($decoded))$value=$decoded;
            else $value=substr($raw,1,-1);
        }elseif(strlen($raw)>=2&&$raw[0]==="'"&&substr($raw,-1)==="'"){
            $value=substr($raw,1,-1);
        }
        if(getenv($key)===false){putenv($key.'='.$value);$_ENV[$key]=$value;}
    }
}

function runtime_env(string $key,string $value):void
{
    if(getenv($key)!==false)return;
    putenv($key.'='.$value);$_ENV[$key]=$value;
}

function load_sqlite_test_fallback():void
{
    $root=dirname(__DIR__);
    if(is_file($root.'/.env'))return;
    $lockPath=$root.'/storage/installed.lock';
    $databasePath=$root.'/storage/eventmenu-test.sqlite';
    if(!is_file($lockPath)||!is_file($databasePath))return;
    $meta=json_decode((string)@file_get_contents($lockPath),true);
    if(!is_array($meta)||strtolower((string)($meta['database']??''))!=='sqlite')return;
    $mode=strtolower((string)($meta['mode']??'testing'));
    if(!in_array($mode,['test','testing'],true))return;

    $keyPath=$root.'/storage/test-app-key.txt';
    $key=is_file($keyPath)?trim((string)@file_get_contents($keyPath)):'';
    if(strlen($key)<32){
        $key=bin2hex(random_bytes(32));
        if(@file_put_contents($keyPath,$key."\n",LOCK_EX)===false)throw new RuntimeException('Não foi possível criar a chave local do modo SQLite de teste. Verifique a permissão da pasta storage.');
        @chmod($keyPath,0600);
    }

    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443');
    $host=(string)($_SERVER['HTTP_HOST']??'localhost');
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/index.php'));
    $base=rtrim(str_replace('\\','/',dirname($script)),'/');
    if($base==='.'||$base==='/')$base='';
    $appUrl=($https?'https':'http').'://'.$host.$base;

    runtime_env('APP_NAME','EventMenu Premium');
    runtime_env('APP_ENV','testing');
    runtime_env('APP_DEBUG','false');
    runtime_env('APP_URL',$appUrl);
    runtime_env('APP_KEY',$key);
    runtime_env('DB_DRIVER','sqlite');
    runtime_env('DB_DATABASE','storage/eventmenu-test.sqlite');
    runtime_env('SESSION_NAME','eventmenu_shared_test');
    runtime_env('SESSION_SECURE',$https?'true':'false');
    runtime_env('PAYMENT_CURRENCY','BRL');
    runtime_env('MAIL_DRIVER','log');
}

load_env(__DIR__.'/../.env');
load_sqlite_test_fallback();
date_default_timezone_set('UTC');

function env(string $key,mixed $default=null):mixed
{
    $value=$_ENV[$key]??getenv($key);
    return($value===false||$value===null||$value==='')?$default:$value;
}

function app_base_path():string
{
    $configured=(string)env('APP_URL','');
    $configuredPath=$configured!==''?(string)(parse_url($configured,PHP_URL_PATH)??''):'';
    $configuredPath=rtrim($configuredPath,'/');
    if($configuredPath==='/')$configuredPath='';
    if($configuredPath!=='')return$configuredPath;

    $script=(string)($_SERVER['SCRIPT_NAME']??'');
    $scriptDir=$script!==''?str_replace('\\','/',dirname($script)):'';
    if($scriptDir==='/'||$scriptDir==='.')$scriptDir='';
    return$scriptDir;
}

function app_url(string $path=''):string
{
    $base=app_base_path();
    if($path==='')return $base!==''?$base.'/':'/';
    if(!str_starts_with($path,'/'))$path='/'.$path;
    if($base!==''&&($path===$base||str_starts_with($path,$base.'/')))return $path;
    return $base.$path;
}

$vendor=__DIR__.'/../vendor/autoload.php';
if(is_file($vendor))require_once $vendor;

spl_autoload_register(function(string $class):void{
    $prefix='EventMenu\\';
    if(!str_starts_with($class,$prefix))return;
    $relative=substr($class,strlen($prefix));
    $path=__DIR__.'/../src/'.str_replace('\\','/',$relative).'.php';
    if(is_file($path))require $path;
});

if(PHP_SAPI!=='cli'){
    $base=app_base_path();

    if(function_exists('header_register_callback')){
        header_register_callback(static function()use($base):void{
            if($base==='')return;
            foreach(headers_list() as$h){
                if(strncasecmp($h,'Location:',9)!==0)continue;
                $location=trim(substr($h,9));
                if($location===''||!str_starts_with($location,'/')||str_starts_with($location,'//'))return;
                if($location===$base||str_starts_with($location,$base.'/'))return;
                $code=http_response_code();
                header_remove('Location');
                header('Location: '.$base.$location,true,($code>=300&&$code<400)?$code:302);
                return;
            }
        });
    }

    if($base!==''&&ob_get_level()===0){
        ob_start(static function(string $buffer)use($base):string{
            return preg_replace_callback(
                "~\\b(href|src|action)=([\"'])(/[^\"'>]*)\\2~i",
                static function(array $m)use($base):string{
                    $path=$m[3];
                    if(str_starts_with($path,'//')||$path===$base||str_starts_with($path,$base.'/'))return$m[0];
                    return$m[1].'='.$m[2].$base.$path.$m[2];
                },
                $buffer
            )??$buffer;
        });
    }
}

if(PHP_SAPI!=='cli'&&!headers_sent()){
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443');
    if($https&&strtolower((string)env('APP_ENV','production'))==='production')header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if(session_status()!==PHP_SESSION_ACTIVE){
    $secure=filter_var(env('SESSION_SECURE','false'),FILTER_VALIDATE_BOOL);
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.gc_maxlifetime','28800');
    session_name((string)env('SESSION_NAME','eventmenu_session'));
    $cookiePath=app_base_path();if($cookiePath==='')$cookiePath='/';else$cookiePath.='/';
    session_set_cookie_params([
        'lifetime'=>0,
        'httponly'=>true,
        'secure'=>$secure,
        'samesite'=>'Lax',
        'path'=>$cookiePath,
    ]);
    session_start();
}
