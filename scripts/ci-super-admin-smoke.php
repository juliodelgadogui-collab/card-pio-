<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;

function super_fail(string $message): never { fwrite(STDERR,"SUPER CI FAIL: {$message}\n"); exit(1); }

$pdo=Database::connection();
$tenantId=(int)$pdo->query('SELECT id FROM tenants WHERE status="active" ORDER BY id LIMIT 1')->fetchColumn();
if($tenantId<1)super_fail('Tenant ativo não encontrado.');

$email='super-ci-'.bin2hex(random_bytes(4)).'@example.test';
$s=$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (NULL,?,?,?,"super_admin","active")');
$s->execute(['CI Super ADM',$email,'ci-not-used']);$userId=(int)$pdo->lastInsertId();

$_SESSION['user_id']=$userId;$_SESSION['tenant_id']=null;$_SESSION['role']='super_admin';$_SESSION['name']='CI Super ADM';
if(!Auth::isSuperAdmin())super_fail('Role Super ADM não reconhecida.');
if(Auth::tenantId()!==null)super_fail('Super ADM iniciou com tenant indevido.');

Auth::actAsTenant($tenantId);
if(Auth::tenantId()!==$tenantId||Auth::actingTenantId()!==$tenantId)super_fail('Contexto de tenant não foi aplicado.');

Auth::clearTenantContext();
if(Auth::tenantId()!==null)super_fail('Contexto de tenant não foi removido.');

$slug='super-suspended-'.bin2hex(random_bytes(3));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","suspended")')->execute(['Suspended CI',$slug]);$suspendedId=(int)$pdo->lastInsertId();
try{Auth::actAsTenant($suspendedId);super_fail('Super ADM entrou em tenant suspenso.');}catch(RuntimeException){}

$moduleSlug='super-modules-'.bin2hex(random_bytes(3));
$moduleSettings=json_encode([
    'business_type'=>'full',
    'modules'=>[
        'catalog'=>true,
        'pos'=>true,
        'kitchen'=>true,
        'restaurant'=>true,
        'delivery'=>false,
        'inventory'=>false,
        'customers'=>true,
        'payments'=>true,
        'events'=>true,
    ],
],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['Module Override CI',$moduleSlug,$moduleSettings]);
$moduleTenantId=(int)$pdo->lastInsertId();
if(!TenantFeatures::routeEnabled('products',$moduleTenantId))super_fail('Módulo de catálogo ativo foi bloqueado.');
if(TenantFeatures::routeEnabled('delivery',$moduleTenantId))super_fail('Módulo delivery desativado continuou acessível.');
if(TenantFeatures::routeEnabled('inventory',$moduleTenantId))super_fail('Módulo estoque desativado continuou acessível.');
if(!TenantFeatures::routeEnabled('events',$moduleTenantId))super_fail('Módulo eventos ativo foi bloqueado.');
if(!TenantFeatures::routeEnabled('orders',$moduleTenantId))super_fail('Rota central de pedidos não deve ser bloqueada por módulo.');

$legacyEventSlug='super-legacy-event-'.bin2hex(random_bytes(3));
$legacyEventSettings=json_encode(['business_type'=>'event'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['Legacy Event CI',$legacyEventSlug,$legacyEventSettings]);
$legacyEventId=(int)$pdo->lastInsertId();
if(TenantFeatures::routeEnabled('products',$legacyEventId))super_fail('Compatibilidade legada: empresa de eventos recebeu catálogo de restaurante.');
if(!TenantFeatures::routeEnabled('events',$legacyEventId))super_fail('Compatibilidade legada: empresa de eventos perdeu módulo de eventos.');
if(!TenantFeatures::routeEnabled('payments',$legacyEventId))super_fail('Compatibilidade legada: empresa de eventos perdeu pagamentos.');

echo "CI Super ADM smoke OK\n";
