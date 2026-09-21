<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class PaymentCertificateService
{
    private const MAX_BYTES = 262144;
    private const ALLOWED_EXTENSIONS = ['p12','pfx','pem','crt','cer','key'];

    public function storeUploaded(int $tenantId,array $file,string $label):string
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida para armazenar certificado.');
        $error=(int)($file['error']??UPLOAD_ERR_NO_FILE);
        if($error===UPLOAD_ERR_NO_FILE)return '';
        if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Não foi possível receber o arquivo de certificado.');
        $tmp=(string)($file['tmp_name']??'');$size=(int)($file['size']??0);$name=(string)($file['name']??'');
        if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Upload de certificado inválido.');
        if($size<1||$size>self::MAX_BYTES)throw new RuntimeException('O certificado deve ter no máximo 256 KB.');
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($ext,self::ALLOWED_EXTENSIONS,true))throw new RuntimeException('Formato de certificado não permitido.');
        $safeLabel=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($label))?:'cert';
        $relative='storage/private/payment-certificates/'.$tenantId;
        $dir=$this->root().'/'.$relative;
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar o armazenamento seguro de certificados.');
        @chmod($dir,0700);
        $filename=$safeLabel.'-'.bin2hex(random_bytes(12)).'.'.$ext;$target=$dir.'/'.$filename;
        if(!@move_uploaded_file($tmp,$target))throw new RuntimeException('Não foi possível salvar o certificado.');
        @chmod($target,0600);
        return $relative.'/'.$filename;
    }

    public function resolve(string $storedPath):string
    {
        $storedPath=trim(str_replace('\\','/',$storedPath));if($storedPath==='')throw new RuntimeException('Certificado não configurado.');
        $root=$this->root();$prefix='storage/private/payment-certificates/';
        if(!str_starts_with($storedPath,$prefix)||str_contains($storedPath,'../'))throw new RuntimeException('Caminho de certificado inválido.');
        $full=$root.'/'.$storedPath;$real=realpath($full);$safeRoot=realpath($root.'/storage/private/payment-certificates');
        if($real===false||$safeRoot===false||!str_starts_with($real,$safeRoot.DIRECTORY_SEPARATOR)||!is_file($real)||!is_readable($real))throw new RuntimeException('Arquivo de certificado não encontrado.');
        return $real;
    }

    public function remove(?string $storedPath):void
    {
        if(!$storedPath)return;
        try{$path=$this->resolve($storedPath);@unlink($path);}catch(\Throwable){}
    }

    private function root():string{return dirname(__DIR__,2);}
}
