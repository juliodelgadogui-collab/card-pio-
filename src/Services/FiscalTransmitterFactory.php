<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class FiscalTransmitterFactory
{
    public static function make(): FiscalTransmitterInterface
    {
        $class = trim((string)env('FISCAL_TRANSMITTER_CLASS', ''));
        if ($class === '') return new UnavailableFiscalTransmitter();

        // O nome da classe vem apenas da configuração do servidor. O painel e os clientes
        // não podem escolher nem injetar uma classe de transmissor.
        if (!class_exists($class)) throw new RuntimeException('Classe do transmissor fiscal não encontrada.');
        $transmitter = new $class();
        if (!$transmitter instanceof FiscalTransmitterInterface) {
            throw new RuntimeException('O transmissor fiscal configurado não implementa o contrato do EventMenu.');
        }
        return $transmitter;
    }

    public static function makeLifecycle(): FiscalLifecycleTransmitterInterface
    {
        $transmitter=self::make();
        if(!$transmitter instanceof FiscalLifecycleTransmitterInterface){
            throw new RuntimeException('O transmissor fiscal configurado ainda não suporta cancelamento, inutilização, consulta e contingência.');
        }
        return $transmitter;
    }

    public static function isConfigured(): bool
    {
        return trim((string)env('FISCAL_TRANSMITTER_CLASS', '')) !== '';
    }

    public static function supportsLifecycle():bool
    {
        if(!self::isConfigured())return false;
        try{return self::make() instanceof FiscalLifecycleTransmitterInterface;}catch(\Throwable){return false;}
    }
}
