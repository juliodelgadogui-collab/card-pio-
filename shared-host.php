<?php

declare(strict_types=1);

function em_shared_base_path(): string
{
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
    $dir=$script!==''?rtrim(str_replace('\\','/',dirname($script)),'/'):'';
    return ($dir===''||$dir==='.'||$dir==='/')?'':$dir;
}

function em_shared_url(string $path=''): string
{
    $base=em_shared_base_path();
    if($path==='')return $base!==''?$base.'/':'/';
    if(!str_starts_with($path,'/'))$path='/'.$path;
    return $base.$path;
}

function em_shared_env(): array
{
    $path=__DIR__.'/.env';$out=[];
    if(!is_file($path))return$out;
    foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as$line){
        $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$raw]=explode('=',$line,2);$key=trim($key);$raw=trim($raw);
        if(!preg_match('/^[A-Z0-9_]+$/i',$key))continue;
        if(strlen($raw)>=2&&$raw[0]==='"'&&substr($raw,-1)==='"'){$decoded=json_decode($raw,true);$raw=is_string($decoded)?$decoded:substr($raw,1,-1);}
        elseif(strlen($raw)>=2&&$raw[0]==="'"&&substr($raw,-1)==="'")$raw=substr($raw,1,-1);
        $out[$key]=$raw;
    }
    return$out;
}

function em_shared_render_error(string $title,string $message,array $details=[]): never
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    $e=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$e($title).' — EventMenu</title><style>body{margin:0;background:#f5f4fb;color:#1d1b2a;font-family:system-ui,-apple-system,sans-serif}.box{max-width:760px;margin:32px auto;padding:24px}.card{background:#fff;border:1px solid #e5e1f4;border-radius:20px;padding:24px;box-shadow:0 18px 55px rgba(62,45,120,.09)}h1{margin-top:0}.brand{font-weight:800;color:#6d4aff}.alert{padding:14px;border-radius:14px;background:#fff4f4;border:1px solid #f3b4b4;color:#8a1f1f}.details{margin-top:18px;padding:14px;background:#f7f6fb;border-radius:14px;font-size:14px}.button{display:inline-block;margin-top:18px;padding:12px 16px;border-radius:12px;background:#6d4aff;color:white;text-decoration:none;font-weight:700}code{word-break:break-word}</style></head><body><main class="box"><section class="card"><div class="brand">EventMenu Premium · modo de teste</div><h1>'.$e($title).'</h1><div class="alert">'.$e($message).'</div>';
    if($details){echo'<div class="details">';foreach($details as$k=>$v)echo'<div><strong>'.$e((string)$k).':</strong> '.$e((string)$v).'</div>';echo'</div>';}
    echo '<a class="button" href="'.$e(em_shared_url('/diagnostico.php')).'">Abrir diagnóstico</a></section></main></body></html>';
    exit;
}

function em_shared_preflight(string $target): void
{
    $storage=__DIR__.'/storage';
    if(!is_dir($storage))@mkdir($storage,0775,true);
    if(is_dir($storage)){
        @ini_set('log_errors','1');@ini_set('error_log',$storage.'/php-error.log');
    }
    if(PHP_VERSION_ID<80200)em_shared_render_error('PHP incompatível','O EventMenu precisa de PHP 8.2 ou superior.',['PHP atual'=>PHP_VERSION]);
    if(!is_file(__DIR__.'/'.$target))em_shared_render_error('Arquivo ausente','O pacote foi extraído incompleto.',['Arquivo esperado'=>$target]);
    if(!is_file(__DIR__.'/app/bootstrap.php'))em_shared_render_error('Estrutura incompleta','A pasta app/ não foi encontrada. Extraia o ZIP diretamente dentro de /1.');
    if(!extension_loaded('openssl'))em_shared_render_error('Extensão ausente','A extensão OpenSSL do PHP precisa estar habilitada.');
    if(!extension_loaded('pdo'))em_shared_render_error('Extensão ausente','A extensão PDO do PHP precisa estar habilitada.');

    $env=em_shared_env();$driver=strtolower((string)($env['DB_DRIVER']??''));
    if($target!=='public/install.php'&&$driver==='sqlite'&&!extension_loaded('pdo_sqlite')){
        em_shared_render_error('SQLite não disponível nesta hospedagem','O PHP do servidor não possui a extensão pdo_sqlite. Sem essa extensão o SQLite não consegue abrir. Ative pdo_sqlite no cPanel ou instale o teste usando MySQL.',['DB_DRIVER'=>'sqlite','pdo_sqlite'=>'ausente']);
    }
    if($target!=='public/install.php'&&$driver==='mysql'&&!extension_loaded('pdo_mysql')){
        em_shared_render_error('MySQL não disponível nesta hospedagem','A extensão pdo_mysql do PHP não está habilitada.',['DB_DRIVER'=>'mysql','pdo_mysql'=>'ausente']);
    }
    if(is_dir($storage)&&!is_writable($storage))em_shared_render_error('Permissão insuficiente','A pasta storage precisa permitir escrita pelo PHP.',['storage'=>$storage]);
}

function em_shared_run(string $target): never
{
    ob_start();
    em_shared_preflight($target);
    register_shutdown_function(static function():void{
        $error=error_get_last();
        if(!$error||!in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
        while(ob_get_level()>0)ob_end_clean();
        em_shared_render_error('Erro interno do PHP','O sistema encontrou um erro antes de conseguir abrir a página. O detalhe foi salvo em storage/php-error.log.',['Erro'=>$error['message'],'Arquivo'=>basename((string)$error['file']),'Linha'=>(string)$error['line']]);
    });
    try{require __DIR__.'/'.$target;}catch(Throwable $e){
        while(ob_get_level()>0)ob_end_clean();
        error_log('[EventMenu shared host] '.$e);
        em_shared_render_error('Erro ao abrir o EventMenu',$e->getMessage(),['Tipo'=>get_class($e),'Arquivo'=>basename($e->getFile()),'Linha'=>(string)$e->getLine()]);
    }
    $body=ob_get_clean();echo$body;exit;
}
