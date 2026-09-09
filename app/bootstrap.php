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

function app_base_path(): string
{
    static $resolved = null;
    if ($resolved !== null) return $resolved;

    $configured = trim((string)env('APP_BASE_PATH', ''));
    if ($configured !== '') {
        $configured = '/' . trim(str_replace('\\', '/', $configured), '/');
        return $resolved = ($configured === '/' ? '' : $configured);
    }

    if (PHP_SAPI !== 'cli') {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== '' && str_starts_with($script, '/')) {
            $dir = str_replace('\\', '/', dirname($script));
            if ($dir !== '/' && $dir !== '.') return $resolved = rtrim($dir, '/');
        }
    }
    return $resolved = '';
}

function app_url(string $path = ''): string
{
    $base = app_base_path();
    if ($path === '' || $path === '/') return $base === '' ? '/' : $base . '/';
    if (preg_match('~^https?://~i', $path)) return $path;
    return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
}

function app_absolute_url(string $path = ''): string
{
    $origin = rtrim((string)env('APP_URL', ''), '/');
    if ($origin === '') return app_url($path);
    $base = app_base_path();
    $originPath = (string)(parse_url($origin, PHP_URL_PATH) ?? '');
    if ($base !== '' && !str_ends_with(rtrim($originPath, '/'), $base)) $origin .= $base;
    if ($path === '' || $path === '/') return $origin . '/';
    return $origin . '/' . ltrim($path, '/');
}

function app_redirect(string $path = ''): never
{
    header('Location: ' . app_url($path));
    exit;
}

function app_rewrite_root_urls(string $html): string
{
    $base = app_base_path();
    if ($base === '') return $html;
    return preg_replace_callback(
        "~\\b(href|src|action)=([\"'])/(?!/)~i",
        static fn(array $m): string => $m[1] . '=' . $m[2] . $base . '/',
        $html
    ) ?? $html;
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    // Compatível com o painel atual, que ainda possui scripts/estilos inline.
    // Mesmo assim bloqueia plugins, frames externos, base-uri maliciosa e carregamento de scripts de terceiros.
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https:; worker-src 'self'; manifest-src 'self'; upgrade-insecure-requests");
    $production = strtolower(trim((string)env('APP_ENV', 'production'))) === 'production';
    $httpsConfigured = strtolower((string)parse_url((string)env('APP_URL', ''), PHP_URL_SCHEME)) === 'https';
    if ($production && $httpsConfigured) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
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

if (PHP_SAPI !== 'cli' && ob_get_level() === 0) ob_start('app_rewrite_root_urls');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = filter_var(env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOL);
    $cookiePath = app_base_path() === '' ? '/' : app_base_path() . '/';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_name((string)env('SESSION_NAME', 'eventmenu_session'));
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => $cookiePath,
    ]);
    session_start();
}
