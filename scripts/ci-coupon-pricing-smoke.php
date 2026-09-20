<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Services/CouponPricingService.php';

use EventMenu\Services\CouponPricingService;

$pricing=new CouponPricingService();
$percentCap=['type'=>'percent','value'=>10,'max_discount_cents'=>500];
$cases=[3000=>300,5000=>500,8000=>500];
foreach($cases as$subtotal=>$expected){
    $actual=$pricing->discount($percentCap,$subtotal);
    if($actual!==$expected){
        fwrite(STDERR,"coupon-pricing-smoke: subtotal={$subtotal} expected={$expected} actual={$actual}\n");
        exit(1);
    }
}

if($pricing->discount(['type'=>'percent','value'=>10,'max_discount_cents'=>null],8000)!==800){
    fwrite(STDERR,"coupon-pricing-smoke: uncapped percentage failed\n");
    exit(1);
}
if($pricing->discount(['type'=>'fixed','value'=>500,'max_discount_cents'=>100],3000)!==500){
    fwrite(STDERR,"coupon-pricing-smoke: fixed coupon must ignore percentage cap\n");
    exit(1);
}
if($pricing->discount(['type'=>'percent','value'=>150,'max_discount_cents'=>null],3000)!==3000){
    fwrite(STDERR,"coupon-pricing-smoke: percentage must never exceed subtotal\n");
    exit(1);
}

fwrite(STDOUT,"coupon-pricing-smoke: OK\n");
