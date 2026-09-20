<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class BankPixGatewayService
{
    private const PROVIDERS=['efi','inter'];

    public function save(string $provider,string $accountReference,array $config,bool $active):void
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();$provider=strtolower(trim($provider));
        if(!$tenantId||!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Provedor Pix bancário inválido.');
        $config['pix_key']=$this->normalizePixKey((string)($config['pix_key']??''));
        $accountReference=$provider==='efi'?$config['pix_key']:trim($accountReference);
        if($active&&$accountReference==='')throw new RuntimeException('Informe a referência da conta recebedora.');
        if($active){
            foreach(['client_id','client_secret','pix_key','certificate_path']as$key)if(trim((string)($config[$key]??''))==='')throw new RuntimeException('Preencha credenciais, chave Pix e certificado do provedor.');
            if($provider==='inter'&&trim((string)($config['account_number']??''))==='')throw new RuntimeException('Informe a conta corrente do Banco Inter.');
            $cert=(string)$config['certificate_path'];$ext=strtolower(pathinfo($cert,PATHINFO_EXTENSION));
            if(!in_array($ext,['p12','pfx'],true)&&trim((string)($config['private_key_path']??''))==='')throw new RuntimeException('Certificados .crt/.cer/.pem exigem também a chave privada .key/.pem.');
            $files=new PaymentCertificateService();$files->resolve($cert);if(!in_array($ext,['p12','pfx'],true))$files->resolve((string)$config['private_key_path']);
        }
        $config['pix_enabled']=!array_key_exists('pix_enabled',$config)||filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL);
        $config['pix_priority']=max(1,min(999,(int)($config['pix_priority']??100)));
        $pdo=Database::connection();
        if(Database::isSqlite($pdo))$sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,NULL,?) ON CONFLICT(tenant_id,provider) DO UPDATE SET account_reference=excluded.account_reference,config_encrypted=excluded.config_encrypted,active=excluded.active';
        else$sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,NULL,?) ON DUPLICATE KEY UPDATE account_reference=VALUES(account_reference),config_encrypted=VALUES(config_encrypted),active=VALUES(active)';
        $pdo->prepare($sql)->execute([$tenantId,$provider,$accountReference,Crypto::encryptJson($config),$active?1:0]);
        Auth::audit('gateway.saved','gateway',$provider,['active'=>$active,'pix'=>true]);
    }

    private function normalizePixKey(string $value):string
    {
        $value=trim($value);if($value==='')return'';
        if(str_contains($value,'@'))return mb_strtolower($value);
        $digits=preg_replace('/\D+/','',$value)??'';
        if(in_array(strlen($digits),[11,14],true))return$digits;
        if(str_starts_with($value,'+'))return'+'.$digits;
        if(preg_match('/^[0-9a-fA-F-]{32,36}$/',$value))return strtolower($value);
        return$value;
    }
}
