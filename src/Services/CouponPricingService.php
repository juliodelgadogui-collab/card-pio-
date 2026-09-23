<?php

declare(strict_types=1);

namespace EventMenu\Services;

final class CouponPricingService
{
    public function discount(array $coupon,int $subtotalCents):int
    {
        $subtotal=max(0,$subtotalCents);
        if($subtotal===0)return 0;

        if((string)($coupon['type']??'')==='percent'){
            $percent=min(100,max(0,(int)($coupon['value']??0)));
            $discount=(int)round($subtotal*$percent/100);
            $cap=$coupon['max_discount_cents']??null;
            if($cap!==null&&(int)$cap>0)$discount=min($discount,(int)$cap);
            return max(0,min($subtotal,$discount));
        }

        return max(0,min($subtotal,(int)($coupon['value']??0)));
    }
}
