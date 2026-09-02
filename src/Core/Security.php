<?php

declare(strict_types=1);

namespace EventMenu\Core;

final class Security
{
    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function validateCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function randomKey(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function clientIp(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
    }
}
