<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;
use EventMenu\Support\JcLancheDemoSeeder;

$root=dirname(__DIR__);$storage=$root.'/storage';$lock=$storage.'/installed.lock';$error=null;$success=false;
if(is_file($lock)){http_response_code(403);exit('EventMenu já instalado.');}
function jc_url(): string{$https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''))==='https';$host=trim((string)($_SERVER['HTTP_HOST']??'localhost'));if(!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/',$host))$host='localhost';return($https?'https':'http').'://'.$host;}
function jc_env(string $path,array $v):void{$lines=[];foreach($v as $k=>$x){$x=(string)$x;$lines[]=$k.'='.(preg_match('/^[A-Za-z0-9_.\-\/:]+$/',$x)?$x:'"'.addcslashes($x,"\\\"").'"');putenv($k.'='.$x);$_ENV[$k]=$x;}if(file_put_contents($path,implode("\n",$lines)."\n",LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar .env.');@chmod($path,0640);}
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!Security::validateCsrf($_POST['_csrf']??null))throw new RuntimeException('Sessão expirada. Atualize a página.');
  if(version_compare(PHP_VERSION,'8.2.0','<'))throw new RuntimeException('PHP 8.2 ou superior é obrigatório.');
  if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite não está habilitado.');
  if(!is_dir($storage)&&!mkdir($storage,0775,true)&&!is_dir($storage))throw new RuntimeException('Não foi possível criar storage.');
  if(!is_writable($storage))throw new RuntimeException('A pasta storage precisa de permissão de escrita.');
  $url=rtrim(trim((string)($_POST['app_url']??jc_url())),'/');if(!filter_var($url,FILTER_VALIDATE_URL))throw new RuntimeException('URL inválida.');
  $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));$host=strtolower((string)parse_url($url,PHP_URL_HOST));if($scheme!=='https'&&!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('HTTPS é obrigatório.');
  $db=$storage.'/eventmenu.sqlite';
  jc_env($root.'/.env',['APP_NAME'=>'EventMenu Premium','APP_ENV'=>'production','APP_DEBUG'=>'false','APP_URL'=>$url,'APP_BASE_PATH'=>app_base_path(),'APP_KEY'=>Security::randomKey(32),'CRON_SECRET'=>Security::randomKey(32),'DB_CONNECTION'=>'sqlite','DB_SQLITE_PATH'=>'storage/eventmenu.sqlite','DB_SQLITE_WAL'=>'auto','SESSION_NAME'=>'eventmenu_session','SESSION_SECURE'=>$scheme==='https'?'true':'false','PAYMENT_CURRENCY'=>'BRL','STRIPE_WEBHOOK_SECRET'=>'','PAGBANK_WEBHOOK_SECRET'=>'','MERCADOPAGO_WEBHOOK_SECRET'=>'']);
  $pdo=Database::connection();$schema=file_get_contents($root.'/database/sqlite/schema.sql');if($schema===false)throw new RuntimeException('Schema SQLite não encontrado.');$pdo->exec($schema);Migrator::run($pdo);JcLancheDemoSeeder::run($pdo);
  if(file_put_contents($lock,date(DATE_ATOM),LOCK_EX)===false)throw new RuntimeException('Banco criado, mas não foi possível gravar installed.lock.');@chmod($lock,0640);$success=true;
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Instalar EventMenu — JC Lanche</title><style>body{font-family:system-ui;background:#f4f2f8;margin:0;padding:24px;color:#211d2b}.card{max-width:680px;margin:auto;background:#fff;padding:28px;border-radius:22px;box-shadow:0 10px 40px #0001}h1{margin-top:0}.ok{background:#e9f8ef;padding:16px;border-radius:12px}.err{background:#feecec;padding:16px;border-radius:12px}.info{background:#f4f0ff;padding:16px;border-radius:12px;margin:16px 0}input{box-sizing:border-box;width:100%;padding:13px;border:1px solid #ccc;border-radius:10px;margin:8px 0 16px}button{width:100%;padding:14px;border:0;border-radius:12px;background:#5b34d6;color:white;font-weight:800;font-size:16px}code{word-break:break-all}</style></head><body><main class="card"><h1>EventMenu Premium</h1><h2>Instalação JC Lanche</h2><?php if($success):?><div class="ok"><strong>Instalação concluída.</strong><p>JC Lanche, 20 mesas, cardápio e equipe foram criados.</p><p>Super ADM: <code>juliodelgadogui@gmail.com</code> / senha <code>1</code><br>JC Lanche: <code>A@1.com</code> / senha <code>1</code><br>Carlos: <code>1@1.com</code> / senha <code>1</code></p><p><strong>Troque as senhas antes de produção.</strong></p><a href="<?=Security::e(app_url(''))?>">Entrar no EventMenu</a></div><?php else:?><?php if($error):?><div class="err"><?=Security::e($error)?></div><?php endif;?><div class="info">Este instalador cria SQLite, aplica schema/migrações e carrega a demonstração completa da JC Lanche. Ele é bloqueado automaticamente após a primeira instalação.</div><form method="post"><input type="hidden" name="_csrf" value="<?=Security::e(Security::csrfToken())?>"><label>URL do sistema</label><input name="app_url" value="<?=Security::e(jc_url())?>" required><button type="submit">Instalar JC Lanche</button></form><?php endif;?></main></body></html>