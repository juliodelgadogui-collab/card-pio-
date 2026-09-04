<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;

function assert_remember(bool $condition,string $message):void
{
    if(!$condition){fwrite(STDERR,"Remember auth integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();
$suffix=bin2hex(random_bytes(4));
$slug='ci-remember-'.$suffix;$email='remember-'.$suffix.'@example.com';$password='remember-ci-password-123';
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['CI Remember',$slug]);$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")')->execute([$tenantId,'Admin Remember',$email,password_hash($password,PASSWORD_DEFAULT)]);$userId=(int)$pdo->lastInsertId();

$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['HTTPS']='off';$_SESSION=[];
$cookieName=preg_replace('/[^A-Za-z0-9_-]/','_',((string)env('SESSION_NAME','eventmenu')).'_remember')?:'eventmenu_remember';
assert_remember(Auth::attempt($email,$password,true),'login com lembrar-me falhou');
assert_remember(Auth::id()===$userId,'sessão não foi criada');
assert_remember(isset($_COOKIE[$cookieName]),'cookie de lembrar-me não foi emitido');
$raw=(string)$_COOKIE[$cookieName];assert_remember((bool)preg_match('/^[a-f0-9]{20}\.[a-f0-9]{64}$/',$raw),'cookie persistente possui formato inesperado');
$selectorBefore=explode('.',$raw,2)[0];
$count=$pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE user_id=?');$count->execute([$userId]);assert_remember((int)$count->fetchColumn()===1,'token persistente não foi armazenado');
$stored=$pdo->prepare('SELECT token_hash FROM remember_tokens WHERE selector=?');$stored->execute([$selectorBefore]);$storedHash=(string)$stored->fetchColumn();$validator=explode('.',$raw,2)[1];assert_remember($storedHash===hash('sha256',$validator),'banco não guarda o hash esperado do token');assert_remember($storedHash!==$validator,'token bruto foi salvo no banco');

// Simula uma nova requisição/navegador com cookie, mas sem sessão PHP.
$_SESSION=[];$ref=new ReflectionClass(Auth::class);$flag=$ref->getProperty('rememberChecked');$flag->setAccessible(true);$flag->setValue(null,false);
assert_remember(Auth::check(),'login lembrado não restaurou a sessão');assert_remember(Auth::id()===$userId,'login lembrado restaurou usuário incorreto');
$rawAfter=(string)($_COOKIE[$cookieName]??'');assert_remember($rawAfter!==''&&$rawAfter!==$raw,'token não foi rotacionado após uso');$selectorAfter=explode('.',$rawAfter,2)[0];
$old=$pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE selector=?');$old->execute([$selectorBefore]);assert_remember((int)$old->fetchColumn()===0,'selector antigo permaneceu válido após rotação');$new=$pdo->prepare('SELECT COUNT(*) FROM remember_tokens WHERE selector=? AND user_id=?');$new->execute([$selectorAfter,$userId]);assert_remember((int)$new->fetchColumn()===1,'novo token rotacionado não foi salvo');

Auth::logout();
$new->execute([$selectorAfter,$userId]);assert_remember((int)$new->fetchColumn()===0,'logout não revogou token persistente');assert_remember(!isset($_COOKIE[$cookieName]),'logout não removeu cookie persistente');

echo "Remember auth integration OK\n";
