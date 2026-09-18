<?php

declare(strict_types=1);
namespace EventMenu\Services;
/** Backward-compatible facade used by GO and older clients. Provider choice now belongs to the server. */
final class NativePixService{
 public function create(int $orderId,string $taxId='',?int $amountCents=null,?string $provider=null):array{return (new PixService())->create($orderId,$taxId,$amountCents,$provider);}
 public function available():array{return (new PixService())->available();}
}
