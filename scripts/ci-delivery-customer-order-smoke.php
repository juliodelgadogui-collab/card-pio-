<?php

declare(strict_types=1);

putenv('APP_KEY=ci-delivery-customer-order-key-1234567890');
require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Services\DeliveryCustomerMarketplaceService;
use EventMenu\Services\DeliveryOrderSchemaGuard;
use EventMenu\Services\MarketplaceCommissionService;
use EventMenu\Services\MarketplaceEntryTokenService;

function delivery_order_fail(string $message): never
{
    fwrite(STDERR,"DELYVRE ORDER CI FAIL: {$message}\n");
    exit(1);
}

function delivery_order_assert(bool $condition,string $message):void
{
    if(!$condition)delivery_order_fail($message);
}

try{
    $pdo=Database::connection();
    $schema=file_get_contents(Database::schemaPath($pdo));
    if($schema===false)delivery_order_fail('Schema ausente.');
    $pdo->exec($schema);
    Migrator::run($pdo);

    foreach([
        'marketplace_tenant_settings','marketplace_entry_tokens','marketplace_delivery_coupons','marketplace_delivery_coupon_targets',
        'delivery_customer_accounts','delivery_customer_addresses','delivery_customer_order_links','delivery_customer_order_requests',
    ]as$table)$pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');

    // Simula upload/migration incompleta em hospedagem compartilhada. O guard deve
    // reconstruir tanto o vínculo do pedido quanto o ledger de idempotência.
    $pdo->exec('DROP TABLE delivery_customer_order_requests');
    $pdo->exec('DROP TABLE delivery_customer_order_links');
    $guard=new DeliveryOrderSchemaGuard();
    delivery_order_assert(!$guard->isReady($pdo),'Guard não detectou schema DELYVRE incompleto.');
    $guard->ensure($pdo);
    delivery_order_assert($guard->isReady($pdo),'Guard não reparou schema DELYVRE incompleto.');
    $pdo->query('SELECT account_id,tenant_id,order_id FROM delivery_customer_order_links WHERE 1=0');
    $pdo->query('SELECT account_id,tenant_id,idempotency_key,request_hash,order_id FROM delivery_customer_order_requests WHERE 1=0');

    $slug='delyvre-order-'.bin2hex(random_bytes(4));
    $settings=json_encode(['business_type'=>'full','delivery_pickup_enabled'=>true],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['Loja DELYVRE CI',$slug,$settings]);
    $tenantId=(int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,address,active) VALUES (?,"principal","Principal","Rua CI, 100",1)')->execute([$tenantId]);
    $unitId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO marketplace_tenant_settings (tenant_id,participates,status,joined_at,city,state) VALUES (?,1,"active",CURRENT_TIMESTAMP,"Bom Jesus do Itabapoana","RJ")')->execute([$tenantId]);

    $pdo->prepare('INSERT INTO categories (tenant_id,name,active,sort_order) VALUES (?,"Lanches",1,1)')->execute([$tenantId]);
    $categoryId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,sku,price_cents,stock_qty,track_stock,active) VALUES (?, ?,"Cachorro-Quente Especial","Produto DELYVRE CI","DELYVRE-CI",1690,100,0,1)')->execute([$tenantId,$categoryId]);
    $productId=(int)$pdo->lastInsertId();

    $pdo->prepare('INSERT INTO modifier_groups (tenant_id,product_id,name,required,min_select,max_select,sort_order,active) VALUES (?, ?,"Adicionais",0,0,3,1,1)')->execute([$tenantId,$productId]);
    $groupId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO modifier_options (tenant_id,group_id,name,price_delta_cents,cost_cents,inventory_quantity,production_enabled,sort_order,active) VALUES (?, ?,"Bacon",350,0,0,0,1,1)')->execute([$tenantId,$groupId]);
    $baconId=(int)$pdo->lastInsertId();

    $email='cliente-'.bin2hex(random_bytes(4)).'@example.test';
    $pdo->prepare('INSERT INTO delivery_customer_accounts (name,email,phone,password_hash,email_verified_at,status) VALUES (?,?,?,?,CURRENT_TIMESTAMP,"active")')->execute(['Cliente DELYVRE',$email,'22999991111',password_hash('Teste123!',PASSWORD_DEFAULT)]);
    $accountId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO delivery_customer_addresses (account_id,label,phone,street,number,neighborhood,city,state,is_default) VALUES (?,"Casa",?,"Cirilo Ferreira Borges","10","Centro","Bom Jesus do Itabapoana","RJ",1)')->execute([$accountId,'22999991111']);
    $addressId=(int)$pdo->lastInsertId();

    // Cupom do Super ADM sem sync manual: o primeiro uso deve materializá-lo.
    $pdo->prepare('INSERT INTO marketplace_delivery_coupons (code,name,type,value,max_discount_cents,min_order_cents,max_uses_per_tenant,scope_type,active) VALUES ("DELYVRE10","10% DELYVRE","percent",10,500,0,50,"default",1)')->execute();
    $platformCouponId=(int)$pdo->lastInsertId();
    delivery_order_assert($platformCouponId>0,'Cupom do Super ADM não foi criado.');
    $before=$pdo->prepare('SELECT id FROM coupons WHERE tenant_id=? AND UPPER(code)="DELYVRE10" LIMIT 1');$before->execute([$tenantId]);
    delivery_order_assert($before->fetchColumn()===false,'Teste inválido: cupom local já existia antes do primeiro uso.');

    $service=new DeliveryCustomerMarketplaceService();
    $quote=$service->couponQuote($pdo,$accountId,$tenantId,'delyvre10',1690);
    delivery_order_assert((string)($quote['code']??'')==='DELYVRE10','Quote não normalizou o código do cupom.');
    delivery_order_assert((int)($quote['discount_cents']??0)===169,'Quote não calculou 10% corretamente.');
    $mapped=$pdo->prepare('SELECT c.id,c.active,t.marketplace_coupon_id FROM coupons c JOIN marketplace_delivery_coupon_targets t ON t.coupon_id=c.id AND t.tenant_id=c.tenant_id WHERE c.tenant_id=? AND UPPER(c.code)="DELYVRE10" LIMIT 1');$mapped->execute([$tenantId]);$materialized=$mapped->fetch();
    delivery_order_assert(is_array($materialized),'Cupom do Super ADM não foi sincronizado automaticamente para a empresa.');
    delivery_order_assert((int)$materialized['active']===1,'Cupom sincronizado ficou inativo.');
    delivery_order_assert((int)$materialized['marketplace_coupon_id']===$platformCouponId,'Vínculo com o cupom do Super ADM ficou incorreto.');

    // Entrega tradicional continua funcionando.
    $entry=(new MarketplaceEntryTokenService())->issue($pdo,$tenantId,$unitId,null);
    $deliveryPayload=[
        'entry_token'=>(string)$entry['token'],'client_request_id'=>'delivery-ci-1','fulfillment'=>'delivery','address_id'=>$addressId,'coupon_code'=>'delyvre10',
        'items'=>[['product_id'=>$productId,'quantity'=>1,'option_ids'=>[],'notes'=>'']],
    ];
    $order=Database::transaction(fn(PDO $tx):array=>$service->createOrder($tx,$accountId,$deliveryPayload));
    $orderId=(int)($order['order_number']??0);
    delivery_order_assert($orderId>0,'Pedido autenticado não retornou número válido.');
    delivery_order_assert((int)($order['total_cents']??0)===1521,'Pedido não recebeu o desconto do cupom.');
    delivery_order_assert((int)($order['discount_cents']??0)===169,'Desconto não voltou no resumo do pedido.');
    delivery_order_assert((string)($order['channel']??'')==='delivery','Pedido de entrega perdeu o canal delivery.');
    delivery_order_assert(empty($order['idempotent_replay']),'Primeira criação foi marcada como replay.');

    // Retry com a MESMA chave não cria outro pedido, mesmo com token já consumido.
    $replayed=Database::transaction(fn(PDO $tx):array=>$service->createOrder($tx,$accountId,$deliveryPayload));
    delivery_order_assert((int)($replayed['order_number']??0)===$orderId,'Retry idempotente criou outro pedido.');
    delivery_order_assert(!empty($replayed['idempotent_replay']),'Retry idempotente não foi identificado como replay.');
    $count=$pdo->prepare('SELECT COUNT(*) FROM orders WHERE tenant_id=? AND order_source=?');$count->execute([$tenantId,MarketplaceCommissionService::ORDER_SOURCE]);
    delivery_order_assert((int)$count->fetchColumn()===1,'Idempotência não impediu duplicação de pedido.');

    $saved=$pdo->prepare('SELECT channel,order_source,coupon_id,discount_cents,total_cents FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$saved->execute([$orderId,$tenantId]);$row=$saved->fetch();
    delivery_order_assert(is_array($row),'Pedido não foi persistido.');
    delivery_order_assert((string)$row['order_source']===MarketplaceCommissionService::ORDER_SOURCE,'Pedido DELYVRE perdeu a origem do marketplace.');
    delivery_order_assert((int)$row['coupon_id']>0,'Pedido foi salvo sem o cupom.');
    delivery_order_assert((int)$row['discount_cents']===169,'Desconto persistido ficou incorreto.');

    // Retirada real: sem address_id, sem taxa de entrega, com adicionais e observação.
    $pickupEntry=(new MarketplaceEntryTokenService())->issue($pdo,$tenantId,$unitId,null);
    $pickupPayload=[
        'entry_token'=>(string)$pickupEntry['token'],'client_request_id'=>'pickup-ci-1','fulfillment'=>'pickup',
        'items'=>[['product_id'=>$productId,'quantity'=>2,'option_ids'=>[$baconId],'notes'=>'Sem ketchup']],
    ];
    $pickup=Database::transaction(fn(PDO $tx):array=>$service->createOrder($tx,$accountId,$pickupPayload));
    $pickupId=(int)($pickup['order_number']??0);
    delivery_order_assert($pickupId>0,'Retirada não criou pedido.');
    delivery_order_assert((string)($pickup['channel']??'')==='pickup','Retirada não foi gravada no canal pickup.');
    delivery_order_assert((string)($pickup['fulfillment']??'')==='pickup','Resposta não informou fulfillment pickup.');
    delivery_order_assert((int)($pickup['delivery_fee_cents']??-1)===0,'Retirada recebeu taxa de entrega.');
    delivery_order_assert((string)($pickup['items'][0]['notes']??'')==='Sem ketchup','Observação da retirada não foi preservada.');
    delivery_order_assert((int)($pickup['items'][0]['modifiers'][0]['option_id']??0)===$baconId,'Adicional da retirada não foi persistido/devolvido.');

    $pickupReplay=Database::transaction(fn(PDO $tx):array=>$service->createOrder($tx,$accountId,$pickupPayload));
    delivery_order_assert((int)($pickupReplay['order_number']??0)===$pickupId,'Retry da retirada criou pedido duplicado.');
    delivery_order_assert(!empty($pickupReplay['idempotent_replay']),'Retry da retirada não retornou replay.');

    // Pedir novamente deve recuperar o adicional por ID e a observação, mas sempre
    // usando catálogo/preços atuais e filtrando opções que já não estiverem ativas.
    $repeat=$service->reorder($pdo,$accountId,$pickupId);
    delivery_order_assert((string)($repeat['preferred_fulfillment']??'')==='pickup','Reorder não preservou preferência de retirada disponível.');
    delivery_order_assert(count((array)($repeat['items']??[]))===1,'Reorder não retornou item disponível.');
    delivery_order_assert((int)($repeat['items'][0]['option_ids'][0]??0)===$baconId,'Reorder perdeu adicional ativo.');
    delivery_order_assert((string)($repeat['items'][0]['notes']??'')==='Sem ketchup','Reorder perdeu observação histórica.');

    $pdo->prepare('UPDATE modifier_options SET active=0 WHERE id=? AND tenant_id=?')->execute([$baconId,$tenantId]);
    $repeatWithoutBacon=$service->reorder($pdo,$accountId,$pickupId);
    delivery_order_assert(empty($repeatWithoutBacon['items'][0]['option_ids']??[]),'Reorder restaurou adicional que já está inativo.');
    delivery_order_assert(!empty($repeatWithoutBacon['warnings']),'Reorder não avisou sobre adicional indisponível.');

    $reservation=$pdo->prepare('SELECT discount_cents,status FROM coupon_reservations WHERE tenant_id=? AND order_id=? LIMIT 1');$reservation->execute([$tenantId,$orderId]);$reserved=$reservation->fetch();
    delivery_order_assert(is_array($reserved),'Reserva do cupom não foi criada.');
    delivery_order_assert((int)$reserved['discount_cents']===169&&(string)$reserved['status']==='reserved','Reserva do cupom ficou inconsistente.');

    echo "DELYVRE delivery + pickup + idempotency + modifier-aware reorder + coupon/schema smoke OK\n";
}catch(Throwable $e){
    delivery_order_fail($e::class.': '.$e->getMessage()."\n".$e->getTraceAsString());
}
