<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Services\SystemHealthService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');

try{
    Auth::enforceCurrentUser();
    if(!Auth::check()||!Auth::isSuperAdmin()||!Auth::can('platform.manage')){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Acesso restrito.'],JSON_UNESCAPED_UNICODE);exit;}
    $pdo=Database::connection();
    $scalar=static function(string$sql)use($pdo):int{try{return(int)$pdo->query($sql)->fetchColumn();}catch(Throwable){return 0;}};
    $mrr=0;try{$mrr=(int)$pdo->query('SELECT COALESCE(SUM(CASE WHEN ts.billing_cycle="yearly" THEN COALESCE(ts.custom_price_cents,p.yearly_cents)/12 ELSE COALESCE(ts.custom_price_cents,p.monthly_cents) END),0) FROM tenant_subscriptions ts JOIN saas_plans p ON p.id=ts.plan_id JOIN tenants t ON t.id=ts.tenant_id WHERE ts.status="active" AND t.status="active"')->fetchColumn();}catch(Throwable){}
    $health=['overall'=>'warning','checks'=>[]];try{$health=(new SystemHealthService())->snapshot();}catch(Throwable){}
    $healthWarnings=0;foreach((array)($health['checks']??[])as$check)if(in_array((string)($check['state']??''),['warning','error'],true))$healthWarnings++;
    $data=[
        'mrr_cents'=>$mrr,
        'companies_total'=>$scalar('SELECT COUNT(*) FROM tenants'),
        'companies_active'=>$scalar('SELECT COUNT(*) FROM tenants WHERE status="active"'),
        'companies_suspended'=>$scalar('SELECT COUNT(*) FROM tenants WHERE status="suspended"'),
        'processed_cents'=>$scalar('SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE payment_status="paid"'),
        'delivery_active'=>$scalar('SELECT COUNT(*) FROM marketplace_tenant_settings WHERE participates=1 AND status="active"'),
        'commissions_due_cents'=>$scalar('SELECT COALESCE(SUM(commission_cents),0) FROM marketplace_order_commissions WHERE status IN ("due","invoiced")'),
        'campaigns_active'=>$scalar('SELECT COUNT(*) FROM marketplace_campaign_assignments WHERE active=1'),
        'impulsiona_companies'=>$scalar('SELECT COUNT(*) FROM marketplace_promotion_accounts WHERE participates=1'),
        'impulsiona_balance_cents'=>$scalar('SELECT COALESCE(SUM(balance_cents),0) FROM marketplace_promotion_accounts WHERE participates=1'),
        'invoices_open'=>$scalar('SELECT COUNT(*) FROM platform_invoices WHERE status="open"'),
        'invoices_overdue'=>$scalar('SELECT COUNT(*) FROM platform_invoices WHERE status="overdue"'),
        'overdue_cents'=>$scalar('SELECT COALESCE(SUM(total_cents),0) FROM platform_invoices WHERE status="overdue"'),
        'health'=>(string)($health['overall']??'warning'),
        'health_warnings'=>$healthWarnings,
    ];
    echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable$e){error_log('[platform-cockpit] '.$e::class.': '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Não foi possível carregar o cockpit agora.'],JSON_UNESCAPED_UNICODE);}
