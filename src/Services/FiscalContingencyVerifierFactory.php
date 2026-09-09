<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class FiscalContingencyVerifierFactory
{
    public static function make(): FiscalContingencyVerifierInterface
    {
        $class=trim((string)env('FISCAL_CONTINGENCY_VERIFIER_CLASS',''));
        if($class==='')return new UnavailableFiscalContingencyVerifier();
        if(!class_exists($class))throw new RuntimeException('Classe do verificador de contingência não encontrada.');
        $verifier=new $class();
        if(!$verifier instanceof FiscalContingencyVerifierInterface)throw new RuntimeException('O verificador de contingência não implementa o contrato do EventMenu.');
        return $verifier;
    }

    public static function isConfigured():bool
    {
        return trim((string)env('FISCAL_CONTINGENCY_VERIFIER_CLASS',''))!=='';
    }
}
