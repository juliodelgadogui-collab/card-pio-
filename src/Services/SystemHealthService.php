<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use Throwable;

final class SystemHealthService
{
    public function snapshot(): array
    {
        $checks = [];
        $pdo = Database::connection();

        try {
            $pdo->query('SELECT 1')->fetchColumn();
            $checks['database'] = $this->check('ok', 'Banco de dados conectado.', ['driver' => Database::driver($pdo)]);
        } catch (Throwable $e) {
            $checks['database'] = $this->check('error', 'Falha de conexão com o banco.', ['error' => $this->safe($e->getMessage())]);
        }

        $runtime = new RuntimeStatusService();
        $cron = $runtime->get('cron.last_run');
        $cronAge = $this->age($cron['checked_at'] ?? null);
        $checks['cron'] = $cron === null
            ? $this->check('warning', 'Cron ainda não registrou execução.')
            : ($cronAge > 300
                ? $this->check('warning', 'Cron está atrasado.', ['seconds_since_run' => $cronAge, 'last_state' => $cron['state'] ?? null])
                : $this->check((string)($cron['state'] ?? 'ok'), 'Cron executando.', ['seconds_since_run' => $cronAge, 'last_result' => $cron['metadata'] ?? []]));

        $worker = $runtime->get('queue.worker.last_run');
        $workerAge = $this->age($worker['checked_at'] ?? null);
        $checks['worker'] = $worker === null
            ? $this->check('warning', 'Worker da fila ainda não registrou execução.')
            : ($workerAge > 300
                ? $this->check('warning', 'Worker da fila está atrasado.', ['seconds_since_run' => $workerAge, 'last_state' => $worker['state'] ?? null])
                : $this->check((string)($worker['state'] ?? 'ok'), 'Worker da fila executando.', ['seconds_since_run' => $workerAge, 'last_result' => $worker['metadata'] ?? []]));

        try {
            $queue = (new BackgroundJobService())->stats();
            $waiting = (int)$queue['pending'] + (int)$queue['retry'];
            $oldestAge = $waiting > 0 ? $this->age($queue['oldest_pending_at'] ?? null) : 0;
            $staleProcessing = (int)($queue['stale_processing'] ?? 0);
            $state = (int)$queue['failed'] > 0 || $staleProcessing > 0 || $waiting > 100 || ($waiting > 0 && $oldestAge > 180) ? 'warning' : 'ok';
            $checks['queue'] = $this->check(
                $state,
                $state === 'ok' ? 'Fila em dia.' : 'Fila precisa de atenção.',
                $queue + ['oldest_pending_seconds' => $oldestAge]
            );
        } catch (Throwable $e) {
            $checks['queue'] = $this->check('error', 'Fila indisponível.', ['error' => $this->safe($e->getMessage())]);
        }

        try {
            $pushConfigured = (new FcmPushService())->configured();
            $devices = (int)$pdo->query('SELECT COUNT(*) FROM push_devices WHERE active=1')->fetchColumn();
            $lastTest = $runtime->get('push.last_test');
            $details = ['active_devices' => $devices, 'configured' => $pushConfigured, 'last_test' => $lastTest ? [
                'state' => $lastTest['state'] ?? null,
                'checked_at' => $lastTest['checked_at'] ?? null,
                'metadata' => $lastTest['metadata'] ?? [],
            ] : null];
            $checks['push'] = $this->check(
                $pushConfigured ? 'ok' : 'warning',
                $pushConfigured ? 'Firebase Cloud Messaging configurado.' : 'Push instantâneo ainda sem credencial Firebase.',
                $details
            );
        } catch (Throwable $e) {
            $checks['push'] = $this->check('warning', 'Push ainda não disponível.', ['error' => $this->safe($e->getMessage())]);
        }

        $backup = $runtime->get('backup.last_success');
        $backupAge = $this->age($backup['checked_at'] ?? null);
        $backupState = $backup === null || $backupAge > 36 * 3600 ? 'warning' : 'ok';
        $checks['backup'] = $this->check(
            $backupState,
            $backupState === 'ok' ? 'Backup recente disponível.' : 'Backup automático ainda não está recente.',
            ['seconds_since_backup' => $backupAge, 'last_backup' => $backup['metadata'] ?? null]
        );

        try {
            $gatewayRows = $pdo->query('SELECT provider,COUNT(*) qty FROM payment_gateways WHERE active=1 GROUP BY provider ORDER BY provider')->fetchAll();
            $checks['gateways'] = $this->check(
                $gatewayRows ? 'ok' : 'warning',
                $gatewayRows ? 'Gateways ativos encontrados.' : 'Nenhum gateway de pagamento ativo.',
                ['providers' => $gatewayRows]
            );
        } catch (Throwable $e) {
            $checks['gateways'] = $this->check('warning', 'Não foi possível ler os gateways.', ['error' => $this->safe($e->getMessage())]);
        }

        try {
            $last = $pdo->query('SELECT provider,status,created_at,processed_at FROM webhook_events ORDER BY id DESC LIMIT 1')->fetch();
            $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            $s = $pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE status="failed" AND created_at>=?');
            $s->execute([$cutoff]);
            $failed = (int)$s->fetchColumn();
            $checks['webhooks'] = $this->check(
                $failed > 0 ? 'warning' : 'ok',
                $last ? 'Webhooks estão sendo registrados.' : 'Nenhum webhook recebido ainda.',
                ['last' => $last ?: null, 'failed_last_24h' => $failed]
            );
        } catch (Throwable $e) {
            $checks['webhooks'] = $this->check('warning', 'Histórico de webhooks indisponível.', ['error' => $this->safe($e->getMessage())]);
        }

        $root = dirname(__DIR__, 2);
        $storage = $root . '/storage';
        $writable = is_dir($storage) && is_writable($storage);
        $free = @disk_free_space($storage);
        $checks['storage'] = $this->check(
            $writable ? 'ok' : 'error',
            $writable ? 'Armazenamento gravável.' : 'Pasta storage sem permissão de escrita.',
            ['free_bytes' => $free !== false ? (int)$free : null]
        );

        $overall = 'ok';
        foreach ($checks as $check) {
            if ($check['state'] === 'error') {
                $overall = 'error';
                break;
            }
            if ($check['state'] === 'warning') $overall = 'warning';
        }

        return [
            'overall' => $overall,
            'checks' => $checks,
            'generated_at' => gmdate('c'),
            'app' => [
                'environment' => (string)env('APP_ENV', 'production'),
                'debug' => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
                'min_app_version' => (string)env('APP_MIN_VERSION', '0'),
                'recommended_app_version' => (string)env('APP_RECOMMENDED_VERSION', ''),
            ],
        ];
    }

    private function check(string $state, string $message, array $details = []): array
    {
        return [
            'state' => in_array($state, ['ok', 'warning', 'error', 'disabled'], true) ? $state : 'warning',
            'message' => $message,
            'details' => $details,
        ];
    }

    private function age(mixed $date): int
    {
        if (!$date) return PHP_INT_MAX;
        $ts = strtotime((string)$date);
        return $ts === false ? PHP_INT_MAX : max(0, time() - $ts);
    }

    private function safe(string $message): string
    {
        return mb_substr(preg_replace('/password|secret|token|key/i', 'credencial', $message) ?? $message, 0, 300);
    }
}
