<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\Payments\SumUpProvider;

if(!Auth::check()){
    $_SESSION['_flash']=['error','Entre novamente para concluir a conexão SumUp.'];
    header('Location: '.app_url('?route=login'),true,302);
    exit;
}

$state=trim((string)($_GET['state']??''));
$code=trim((string)($_GET['code']??''));
$error=trim((string)($_GET['error']??''));
$errorDescription=trim((string)($_GET['error_description']??''));

try{
    if($error!=='')throw new RuntimeException($errorDescription!==''?$errorDescription:'A autorização SumUp foi cancelada.');
    (new SumUpProvider())->completeAuthorization($state,$code);
    $_SESSION['_flash']=['ok','Conta SumUp conectada. Agora selecione SumUp como provedor de aproximação.'];
}catch(Throwable$e){
    $_SESSION['_flash']=['error','Não foi possível concluir a SumUp: '.$e->getMessage()];
}

header('Location: '.app_url('?route=payment-providers'),true,302);
exit;
