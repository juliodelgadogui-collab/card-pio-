<?php

declare(strict_types=1);

namespace EventMenu\Services;

interface FiscalContingencyVerifierInterface
{
    /**
     * Valida o XML assinado localmente antes de o servidor aceitá-lo como
     * emissão em contingência. A implementação deve conferir assinatura XML,
     * certificado, emitente, modelo, série, número, valor e chave de acesso.
     *
     * @return array{verified:bool,access_key?:string,error?:string,metadata?:array}
     */
    public function verify(array $document, array $snapshot, string $signedXml): array;
}
