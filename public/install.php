<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;

$root=dirname(__DIR__);
$storage=$root.'/storage';
$lock=$storage.'/installed.lock';
$installingLock=$storage.'/installing.lock';
$envPath=$root.'/.env';
if(is_file($lock)){http_response_code(403);exit('O EventMenu já está instalado. Entre no painel para usar o sistema ou use /update.php para aplicar atualizações.');}

function installer_env_line(string $key,string $value):string{return $key.'='.json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function installer_runtime_env(array $values):void{foreach($values as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;}}
function installer_default_url():string{
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443');
    $host=(string)($_SERVER['HTTP_HOST']??'localhost');
    return ($https?'https':'http').'://'.$host;
}
function installer_valid_url(string $url):bool{
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if($scheme==='https')return true;
    return $scheme==='http'&&in_array($host,['localhost','127.0.0.1','::1'],true);
}

$error=null;$success=false;$installHandle=null;
$defaults=[
    'app_url'=>(string)env('APP_URL',installer_default_url()),
    'db_host'=>(string)env('DB_HOST','127.0.0.1'),
    'db_port'=>(string)env('DB_PORT','3306'),
    'db_database'=>(string)env('DB_DATABASE','eventmenu'),
    'db_username'=>(string)env('DB_USERNAME','eventmenu'),
    'tenant_name'=>'',
    'name'=>'',
    'email'=>'',
];

if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach(array_keys($defaults) as$key)$defaults[$key]=trim((string)($_POST[$key]??$defaults[$key]));
    if(!Security::validateCsrf($_POST['_csrf']??null)){
        $error='Sessão expirada. Atualize a página.';
    }else{
        try{
            if(!installer_valid_url($defaults['app_url']))throw new RuntimeException('Use uma URL HTTPS válida. HTTP só é aceito em localhost.');
            if($defaults['db_host']===''||strlen($defaults['db_host'])>255||preg_match('/[;\r\n]/',$defaults['db_host']))throw new RuntimeException('Host do banco inválido.');
            $dbPort=(int)$defaults['db_port'];if($dbPort<1||$dbPort>65535)throw new RuntimeException('Porta do banco inválida.');
            if(!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/',$defaults['db_database']))throw new RuntimeException('Nome do banco inválido.');
            if(!preg_match('/^[A-Za-z0-9_@.$-]{1,128}$/',$defaults['db_username']))throw new RuntimeException('Usuário do banco inválido.');
            $dbPassword=(string)($_POST['db_password']??'');
            if(str_contains($dbPassword,"\n")||str_contains($dbPassword,"\r"))throw new RuntimeException('Senha do banco contém caractere inválido.');

            $tenantName=$defaults['tenant_name'];
            $email=mb_strtolower($defaults['email']);
            $name=$defaults['name'];
            $password=(string)($_POST['password']??'');
            if($tenantName===''||$name==='')throw new RuntimeException('Informe o nome da empresa e do administrador.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
            if(strlen($password)<10)throw new RuntimeException('A senha do administrador precisa ter pelo menos 10 caracteres.');

            if(!is_dir($storage)&&!mkdir($storage,0775,true)&&!is_dir($storage))throw new RuntimeException('Não foi possível criar a pasta storage.');
            if(!is_writable($storage))throw new RuntimeException('A pasta storage precisa ter permissão de escrita durante a instalação.');
            if(!is_writable($root)&&!is_file($envPath))throw new RuntimeException('A pasta raiz precisa estar gravável uma vez para o instalador criar o arquivo .env.');

            $installHandle=fopen($installingLock,'c+');
            if($installHandle===false||!flock($installHandle,LOCK_EX|LOCK_NB))throw new RuntimeException('Já existe outra instalação em andamento.');
            if(is_file($lock))throw new RuntimeException('O EventMenu já foi instalado.');

            $appKey=(string)env('APP_KEY','');
            if(strlen($appKey)<32||stripos($appKey,'change-me')!==false||stripos($appKey,'replace')!==false)$appKey=bin2hex(random_bytes(32));
            $sessionName='eventmenu_'.substr(hash('sha256',$defaults['app_url'].'|'.$tenantName),0,16);
            $secure=str_starts_with(strtolower($defaults['app_url']),'https://')?'true':'false';
            $envValues=[
                'APP_NAME'=>'EventMenu Premium','APP_ENV'=>'production','APP_DEBUG'=>'false','APP_URL'=>rtrim($defaults['app_url'],'/'),'APP_KEY'=>$appKey,
                'DB_HOST'=>$defaults['db_host'],'DB_PORT'=>(string)$dbPort,'DB_DATABASE'=>$defaults['db_database'],'DB_USERNAME'=>$defaults['db_username'],'DB_PASSWORD'=>$dbPassword,
                'SESSION_NAME'=>$sessionName,'SESSION_SECURE'=>$secure,'PAYMENT_CURRENCY'=>'BRL',
            ];
            $lines=[];foreach($envValues as$key=>$value)$lines[]=installer_env_line($key,$value);
            $envContent=implode("\n",$lines)."\n";
            $tmp=$envPath.'.tmp-'.bin2hex(random_bytes(6));
            if(file_put_contents($tmp,$envContent,LOCK_EX)===false)throw new RuntimeException('Não foi possível criar o arquivo de configuração .env.');
            @chmod($tmp,0600);
            if(!@rename($tmp,$envPath)){@unlink($tmp);throw new RuntimeException('Não foi possível finalizar a gravação do .env.');}
            @chmod($envPath,0600);
            installer_runtime_env($envValues);

            if(!extension_loaded('pdo_mysql'))throw new RuntimeException('A extensão pdo_mysql do PHP é obrigatória.');
            if(!function_exists('openssl_encrypt'))throw new RuntimeException('A extensão OpenSSL do PHP é obrigatória.');

            $pdo=Database::connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $schema=file_get_contents($root.'/database/schema.sql');
            if($schema===false)throw new RuntimeException('Schema principal não encontrado.');
            $pdo->exec($schema);
            Migrator::run($pdo);

            $existingUsers=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if($existingUsers>0)throw new RuntimeException('Este banco já contém usuários do EventMenu. Use um banco vazio para a instalação inicial.');

            $tenantSlug=strtolower(preg_replace('/[^a-z0-9]+/i','-',$tenantName)??'empresa');
            $tenantSlug=trim($tenantSlug,'-')?:'empresa';

            $pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")');
            $stmt->execute([$tenantName,$tenantSlug.'-'.substr(bin2hex(random_bytes(4)),0,6)]);
            $tenantId=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")');
            $stmt->execute([$tenantId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $pdo->commit();

            if(file_put_contents($lock,json_encode(['installed_at'=>date(DATE_ATOM),'version'=>'9.2'],JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Banco criado, mas não foi possível gravar o lock de instalação. Corrija a permissão de storage.');
            @chmod($lock,0640);
            $success=true;
        }catch(Throwable $e){
            if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
            $error=$e->getMessage();
        }finally{
            if(is_resource($installHandle)){flock($installHandle,LOCK_UN);fclose($installHandle);}
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"><style>.installer{max-width:820px;margin:28px auto}.install-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.span2{grid-column:1/-1}@media(max-width:700px){.install-grid{grid-template-columns:1fr}.span2{grid-column:auto}}</style></head><body class="auth-page"><main class="auth-card installer"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação guiada</h1><?php if($success):?><div class="alert ok"><strong>EventMenu instalado com sucesso.</strong><br>O banco, as migrations, a empresa e o administrador foram criados. <a href="/">Entrar no sistema</a>.</div><p class="muted">O instalador agora está bloqueado automaticamente por <code>storage/installed.lock</code>.</p><?php else:?><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><div class="alert">Suba o pacote, aponte o domínio para a pasta <code>public/</code> e preencha este formulário. O instalador cria o <code>.env</code> e uma APP_KEY segura automaticamente.</div><form method="post" class="install-grid"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label class="span2">URL do sistema<input name="app_url" type="url" required value="<?= Security::e($defaults['app_url']) ?>" placeholder="https://seu-dominio.com.br"></label><h2 class="span2">Banco MySQL</h2><label>Host<input name="db_host" required value="<?= Security::e($defaults['db_host']) ?>"></label><label>Porta<input name="db_port" type="number" min="1" max="65535" required value="<?= Security::e($defaults['db_port']) ?>"></label><label>Banco de dados<input name="db_database" required value="<?= Security::e($defaults['db_database']) ?>"></label><label>Usuário MySQL<input name="db_username" required value="<?= Security::e($defaults['db_username']) ?>"></label><label class="span2">Senha MySQL<input name="db_password" type="password" autocomplete="new-password" placeholder="Senha do usuário do banco"></label><h2 class="span2">Primeira empresa</h2><label class="span2">Nome da empresa<input name="tenant_name" required value="<?= Security::e($defaults['tenant_name']) ?>"></label><label>Nome do administrador<input name="name" required value="<?= Security::e($defaults['name']) ?>"></label><label>E-mail<input name="email" type="email" required value="<?= Security::e($defaults['email']) ?>"></label><label class="span2">Senha do administrador<input name="password" type="password" minlength="10" required autocomplete="new-password"></label><button class="primary span2" type="submit">Instalar EventMenu Premium</button></form><p class="muted" style="margin-top:16px">Requisitos: PHP 8.2+, MySQL 8.0+, pdo_mysql, curl, mbstring e openssl. Em produção use HTTPS.</p><?php endif;?></main></body></html>
