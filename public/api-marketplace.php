<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\MarketplaceCatalogService;
use EventMenu\Services\MarketplaceConsumerService;
use EventMenu\Services\MarketplaceEntryTokenService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, POST, OPTIONS');
    http_response_code(204);
    exit;
}

function marketplace_out(array $data, int $status=200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function marketplace_body(): array
{
    $raw=file_get_contents('php://input');
    if($raw===false||trim($raw)==='')return $_POST?:[];
    try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);return is_array($data)?$data:[];}
    catch(Throwable){marketplace_out(['ok'=>false,'message'=>'Não foi possível ler os dados enviados. Tente novamente.'],400);}
}

function marketplace_error(Throwable $e): string
{
    $message=trim($e->getMessage());
    if($message==='')return 'Não foi possível concluir agora. Tente novamente.';
    $technical=['sqlstate','select ','insert ','update ','delete ','http 4','http 5','json','payload','endpoint','webhook','provider','stack trace','exception','pdo','sqlite','mysql','database','tenant','token'];
    $lower=mb_strtolower($message);
    foreach($technical as$term)if(str_contains($lower,$term))return 'Não foi possível concluir agora. Tente novamente.';
    return mb_substr($message,0,220);
}

try {
    $pdo=Database::connection();
    $action=strtolower(trim((string)($_GET['action']??'stores')));
    $rate=new ApiRateLimitService();
    $catalog=new MarketplaceCatalogService();
    $consumer=new MarketplaceConsumerService();

    if($action==='stores'){
        if($_SERVER['REQUEST_METHOD']!=='GET')marketplace_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $rate->assertAllowed('marketplace.stores',$rate->requestSubject('stores'),120,60,'Muitas atualizações em pouco tempo. Aguarde alguns segundos.');
        marketplace_out(['ok'=>true,'stores'=>$catalog->stores($pdo,$_GET)]);
    }

    if($action==='catalog'){
        if($_SERVER['REQUEST_METHOD']!=='GET')marketplace_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $tenantId=(int)($_GET['tenant_id']??0);$unitId=(int)($_GET['unit_id']??0);
        if($tenantId<1||$unitId<1)marketplace_out(['ok'=>false,'message'=>'Escolha uma loja para continuar.'],422);
        $rate->assertAllowed('marketplace.catalog',$rate->requestSubject($tenantId.':'.$unitId),90,60,'Muitas atualizações em pouco tempo. Aguarde alguns segundos.');
        $data=$catalog->catalog($pdo,$tenantId,$unitId);
        // Campanhas financeiras são resolvidas pelo servidor/ADM Geral. O consumidor não escolhe
        // campaign_code pela URL, evitando manipulação da taxa de comissão do restaurante.
        $entry=(new MarketplaceEntryTokenService())->issue($pdo,$tenantId,$unitId,null);
        $data['checkout_session']=$entry;
        marketplace_out(['ok'=>true]+$data);
    }

    if($action==='order-create'){
        if($_SERVER['REQUEST_METHOD']!=='POST')marketplace_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $body=marketplace_body();$entryToken=trim((string)($body['entry_token']??''));
        if($entryToken==='')marketplace_out(['ok'=>false,'message'=>'Atualize a loja antes de finalizar o pedido.'],409);
        $rate->assertAllowed('marketplace.order.create',$rate->requestSubject('order'),8,600,'Muitos pedidos foram enviados deste aparelho. Aguarde alguns minutos.');
        $order=Database::transaction(fn(PDO $tx):array=>$consumer->createOrder($tx,$entryToken,$body));
        marketplace_out(['ok'=>true,'order'=>$order],201);
    }

    if($action==='order-status'){
        if($_SERVER['REQUEST_METHOD']!=='GET')marketplace_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $publicToken=trim((string)($_GET['t']??''));
        $rate->assertAllowed('marketplace.order.status',$rate->requestSubject(substr($publicToken,0,12)),180,60,'A atualização está muito rápida. Aguarde alguns segundos.');
        marketplace_out(['ok'=>true,'order'=>$consumer->publicOrderByToken($pdo,$publicToken)]);
    }

    if($action==='tracking'){
        if($_SERVER['REQUEST_METHOD']!=='GET')marketplace_out(['ok'=>false,'message'=>'Ação indisponível.'],405);
        $trackingToken=trim((string)($_GET['token']??''));
        $rate->assertAllowed('marketplace.tracking',$rate->requestSubject(substr(hash('sha256',$trackingToken),0,16)),120,60,'A localização está sendo atualizada rápido demais. Aguarde alguns segundos.');
        marketplace_out(['ok'=>true,'tracking'=>$consumer->trackingStatus($trackingToken)]);
    }

    marketplace_out(['ok'=>false,'message'=>'Ação não encontrada.'],404);
} catch (ApiRateLimitExceededException $e) {
    marketplace_out(['ok'=>false,'message'=>marketplace_error($e)],429);
} catch (RuntimeException $e) {
    marketplace_out(['ok'=>false,'message'=>marketplace_error($e)],422);
} catch (Throwable $e) {
    error_log('[eventmenu-marketplace] '.$e::class.': '.$e->getMessage());
    marketplace_out(['ok'=>false,'message'=>'Não foi possível concluir agora. Tente novamente.'],500);
}
