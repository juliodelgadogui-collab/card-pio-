<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

final class ClientReleaseService
{
    private const PLATFORMS = ['android', 'windows'];

    /** @return array<string,mixed> */
    public function get(string $platform): array
    {
        $platform = $this->platform($platform);
        try {
            $stmt = Database::connection()->prepare('SELECT * FROM platform_client_releases WHERE platform=? LIMIT 1');
            $stmt->execute([$platform]);
            $row = $stmt->fetch();
            return $row ? $this->normalize($row) : $this->emptyRelease($platform);
        } catch (Throwable) {
            // Compatibilidade durante atualização gradual: o control plane continua
            // funcionando mesmo se os arquivos novos chegarem antes da migration 106.
            return $this->emptyRelease($platform);
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(string $platform, array $input): array
    {
        if (!Auth::isSuperAdmin()) throw new RuntimeException('Distribuição dos aplicativos restrita ao Super ADM.');
        if (!(new PlatformFailoverService())->isPrimaryNode()) throw new RuntimeException('A distribuição só pode ser alterada no servidor principal.');

        $platform = $this->platform($platform);
        $current = $this->get($platform);
        $published = array_key_exists('published', $input) ? !empty($input['published']) : (bool)$current['published'];
        $version = mb_substr(trim((string)($input['version'] ?? $current['version'])), 0, 40);
        $downloadUrl = $this->downloadUrl((string)($input['download_url'] ?? $current['download_url']));
        $sha256 = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($input['sha256'] ?? $current['sha256'])) ?? '');
        $notes = mb_substr(trim((string)($input['release_notes'] ?? $current['release_notes'])), 0, 1000);

        if ($sha256 !== '' && strlen($sha256) !== 64) throw new RuntimeException('SHA-256 da atualização precisa ter 64 caracteres hexadecimais.');
        if ($published && ($version === '' || $downloadUrl === '' || strlen($sha256) !== 64)) {
            throw new RuntimeException('Para publicar uma atualização informe versão, URL HTTPS e SHA-256 do arquivo.');
        }

        $pdo = Database::connection();
        try {
            $exists = $pdo->prepare('SELECT platform FROM platform_client_releases WHERE platform=?');
            $exists->execute([$platform]);
        } catch (Throwable $e) {
            throw new RuntimeException('Atualize o banco do EventMenu antes de publicar arquivos dos aplicativos.', 0, $e);
        }
        if ($exists->fetchColumn()) {
            $pdo->prepare('UPDATE platform_client_releases SET version=?,download_url=?,sha256=?,release_notes=?,published=?,config_version=config_version+1,updated_at=CURRENT_TIMESTAMP WHERE platform=?')
                ->execute([$version, $downloadUrl, $sha256, $notes !== '' ? $notes : null, $published ? 1 : 0, $platform]);
        } else {
            $pdo->prepare('INSERT INTO platform_client_releases (platform,version,download_url,sha256,release_notes,published) VALUES (?,?,?,?,?,?)')
                ->execute([$platform, $version, $downloadUrl, $sha256, $notes !== '' ? $notes : null, $published ? 1 : 0]);
        }

        Auth::audit('platform.client_release_saved', 'client_release', $platform, [
            'version' => $version,
            'published' => $published,
            'sha256' => $sha256,
        ]);
        return $this->get($platform);
    }

    private function platform(string $platform): string
    {
        $platform = strtolower(trim($platform));
        if (!in_array($platform, self::PLATFORMS, true)) throw new RuntimeException('Plataforma inválida.');
        return $platform;
    }

    private function downloadUrl(string $value): string
    {
        $url = trim($value);
        if ($url === '') return '';
        if (strlen($url) > 1000) throw new RuntimeException('URL da atualização é muito longa.');
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) throw new RuntimeException('URL da atualização inválida.');
        if (strtolower((string)$parts['scheme']) !== 'https') throw new RuntimeException('A atualização precisa usar HTTPS.');
        if (!empty($parts['user']) || !empty($parts['pass']) || !empty($parts['fragment'])) throw new RuntimeException('URL da atualização contém componentes não permitidos.');
        return $url;
    }

    /** @return array<string,mixed> */
    private function emptyRelease(string $platform): array
    {
        return [
            'platform' => $platform,
            'version' => '',
            'download_url' => '',
            'sha256' => '',
            'release_notes' => '',
            'published' => false,
            'config_version' => 0,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalize(array $row): array
    {
        $sha = strtoupper(preg_replace('/[^A-Fa-f0-9]/', '', (string)($row['sha256'] ?? '')) ?? '');
        return [
            'platform' => (string)$row['platform'],
            'version' => trim((string)($row['version'] ?? '')),
            'download_url' => trim((string)($row['download_url'] ?? '')),
            'sha256' => strlen($sha) === 64 ? $sha : '',
            'release_notes' => trim((string)($row['release_notes'] ?? '')),
            'published' => !empty($row['published']),
            'config_version' => (int)($row['config_version'] ?? 0),
        ];
    }
}
