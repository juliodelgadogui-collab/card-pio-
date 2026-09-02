<?php

declare(strict_types=1);

function load_env(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

load_env(__DIR__ . '/../.env');

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

$vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendor)) require_once $vendor;

spl_autoload_register(function (string $class): void {
    $prefix = 'EventMenu\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require $path;
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = filter_var(env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOL);
    session_name((string)env('SESSION_NAME', 'eventmenu_session'));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
