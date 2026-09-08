<?php

declare(strict_types=1);

namespace EventMenu\Services;

final class ProductionReadinessService
{
    /** @return array{ready:bool,state:string,blockers:list<array{key:string,message:string}>,warnings:list<array{key:string,message:string}>,passed:list<array{key:string,message:string}>,security:array<string,mixed>} */
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

        $environment = strtolower(trim((string)($health['app']['environment'] ?? env('APP_ENV', 'production'))));
        $debug = (bool)($health['app']['debug'] ?? filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL));
        $appUrl = trim((string)env('APP_URL', ''));
        $scheme = strtolower((string)parse_url($appUrl, PHP_URL_SCHEME));
        $https = $scheme === 'https';
        $sessionSecure = filter_var(env('SESSION_SECURE', 'false'), FILTER_VALIDATE_BOOL);

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

        $optional = [
            'gateways' => 'Nenhum gateway eletrônico ativo. Dinheiro/operação local pode funcionar, mas cartão/Pix online exigem configuração.',
            'push' => 'Push instantâneo não está totalmente pronto. O app ainda pode usar sincronização periódica.',
            'webhooks' => 'Webhooks ainda não foram comprovados em operação real ou precisam de atenção.',
        ];
        foreach ($optional as $key => $message) {
            $check = is_array($checks[$key] ?? null) ? $checks[$key] : [];
            if (($check['state'] ?? 'warning') === 'ok') $passed[] = ['key' => $key, 'message' => (string)($check['message'] ?? $message)];
            else $warnings[] = ['key' => $key, 'message' => $message];
        }

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
            ],
        ];
    }
}
