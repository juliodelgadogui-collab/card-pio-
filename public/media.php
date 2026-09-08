<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

$tenantId = (int)($_GET['t'] ?? 0);
$name = (string)($_GET['n'] ?? '');
if ($tenantId < 1 || !preg_match('/^(brand-logo|menu-logo|menu-cover)-[a-f0-9]{24}\.(jpg|png|webp)$/', $name)) {
    http_response_code(404);exit;
}
$path = dirname(__DIR__).'/storage/media/'.$tenantId.'/'.$name;
if (!is_file($path) || !is_readable($path)) {http_response_code(404);exit;}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$mime = ['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext] ?? 'application/octet-stream';
header('Content-Type: '.$mime);
header('Content-Length: '.(string)filesize($path));
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
readfile($path);
