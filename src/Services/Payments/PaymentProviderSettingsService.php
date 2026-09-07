<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class PaymentProviderSettingsService
{
    /** Campos que podem ser gravados pelo painel. Segredos vazios preservam o valor atual. */
    private const CONFIG_FIELDS = [
        'pagbank' => ['token','api_base','legacy_email','legacy_token','tap_on_app_key','tap_on_query_base'],
        'mercadopago' => ['access_token'],
        'pagarme' => ['secret_key'],
        'asaas' => ['api_key','api_base'],
        'openpix' => ['app_id'],
    ];

    private const SECRET_FIELDS = [
        'pagbank' => ['token','legacy_token','tap_on_app_key'],
        'mercadopago' => ['access_token'],
        'pagarme' => ['secret_key'],
        'asaas' => ['api_key'],
        'openpix' => ['app_id'],
    ];

    public function overview(): array
    {
        Auth::requirePermission('gateways.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $registry=new PaymentProviderRegistry();$catalog=$registry->catalog();
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT provider,account_reference,config_encrypted,active FROM payment_gateways WHERE tenant_id=?');$q->execute([$tenantId]);
        $rows=[];
        foreach($q->fetchAll() as$row){
            $code=(string)$row['provider'];$config=[];try{$config=Crypto::decryptJson($row['config_encrypted']);}catch(\Throwable){}
            $safe=[];
            foreach((self::CONFIG_FIELDS[$code]??[]) as$field){
                if(in_array($field,self::SECRET_FIELDS[$code]??[],true)){$safe[$field.'_configured']=trim((string)($config[$field]??''))!=='';continue;}
                $safe[$field]=(string)($config[$field]??'');
            }
            $rows[$code]=['provider'=>$code,'account_reference'=>(string)($row['account_reference']??''),'active'=>(bool)$row['active'],'config'=>$safe];
        }
        $p=$pdo->prepare('SELECT card_present_provider,pix_provider,pix_fallback_provider FROM payment_provider_preferences WHERE tenant_id=?');$p->execute([$tenantId]);$prefs=$p->fetch()?:[];
        return ['catalog'=>$catalog,'providers'=>$rows,'preferences'=>[
            'card_present_provider'=>(string)($prefs['card_present_provider']??''),
            'pix_provider'=>(string)($prefs['pix_provider']??''),
            'pix_fallback_provider'=>(string)($prefs['pix_fallback_provider']??''),
        ]];
    }

    public function saveGateway(string $provider,string $accountReference,array $incoming,bool $active): void
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $provider=strtolower(trim($provider));$registry=new PaymentProviderRegistry();$registry->assertProvider($provider);
        if(!isset(self::CONFIG_FIELDS[$provider]))throw new RuntimeException('Este provedor não usa configuração manual nesta tela.');
        $accountReference=mb_substr(trim($accountReference),0,190);
        if($active&&$accountReference==='')throw new RuntimeException('Informe a identificação/conta recebedora do provedor.');

        $pdo=Database::connection();$q=$pdo->prepare('SELECT config_encrypted,webhook_secret_encrypted FROM payment_gateways WHERE tenant_id=? AND provider=? LIMIT 1');$q->execute([$tenantId,$provider]);$old=$q->fetch();
        $config=[];if($old){try{$config=Crypto::decryptJson($old['config_encrypted']);}catch(\Throwable){}}
        foreach(self::CONFIG_FIELDS[$provider] as$field){
            if(!array_key_exists($field,$incoming))continue;
            $value=mb_substr(trim((string)$incoming[$field]),0,1000);
            if($value===''&&in_array($field,self::SECRET_FIELDS[$provider]??[],true))continue;
            if($value==='')unset($config[$field]);else$config[$field]=$value;
        }
        $this->validateRequired($provider,$config,$active);
        $encrypted=Crypto::encrypt($config);$webhook=$old['webhook_secret_encrypted']??null;
        if(Database::isSqlite($pdo)){
            $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,?,?) ON CONFLICT(tenant_id,provider) DO UPDATE SET account_reference=excluded.account_reference,config_encrypted=excluded.config_encrypted,webhook_secret_encrypted=COALESCE(excluded.webhook_secret_encrypted,payment_gateways.webhook_secret_encrypted),active=excluded.active';
        }else{
            $sql='INSERT INTO payment_gateways (tenant_id,provider,account_reference,config_encrypted,webhook_secret_encrypted,active) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE account_reference=VALUES(account_reference),config_encrypted=VALUES(config_encrypted),webhook_secret_encrypted=COALESCE(VALUES(webhook_secret_encrypted),webhook_secret_encrypted),active=VALUES(active)';
        }
        $pdo->prepare($sql)->execute([$tenantId,$provider,$accountReference,$encrypted,$webhook,$active?1:0]);
        Auth::audit('payment.provider_saved','gateway',$provider,['active'=>$active,'account_reference'=>$accountReference]);
    }

    public function setConnectedProviderActive(string $provider,bool $active): void
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        (new PaymentProviderRegistry())->assertProvider($provider);
        $q=Database::connection()->prepare('UPDATE payment_gateways SET active=? WHERE tenant_id=? AND provider=?');$q->execute([$active?1:0,$tenantId,$provider]);
        if($q->rowCount()<1)throw new RuntimeException('Conecte o provedor antes de ativá-lo.');
        Auth::audit('payment.provider_active','gateway',$provider,['active'=>$active]);
    }

    private function validateRequired(string $provider,array $config,bool $active): void
    {
        if(!$active)return;
        $required=match($provider){
            'pagbank'=>['token'],
            'mercadopago'=>['access_token'],
            'pagarme'=>['secret_key'],
            'asaas'=>['api_key'],
            'openpix'=>['app_id'],
            default=>[],
        };
        foreach($required as$field)if(trim((string)($config[$field]??''))==='')throw new RuntimeException('Credencial obrigatória ausente para '.$provider.'.');
    }
}
