<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\OrderSchedulingService;
use EventMenu\Services\PublicOrderService;

function assert_schedule(bool $condition,string $message):void{
    if(!$condition){fwrite(STDERR,"Scheduling integration failed: {$message}\n");exit(1);}
}

$pdo=Database::connection();
$hours=[];for($day=1;$day<=7;$day++)$hours[(string)$day]=['enabled'=>true,'open'=>'00:00','close'=>'23:59'];
$settings=[
    'timezone'=>'America/Sao_Paulo',
    'pickup_enabled'=>true,
    'scheduling_enabled'=>true,
    'scheduling_slot_minutes'=>30,
    'scheduling_lead_minutes'=>0,
    'scheduling_days_ahead'=>2,
    'max_orders_per_slot'=>1,
    'scheduled_kds_lead_minutes'=>45,
    'business_hours'=>$hours,
];
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,"premium","active",?)')->execute(['CI Schedule','ci-schedule-'.bin2hex(random_bytes(3)),json_encode($settings)]);
$tenantId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO products (tenant_id,name,price_cents,track_stock,active) VALUES (?,"Produto agendado",1500,0,1)')->execute([$tenantId]);
$productId=(int)$pdo->lastInsertId();

$scheduler=new OrderSchedulingService();
$slots=$scheduler->availableSlots($pdo,$tenantId,'pickup',5);
assert_schedule(count($slots)>0,'nenhum slot disponível');
$slot=(string)$slots[0]['value'];

$orders=new PublicOrderService();
$first=$orders->createPickup($tenantId,[['product_id'=>$productId,'qty'=>1]],['name'=>'Cliente 1','phone'=>'22991000001'],null,$slot);
assert_schedule($first['channel']==='pickup','canal da retirada incorreto');
assert_schedule(!empty($first['scheduled_for']),'horário agendado não persistido no retorno');

$stmt=$pdo->prepare('SELECT channel,scheduled_for,scheduled_slot_minutes,total_cents FROM orders WHERE id=?');
$stmt->execute([$first['order_id']]);$stored=$stmt->fetch();
assert_schedule($stored&&$stored['channel']==='pickup','pedido de retirada não persistido');
assert_schedule((int)$stored['scheduled_slot_minutes']===30,'intervalo do slot incorreto');
assert_schedule((int)$stored['total_cents']===1500,'total da retirada incorreto');

$capacityBlocked=false;
try{
    $orders->createPickup($tenantId,[['product_id'=>$productId,'qty'=>1]],['name'=>'Cliente 2','phone'=>'22991000002'],null,$slot);
}catch(RuntimeException $e){$capacityBlocked=str_contains($e->getMessage(),'capacidade máxima');}
assert_schedule($capacityBlocked,'capacidade do slot não bloqueou o segundo pedido');

$slotsAfter=$scheduler->availableSlots($pdo,$tenantId,'pickup',5);
assert_schedule(!in_array($slot,array_column($slotsAfter,'value'),true),'slot lotado ainda aparece como disponível');

$settings['scheduling_slot_minutes']=45;
$settings['max_orders_per_slot']=0;
$pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode($settings),$tenantId]);
$slots45=$scheduler->availableSlots($pdo,$tenantId,'pickup',12);
assert_schedule(count($slots45)>0,'nenhum slot de 45 minutos disponível');
foreach($slots45 as $candidate){
    $dt=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',(string)$candidate['value'],new DateTimeZone('America/Sao_Paulo'));
    assert_schedule($dt instanceof DateTimeImmutable,'slot de 45 minutos inválido');
    $minutes=((int)$dt->format('H'))*60+(int)$dt->format('i');
    assert_schedule($minutes%45===0,'slot de 45 minutos desalinhado: '.$candidate['value']);
}

echo "Scheduling integration OK\n";
