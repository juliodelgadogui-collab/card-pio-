<?php

declare(strict_types=1);

namespace EventMenu\Services;

interface FiscalTransmitterInterface
{
    /**
     * O adaptador deve assinar/transmitir o snapshot para a SEFAZ e somente
     * retornar verified=true depois de validar a resposta oficial recebida.
     *
     * @return array{
     *   status:string,
     *   verified:bool,
     *   access_key?:string,
     *   protocol?:string,
     *   xml?:string,
     *   rejection_code?:string,
     *   rejection_message?:string,
     *   retryable?:bool,
     *   raw?:array
     * }
     */
    public function transmit(array $document, array $snapshot): array;
}
