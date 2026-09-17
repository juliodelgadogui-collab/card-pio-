<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;
use Throwable;

final class Database
{
    private const SQLITE_BUSY_TIMEOUT_MS = 3000;
    private const SQLITE_TRANSACTION_ATTEMPTS = 4;

    private static ?PDO $pdo = null;
    private static bool $sqliteImmediateTransaction = false;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $driver = strtolower((string)env('DB_CONNECTION', 'mysql'));
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($driver === 'sqlite') {
            if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
                throw new RuntimeException('A extensão pdo_sqlite não está habilitada.');
            }

            $path = (string)env('DB_SQLITE_PATH', dirname(__DIR__, 2) . '/storage/eventmenu.sqlite');
            if ($path !== ':memory:' && !self::isAbsolutePath($path)) {
                $path = dirname(__DIR__, 2) . '/' . ltrim($path, '/\\');
            }
            if ($path !== ':memory:') {
                $directory = dirname($path);
                if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                    throw new RuntimeException('Não foi possível criar a pasta do banco SQLite.');
                }
            }

            self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = ' . self::SQLITE_BUSY_TIMEOUT_MS);
            if ($path !== ':memory:') {
                $currentMode = strtolower((string)self::$pdo->query('PRAGMA journal_mode')->fetchColumn());
                if ($currentMode !== 'wal') self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA synchronous = NORMAL');
                self::$pdo->exec('PRAGMA wal_autocheckpoint = 1000');
            }
            self::registerSqliteFunctions(self::$pdo);
            return self::$pdo;
        }

        if ($driver !== 'mysql') throw new RuntimeException('DB_CONNECTION deve ser mysql ou sqlite.');
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('A extensão pdo_mysql não está habilitada.');
        }

        $host = (string)env('DB_HOST', '127.0.0.1');
        $port = (string)env('DB_PORT', '3306');
        $db = (string)env('DB_DATABASE', 'eventmenu');
        $user = (string)env('DB_USERNAME', 'root');
        $pass = (string)env('DB_PASSWORD', '');
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, $options);
        return self::$pdo;
    }

    public static function driver(?PDO $pdo = null): string
    {
        $pdo ??= self::connection();
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function isSqlite(?PDO $pdo = null): bool
    {
        return self::driver($pdo) === 'sqlite';
    }

    public static function portableSql(PDO $pdo, string $sql): string
    {
        if (!self::isSqlite($pdo)) return $sql;
        $sql = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $sql) ?? $sql;
        return str_ireplace('INSERT IGNORE INTO', 'INSERT OR IGNORE INTO', $sql);
    }

    public static function schemaPath(?PDO $pdo = null): string
    {
        $root = dirname(__DIR__, 2);
        return self::isSqlite($pdo) ? $root . '/database/sqlite/schema.sql' : $root . '/database/schema.sql';
    }

    public static function migrationDirectory(?PDO $pdo = null): string
    {
        $root = dirname(__DIR__, 2);
        return self::isSqlite($pdo) ? $root . '/database/sqlite/migrations' : $root . '/database/migrations';
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction() || self::$sqliteImmediateTransaction) return $callback($pdo);

        $sqlite = self::isSqlite($pdo);
        if ($sqlite) {
            self::retrySqliteBusy(static fn() => $pdo->exec('BEGIN IMMEDIATE TRANSACTION'));
            self::$sqliteImmediateTransaction = true;
        } else {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($sqlite) {
                self::retrySqliteBusy(static fn() => $pdo->exec('COMMIT'));
                self::$sqliteImmediateTransaction = false;
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($sqlite && self::$sqliteImmediateTransaction) {
                try {
                    $pdo->exec('ROLLBACK');
                } finally {
                    self::$sqliteImmediateTransaction = false;
                }
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function retrySqliteBusy(callable $operation): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= self::SQLITE_TRANSACTION_ATTEMPTS || !self::isSqliteBusy($e)) throw $e;
                $backoffMicros = min(700000, 50000 * (2 ** ($attempt - 1))) + random_int(10000, 70000);
                usleep($backoffMicros);
            }
        }
    }

    private static function isSqliteBusy(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'database is locked')
            || str_contains($message, 'database table is locked')
            || str_contains($message, 'database schema is locked')
            || str_contains($message, 'general error: 5')
            || str_contains($message, 'general error: 6');
    }

    private static function registerSqliteFunctions(PDO $pdo): void
    {
        if (!method_exists($pdo, 'sqliteCreateFunction')) return;
        $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'), 0);
        $pdo->sqliteCreateFunction('GREATEST', static function (...$values): mixed {
            if ($values === []) return null;
            return max($values);
        }, -1);
        $pdo->sqliteCreateFunction('IF', static fn(mixed $condition, mixed $yes, mixed $no): mixed => $condition ? $yes : $no, 3);
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        if ($path[0] === '/' || $path[0] === '\\') return true;
        return (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
