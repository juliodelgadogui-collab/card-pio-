<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
use EventMenu\Services\DeliveryTrackingService;
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
$token=strtolower(trim((string)($_GET['t']??'')));$row=(new DeliveryTrackingService())->latestPublic($token);
if(!$row){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Rastreamento indisponível.'],JSON_UNESCAPED_UNICODE);exit;}
echo json_encode(['ok'=>true,'tracking'=>['order_status'=>$row['status'],'latitude'=>$row['latitude']!==null?(float)$row['latitude']:null,'longitude'=>$row['longitude']!==null?(float)$row['longitude']:null,'accuracy_m'=>$row['accuracy_m']!==null?(float)$row['accuracy_m']:null,'captured_at'=>$row['captured_at']]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);