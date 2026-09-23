<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\DeliveryPublicTrackingService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Access-Control-Allow-Methods: GET');

function delivery_track_out(array$data,int$status=200):never{http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

if($_SERVER['REQUEST_METHOD']!=='GET')delivery_track_out(['ok'=>false,'error'=>'Método não permitido.'],405);
try{
    $token=trim((string)($_GET['token']??''));
    $tracking=(new DeliveryPublicTrackingService())->publicStatus($token);
    delivery_track_out(['ok'=>true,'tracking'=>$tracking]);
}catch(RuntimeException){delivery_track_out(['ok'=>false,'error'=>'Acompanhamento indisponível ou expirado.'],404);}catch(Throwable){delivery_track_out(['ok'=>false,'error'=>'Não foi possível atualizar a entrega.'],500);}
