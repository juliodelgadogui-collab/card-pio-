<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;

[$script,$name,$email,$password]=array_pad($argv,4,null);
$name=trim((string)$name);$email=mb_strtolower(trim((string)$email));$password=(string)$password;
if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Uso: php bin/create-super-admin.php \"Nome\" email@dominio.com \"senha-com-12-ou-mais\"\n");exit(1);}
$pdo=Database::connection();
$s=$pdo->prepare('SELECT id,role FROM users WHERE email=? LIMIT 1');$s->execute([$email]);$existing=$s->fetch();
if($existing){$pdo->prepare('UPDATE users SET tenant_id=NULL,name=?,password_hash=?,role="super_admin",status="active" WHERE id=?')->execute([$name,password_hash($password,PASSWORD_DEFAULT),$existing['id']]);echo "Usuário existente promovido para Super ADM.\n";exit(0);}
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL,?,?,?,"super_admin","active")')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);echo "Super ADM criado com sucesso.\n";
