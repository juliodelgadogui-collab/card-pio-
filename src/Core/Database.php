<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $db = env('DB_DATABASE', 'eventmenu');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        self::$pdo = new PDO($dsn, (string)$user, (string)$pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET time_zone = '+00:00'");
        return self::$pdo;
    }

    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();
        $nested = $pdo->inTransaction();
        $savepoint = $nested ? 'em_sp_'.bin2hex(random_bytes(8)) : null;

        if ($nested) {
            $pdo->exec('SAVEPOINT '.$savepoint);
        } else {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                if ($nested && $savepoint !== null) {
                    try {
                        $pdo->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                        $pdo->exec('RELEASE SAVEPOINT '.$savepoint);
                    } catch (Throwable) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                    }
                } else {
                    $pdo->rollBack();
                }
            }
            throw $e;
        }
    }
}
