<?php

declare(strict_types=1);

namespace EventMenu\Services;

final class ProductionReadinessService
{
    /** @return array{ready:bool,state:string,blockers:list<array{key:string,message:string}>,warnings:list<array{key:string,message:string}>,passed:list<array{key:string,message:string}>,security:array<string,mixed>,payments:array{ready:bool,state:string,blockers:list<array{key:string,message:string}>,warnings:list<array{key:string,message:string}>,passed:list<array{key:string,message:string}>}} */
    public function evaluate(array $health): array
    {
        $blockers = [];
        $warnings = [];
        $passed = [];
        $checks = is_array($health['checks'] ?? null) ? $health['checks'] : [];

        $required = [
            'database' => 'Banco de dados precisa estar acessível.',
            'storage' => 'A pasta de armazenamento precisa estar gravável.',
            'cron' => 'O cron precisa executar regularmente.',
            'worker' => 'O worker da fila precisa estar ativo.',
            'queue' => 'A fila não pode estar atrasada, travada ou com falhas pendentes.',
            'backup' => 'É necessário ter um backup recente e verificado.',
        ];
        foreach ($required as $key => $fallback) {
            $check = is_array($checks[$key] ?? null) ? $checks[$key] : [];
            $state = (string)($check['state'] ?? 'warning');
            $message = trim((string)($check['message'] ?? '')) ?: $fallback;
            if ($state === 'ok') $passed[] = ['key' => $key, 'message' => $message];
            else $blockers[] = ['key' => $key, 'message' => $fallback];
        }

        $app = is_array($health['app'] ?? null) ? $health['app'] : [];
        $environment = strtolower(trim((string)($app['environment'] ?? env('APP_ENV', 'production'))));
        $debug = (bool)($app['debug'] ?? filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
        $appUrl = trim((string)($app['url'] ?? env('APP_URL', '')));
        $scheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
        $https = $scheme === 'https';
        $sessionSecure = (bool)($app['session_secure'] ?? filter_var(env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOL));
        $appKey = (string)($app['key'] ?? env('APP_KEY', ''));
        $appKeyStrong = $this->strongSecret($appKey);

        if ($environment !== 'production') {
            $blockers[] = ['key' => 'environment', 'message' => 'APP_ENV precisa estar em production antes do go-live.'];
        } else {
            $passed[] = ['key' => 'environment', 'message' => 'Ambiente configurado como production.'];
        }
        if ($debug) {
            $blockers[] = ['key' => 'debug', 'message' => 'APP_DEBUG precisa estar desligado em produção.'];
        } else {
            $passed[] = ['key' => 'debug', 'message' => 'Modo debug está desligado.'];
        }
        if (!$https) {
            $blockers[] = ['key' => 'https', 'message' => 'APP_URL precisa usar HTTPS em produção.'];
        } else {
            $passed[] = ['key' => 'https', 'message' => 'URL principal usa HTTPS.'];
        }
        if (!$sessionSecure) {
            $blockers[] = ['key' => 'session_secure', 'message' => 'SESSION_SECURE precisa estar ativado em produção.'];
        } else {
            $passed[] = ['key' => 'session_secure', 'message' => 'Cookies de sessão estão marcados como seguros.'];
        }
        if (!$appKeyStrong) {
            $blockers[] = ['key' => 'app_key', 'message' => 'APP_KEY precisa ser um segredo forte e exclusivo antes do go-live.'];
        } else {
            $passed[] = ['key' => 'app_key', 'message' => 'Chave principal da aplicação está configurada.'];
        }

        $gateway = is_array($checks['gateways'] ?? null) ? $checks['gateways'] : [];
        if (($gateway['state'] ?? 'warning') === 'ok') {
            $passed[] = ['key' => 'gateways', 'message' => (string)($gateway['message'] ?? 'Gateways ativos encontrados.')];
        } else {
            $warnings[] = ['key' => 'gateways', 'message' => 'Nenhum gateway eletrônico ativo. Dinheiro/operação local pode funcionar, mas cartão/Pix online exigem configuração.'];
        }

        $push = is_array($checks['push'] ?? null) ? $checks['push'] : [];
        if (($push['state'] ?? 'warning') === 'ok') {
            $passed[] = ['key' => 'push', 'message' => (string)($push['message'] ?? 'Push instantâneo configurado.')];
        } else {
            $warnings[] = ['key' => 'push', 'message' => 'Push instantâneo não está totalmente pronto. O app ainda pode usar sincronização periódica.'];
        }

        $whatsapp = is_array($checks['whatsapp'] ?? null) ? $checks['whatsapp'] : [];
        $whatsappState = (string)($whatsapp['state'] ?? 'disabled');
        if ($whatsappState === 'ok') {
            $passed[] = ['key' => 'whatsapp', 'message' => (string)($whatsapp['message'] ?? 'WhatsApp / EventMenu Connect saudável.')];
        } elseif ($whatsappState === 'disabled') {
            $passed[] = ['key' => 'whatsapp_optional', 'message' => 'WhatsApp não está configurado; este canal opcional não bloqueia a operação principal.'];
        } else {
            $warnings[] = ['key' => 'whatsapp', 'message' => 'WhatsApp / EventMenu Connect está ativo ou possui fila e precisa de atenção antes de ampliar o uso deste canal.'];
        }

        $printQueue = is_array($checks['print_queue'] ?? null) ? $checks['print_queue'] : [];
        if (($printQueue['state'] ?? 'warning') === 'ok') {
            $passed[] = ['key' => 'print_queue', 'message' => (string)($printQueue['message'] ?? 'Fila de impressão saudável.')];
        } else {
            $warnings[] = ['key' => 'print_queue', 'message' => 'A fila automática de impressão precisa de atenção; a operação deve confirmar impressões antes do go-live.'];
        }

        $webhooks = is_array($checks['webhooks'] ?? null) ? $checks['webhooks'] : [];
        $webhookLast = $webhooks['details']['last'] ?? null;
        if (($webhooks['state'] ?? 'warning') === 'ok' && is_array($webhookLast) && $webhookLast !== []) {
            $passed[] = ['key' => 'webhooks', 'message' => (string)($webhooks['message'] ?? 'Webhooks estão sendo registrados.')];
        } else {
            $warnings[] = ['key' => 'webhooks', 'message' => 'Webhooks ainda não foram comprovados em operação real ou precisam de atenção.'];
        }

        $driver = strtolower((string)($checks['database']['details']['driver'] ?? ''));
        if ($driver === 'sqlite') {
            $warnings[] = [
                'key' => 'database_capacity',
                'message' => 'SQLite está funcional, mas para alto volume e muitos operadores simultâneos prefira MySQL/MariaDB antes de escalar a operação.',
            ];
        }

        $paymentBlockers = [];
        $paymentWarnings = [];
        $paymentPassed = [];

        // Pagamento real nunca pode ser considerado pronto se a própria instalação estiver bloqueada.
        foreach ($blockers as $item) {
            $paymentBlockers[] = [
                'key' => 'system_' . (string)$item['key'],
                'message' => 'Sistema: ' . (string)$item['message'],
            ];
        }

        if (($gateway['state'] ?? 'warning') === 'ok') {
            $paymentPassed[] = ['key' => 'gateway_live', 'message' => 'Existe gateway eletrônico ativo para receber pagamentos.'];
        } else {
            $paymentBlockers[] = ['key' => 'gateway_live', 'message' => 'Configure e ative pelo menos um gateway eletrônico antes de liberar Pix/cartão real.'];
        }

        // webhook_events só recebe registro depois da validação de autenticidade no GatewayService.
        // Portanto qualquer evento registrado comprova que ao menos um webhook válido chegou ao servidor.
        if (is_array($webhookLast) && $webhookLast !== []) {
            $paymentPassed[] = ['key' => 'webhook_live', 'message' => 'O servidor já recebeu e validou webhook real de gateway.'];
        } else {
            $paymentBlockers[] = ['key' => 'webhook_live', 'message' => 'Ainda falta comprovar o recebimento de um webhook válido de pagamento.'];
        }

        $failedWebhooks = max(0, (int)($webhooks['details']['failed_last_24h'] ?? 0));
        if ($failedWebhooks > 0) {
            $paymentWarnings[] = [
                'key' => 'webhook_failures',
                'message' => $failedWebhooks . ' webhook(s) falharam nas últimas 24 horas. Revise antes de ampliar o volume.',
            ];
        } elseif (is_array($webhookLast) && $webhookLast !== []) {
            $paymentPassed[] = ['key' => 'webhook_failures', 'message' => 'Nenhuma falha de webhook registrada nas últimas 24 horas.'];
        }

        if ($driver === 'sqlite') {
            $paymentWarnings[] = [
                'key' => 'payment_database_capacity',
                'message' => 'Pagamentos podem operar em SQLite no início, mas MySQL/MariaDB é recomendado antes de alto volume simultâneo.',
            ];
        }

        $paymentReady = $paymentBlockers === [];

        return [
            'ready' => $blockers === [],
            'state' => $blockers === [] ? 'ready' : 'blocked',
            'blockers' => $blockers,
            'warnings' => $warnings,
            'passed' => $passed,
            'security' => [
                'environment' => $environment,
                'debug' => $debug,
                'https' => $https,
                'session_secure' => $sessionSecure,
                'app_key_strong' => $appKeyStrong,
            ],
            'payments' => [
                'ready' => $paymentReady,
                'state' => $paymentReady ? 'ready' : 'blocked',
                'blockers' => $paymentBlockers,
                'warnings' => $paymentWarnings,
                'passed' => $paymentPassed,
            ],
        ];
    }

    private function strongSecret(string $value): bool
    {
        $value = trim($value);
        if (strlen($value) < 32) return false;
        $lower = strtolower($value);
        foreach (['change-me', 'changeme', 'example', 'default', 'password'] as $unsafe) {
            if (str_contains($lower, $unsafe)) return false;
        }
        return true;
    }
}
