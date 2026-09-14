<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class ClientReleaseDownloadService
{
    private const MAX_BYTES = 600_000_000;

    /** @return array{path:string,filename:string,mime:string,size:int,sha256:string,version:string} */
    public function resolve(string $platform): array
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, ['android', 'windows'], true)) throw new RuntimeException('Plataforma inválida.');

        $policy = new ClientPolicyService();
        $envelope = $policy->signedEnvelope($platform);
        $this->verifyEnvelope($envelope, $policy->publicKeyBundle(), $platform);
        $payloadRaw = base64_decode((string)$envelope['payload_b64'], true);
        if ($payloadRaw === false) throw new RuntimeException('Manifesto de atualização inválido.');
        $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        $release = is_array($payload['release'] ?? null) ? $payload['release'] : [];
        if (empty($release['published'])) throw new RuntimeException('Nenhuma atualização está publicada para esta plataforma.');

        $expected = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($release['sha256'] ?? '')) ?? '');
        $version = trim((string)($release['version'] ?? ''));
        if (strlen($expected) !== 64 || $version === '') throw new RuntimeException('Manifesto da atualização está incompleto.');

        $filename = $platform === 'android' ? 'EventMenu-GO.apk' : 'EventMenu-Desktop.exe';
        $dir = dirname(__DIR__, 2) . '/storage/client-releases';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível preparar a pasta privada de atualizações.');
        }
        $path = $dir . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Arquivo da atualização ainda não foi enviado para storage/client-releases/' . $filename . '.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) throw new RuntimeException('Arquivo da atualização possui tamanho inválido.');

        $actual = strtoupper((string)hash_file('sha256', $path));
        if (!hash_equals($expected, $actual)) {
            throw new RuntimeException('O arquivo armazenado neste servidor não corresponde ao SHA-256 publicado pelo servidor principal.');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'mime' => $platform === 'android' ? 'application/vnd.android.package-archive' : 'application/octet-stream',
            'size' => (int)$size,
            'sha256' => $actual,
            'version' => $version,
        ];
    }

    /** @param array<string,mixed> $envelope @param array{key_id:string,public_key_pem:string} $key */
    private function verifyEnvelope(array $envelope, array $key, string $platform): void
    {
        if ((string)($envelope['algorithm'] ?? '') !== 'RS256') throw new RuntimeException('Algoritmo do manifesto inválido.');
        if (!hash_equals((string)$key['key_id'], (string)($envelope['key_id'] ?? ''))) throw new RuntimeException('Manifesto assinado por chave inesperada.');
        $payloadRaw = base64_decode((string)($envelope['payload_b64'] ?? ''), true);
        $signature = base64_decode((string)($envelope['signature_b64'] ?? ''), true);
        if ($payloadRaw === false || $signature === false) throw new RuntimeException('Manifesto corrompido.');
        if (openssl_verify($payloadRaw, $signature, (string)$key['public_key_pem'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Assinatura do manifesto inválida.');
        }
        $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || (string)($payload['platform'] ?? '') !== $platform) throw new RuntimeException('Manifesto pertence a outra plataforma.');
        $issued = strtotime(trim((string)($payload['issued_at'] ?? '')));
        $expires = strtotime(trim((string)($payload['expires_at'] ?? '')));
        $now = time();
        if ($issued === false || $expires === false || $expires <= $issued || $issued > $now + 300 || $expires < $now - 300) {
            throw new RuntimeException('Manifesto da atualização expirou. Sincronize novamente o servidor adicional.');
        }
    }
}
