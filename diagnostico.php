<?php

declare(strict_types=1);

require __DIR__.'/shared-host.php';

$env=em_shared_env();
$storage=__DIR__.'/storage';
$dbDriver=strtolower((string)($env['DB_DRIVER']??'não configurado'));
$dbPath=(string)($env['DB_DATABASE']??'');
$sqliteStatus='não testado';
if($dbDriver==='sqlite'){
    if(!extension_loaded('pdo_sqlite'))$sqliteStatus='pdo_sqlite ausente';
    else{
        $path=$dbPath;
        if($path!==''&&!str_starts_with($path,'/'))$path=__DIR__.'/'.ltrim($path,'/');
        try{$pdo=new PDO('sqlite:'.$path);$pdo->query('SELECT 1')->fetchColumn();$sqliteStatus='OK · '.($path!==''?$path:'arquivo não definido');}
        catch(Throwable $e){$sqliteStatus='erro: '.$e->getMessage();}
    }
}
$checks=[
    'PHP'=>PHP_VERSION.(PHP_VERSION_ID>=80200?' · OK':' · precisa 8.2+'),
    'PDO'=>extension_loaded('pdo')?'OK':'AUSENTE',
    'pdo_sqlite'=>extension_loaded('pdo_sqlite')?'OK':'AUSENTE',
    'pdo_mysql'=>extension_loaded('pdo_mysql')?'OK':'AUSENTE',
    'OpenSSL'=>extension_loaded('openssl')?'OK':'AUSENTE',
    'cURL'=>extension_loaded('curl')?'OK':'AUSENTE',
    'mbstring'=>extension_loaded('mbstring')?'OK':'AUSENTE',
    'Banco configurado'=>$dbDriver,
    'SQLite'=>$sqliteStatus,
    'storage existe'=>is_dir($storage)?'sim':'não',
    'storage gravável'=>is_dir($storage)&&is_writable($storage)?'sim':'não',
    '.env'=>is_file(__DIR__.'/.env')?'presente':'ausente',
    'public/index.php'=>is_file(__DIR__.'/public/index.php')?'presente':'ausente',
    'vendor/autoload.php'=>is_file(__DIR__.'/vendor/autoload.php')?'presente':'ausente',
    'Diretório'=>__DIR__,
    'SCRIPT_NAME'=>(string)($_SERVER['SCRIPT_NAME']??''),
];
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico EventMenu</title><style>body{margin:0;background:#f5f4fb;color:#1d1b2a;font-family:system-ui,-apple-system,sans-serif}.wrap{max-width:860px;margin:24px auto;padding:20px}.card{background:#fff;border:1px solid #e5e1f4;border-radius:20px;padding:24px}.brand{font-weight:800;color:#6d4aff}table{width:100%;border-collapse:collapse;margin-top:18px}td{padding:11px;border-bottom:1px solid #eee;vertical-align:top}td:first-child{font-weight:700;width:36%}.button{display:inline-block;margin-top:18px;padding:12px 16px;background:#6d4aff;color:#fff;text-decoration:none;border-radius:12px;font-weight:700}.note{margin-top:16px;padding:14px;background:#fff7ed;border:1px solid #fdba74;border-radius:14px;color:#9a3412}</style></head><body><main class="wrap"><section class="card"><div class="brand">EventMenu Premium</div><h1>Diagnóstico da hospedagem</h1><p>Esta página não depende do banco para abrir. Ela mostra o que o PHP realmente tem disponível na pasta atual.</p><table><?php foreach($checks as$k=>$v):?><tr><td><?=htmlspecialchars((string)$k,ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8')?></td></tr><?php endforeach;?></table><?php if($dbDriver==='sqlite'&&!extension_loaded('pdo_sqlite')):?><div class="note"><strong>Causa provável do HTTP 500:</strong> pdo_sqlite está desativado. No cPanel, abra “Select PHP Version” / “PHP Extensions” e habilite <strong>pdo_sqlite</strong> e <strong>sqlite3</strong>. Se a hospedagem não oferecer essas extensões, use MySQL para o teste.</div><?php endif;?><a class="button" href="<?=htmlspecialchars(em_shared_url('/'),ENT_QUOTES,'UTF-8')?>">Tentar abrir o sistema</a></section></main></body></html>
