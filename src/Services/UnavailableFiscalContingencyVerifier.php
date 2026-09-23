<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class UnavailableFiscalContingencyVerifier implements FiscalContingencyVerifierInterface
{
    public function verify(array $document, array $snapshot, string $signedXml): array
    {
        throw new RuntimeException('Verificador de assinatura da contingência fiscal não está configurado.');
    }
}
