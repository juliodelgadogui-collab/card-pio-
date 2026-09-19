<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\DeliveryCustomerAuthService;

header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
$ok=false;$message='Este link é inválido ou expirou.';$token=trim((string)($_GET['token']??''));
if($token!==''){
    try{Database::transaction(fn(PDO$pdo)=> (new DeliveryCustomerAuthService())->verifyEmail($pdo,$token));$ok=true;$message='Seu e-mail foi confirmado. Agora você já pode entrar no EventMenu Delivery.';}
    catch(Throwable$e){$message=$e->getMessage()?:$message;}
}
$deep=$ok?'eventmenu-delivery://email-confirmed':'';
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>EventMenu Delivery</title><style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#fff7f1;color:#201b18;margin:0;display:grid;min-height:100vh;place-items:center}.card{width:min(92vw,520px);background:#fff;border:1px solid #f0ddd2;border-radius:24px;padding:28px;box-shadow:0 16px 60px #5b2b1518;text-align:center}.brand{font-weight:900;font-size:24px;color:#d62828}.icon{font-size:54px;margin:18px 0}.button{display:inline-block;margin-top:18px;background:#d62828;color:#fff;text-decoration:none;font-weight:800;padding:13px 20px;border-radius:12px}.muted{color:#75665e}</style></head><body><main class="card"><div class="brand">EventMenu Delivery</div><div class="icon"><?= $ok?'✅':'⚠️' ?></div><h1><?= $ok?'E-mail confirmado':'Não foi possível confirmar' ?></h1><p><?= htmlspecialchars($message,ENT_QUOTES,'UTF-8') ?></p><?php if($ok):?><a class="button" href="<?=htmlspecialchars($deep,ENT_QUOTES,'UTF-8')?>">Abrir o aplicativo</a><p class="muted">Se o app não abrir automaticamente, abra o EventMenu Delivery e entre com seu e-mail e senha.</p><?php else:?><p class="muted">Abra o aplicativo e use “Reenviar confirmação” para receber um novo link.</p><?php endif;?></main><?php if($ok):?><script>setTimeout(()=>{location.href=<?=json_encode($deep)?>},800)</script><?php endif;?></body></html>
