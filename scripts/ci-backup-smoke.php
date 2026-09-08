<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Services\RuntimeStatusService;
use EventMenu\Services\SystemHealthService;
use EventMenu\Services\VerifiedBackupService;

function backup_fail(string $message): never { fwrite(STDERR, "BACKUP CI FAIL: {$message}\n"); exit(1); }
function backup_assert(bool $ok, string $message): void { if (!$ok) backup_fail($message); }

$dir = sys_get_temp_dir() . '/eventmenu-backup-ci-' . bin2hex(random_bytes(4));
if (!mkdir($dir, 0750, true) && !is_dir($dir)) backup_fail('Não foi possível criar diretório temporário.');
putenv('BACKUP_PATH=' . $dir);
$_ENV['BACKUP_PATH'] = $dir;
putenv('BACKUP_RETENTION_DAYS=1');
$_ENV['BACKUP_RETENTION_DAYS'] = '1';

try {
    $result = (new VerifiedBackupService())->run();
    backup_assert(!empty($result['ok']), 'Serviço não retornou ok.');
    backup_assert(!empty($result['verified']), 'Backup não retornou verified=true.');
    backup_assert(($result['verification'] ?? '') === 'sqlite_quick_check', 'Método de verificação SQLite incorreto.');
    backup_assert(($result['integrity_check'] ?? '') === 'ok', 'quick_check não retornou ok.');
    backup_assert((int)($result['essential_tables'] ?? 0) === 3, 'Tabelas essenciais não foram confirmadas.');

    $file = $dir . '/' . basename((string)($result['file'] ?? ''));
    $checksumFile = $dir . '/' . basename((string)($result['checksum_file'] ?? ''));
    backup_assert(is_file($file) && filesize($file) > 0, 'Arquivo de backup não foi criado.');
    backup_assert(is_file($checksumFile) && filesize($checksumFile) > 0, 'Arquivo SHA-256 não foi criado.');

    $sha = hash_file('sha256', $file);
    backup_assert(is_string($sha) && strlen($sha) === 64, 'SHA-256 do arquivo inválido.');
    backup_assert(hash_equals($sha, (string)($result['sha256'] ?? '')), 'SHA-256 retornado diverge do arquivo.');
    $sidecar = trim((string)file_get_contents($checksumFile));
    backup_assert($sidecar === $sha . '  ' . basename($file), 'Conteúdo do arquivo SHA-256 divergente.');

    $copy = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    backup_assert(strtolower(trim((string)$copy->query('PRAGMA quick_check')->fetchColumn())) === 'ok', 'A cópia não abre com quick_check=ok.');
    backup_assert((int)$copy->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('tenants','users','orders')")->fetchColumn() === 3, 'A cópia não possui as tabelas essenciais.');
    $copy = null;

    $status = (new RuntimeStatusService())->get('backup.last_verified');
    backup_assert(($status['state'] ?? null) === 'ok', 'Runtime não registrou backup.last_verified como OK.');
    backup_assert(!empty($status['metadata']['verified']), 'Runtime não registrou verified=true.');

    $health = (new SystemHealthService())->snapshot();
    backup_assert(($health['checks']['backup']['state'] ?? null) === 'ok', 'Saúde do sistema não reconheceu backup verificado recente.');
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) if (is_file($file)) @unlink($file);
    @rmdir($dir);
}

echo "CI verified backup smoke OK\n";
