<?php

declare(strict_types=1);

$path=dirname(__DIR__).'/src/Services/ProductionService.php';
$source=file_get_contents($path);
if($source===false){fwrite(STDERR,"PRODUCTION TENANT CI FAIL: não foi possível ler ProductionService.php\n");exit(1);}
function production_tenant_assert(bool$ok,string$message):void{if(!$ok){fwrite(STDERR,"PRODUCTION TENANT CI FAIL: {$message}\n");exit(1);}}

production_tenant_assert(str_contains($source,'JOIN production_stations ps ON ps.id=j.station_id AND ps.tenant_id=j.tenant_id'),'Board/produção precisa vincular estação ao tenant do job.');
production_tenant_assert(str_contains($source,'JOIN orders o ON o.id=j.order_id AND o.tenant_id=j.tenant_id'),'Produção precisa vincular pedido ao tenant do job.');
production_tenant_assert(str_contains($source,'LEFT JOIN restaurant_tables rt ON rt.id=o.table_id AND rt.tenant_id=o.tenant_id'),'Mesa precisa ser resolvida no tenant do pedido.');
production_tenant_assert(str_contains($source,'LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id'),'Cliente precisa ser resolvido no tenant do pedido.');
production_tenant_assert(str_contains($source,'WHERE id=? AND tenant_id=? AND order_id=? AND status="ready"'),'Expedição precisa mutar o job com tenant + pedido + estado esperado.');
production_tenant_assert(str_contains($source,'UPDATE production_jobs SET change_version=change_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND order_id=?'),'Mudança do pedido precisa atualizar jobs dentro do tenant/pedido.');
production_tenant_assert(str_contains($source,'JOIN production_stations ps ON ps.id=q.station_id AND ps.tenant_id=q.tenant_id AND ps.unit_id=q.unit_id'),'Fila de impressão precisa resolver estação no mesmo tenant/unidade.');

echo "CI production tenant contract OK\n";
