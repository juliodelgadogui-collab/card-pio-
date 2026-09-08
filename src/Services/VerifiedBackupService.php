<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;
use Throwable;

final class VerifiedBackupService
{
    public function runScheduled(): array
    {
        $verified = (new RuntimeStatusService())->get('backup.last_verified');
        if ($verified && ($verified['state'] ?? null) === 'ok' && !empty($verified['checked_at'])) {
            $ts = strtotime((string)$verified['checked_at']);
            if ($ts !== false && $ts > time() - 20 * 3600) {
                return ['skipped' => true, 'reason' => 'backup verificado recente'];
            }
        }
        return $this->run();
    }

    public function run(): array
    {
        $runtime = new RuntimeStatusService();
        $file = null;
        try {
            $result = (new BackupService())->run();
            $file = $this->backupFile((string)($result['file'] ?? ''));
            $verification = $this->verify((string)($result['driver'] ?? ''), $file);
            $meta = $result + $verification + ['verified' => true];
            $runtime->set('backup.last_verification', 'ok', 'Integridade do backup confirmada.', $meta);
            $runtime->set('backup.last_verified', 'ok', 'Último backup verificado e disponível.', $meta);
            return $meta;
        } catch (Throwable $e) {
            if ($file !== null) {
                @unlink($file . '.sha256');
                @unlink($file);
                $runtime->set('backup.last_run', 'error', 'Backup descartado por falha na verificação.', [
                    'file' => basename($file),
                    'verified' => false,
                    'error' => $this->safe($e->getMessage()),
                ]);
            }
            $runtime->set(
                'backup.last_verification',
                'error',
                $file !== null ? 'O backup não passou na verificação e foi descartado.' : 'O backup falhou antes da etapa de verificação.',
                ['verified' => false, 'error' => $this->safe($e->getMessage())]
            );
            throw $e;
        }
    }

    /** @return array{verification:string,sha256:string,checksum_file:string,integrity_check?:string,essential_tables?:int} */
    private function verify(string $driver, string $file): array
    {
        if (!is_file($file) || (int)filesize($file) < 1) throw new RuntimeException('Arquivo de backup ausente ou vazio.');

        $details = $driver === 'sqlite'
            ? $this->verifySqlite($file)
            : ['verification' => 'dump_exit_and_checksum'];

        $hash = hash_file('sha256', $file);
        if ($hash === false || !preg_match('/^[a-f0-9]{64}$/', $hash)) throw new RuntimeException('Não foi possível calcular o SHA-256 do backup.');

        $checksum = $file . '.sha256';
        if (file_put_contents($checksum, $hash . '  ' . basename($file) . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar o checksum do backup.');
        }
        @chmod($checksum, 0640);

        return $details + [
            'sha256' => $hash,
            'checksum_file' => basename($checksum),
        ];
    }

    /** @return array{verification:string,integrity_check:string,essential_tables:int} */
    private function verifySqlite(string $file): array
    {
        $backup = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $integrity = strtolower(trim((string)$backup->query('PRAGMA quick_check')->fetchColumn()));
        if ($integrity !== 'ok') {
            throw new RuntimeException('SQLite quick_check retornou: ' . ($integrity !== '' ? $integrity : 'sem resposta') . '.');
        }

        $essential = (int)$backup->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('tenants','users','orders')"
        )->fetchColumn();
        if ($essential !== 3) throw new RuntimeException('Backup SQLite não contém todas as tabelas essenciais do EventMenu.');

        return [
            'verification' => 'sqlite_quick_check',
            'integrity_check' => 'ok',
            'essential_tables' => $essential,
        ];
    }

    private function backupFile(string $basename): string
    {
        if ($basename === '' || basename($basename) !== $basename) throw new RuntimeException('Nome do arquivo de backup inválido.');
        $root = dirname(__DIR__, 2);
        $dir = trim((string)env('BACKUP_PATH', 'storage/backups'));
        if (!str_starts_with($dir, '/') && !preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)) {
            $dir = $root . '/' . ltrim($dir, '/\\');
        }
        return rtrim($dir, '/\\') . '/' . $basename;
    }

    private function safe(string $message): string
    {
        return mb_substr(preg_replace('/password|secret|token|key/i', 'credencial', $message) ?? $message, 0, 300);
    }
}
