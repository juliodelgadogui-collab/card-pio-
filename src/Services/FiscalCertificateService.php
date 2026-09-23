<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FiscalCertificateService
{
    public function saveA1(int $profileId,string $pfxBase64,string $password,string $storageScope='server'):array
    {
        Auth::requirePermission('fiscal.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$profileId<1)throw new RuntimeException('Perfil fiscal inválido.');
        if(!in_array($storageScope,['server','hybrid'],true))throw new RuntimeException('Modo de armazenamento do certificado inválido.');

        $raw=base64_decode(trim($pfxBase64),true);
        if($raw===false||$raw==='')throw new RuntimeException('Arquivo PFX/P12 inválido.');
        if(strlen($raw)>2*1024*1024)throw new RuntimeException('Certificado maior que o limite permitido.');
        if(strlen($password)>300)throw new RuntimeException('Senha do certificado inválida.');

        $pdo=Database::connection();
        $profile=$pdo->prepare('SELECT id,tenant_id FROM fiscal_profiles WHERE id=? AND tenant_id=? LIMIT 1');
        $profile->execute([$profileId,$tenantId]);
        if(!$profile->fetch())throw new RuntimeException('Perfil fiscal não encontrado.');

        $parsed=[];
        if(!openssl_pkcs12_read($raw,$parsed,$password))throw new RuntimeException('Não foi possível abrir o certificado. Confira o arquivo e a senha.');
        $certPem=(string)($parsed['cert']??'');
        if($certPem==='')throw new RuntimeException('O arquivo não contém certificado válido.');
        $info=openssl_x509_parse($certPem,false);
        if(!is_array($info))throw new RuntimeException('Não foi possível ler os dados do certificado.');

        $validFrom=isset($info['validFrom_time_t'])?gmdate('Y-m-d H:i:s',(int)$info['validFrom_time_t']):null;
        $validUntil=isset($info['validTo_time_t'])?gmdate('Y-m-d H:i:s',(int)$info['validTo_time_t']):null;
        if($validUntil!==null&&strtotime($validUntil)!==false&&strtotime($validUntil)<time())throw new RuntimeException('O certificado informado está expirado.');

        $subject=$this->distinguishedName($info['subject']??[]);
        $issuer=$this->distinguishedName($info['issuer']??[]);
        $serial=(string)($info['serialNumberHex']??$info['serialNumber']??'');
        $thumbprint=openssl_x509_fingerprint($certPem,'sha256')?:'';

        return Database::transaction(function(PDO $tx)use($tenantId,$profileId,$storageScope,$raw,$password,$subject,$issuer,$serial,$thumbprint,$validFrom,$validUntil):array{
            $tx->prepare('UPDATE fiscal_certificates SET status="disabled",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND fiscal_profile_id=? AND status="active"')->execute([$tenantId,$profileId]);
            $insert=$tx->prepare('INSERT INTO fiscal_certificates (tenant_id,fiscal_profile_id,certificate_type,storage_scope,pfx_encrypted,password_encrypted,subject_name,issuer_name,serial_number,thumbprint,valid_from,valid_until,status) VALUES (?, ?, "a1", ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")');
            $insert->execute([
                $tenantId,$profileId,$storageScope,
                Crypto::encrypt(base64_encode($raw)),Crypto::encrypt($password),
                $subject?:null,$issuer?:null,$serial?:null,$thumbprint?:null,$validFrom,$validUntil,
            ]);
            $id=(int)$tx->lastInsertId();
            Auth::audit('fiscal.certificate_saved','fiscal_certificate',(string)$id,['profile_id'=>$profileId,'type'=>'a1','storage_scope'=>$storageScope,'valid_until'=>$validUntil]);
            return $this->metadata($profileId,$tx);
        });
    }

    public function registerA3Reference(int $profileId,string $subject='',string $serial='',string $thumbprint='',?string $validUntil=null):array
    {
        Auth::requirePermission('fiscal.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$profileId<1)throw new RuntimeException('Perfil fiscal inválido.');
        $pdo=Database::connection();
        $profile=$pdo->prepare('SELECT id FROM fiscal_profiles WHERE id=? AND tenant_id=? LIMIT 1');
        $profile->execute([$profileId,$tenantId]);
        if(!$profile->fetchColumn())throw new RuntimeException('Perfil fiscal não encontrado.');
        return Database::transaction(function(PDO $tx)use($tenantId,$profileId,$subject,$serial,$thumbprint,$validUntil):array{
            $tx->prepare('UPDATE fiscal_certificates SET status="disabled",updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND fiscal_profile_id=? AND status="active"')->execute([$tenantId,$profileId]);
            $insert=$tx->prepare('INSERT INTO fiscal_certificates (tenant_id,fiscal_profile_id,certificate_type,storage_scope,subject_name,serial_number,thumbprint,valid_until,status) VALUES (?, ?, "a3", "local_reference", ?, ?, ?, ?, "active")');
            $insert->execute([$tenantId,$profileId,mb_substr(trim($subject),0,500)?:null,mb_substr(trim($serial),0,190)?:null,mb_substr(trim($thumbprint),0,190)?:null,$validUntil]);
            $id=(int)$tx->lastInsertId();
            Auth::audit('fiscal.certificate_a3_registered','fiscal_certificate',(string)$id,['profile_id'=>$profileId,'type'=>'a3']);
            return $this->metadata($profileId,$tx);
        });
    }

    public function metadata(int $profileId,?PDO $pdo=null):array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId||$profileId<1)throw new RuntimeException('Perfil fiscal inválido.');
        $pdo??=Database::connection();
        $s=$pdo->prepare('SELECT id,certificate_type,storage_scope,subject_name,issuer_name,serial_number,thumbprint,valid_from,valid_until,status,created_at,updated_at FROM fiscal_certificates WHERE tenant_id=? AND fiscal_profile_id=? ORDER BY CASE WHEN status="active" THEN 0 ELSE 1 END,id DESC LIMIT 1');
        $s->execute([$tenantId,$profileId]);
        $row=$s->fetch();
        return $row?:[];
    }

    public function secretsForServerSigning(int $profileId):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$profileId<1)throw new RuntimeException('Perfil fiscal inválido.');
        $s=Database::connection()->prepare('SELECT certificate_type,storage_scope,pfx_encrypted,password_encrypted,status,valid_until FROM fiscal_certificates WHERE tenant_id=? AND fiscal_profile_id=? AND status="active" ORDER BY id DESC LIMIT 1');
        $s->execute([$tenantId,$profileId]);
        $row=$s->fetch();
        if(!$row)throw new RuntimeException('Certificado fiscal ativo não configurado.');
        if((string)$row['certificate_type']!=='a1'||!in_array((string)$row['storage_scope'],['server','hybrid'],true))throw new RuntimeException('Este perfil exige certificado local A3/Desktop.');
        if(!empty($row['valid_until'])&&strtotime((string)$row['valid_until'])!==false&&strtotime((string)$row['valid_until'])<time())throw new RuntimeException('O certificado fiscal está expirado.');
        return ['pfx'=>base64_decode(Crypto::decrypt((string)$row['pfx_encrypted']),true)?:'','password'=>Crypto::decrypt((string)$row['password_encrypted'])];
    }

    private function distinguishedName(mixed $value):string
    {
        if(!is_array($value))return '';
        $parts=[];
        foreach(['CN','OU','O','L','ST','C'] as $key){if(isset($value[$key])&&$value[$key]!=='')$parts[]=$key.'='.(is_array($value[$key])?implode('+',$value[$key]):$value[$key]);}
        return mb_substr(implode(', ',$parts),0,500);
    }
}
