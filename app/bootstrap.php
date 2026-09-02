<?php

declare(strict_types=1);

function load_env(string $path):void
{
    if(!is_file($path))return;
    foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
        $line=trim($line);
        if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$value]=explode('=',$line,2);
        $key=trim($key);
        $value=trim($value," \t\n\r\0\x0B\"'");
        if(getenv($key)===false){putenv("{$key}={$value}");$_ENV[$key]=$value;}
    }
}

load_env(__DIR__.'/../.env');

function env(string $key,mixed $default=null):mixed
{
    $value=$_ENV[$key]??getenv($key);
    return($value===false||$value===null||$value==='')?$default:$value;
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
    session_set_cookie_params([
        'lifetime'=>0,
        'httponly'=>true,
        'secure'=>$secure,
        'samesite'=>'Lax',
        'path'=>'/',
    ]);
    session_start();
}
