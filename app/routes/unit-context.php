<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Services\OperatingUnitService;

Auth::requirePermission('dashboard');
$tenantId=em_require_tenant();
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Método não permitido.');}
em_post_csrf();
try{
    (new OperatingUnitService())->selectCurrent((int)($_POST['unit_id']??0));
    em_flash('ok','Unidade de operação alterada.');
}catch(Throwable $e){em_flash('error',$e->getMessage());}
$back=(string)($_POST['back_route']??'dashboard');
$allowed=array_map(static fn($row)=>(string)$row[0],em_nav());
if(!in_array($back,$allowed,true))$back='dashboard';
em_go($back);
