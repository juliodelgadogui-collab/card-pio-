<?php

declare(strict_types=1);

namespace EventMenu\Core;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function run(?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();
        if (Database::isSqlite($pdo)) {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration TEXT NOT NULL UNIQUE, applied_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(190) NOT NULL UNIQUE, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }

        $dir = Database::migrationDirectory($pdo);
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);
            $stmt = $pdo->prepare('SELECT 1 FROM migrations WHERE migration=?');
            $stmt->execute([$name]);
            if ($stmt->fetchColumn()) continue;

            $sql = file_get_contents($file);
            if ($sql === false) throw new RuntimeException('Não foi possível ler a migração ' . $name);

            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
                $stmt->execute([$name]);
                if ($ownsTransaction) $pdo->commit();
                $applied[] = $name;
            } catch (\Throwable $e) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }
        return $applied;
    }
}
