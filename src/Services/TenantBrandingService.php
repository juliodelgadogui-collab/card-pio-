<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;

final class TenantBrandingService
{
    public function forTenant(int $tenantId): array
    {
        $pdo = Database::connection();
        $q = $pdo->prepare('SELECT name,settings FROM tenants WHERE id=? LIMIT 1');
        $q->execute([$tenantId]);
        $tenant = $q->fetch() ?: ['name'=>'EventMenu','settings'=>'{}'];
        $settings = json_decode((string)($tenant['settings'] ?? '{}'), true);
        if (!is_array($settings)) $settings = [];

        $primary = $this->color($settings['brand_primary_color'] ?? $settings['menu_primary_color'] ?? null, '#5B34D6');
        $secondary = $this->color($settings['brand_secondary_color'] ?? null, '#159B63');
        $background = $this->color($settings['brand_background_color'] ?? $settings['menu_background_color'] ?? null, '#F6F7FB');
        $surface = $this->color($settings['brand_surface_color'] ?? $settings['menu_surface_color'] ?? null, '#FFFFFF');
        $text = $this->color($settings['brand_text_color'] ?? $settings['menu_text_color'] ?? null, '#1E1B2B');
        $logo = $this->logoUrl($tenantId, $settings);
        $display = trim((string)($settings['brand_display_name'] ?? '')) ?: (string)$tenant['name'];

        return [
            'display_name' => mb_substr($display, 0, 90),
            'logo_url' => $logo,
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'background_color' => $background,
            'surface_color' => $surface,
            'text_color' => $text,
            'apply_app' => array_key_exists('brand_apply_app',$settings) ? (bool)$settings['brand_apply_app'] : true,
            'apply_panel' => array_key_exists('brand_apply_panel',$settings) ? (bool)$settings['brand_apply_panel'] : true,
            'sync_public_menu' => !empty($settings['brand_sync_public_menu']),
        ];
    }

    public function saveUploadedLogo(int $tenantId,array $file):?string
    {
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error===UPLOAD_ERR_NO_FILE)return null;
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber a logo.');
        $tmp=(string)($file['tmp_name']??'');
        $size=(int)($file['size']??0);
        if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Arquivo de logo inválido.');
        if($size<1||$size>3*1024*1024)throw new RuntimeException('A logo deve ter no máximo 3 MB.');

        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp)?:'';
        $ext=match($mime){'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',default=>throw new RuntimeException('Use uma logo PNG, JPG ou WEBP.')};
        $dir=dirname(__DIR__,2).'/storage/branding/'.$tenantId;
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível preparar o armazenamento da logo.');
        foreach(glob($dir.'/logo.*')?:[] as$old)@unlink($old);
        $path=$dir.'/logo.'.$ext;
        if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Não foi possível salvar a logo.');
        @chmod($path,0664);
        return 'logo.'.$ext;
    }

    public function logoFile(int $tenantId):?array
    {
        $pdo=Database::connection();$q=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$q->execute([$tenantId]);$raw=$q->fetchColumn();if($raw===false)return null;
        $settings=json_decode((string)$raw,true);if(!is_array($settings))return null;
        $name=basename((string)($settings['brand_logo_file']??''));if(!preg_match('/^logo\.(png|jpg|webp)$/',$name))return null;
        $path=dirname(__DIR__,2).'/storage/branding/'.$tenantId.'/'.$name;if(!is_file($path))return null;
        $mime=match(pathinfo($name,PATHINFO_EXTENSION)){'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp',default=>'application/octet-stream'};
        return ['path'=>$path,'mime'=>$mime,'mtime'=>(int)filemtime($path)];
    }

    public function cssVariables(int $tenantId): string
    {
        $b = $this->forTenant($tenantId);
        if (!$b['apply_panel']) return '';
        $primary2 = $this->mix($b['primary_color'], '#FFFFFF', 0.12);
        $primarySoft = $this->mix($b['primary_color'], '#FFFFFF', 0.88);
        $sidebar2 = $this->mix($b['primary_color'], '#000000', 0.46);
        $sidebar = $this->mix($b['primary_color'], '#000000', 0.58);
        return sprintf(
            '--em-primary:%s;--em-primary-2:%s;--em-primary-soft:%s;--em-sidebar:%s;--em-sidebar-2:%s;--em-bg:%s;--em-surface:%s;--em-text:%s;--accent:%s;--bg:%s;--panel:%s;--text:%s;',
            $b['primary_color'],$primary2,$primarySoft,$sidebar,$sidebar2,$b['background_color'],$b['surface_color'],$b['text_color'],$b['primary_color'],$b['background_color'],$b['surface_color'],$b['text_color']
        );
    }

    private function logoUrl(int $tenantId,array $settings):string
    {
        if(!empty($settings['brand_logo_file'])){
            $file=$this->logoFile($tenantId);
            if($file&&function_exists('app_url'))return app_url('brand-asset.php?tenant='.$tenantId.'&v='.$file['mtime']);
        }
        return $this->url($settings['brand_logo_url'] ?? $settings['menu_logo_url'] ?? '');
    }

    private function color(mixed $value,string $fallback):string
    {
        $v=strtoupper(trim((string)$value));
        return preg_match('/^#[0-9A-F]{6}$/',$v)?$v:$fallback;
    }

    private function url(mixed $value):string
    {
        $v=trim((string)$value);
        if($v===''||!filter_var($v,FILTER_VALIDATE_URL))return'';
        $scheme=strtolower((string)parse_url($v,PHP_URL_SCHEME));
        return in_array($scheme,['http','https'],true)?mb_substr($v,0,600):'';
    }

    private function mix(string $a,string $b,float $towardB):string
    {
        $towardB=max(0,min(1,$towardB));
        $ar=hexdec(substr($a,1,2));$ag=hexdec(substr($a,3,2));$ab=hexdec(substr($a,5,2));
        $br=hexdec(substr($b,1,2));$bg=hexdec(substr($b,3,2));$bb=hexdec(substr($b,5,2));
        $r=(int)round($ar+($br-$ar)*$towardB);$g=(int)round($ag+($bg-$ag)*$towardB);$bl=(int)round($ab+($bb-$ab)*$towardB);
        return sprintf('#%02X%02X%02X',$r,$g,$bl);
    }
}
