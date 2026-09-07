<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

interface PixProviderInterface
{
    public function code(): string;
    /** @return array{external_id:string,copy_paste:string,image_url:string,image_base64:string,expires_at:string,raw:array} */
    public function createCharge(array $context,array $gateway): array;
    /** @return array{paid:bool,provider_payment_id:string,amount_cents:int,currency:string,account_reference:string,raw_status:string,raw:array} */
    public function verifyCharge(array $payment,array $gateway): array;
}
