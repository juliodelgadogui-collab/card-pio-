<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryCustomerAuthService;

header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$token=trim((string)($_GET['token']??$_POST['token']??''));$error=null;$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada. Atualize a página.';
    else{
        try{Database::transaction(fn(PDO$pdo)=> (new DeliveryCustomerAuthService())->resetPassword($pdo,$token,(string)($_POST['password']??'')));$success=true;}
        catch(Throwable$e){$error=$e->getMessage();}
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Nova senha · EventMenu Delivery</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#fff7f1;color:#201b18;margin:0;display:grid;min-height:100vh;place-items:center}.card{width:min(92vw,520px);background:#fff;border:1px solid #f0ddd2;border-radius:24px;padding:28px;box-shadow:0 16px 60px #5b2b1518}.brand{font-weight:900;font-size:24px;color:#d62828}.alert{padding:12px;border-radius:10px;background:#fff0f0;color:#942525;margin:14px 0}.ok{background:#eefaf1;color:#166b2c}label{display:block;margin:14px 0;font-weight:700}input{display:block;width:100%;box-sizing:border-box;margin-top:7px;padding:13px;border:1px solid #d8c9c1;border-radius:10px;font:inherit}button,.button{display:inline-block;border:0;background:#d62828;color:#fff;text-decoration:none;font-weight:800;padding:13px 20px;border-radius:12px;cursor:pointer}</style></head><body><main class="card"><div class="brand">EventMenu Delivery</div><?php if($success):?><div class="alert ok">Senha atualizada com sucesso.</div><h1>Pronto!</h1><p>Volte ao aplicativo e entre com sua nova senha.</p><a class="button" href="eventmenu-delivery://login">Abrir o aplicativo</a><?php else:?><h1>Criar nova senha</h1><?php if($error):?><div class="alert"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars(Security::csrfToken(),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="token" value="<?=htmlspecialchars($token,ENT_QUOTES,'UTF-8')?>"><label>Nova senha<input type="password" name="password" minlength="8" autocomplete="new-password" required></label><button type="submit">Salvar nova senha</button></form><?php endif;?></main></body></html>
