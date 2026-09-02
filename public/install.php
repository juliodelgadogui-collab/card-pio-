<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;

$storage=__DIR__.'/../storage';
$lock=$storage.'/installed.lock';
$installingLock=$storage.'/installing.lock';
if(is_file($lock)){http_response_code(403);exit('O EventMenu já está instalado. Use /update.php para aplicar atualizações de banco.');}

$error=null;$success=false;$installHandle=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null)){
        $error='Sessão expirada. Atualize a página.';
    }else{
        try{
            $appKey=(string)env('APP_KEY','');
            if(strlen($appKey)<32||stripos($appKey,'change-me')!==false||stripos($appKey,'replace')!==false)throw new RuntimeException('Configure APP_KEY com pelo menos 32 caracteres aleatórios no arquivo .env antes de instalar.');
            if(!function_exists('openssl_encrypt'))throw new RuntimeException('A extensão OpenSSL do PHP é obrigatória.');

            if(!is_dir($storage)&&!mkdir($storage,0775,true)&&!is_dir($storage))throw new RuntimeException('Não foi possível criar a pasta storage.');
            if(!is_writable($storage))throw new RuntimeException('A pasta storage precisa ter permissão de escrita durante a instalação.');
            $installHandle=fopen($installingLock,'c+');
            if($installHandle===false||!flock($installHandle,LOCK_EX|LOCK_NB))throw new RuntimeException('Já existe outra instalação em andamento.');
            if(is_file($lock))throw new RuntimeException('O EventMenu já foi instalado.');

            $tenantName=trim((string)($_POST['tenant_name']??''));
            $email=mb_strtolower(trim((string)($_POST['email']??'')));
            $name=trim((string)($_POST['name']??''));
            $password=(string)($_POST['password']??'');
            if($tenantName===''||$name==='')throw new RuntimeException('Informe o nome da empresa e do administrador.');
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
            if(strlen($password)<10)throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');

            $pdo=Database::connection();
            $schema=file_get_contents(__DIR__.'/../database/schema.sql');
            if($schema===false)throw new RuntimeException('Schema principal não encontrado.');
            $pdo->exec($schema);
            Migrator::run($pdo);

            $existingUsers=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if($existingUsers>0)throw new RuntimeException('Este banco já contém usuários do EventMenu. A instalação inicial foi bloqueada para evitar duplicidade.');

            $tenantSlug=strtolower(preg_replace('/[^a-z0-9]+/i','-',$tenantName)??'empresa');
            $tenantSlug=trim($tenantSlug,'-')?:'empresa';

            $pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")');
            $stmt->execute([$tenantName,$tenantSlug.'-'.substr(bin2hex(random_bytes(4)),0,6)]);
            $tenantId=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")');
            $stmt->execute([$tenantId,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $pdo->commit();

            if(file_put_contents($lock,date(DATE_ATOM),LOCK_EX)===false)throw new RuntimeException('Banco criado, mas não foi possível gravar o lock de instalação. Corrija a permissão de storage antes de acessar novamente o instalador.');
            $success=true;
        }catch(Throwable $e){
            if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
            $error=$e->getMessage();
        }finally{
            if(is_resource($installHandle)){flock($installHandle,LOCK_UN);fclose($installHandle);}
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar EventMenu Premium</title><link rel="stylesheet" href="assets/app.css"></head><body class="auth-page"><main class="auth-card"><div class="brand">EventMenu <span>Premium</span></div><h1>Instalação inicial</h1><?php if($success):?><div class="alert ok">Instalado com sucesso. <a href="/">Entrar no sistema</a>.</div><?php else:?><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><div class="alert">Antes de instalar, configure banco, APP_URL e uma APP_KEY aleatória de pelo menos 32 caracteres no <code>.env</code>.</div><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><label>Empresa<input name="tenant_name" required></label><label>Seu nome<input name="name" required></label><label>E-mail do administrador<input name="email" type="email" required></label><label>Senha<input name="password" type="password" minlength="10" required></label><button class="primary" type="submit">Instalar EventMenu</button></form><?php endif;?></main></body></html>
