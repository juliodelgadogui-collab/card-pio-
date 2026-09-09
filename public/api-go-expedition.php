<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\ApiAuthService;
use EventMenu\Services\ExpeditionDeliveryService;
use EventMenu\Services\WorkShiftService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: GET, OPTIONS');http_response_code(204);exit;}

function goe_out(array $data,int $status=200):never{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if($_SERVER['REQUEST_METHOD']!=='GET')goe_out(['ok'=>false,'error'=>'Método não permitido.'],405);

    $auth=new ApiAuthService();
    $token=ApiAuthService::bearerToken();
    if($token==='')goe_out(['ok'=>false,'error'=>'Token Bearer obrigatório.'],401);
    $user=$auth->authenticate($token,ApiAuthService::deviceId());
    $tenantId=(int)$user['tenant_id'];

    if(!Auth::can('orders.dispatch')&&!Auth::can('delivery.assign')&&!Auth::can('orders.view')&&!Auth::can('orders.manage')){
        goe_out(['ok'=>false,'error'=>'Acesso negado à expedição.'],403);
    }

    $shift=(new WorkShiftService())->current();
    if(!$shift||$shift['mode']!=='operation')throw new RuntimeException('Inicie um turno de Operação para acompanhar a expedição.');
    $unitId=isset($shift['unit_id'])&&$shift['unit_id']!==null?(int)$shift['unit_id']:null;
    if(!$unitId)throw new RuntimeException('Selecione uma unidade para acompanhar a expedição.');

    $action=(string)($_GET['action']??'status');
    if($action!=='status')goe_out(['ok'=>false,'error'=>'Endpoint de expedição não encontrado.'],404);

    $sql='SELECT o.id,o.channel,o.status,o.assigned_delivery_user_id
          FROM orders o
          WHERE o.tenant_id=? AND o.unit_id=? AND o.channel="delivery"
            AND o.status NOT IN ("cancelled","completed")
          ORDER BY o.id DESC LIMIT 200';
    $s=Database::connection()->prepare($sql);
    $s->execute([$tenantId,$unitId]);
    $rows=(new ExpeditionDeliveryService())->enrich($s->fetchAll());

    $status=array_map(static function(array$row):array{
        return [
            'order_id'=>(int)$row['id'],
            'stage'=>(string)($row['delivery_stage']??''),
            'delivery_user_id'=>$row['delivery_user_id']!==null?(int)$row['delivery_user_id']:null,
            'delivery_name'=>(string)($row['delivery_name']??''),
            'picked_up'=>!empty($row['delivery_picked_up']),
            'route_started'=>!empty($row['delivery_route_started']),
            'arrived'=>!empty($row['delivery_arrived']),
            'completed'=>!empty($row['delivery_completed']),
            'location_fresh'=>!empty($row['delivery_location_fresh']),
        ];
    },$rows);

    goe_out(['ok'=>true,'delivery'=>$status]);
}catch(RuntimeException$e){
    goe_out(['ok'=>false,'error'=>$e->getMessage()],422);
}catch(Throwable$e){
    goe_out([
        'ok'=>false,
        'error'=>filter_var(env('APP_DEBUG','false'),FILTER_VALIDATE_BOOL)?$e->getMessage():'Erro interno.'
    ],500);
}
