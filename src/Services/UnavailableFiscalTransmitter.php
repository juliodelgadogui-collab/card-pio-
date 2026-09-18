<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class UnavailableFiscalTransmitter implements FiscalTransmitterInterface
{
    public function transmit(array $document, array $snapshot): array
    {
        throw new RuntimeException('Nenhum transmissor SEFAZ homologado está configurado neste ambiente.');
    }
}
