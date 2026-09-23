<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;
use Throwable;

final class ProductMediaService
{
    /** @return array{url:string,path:string,mime:string,size:int} */
    public function replace(int $tenantId,int $productId,array $file):array
    {
        if($tenantId<1||$productId<1)throw new RuntimeException('Produto ou empresa inválidos.');
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT image_url FROM products WHERE id=? AND tenant_id=? LIMIT 1');
        $q->execute([$productId,$tenantId]);
        $current=$q->fetchColumn();
        if($current===false)throw new RuntimeException('Produto não encontrado.');

        $stored=$this->store($tenantId,$productId,$file);
        try{
            $u=$pdo->prepare('UPDATE products SET image_url=? WHERE id=? AND tenant_id=?');
            $u->execute([$stored['url'],$productId,$tenantId]);
            if($u->rowCount()!==1){
                $check=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=?');
                $check->execute([$productId,$tenantId]);
                if(!$check->fetchColumn())throw new RuntimeException('Produto não encontrado.');
            }
        }catch(Throwable $e){
            if(is_file($stored['path']))@unlink($stored['path']);
            throw $e;
        }
        $this->removeLocalUrl($tenantId,$productId,is_string($current)?$current:null);
        return$stored;
    }

    public function remove(int $tenantId,int $productId):void
    {
        if($tenantId<1||$productId<1)throw new RuntimeException('Produto ou empresa inválidos.');
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT image_url FROM products WHERE id=? AND tenant_id=? LIMIT 1');
        $q->execute([$productId,$tenantId]);
        $current=$q->fetchColumn();
        if($current===false)throw new RuntimeException('Produto não encontrado.');
        $pdo->prepare('UPDATE products SET image_url=NULL WHERE id=? AND tenant_id=?')->execute([$productId,$tenantId]);
        $this->removeLocalUrl($tenantId,$productId,is_string($current)?$current:null);
    }

    /** @return array{url:string,path:string,mime:string,size:int} */
    private function store(int $tenantId,int $productId,array $file):array
    {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber a foto do produto.');
        $tmp=(string)($file['tmp_name']??'');
        if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Upload da foto inválido.');
        $size=(int)($file['size']??0);
        if($size<1||$size>5*1024*1024)throw new RuntimeException('A foto deve ter no máximo 5 MB.');

        $finfo=new \finfo(FILEINFO_MIME_TYPE);
        $mime=(string)$finfo->file($tmp);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $ext=$allowed[$mime]??null;
        if(!$ext)throw new RuntimeException('Use foto JPG, PNG ou WebP.');
        $dimensions=@getimagesize($tmp);
        if(!$dimensions||(int)$dimensions[0]<1||(int)$dimensions[1]<1)throw new RuntimeException('Arquivo de imagem inválido.');
        if((int)$dimensions[0]>6000||(int)$dimensions[1]>6000)throw new RuntimeException('A foto é grande demais. Use até 6000 × 6000 pixels.');

        $root=dirname(__DIR__,2).'/storage/media/'.$tenantId;
        if(!is_dir($root)&&!mkdir($root,0775,true)&&!is_dir($root))throw new RuntimeException('Não foi possível criar a pasta de imagens.');
        $name='product-'.$productId.'-'.bin2hex(random_bytes(12)).'.'.$ext;
        $path=$root.'/'.$name;
        if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Não foi possível salvar a foto do produto.');
        @chmod($path,0640);

        return[
            'url'=>app_url('media.php?t='.$tenantId.'&n='.rawurlencode($name)),
            'path'=>$path,
            'mime'=>$mime,
            'size'=>$size,
        ];
    }

    private function removeLocalUrl(int $tenantId,int $productId,?string $url):void
    {
        $url=trim((string)$url);if($url==='')return;
        $query=parse_url($url,PHP_URL_QUERY);if(!is_string($query))return;
        parse_str($query,$params);
        if((int)($params['t']??0)!==$tenantId)return;
        $name=(string)($params['n']??'');
        if(!preg_match('/^product-'.$productId.'-[a-f0-9]{24}\.(jpg|png|webp)$/',$name))return;
        $path=dirname(__DIR__,2).'/storage/media/'.$tenantId.'/'.$name;
        if(is_file($path))@unlink($path);
    }
}
