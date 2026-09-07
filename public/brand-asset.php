<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\TenantBrandingService;

$tenantId=(int)($_GET['tenant']??0);
if($tenantId<1){http_response_code(404);exit;}
$file=(new TenantBrandingService())->logoFile($tenantId);
if(!$file){http_response_code(404);exit;}
header('Content-Type: '.$file['mime']);
header('Content-Length: '.filesize($file['path']));
header('Cache-Control: public,max-age=86400,immutable');
header('X-Content-Type-Options: nosniff');
readfile($file['path']);
