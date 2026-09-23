<?php

declare(strict_types=1);

namespace EventMenu\Services;

interface FiscalLifecycleTransmitterInterface extends FiscalTransmitterInterface
{
    /**
     * Cancela uma NF-e/NFC-e previamente autorizada. O adaptador só pode retornar
     * verified=true depois de validar a resposta oficial da SEFAZ.
     *
     * @return array{status:string,verified:bool,protocol?:string,xml?:string,rejection_code?:string,rejection_message?:string,raw?:array}
     */
    public function cancel(array $document, array $event): array;

    /**
     * Inutiliza uma faixa de numeração. A solicitação já chega com perfil, modelo,
     * série, ano e intervalo imutáveis.
     *
     * @return array{status:string,verified:bool,protocol?:string,xml?:string,rejection_code?:string,rejection_message?:string,raw?:array}
     */
    public function inutilize(array $request): array;

    /**
     * Consulta a situação oficial de uma chave já conhecida.
     *
     * @return array{status:string,verified:bool,access_key?:string,protocol?:string,xml?:string,rejection_code?:string,rejection_message?:string,raw?:array}
     */
    public function query(array $document): array;

    /**
     * Transmite uma NFC-e gerada/assinada em contingência local. O XML recebido
     * deve ser preservado sem alteração pelo adaptador.
     *
     * @return array{status:string,verified:bool,access_key?:string,protocol?:string,xml?:string,rejection_code?:string,rejection_message?:string,raw?:array}
     */
    public function transmitContingency(array $document, string $signedXml): array;
}
