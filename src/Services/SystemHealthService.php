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

        $checks['whatsapp'] = $this->whatsappHealth($pdo);
        $checks['print_queue'] = $this->printQueueHealth($pdo);

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

        $backup = $runtime->get('backup.last_verified');
        $backupAge = $this->age($backup['checked_at'] ?? null);
        $backupMeta = is_array($backup['metadata'] ?? null) ? $backup['metadata'] : [];
        $backupVerified = $backup !== null && ($backup['state'] ?? null) === 'ok' && !empty($backupMeta['verified']);
        $backupRecent = $backupVerified && $backupAge <= 36 * 3600;
        $lastAttempt = $runtime->get('backup.last_run');
        $lastVerification = $runtime->get('backup.last_verification');
        $checks['backup'] = $this->check(
            $backupRecent ? 'ok' : 'warning',
            $backupRecent ? 'Backup recente e verificado disponível.' : 'Ainda não há backup verificado recente.',
            [
                'seconds_since_verified_backup' => $backupAge,
                'last_verified' => $backup ? [
                    'checked_at' => $backup['checked_at'] ?? null,
                    'metadata' => $backupMeta,
                ] : null,
                'last_attempt' => $lastAttempt ? [
                    'state' => $lastAttempt['state'] ?? null,
                    'checked_at' => $lastAttempt['checked_at'] ?? null,
                ] : null,
                'last_verification' => $lastVerification ? [
                    'state' => $lastVerification['state'] ?? null,
                    'checked_at' => $lastVerification['checked_at'] ?? null,
                    'metadata' => $lastVerification['metadata'] ?? [],
                ] : null,
            ]
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

    private function whatsappHealth(\PDO $pdo): array
    {
        try {
            $now = gmdate('Y-m-d H:i:s');
            $recentAgentCutoff = gmdate('Y-m-d H:i:s', time() - 180);
            $staleCutoff = gmdate('Y-m-d H:i:s', time() - 300);
            $dayCutoff = gmdate('Y-m-d H:i:s', time() - 86400);

            $connections = $pdo->query('SELECT COUNT(*) total,COALESCE(SUM(CASE WHEN automation_enabled=1 THEN 1 ELSE 0 END),0) automated FROM whatsapp_connections')->fetch() ?: [];
            $agents = $pdo->query('SELECT COUNT(*) total FROM whatsapp_desktop_agents')->fetch() ?: [];
            $recent = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_desktop_agents WHERE status="connected" AND last_seen_at>=?');
            $recent->execute([$recentAgentCutoff]);
            $recentConnected = (int)$recent->fetchColumn();
            $latestAgent = $pdo->query('SELECT tenant_id,device_label,status,phone_number,engine,last_seen_at,last_error FROM whatsapp_desktop_agents ORDER BY last_seen_at DESC,id DESC LIMIT 1')->fetch() ?: null;

            $stats = $pdo->query('SELECT COUNT(*) total,
                COALESCE(SUM(CASE WHEN status IN ("queued","desktop_queued") AND attempt_count<max_attempts THEN 1 ELSE 0 END),0) queued,
                COALESCE(SUM(CASE WHEN status IN ("failed","desktop_failed") AND attempt_count<max_attempts THEN 1 ELSE 0 END),0) retryable_failed,
                COALESCE(SUM(CASE WHEN status IN ("processing","desktop_processing") THEN 1 ELSE 0 END),0) processing,
                COALESCE(SUM(CASE WHEN status IN ("failed","desktop_failed") AND attempt_count>=max_attempts THEN 1 ELSE 0 END),0) terminal_failed,
                MIN(CASE WHEN status IN ("queued","desktop_queued","failed","desktop_failed") AND attempt_count<max_attempts THEN created_at ELSE NULL END) oldest_waiting_at
                FROM whatsapp_outbox')->fetch() ?: [];

            $stale = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE status IN ("processing","desktop_processing") AND ((claim_expires_at IS NOT NULL AND claim_expires_at<?) OR (claim_expires_at IS NULL AND locked_at IS NOT NULL AND locked_at<?))');
            $stale->execute([$now,$staleCutoff]);
            $staleClaims = (int)$stale->fetchColumn();
            $recentFailed = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_outbox WHERE status IN ("failed","desktop_failed") AND attempt_count>=max_attempts AND updated_at>=?');
            $recentFailed->execute([$dayCutoff]);
            $terminalFailed24h = (int)$recentFailed->fetchColumn();

            $waiting = (int)($stats['queued'] ?? 0) + (int)($stats['retryable_failed'] ?? 0);
            $oldestAge = $waiting > 0 ? $this->age($stats['oldest_waiting_at'] ?? null) : 0;
            $configured = (int)($connections['total'] ?? 0);
            $automated = (int)($connections['automated'] ?? 0);
            $agentCount = (int)($agents['total'] ?? 0);

            if ($configured === 0 && $agentCount === 0 && (int)($stats['total'] ?? 0) === 0) {
                return $this->check('disabled', 'WhatsApp ainda não configurado.', [
                    'connections' => 0,
                    'agents' => 0,
                    'waiting' => 0,
                ]);
            }

            $state = 'ok';
            if ($staleClaims > 0 || $terminalFailed24h > 0) $state = 'error';
            elseif ($waiting > 100 || ($waiting > 0 && $oldestAge > 300) || ($waiting > 0 && $recentConnected === 0) || (int)($stats['retryable_failed'] ?? 0) > 0) $state = 'warning';

            $message = match ($state) {
                'error' => 'WhatsApp / EventMenu Connect com falha operacional.',
                'warning' => 'WhatsApp / EventMenu Connect precisa de atenção.',
                default => $automated > 0 ? 'WhatsApp / EventMenu Connect saudável.' : 'WhatsApp configurado; automação desligada.',
            };
            return $this->check($state,$message,[
                'connections' => $configured,
                'automation_enabled' => $automated,
                'agents' => $agentCount,
                'recent_connected_agents' => $recentConnected,
                'queued' => (int)($stats['queued'] ?? 0),
                'retryable_failed' => (int)($stats['retryable_failed'] ?? 0),
                'processing' => (int)($stats['processing'] ?? 0),
                'terminal_failed' => (int)($stats['terminal_failed'] ?? 0),
                'terminal_failed_last_24h' => $terminalFailed24h,
                'stale_processing' => $staleClaims,
                'oldest_waiting_seconds' => $oldestAge,
                'latest_agent' => $latestAgent,
            ]);
        } catch (Throwable $e) {
            return $this->check('warning','Saúde do WhatsApp indisponível.',['error'=>$this->safe($e->getMessage())]);
        }
    }

    private function printQueueHealth(\PDO $pdo): array
    {
        try {
            $staleCutoff = gmdate('Y-m-d H:i:s', time() - 300);
            $dayCutoff = gmdate('Y-m-d H:i:s', time() - 86400);
            $stats = $pdo->query('SELECT COUNT(*) total,
                COALESCE(SUM(CASE WHEN status IN ("pending","error") AND attempts<5 THEN 1 ELSE 0 END),0) waiting,
                COALESCE(SUM(CASE WHEN status="error" AND attempts<5 THEN 1 ELSE 0 END),0) retryable_error,
                COALESCE(SUM(CASE WHEN status="processing" THEN 1 ELSE 0 END),0) processing,
                COALESCE(SUM(CASE WHEN status="failed" OR attempts>=5 THEN 1 ELSE 0 END),0) terminal_failed,
                MIN(CASE WHEN status IN ("pending","error") AND attempts<5 THEN created_at ELSE NULL END) oldest_waiting_at
                FROM production_print_queue')->fetch() ?: [];
            $stale = $pdo->prepare('SELECT COUNT(*) FROM production_print_queue WHERE status="processing" AND claimed_at IS NOT NULL AND claimed_at<?');
            $stale->execute([$staleCutoff]);
            $staleProcessing = (int)$stale->fetchColumn();
            $recentFailed = $pdo->prepare('SELECT COUNT(*) FROM production_print_queue WHERE (status="failed" OR attempts>=5) AND updated_at>=?');
            $recentFailed->execute([$dayCutoff]);
            $terminalFailed24h = (int)$recentFailed->fetchColumn();
            $lastFailure = $pdo->query('SELECT tenant_id,station_id,order_id,status,attempts,last_error,failed_at,updated_at FROM production_print_queue WHERE status IN ("error","failed") ORDER BY updated_at DESC,id DESC LIMIT 1')->fetch() ?: null;

            $waiting = (int)($stats['waiting'] ?? 0);
            $oldestAge = $waiting > 0 ? $this->age($stats['oldest_waiting_at'] ?? null) : 0;
            $state = 'ok';
            if ($staleProcessing > 0 || $terminalFailed24h > 0) $state = 'error';
            elseif ($waiting > 50 || ($waiting > 0 && $oldestAge > 300) || (int)($stats['retryable_error'] ?? 0) > 0) $state = 'warning';

            return $this->check(
                $state,
                $state === 'ok' ? 'Fila de impressão saudável.' : ($state === 'error' ? 'Fila de impressão com falha operacional.' : 'Fila de impressão precisa de atenção.'),
                [
                    'total' => (int)($stats['total'] ?? 0),
                    'waiting' => $waiting,
                    'retryable_error' => (int)($stats['retryable_error'] ?? 0),
                    'processing' => (int)($stats['processing'] ?? 0),
                    'terminal_failed' => (int)($stats['terminal_failed'] ?? 0),
                    'terminal_failed_last_24h' => $terminalFailed24h,
                    'stale_processing' => $staleProcessing,
                    'oldest_waiting_seconds' => $oldestAge,
                    'last_failure' => $lastFailure,
                ]
            );
        } catch (Throwable $e) {
            return $this->check('warning','Saúde da fila de impressão indisponível.',['error'=>$this->safe($e->getMessage())]);
        }
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
