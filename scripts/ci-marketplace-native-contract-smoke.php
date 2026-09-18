<?php

declare(strict_types=1);

putenv('APP_KEY=ci-marketplace-native-key-1234567890');
require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Migrator;
use EventMenu\Services\MarketplaceCatalogService;
use EventMenu\Services\MarketplaceCommissionService;
use EventMenu\Services\MarketplaceConsumerService;
use EventMenu\Services\MarketplaceEntryTokenService;

function native_fail(string $message): never { fwrite(STDERR,"MARKETPLACE NATIVE CI FAIL: {$message}\n"); exit(1); }
function native_assert(bool $condition,string $message): void { if(!$condition) native_fail($message); }

try {
    $pdo=Database::connection();
    $schema=file_get_contents(Database::schemaPath($pdo));
    if($schema===false)native_fail('Schema ausente.');
    $pdo->exec($schema);Migrator::run($pdo);
    foreach(['marketplace_tenant_settings','marketplace_order_commissions','marketplace_entry_tokens'] as $table){$pdo->query('SELECT 1 FROM '.$table.' LIMIT 1');}

    $slug='native-market-'.bin2hex(random_bytes(4));
    $pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Loja Native CI',$slug]);
    $tenantId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO operating_units (tenant_id,code,name,address,active) VALUES (?,"principal","Principal","Rua CI, 100",1)')->execute([$tenantId]);
    $unitId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO marketplace_tenant_settings (tenant_id,participates,status,joined_at,city,state) VALUES (?,1,"active",CURRENT_TIMESTAMP,"Bom Jesus","RJ")')->execute([$tenantId]);
    $pdo->prepare('INSERT INTO categories (tenant_id,name,active,sort_order) VALUES (?,"Lanches",1,1)')->execute([$tenantId]);
    $categoryId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (tenant_id,category_id,name,description,sku,price_cents,stock_qty,track_stock,active) VALUES (?, ?,"Hambúrguer CI","Produto do contrato nativo","NATIVE-CI",2500,100,0,1)')->execute([$tenantId,$categoryId]);
    $productId=(int)$pdo->lastInsertId();

    $catalogService=new MarketplaceCatalogService();
    $stores=$catalogService->stores($pdo,['q'=>'Loja Native']);
    native_assert(count($stores)===1,'Loja participante não apareceu no marketplace.');
    native_assert((int)$stores[0]['tenant_id']===$tenantId&&((int)$stores[0]['unit_id']===$unitId),'Loja/unidade incorreta na descoberta.');
    $catalog=$catalogService->catalog($pdo,$tenantId,$unitId);
    native_assert(count($catalog['products'])===1,'Catálogo seguro não retornou produto ativo.');
    native_assert(!array_key_exists('stock_qty',$catalog['products'][0]),'Catálogo público vazou estoque bruto.');
    native_assert(!array_key_exists('cost_cents',$catalog['products'][0]),'Catálogo público vazou custo interno.');

    $tokens=new MarketplaceEntryTokenService();
    $entry=$tokens->issue($pdo,$tenantId,$unitId,null);
    native_assert(!empty($entry['token'])&&str_contains((string)$entry['token'],'.'),'Token de checkout não foi emitido.');
    $tampered=substr((string)$entry['token'],0,-1).(((string)$entry['token'])[-1]==='a'?'b':'a');
    $tamperedBlocked=false;try{$tokens->decodeAndVerify($tampered);}catch(Throwable){$tamperedBlocked=true;}
    native_assert($tamperedBlocked,'Token adulterado foi aceito.');

    $consumer=new MarketplaceConsumerService();
    $order=Database::transaction(fn(PDO $tx)=>$consumer->createOrder($tx,(string)$entry['token'],[
        'items'=>[['product_id'=>$productId,'quantity'=>2,'option_ids'=>[]]],
        'name'=>'Cliente Native','phone'=>'22999991111','address'=>'Rua do Cliente, 50',
    ]));
    native_assert((int)$order['total_cents']===5000,'Pedido nativo retornou total incorreto.');
    $s=$pdo->prepare('SELECT order_source,channel FROM orders WHERE public_token=?');$s->execute([$order['public_token']]);$saved=$s->fetch();
    native_assert(($saved['order_source']??'')===MarketplaceCommissionService::ORDER_SOURCE,'Pedido nativo não foi marcado como EVENTMENU_DELIVERY.');
    native_assert(($saved['channel']??'')==='delivery','Pedido do marketplace não entrou no canal operacional de delivery.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM marketplace_order_commissions mc JOIN orders o ON o.id=mc.order_id WHERE o.public_token=?');$s->execute([$order['public_token']]);
    native_assert((int)$s->fetchColumn()===1,'Pedido nativo não provisionou comissão na mesma operação.');

    $replayBlocked=false;try{Database::transaction(fn(PDO $tx)=>$consumer->createOrder($tx,(string)$entry['token'],[
        'items'=>[['product_id'=>$productId,'quantity'=>1]],'name'=>'Replay','phone'=>'22999992222','address'=>'Rua Replay, 1'
    ]));}catch(Throwable){$replayBlocked=true;}
    native_assert($replayBlocked,'Token de checkout foi reutilizado para criar um segundo pedido.');

    $status=$consumer->publicOrderByToken($pdo,(string)$order['public_token']);
    native_assert((int)$status['order_number']>0&&$status['status_label']==='Pedido recebido','Contrato público do pedido ficou inconsistente.');
    native_assert(!array_key_exists('tenant_id',$status),'Contrato consumidor vazou tenant_id.');
    native_assert(!array_key_exists('commission_cents',$status),'Contrato consumidor vazou comissão.');

    $pdo->prepare('UPDATE marketplace_tenant_settings SET participates=0,status="inactive" WHERE tenant_id=?')->execute([$tenantId]);
    $blocked=false;try{$catalogService->catalog($pdo,$tenantId,$unitId);}catch(Throwable){$blocked=true;}
    native_assert($blocked,'Empresa fora do marketplace continuou expondo catálogo nativo.');

    echo "Marketplace native contract smoke OK\n";
} catch(Throwable $e) {
    native_fail($e->getMessage()."\n".$e->getTraceAsString());
}
