<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class BrandingMediaService
{
    /** @return array{url:string,path:string,mime:string,size:int} */
    public function store(int $tenantId, array $file, string $slot): array
    {
        if ($tenantId < 1) throw new RuntimeException('Empresa inválida.');
        if (!in_array($slot, ['brand-logo','menu-logo','menu-cover'], true)) throw new RuntimeException('Tipo de imagem inválido.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a imagem.');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Upload de imagem inválido.');
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) throw new RuntimeException('A imagem deve ter no máximo 5 MB.');

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $ext = $allowed[$mime] ?? null;
        if (!$ext) throw new RuntimeException('Use imagem JPG, PNG ou WebP.');
        $dimensions = @getimagesize($tmp);
        if (!$dimensions || (int)$dimensions[0] < 1 || (int)$dimensions[1] < 1) throw new RuntimeException('Arquivo de imagem inválido.');
        if ((int)$dimensions[0] > 6000 || (int)$dimensions[1] > 6000) throw new RuntimeException('A imagem é grande demais. Use até 6000 × 6000 pixels.');

        $root = dirname(__DIR__, 2) . '/storage/media/' . $tenantId;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) throw new RuntimeException('Não foi possível criar a pasta de imagens.');
        $name = $slot . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
        $path = $root . '/' . $name;
        if (!move_uploaded_file($tmp, $path)) throw new RuntimeException('Não foi possível salvar a imagem.');
        @chmod($path, 0640);

        return [
            'url' => app_url('media.php?t='.$tenantId.'&n='.rawurlencode($name)),
            'path' => $path,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    public function removeLocalUrl(int $tenantId, ?string $url): void
    {
        $url = trim((string)$url);
        if ($url === '') return;
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return;
        parse_str($query, $params);
        if ((int)($params['t'] ?? 0) !== $tenantId) return;
        $name = (string)($params['n'] ?? '');
        if (!preg_match('/^(brand-logo|menu-logo|menu-cover)-[a-f0-9]{24}\.(jpg|png|webp)$/', $name)) return;
        $path = dirname(__DIR__, 2) . '/storage/media/' . $tenantId . '/' . $name;
        if (is_file($path)) @unlink($path);
    }
}
