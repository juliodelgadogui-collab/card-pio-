<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\OperatingUnitService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');

if (!Auth::check() || !Auth::tenantId()) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Não autenticado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tenantId=(int)Auth::tenantId();
    $unit=(new OperatingUnitService())->requireCurrent();
    $unitId=(int)$unit['id'];
    $pdo=Database::connection();

    $aggregate=$pdo->prepare('SELECT
        COALESCE(SUM(CASE WHEN DATE(created_at)=DATE(CURRENT_TIMESTAMP) THEN 1 ELSE 0 END),0) orders_today,
        COALESCE(SUM(CASE WHEN payment_status="paid" AND DATE(created_at)=DATE(CURRENT_TIMESTAMP) THEN total_cents ELSE 0 END),0) sales_today,
        COALESCE(SUM(CASE WHEN payment_status="paid" AND DATE(created_at)=DATE(CURRENT_TIMESTAMP) THEN 1 ELSE 0 END),0) paid_today,
        COALESCE(SUM(CASE WHEN status IN ("confirmed","preparing","ready","out_for_delivery") THEN 1 ELSE 0 END),0) active_orders,
        COALESCE(SUM(CASE WHEN status="preparing" THEN 1 ELSE 0 END),0) preparing,
        COALESCE(SUM(CASE WHEN status="ready" THEN 1 ELSE 0 END),0) ready,
        COALESCE(SUM(CASE WHEN status="out_for_delivery" THEN 1 ELSE 0 END),0) delivery
        FROM orders WHERE tenant_id=? AND unit_id=?');
    $aggregate->execute([$tenantId,$unitId]);
    $stats=$aggregate->fetch() ?: [];

    $active=$pdo->prepare('SELECT status,created_at FROM orders WHERE tenant_id=? AND unit_id=? AND status IN ("confirmed","preparing","ready","out_for_delivery")');
    $active->execute([$tenantId,$unitId]);
    $overdue=0;
    foreach($active->fetchAll() as $row){
        $time=strtotime((string)$row['created_at']);if($time===false)continue;
        $minutes=max(0,intdiv(time()-$time,60));
        $limit=match((string)$row['status']){'preparing'=>30,'ready'=>15,'out_for_delivery'=>60,default=>20};
        if($minutes >= $limit)$overdue++;
    }

    $low=$pdo->prepare('SELECT COUNT(*) FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.active=1 AND p.track_stock=1 AND COALESCE(ui.stock_qty,0)<=COALESCE(ui.min_stock_qty,p.min_stock_qty,0)');
    $low->execute([$unitId,$tenantId]);

    $pending=$pdo->prepare('SELECT COUNT(*) FROM payments p JOIN orders o ON o.id=p.order_id AND o.tenant_id=p.tenant_id WHERE p.tenant_id=? AND o.unit_id=? AND p.status IN ("created","pending","authorized")');
    $pending->execute([$tenantId,$unitId]);

    $tables=$pdo->prepare('SELECT
        COALESCE(SUM(CASE WHEN status="occupied" THEN 1 ELSE 0 END),0) occupied_tables
        FROM restaurant_tables WHERE tenant_id=? AND unit_id=?');
    $tables->execute([$tenantId,$unitId]);
    $tableStats=$tables->fetch() ?: [];

    $tabs=$pdo->prepare('SELECT COUNT(*) FROM tabs t JOIN restaurant_tables rt ON rt.id=t.table_id AND rt.tenant_id=t.tenant_id WHERE t.tenant_id=? AND rt.unit_id=? AND t.status="open"');
    $tabs->execute([$tenantId,$unitId]);

    $sales=(int)($stats['sales_today']??0);$paid=(int)($stats['paid_today']??0);
    echo json_encode(['ok'=>true,'data'=>[
        'unit_id'=>$unitId,
        'orders'=>(int)($stats['orders_today']??0),
        'sales'=>$sales,
        'paid_orders'=>$paid,
        'ticket_average'=>$paid>0?(int)round($sales/$paid):0,
        'active_orders'=>(int)($stats['active_orders']??0),
        'preparing'=>(int)($stats['preparing']??0),
        'ready'=>(int)($stats['ready']??0),
        'delivery'=>(int)($stats['delivery']??0),
        'overdue'=>$overdue,
        'pending_payments'=>(int)$pending->fetchColumn(),
        'low_stock'=>(int)$low->fetchColumn(),
        'occupied_tables'=>(int)($tableStats['occupied_tables']??0),
        'open_tabs'=>(int)$tabs->fetchColumn(),
        'updated_at'=>date(DATE_ATOM),
    ]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'Não foi possível atualizar o painel agora.'], JSON_UNESCAPED_UNICODE);
}
