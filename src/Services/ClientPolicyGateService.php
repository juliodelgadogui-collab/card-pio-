<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;
use Throwable;

final class ClientPolicyGateService
{
    /**
     * Aplica a política central quando a chamada declara ser de um cliente
     * EventMenu conhecido. Chamadas web tradicionais continuam inalteradas.
     */
    public function assertCurrentRequestAllowed(): void
    {
        $platform = strtolower(trim((string)($_SERVER['HTTP_X_EVENTMENU_CLIENT'] ?? '')));
        if (!in_array($platform, ['android', 'windows'], true)) return;

        try {
            $policy = (new ClientPolicyService())->get($platform);
        } catch (Throwable) {
            // Compatibilidade durante a primeira atualização: antes da migration
            // 105 existir, não bloqueia todo o sistema.
            return;
        }

        if (empty($policy['enabled'])) {
            throw new RuntimeException($platform === 'android'
                ? 'Esta versão do EventMenu GO foi desativada pelo administrador.'
                : 'Este EventMenu Desktop foi desativado pelo administrador.');
        }

        if (!empty($policy['maintenance'])) {
            $message = trim((string)($policy['maintenance_message'] ?? ''));
            throw new RuntimeException($message !== '' ? $message : 'Aplicativo temporariamente em manutenção.');
        }

        $clientVersion = trim((string)($_SERVER['HTTP_X_EVENTMENU_VERSION'] ?? ''));
        $minVersion = trim((string)($policy['min_version'] ?? ''));
        if ($minVersion !== '' && $clientVersion !== '' && version_compare($this->versionCore($clientVersion), $this->versionCore($minVersion), '<')) {
            throw new RuntimeException('Atualização obrigatória. Versão mínima permitida: ' . $minVersion . '.');
        }
        if ($minVersion !== '' && $clientVersion === '') {
            throw new RuntimeException('Versão do aplicativo não identificada. Atualize o cliente EventMenu.');
        }

        $allowed = (array)($policy['allowed_signing_fingerprints'] ?? []);
        if ($allowed !== []) {
            $received = $this->normalizeFingerprint((string)($_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT'] ?? ''));
            $normalizedAllowed = array_values(array_filter(array_map(fn($v) => $this->normalizeFingerprint((string)$v), $allowed)));
            if ($received === '' || !in_array($received, $normalizedAllowed, true)) {
                throw new RuntimeException('Assinatura deste aplicativo/programa não está autorizada.');
            }
        }
    }

    private function versionCore(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\d+(?:\.\d+){0,3}/', $value, $m)) return $m[0];
        return '0.0.0';
    }

    private function normalizeFingerprint(string $value): string
    {
        $hex = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', $value) ?? '');
        return strlen($hex) === 64 ? $hex : '';
    }
}
