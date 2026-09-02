<?php

declare(strict_types=1);

namespace EventMenu\Core;

use RuntimeException;

final class Crypto
{
    private static function key(): string
    {
        $raw = (string)env('APP_KEY', '');
        if ($raw === '') throw new RuntimeException('APP_KEY não configurada.');
        return hash('sha256', $raw, true);
    }

    public static function encrypt(array|string $value): string
    {
        $plain = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value;
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('Falha ao criptografar segredo.');
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $value): string
    {
        if (!$value) return '';
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Segredo criptografado inválido.');
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('Falha ao descriptografar segredo.');
        return $plain;
    }

    public static function decryptJson(?string $value): array
    {
        $plain = self::decrypt($value);
        if ($plain === '') return [];
        $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }
}
