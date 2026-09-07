<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

interface PixProviderInterface
{
    public function code(): string;
    /** @return array{external_id:string,copy_paste:string,image_url:string,image_base64:string,expires_at:string,raw:array} */
    public function createCharge(array $context,array $gateway): array;
}
