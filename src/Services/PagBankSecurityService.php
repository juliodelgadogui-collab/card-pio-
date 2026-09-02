<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class PagBankSecurityService
{
    private const API_BASES=[
        'https://api.pagseguro.com',
        'https://sandbox.api.pagseguro.com',
    ];

    public static function apiBase(?string $value):string
    {
        $base=rtrim(trim((string)$value),'/');
        if($base==='')$base='https://api.pagseguro.com';
        if(!in_array($base,self::API_BASES,true))throw new RuntimeException('API base PagBank inválida. Use apenas produção ou sandbox oficial.');
        return $base;
    }

    public static function tapOnLegacyBase(string $environment):string
    {
        return strtolower(trim($environment))==='sandbox'
            ? 'https://ws.sandbox.pagseguro.uol.com.br/v2/transactions'
            : 'https://ws.pagseguro.uol.com.br/v2/transactions';
    }

    public static function deviceFingerprint(string $identifier):string
    {
        $identifier=trim($identifier);
        if(strlen($identifier)<8||strlen($identifier)>255)throw new RuntimeException('Identificador do dispositivo inválido.');
        $secret=(string)env('APP_KEY','');
        if(strlen($secret)<16)throw new RuntimeException('APP_KEY inválida para proteger dispositivos NFC.');
        return hash_hmac('sha256','eventmenu:nfc-device:'.$identifier,$secret);
    }

    public static function legacyDeviceFingerprint(string $identifier):string
    {
        return hash('sha256',trim($identifier));
    }

    public static function transactionFingerprint(string $transactionCode):string
    {
        $code=strtoupper(trim($transactionCode));
        if(!preg_match('/^[A-Z0-9_-]{16,100}$/',$code))throw new RuntimeException('Código de transação PagBank inválido.');
        $secret=(string)env('APP_KEY','');
        if(strlen($secret)<16)throw new RuntimeException('APP_KEY inválida para proteger transações NFC.');
        return hash_hmac('sha256','eventmenu:nfc-transaction:'.$code,$secret);
    }
}
