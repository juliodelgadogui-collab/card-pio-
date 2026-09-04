<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;
use EventMenu\Core\SqliteSchema;

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
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/install.php'));
    $base=rtrim(str_replace('/public','',str_replace('\\','/',dirname($script))),'/');
    if($base==='.'||$base==='/')$base='';
    return ($https?'https':'http').'://'.$host.$base;
}
function installer_valid_url(string $url):bool{
    if(!filter_var($url,FILTER_VALIDATE_URL))return false;
    $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if($scheme==='https')return true;
    return $scheme==='http'&&in_array($host,['localhost','127.0.0.1','::1'],true);
}

$error=null;$success=false;$installHandle=null;$newSqliteFile=null;
$defaults=[
    'app_url'=>(string)env('APP_URL',installer_default_url()),
    'db_driver'=>strtolower((string)env('DB_DRIVER','mysql'))==='sqlite'?'sqlite':'mysql',
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
    $defaults['db_driver']=in_array($defaults['db_driver'],['mysql','sqlite'],true)?$defaults['db_driver']:'mysql';
    if(!Security::validateCsrf($_POST['_csrf']??null)){
        $error='Sessão expirada. Atualize a página.';
    }else{
        try{
            if(!installer_valid_url($defaults['app_url']))throw new RuntimeException('Use uma URL HTTPS válida. HTTP só é aceito em localhost.');
            $dbPassword=(string)($_POST['db_password']??'');
            $dbPort=3306;
            if($defaults['db_driver']==='mysql'){
                if($defaults['db_host']===''||strlen($defaults['db_host'])>255||preg_match('/[;\r\n]/',$defaults['db_host']))throw new RuntimeException('Host do banco inválido.');
                $dbPort=(int)$defaults['db_port'];if($dbPort<1||$dbPort>65535)throw new RuntimeException('Porta do banco inválida.');
                if(!preg_match('/^[A-Za-z0-9_$.-]{1,64}$/',$defaults['db_database']))throw new RuntimeException('Nome do banco inválido.');
                if(!preg_match('/^[A-Za-z0-9_@.$-]{1,128}$/',$defaults['db_username']))throw new RuntimeException('Usuário do banco inválido.');
                if(str_contains($dbPassword,"\n")||str_contains($dbPassword,"\r"))throw new RuntimeException('Senha do banco contém caractere inválido.');
                if(!extension_loaded('pdo_mysql'))throw new RuntimeException('A extensão pdo_mysql do PHP é obrigatória para instalar com MySQL.');
            }else{
                if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('A extensão pdo_sqlite do PHP não está habilitada nesta hospedagem. Ative-a para usar SQLite no modo de teste.');
            }

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
            if(!function_exists('openssl_encrypt'))throw new RuntimeException('A extensão OpenSSL do PHP é obrigatória.');

            $installHandle=fopen($installingLock,'c+');
            if($installHandle===false||!flock($installHandle,LOCK_EX|LOCK_NB))throw new RuntimeException('Já existe outra instalação em andamento.');
            if(is_file($lock))throw new RuntimeException('O EventMenu já foi instalado.');

            $appKey=(string)env('APP_KEY','');
            if(strlen($appKey)<32||stripos($appKey,'change-me')!==false||stripos($appKey,'replace')!==false)$appKey=bin2hex(random_bytes(32));
            $sessionName='eventmenu_'.substr(hash('sha256',$defaults['app_url'].'|'.$tenantName),0,16);
            $secure=str_starts_with(strtolower($defaults['app_url']),'https://')?'true':'false';
            $sqliteDatabase='storage/eventmenu-test.sqlite';
            $envValues=[
                'APP_NAME'=>'EventMenu Premium',
                'APP_ENV'=>$defaults['db_driver']==='sqlite'?'testing':'production',
                'APP_DEBUG'=>'false','APP_URL'=>rtrim($defaults['app_url'],'/'),'APP_KEY'=>$appKey,
                'DB_DRIVER'=>$defaults['db_driver'],
                'DB_HOST'=>$defaults['db_driver']==='mysql'?$defaults['db_host']:'',
                'DB_PORT'=>$defaults['db_driver']==='mysql'?(string)$dbPort:'',
                'DB_DATABASE'=>$defaults['db_driver']==='mysql'?$defaults['db_database']:$sqliteDatabase,
                'DB_USERNAME'=>$defaults['db_driver']==='mysql'?$defaults['db_username']:'',
                'DB_PASSWORD'=>$defaults['db_driver']==='mysql'?$dbPassword:'',
                'SESSION_NAME'=>$sessionName,'SESSION_SECURE'=>$secure,'PAYMENT_CURRENCY'=>'BRL',
                'MAIL_DRIVER'=>'log',
            ];

            if($defaults['db_driver']==='sqlite'){
                $sqlitePath=Database::sqlitePath($sqliteDatabase,$root);
                if(is_file($sqlitePath)){
                    $probe=new PDO('sqlite:'.$sqlitePath);$hasUsers=false;
                    try{$hasUsers=(bool)$probe->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();if($hasUsers)$hasUsers=(int)$probe->query('SELECT COUNT(*) FROM users')->fetchColumn()>0;}catch(Throwable){}
                    $probe=null;
                    if($hasUsers)throw new RuntimeException('O arquivo SQLite de teste já contém uma instalação do EventMenu. Remova storage/eventmenu-test.sqlite ou use a instalação existente.');
                }else{$newSqliteFile=$sqlitePath;}
            }

            $lines=[];foreach($envValues as$key=>$value)$lines[]=installer_env_line($key,$value);
            $envContent=implode("\n",$lines)."\n";
            $tmp=$envPath.'.tmp-'.bin2hex(random_bytes(6));
            if(file_put_contents($tmp,$envContent,LOCK_EX)===false)throw new RuntimeException('Não foi possível criar o arquivo de configuração .env.');
            @chmod($tmp,0600);
            if(!@rename($tmp,$envPath)){@unlink($tmp);throw new RuntimeException('Não foi possível finalizar a gravação do .env.');}
            @chmod($envPath,0600);
            installer_runtime_env($envValues);
            Database::resetForTests();

            $pdo=Database::connection();
            $pdo->query('SELECT 1')->fetchColumn();
            $schema=file_get_contents($root.'/database/schema.sql');
            if($schema===false)throw new RuntimeException('Schema principal não encontrado.');
            if($defaults['db_driver']==='sqlite')SqliteSchema::executeBatch($pdo,$schema);else$pdo->exec($schema);
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

            if(file_put_contents($lock,json_encode(['installed_at'=>date(DATE_ATOM),'version'=>'9.2','database'=>$defaults['db_driver']],JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Banco criado, mas não foi possível gravar o lock de instalação. Corrija a permissão de storage.');
            @chmod($lock,0640);
            $success=true;
        }catch(Throwable $e){
            if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
            if($newSqliteFile&&is_file($newSqliteFile)){@unlink($newSqliteFile);@unlink($newSqliteFile.'-wal');@unlink($newSqliteFile.'-shm');}
            $error=$e->getMessage();
        }finally{
            if(is_resource($installHandle)){flock($installHandle,LOCK_UN);fclose($installHandle);}
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"><style>.installer{max-width:860px;margin:28px auto}.install-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.span2{grid-column:1/-1}.db-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px}.db-option{display:block;border:1px solid #dfe1ea;border-radius:16px;padding:16px;cursor:pointer}.db-option input{width:auto;margin-right:8px}.db-option strong{display:block;margin-bottom:5px}.test-note{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:14px;padding:13px}.hidden{display:none!important}@media(max-width:700px){.install-grid,.db-choice{grid-template-columns:1fr}.span2{grid-column:auto}}</style></head><body class="auth-page"><main class="auth-card installer"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação guiada</h1><?php if($success):?><div class="alert ok"><strong>EventMenu instalado com sucesso.</strong><br>Banco <strong><?= Security::e(strtoupper($defaults['db_driver'])) ?></strong>, migrations, empresa e administrador foram criados. <a href="<?= Security::e(rtrim($defaults['app_url'],'/').'/') ?>">Entrar no sistema</a>.</div><?php if($defaults['db_driver']==='sqlite'):?><div class="test-note"><strong>Modo de teste SQLite ativo.</strong> Use para conhecer e testar o sistema. Antes de produção e pagamentos reais, faça uma instalação MySQL separada.</div><?php endif;?><p class="muted">O instalador agora está bloqueado automaticamente por <code>storage/installed.lock</code>.</p><?php else:?><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><div class="alert">Você pode instalar diretamente em uma subpasta como <code>/1</code>. Para produção, use MySQL. Para testar sem criar banco no painel da hospedagem, escolha SQLite.</div><form method="post" class="install-grid" id="installerForm"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label class="span2">URL do sistema<input name="app_url" type="url" required value="<?= Security::e($defaults['app_url']) ?>" placeholder="https://seu-dominio.com.br/1"></label><div class="span2"><h2>Banco de dados</h2><div class="db-choice"><label class="db-option"><strong><input type="radio" name="db_driver" value="mysql"<?= $defaults['db_driver']==='mysql'?' checked':'' ?>> MySQL — produção</strong><span class="muted">Recomendado e obrigatório para uso real, alta concorrência e pagamentos.</span></label><label class="db-option"><strong><input type="radio" name="db_driver" value="sqlite"<?= $defaults['db_driver']==='sqlite'?' checked':'' ?>> SQLite — teste</strong><span class="muted">Cria automaticamente <code>storage/eventmenu-test.sqlite</code>. Não precisa usuário ou senha de banco.</span></label></div></div><div id="mysqlFields" class="span2 install-grid"><label>Host<input data-mysql name="db_host" value="<?= Security::e($defaults['db_host']) ?>"></label><label>Porta<input data-mysql name="db_port" type="number" min="1" max="65535" value="<?= Security::e($defaults['db_port']) ?>"></label><label>Banco de dados<input data-mysql name="db_database" value="<?= Security::e($defaults['db_database']) ?>"></label><label>Usuário MySQL<input data-mysql name="db_username" value="<?= Security::e($defaults['db_username']) ?>"></label><label class="span2">Senha MySQL<input data-mysql name="db_password" type="password" autocomplete="new-password" placeholder="Senha do usuário do banco"></label></div><div id="sqliteNote" class="span2 test-note hidden"><strong>SQLite é somente para teste.</strong><br>O arquivo fica dentro de <code>storage/</code>, protegido pelo sistema. Gateways reais e operação de produção devem usar MySQL.</div><h2 class="span2">Primeira empresa</h2><label class="span2">Nome da empresa<input name="tenant_name" required value="<?= Security::e($defaults['tenant_name']) ?>"></label><label>Nome do administrador<input name="name" required value="<?= Security::e($defaults['name']) ?>"></label><label>E-mail<input name="email" type="email" required value="<?= Security::e($defaults['email']) ?>"></label><label class="span2">Senha do administrador<input name="password" type="password" minlength="10" required autocomplete="new-password"></label><button class="primary span2" type="submit">Instalar EventMenu Premium</button></form><p class="muted" style="margin-top:16px">Requisitos: PHP 8.2+, curl, mbstring e openssl; pdo_mysql para MySQL ou pdo_sqlite para SQLite. Em produção use HTTPS + MySQL 8.0+.</p><script>(()=>{const radios=[...document.querySelectorAll('input[name="db_driver"]')],mysql=document.getElementById('mysqlFields'),note=document.getElementById('sqliteNote');function sync(){const sqlite=document.querySelector('input[name="db_driver"]:checked')?.value==='sqlite';mysql.classList.toggle('hidden',sqlite);note.classList.toggle('hidden',!sqlite);document.querySelectorAll('[data-mysql]').forEach(el=>{el.required=!sqlite;el.disabled=sqlite;});}radios.forEach(r=>r.addEventListener('change',sync));sync();})();</script><?php endif;?></main></body></html>
