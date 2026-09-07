<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class PaymentProviderRegistry
{
    private const PROVIDERS = [
        'sumup' => ['label'=>'SumUp','card_present'=>true,'pix'=>false,'webhook'=>false,'connection'=>'oauth'],
        'pagbank' => ['label'=>'PagBank','card_present'=>true,'pix'=>true,'webhook'=>true,'connection'=>'token'],
        'mercadopago' => ['label'=>'Mercado Pago','card_present'=>false,'pix'=>true,'webhook'=>true,'connection'=>'oauth'],
        'pagarme' => ['label'=>'Pagar.me','card_present'=>false,'pix'=>true,'webhook'=>true,'connection'=>'secret_key'],
        'efi' => ['label'=>'Efí','card_present'=>false,'pix'=>true,'webhook'=>true,'connection'=>'oauth_mtls'],
        'asaas' => ['label'=>'Asaas','card_present'=>false,'pix'=>true,'webhook'=>true,'connection'=>'api_key'],
        'openpix' => ['label'=>'OpenPix / Woovi','card_present'=>false,'pix'=>true,'webhook'=>true,'connection'=>'app_id'],
        'cielo' => ['label'=>'Cielo','card_present'=>true,'pix'=>false,'webhook'=>true,'connection'=>'merchant_credentials'],
        'stripe' => ['label'=>'Stripe','card_present'=>false,'pix'=>false,'webhook'=>true,'connection'=>'secret_key'],
    ];

    private const RUNTIME_DEFAULTS = [
        'card_present_enabled'=>false,
        'pix_enabled'=>true,
        'cash_enabled'=>true,
        'external_terminal_enabled'=>true,
        'external_terminal_reference_required'=>true,
    ];

    public function catalog(): array { return self::PROVIDERS; }
    public function supports(string $provider,string $capability): bool { return !empty(self::PROVIDERS[$provider][$capability]); }
    public function assertProvider(string $provider): void { if(!isset(self::PROVIDERS[$provider]))throw new RuntimeException('Provedor de pagamento não suportado.'); }

    public function gateway(int $tenantId,string $provider,bool $requireActive=true): array
    {
        $this->assertProvider($provider);$sql='SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=?'.($requireActive?' AND active=1':'').' LIMIT 1';$q=Database::connection()->prepare($sql);$q->execute([$tenantId,$provider]);$row=$q->fetch();if(!$row)throw new RuntimeException(self::PROVIDERS[$provider]['label'].' não está conectado nesta empresa.');$row['config']=Crypto::decryptJson($row['config_encrypted']);$row['webhook_secret']=Crypto::decrypt($row['webhook_secret_encrypted']);return$row;
    }

    public function preference(int $tenantId,string $capability): ?string
    {
        $column=match($capability){'card_present'=>'card_present_provider','pix'=>'pix_provider',default=>throw new RuntimeException('Capacidade de pagamento inválida.')};$q=Database::connection()->prepare('SELECT '.$column.' FROM payment_provider_preferences WHERE tenant_id=?');$q->execute([$tenantId]);$preferred=$q->fetchColumn();if(is_string($preferred)&&$preferred!==''&&$this->supports($preferred,$capability)&&$this->isActive($tenantId,$preferred))return$preferred;
        foreach(self::PROVIDERS as$code=>$meta)if(!empty($meta[$capability])&&$this->isActive($tenantId,$code))return$code;return null;
    }

    public function runtimeOptions(int $tenantId): array
    {
        try{
            $q=Database::connection()->prepare('SELECT card_present_enabled,pix_enabled,cash_enabled,external_terminal_enabled,external_terminal_reference_required FROM payment_provider_preferences WHERE tenant_id=? LIMIT 1');$q->execute([$tenantId]);$row=$q->fetch();
            if(!$row)return self::RUNTIME_DEFAULTS;
            return [
                'card_present_enabled'=>(bool)$row['card_present_enabled'],
                'pix_enabled'=>(bool)$row['pix_enabled'],
                'cash_enabled'=>(bool)$row['cash_enabled'],
                'external_terminal_enabled'=>(bool)$row['external_terminal_enabled'],
                'external_terminal_reference_required'=>(bool)$row['external_terminal_reference_required'],
            ];
        }catch(\Throwable){return self::RUNTIME_DEFAULTS;}
    }

    public function setRuntimeOptions(bool $cardPresent,bool $pix,bool $cash,bool $externalTerminal,bool $referenceRequired): void
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$pdo=Database::connection();
        if(Database::isSqlite($pdo)){
            $sql='INSERT INTO payment_provider_preferences (tenant_id,card_present_enabled,pix_enabled,cash_enabled,external_terminal_enabled,external_terminal_reference_required,updated_by,updated_at) VALUES (?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id) DO UPDATE SET card_present_enabled=excluded.card_present_enabled,pix_enabled=excluded.pix_enabled,cash_enabled=excluded.cash_enabled,external_terminal_enabled=excluded.external_terminal_enabled,external_terminal_reference_required=excluded.external_terminal_reference_required,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP';
        }else{
            $sql='INSERT INTO payment_provider_preferences (tenant_id,card_present_enabled,pix_enabled,cash_enabled,external_terminal_enabled,external_terminal_reference_required,updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE card_present_enabled=VALUES(card_present_enabled),pix_enabled=VALUES(pix_enabled),cash_enabled=VALUES(cash_enabled),external_terminal_enabled=VALUES(external_terminal_enabled),external_terminal_reference_required=VALUES(external_terminal_reference_required),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP';
        }
        $pdo->prepare($sql)->execute([$tenantId,$cardPresent?1:0,$pix?1:0,$cash?1:0,$externalTerminal?1:0,$referenceRequired?1:0,Auth::id()]);
        Auth::audit('payment.runtime_options','tenant',(string)$tenantId,['card_present'=>$cardPresent,'pix'=>$pix,'cash'=>$cash,'external_terminal'=>$externalTerminal,'external_terminal_reference_required'=>$referenceRequired]);
    }

    public function setPreferences(?string $cardProvider,?string $pixProvider,?string $pixFallback): void
    {
        Auth::requirePermission('gateways.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        foreach([[$cardProvider,'card_present'],[$pixProvider,'pix'],[$pixFallback,'pix']] as[$provider,$cap])if($provider!==null&&$provider!==''){if(!$this->supports($provider,$cap))throw new RuntimeException('Provedor não suporta '.$cap.'.');if(!$this->isActive($tenantId,$provider))throw new RuntimeException('Ative o provedor '.$provider.' antes de selecioná-lo.');}
        $pdo=Database::connection();if(Database::isSqlite($pdo))$sql='INSERT INTO payment_provider_preferences (tenant_id,card_present_provider,pix_provider,pix_fallback_provider,updated_by,updated_at) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id) DO UPDATE SET card_present_provider=excluded.card_present_provider,pix_provider=excluded.pix_provider,pix_fallback_provider=excluded.pix_fallback_provider,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP';else$sql='INSERT INTO payment_provider_preferences (tenant_id,card_present_provider,pix_provider,pix_fallback_provider,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE card_present_provider=VALUES(card_present_provider),pix_provider=VALUES(pix_provider),pix_fallback_provider=VALUES(pix_fallback_provider),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP';$pdo->prepare($sql)->execute([$tenantId,$cardProvider?:null,$pixProvider?:null,$pixFallback?:null,Auth::id()]);Auth::audit('payment.providers_preferences','tenant',(string)$tenantId,['card_present'=>$cardProvider,'pix'=>$pixProvider,'pix_fallback'=>$pixFallback]);
    }

    public function activeProviders(int $tenantId): array
    {
        $q=Database::connection()->prepare('SELECT provider,account_reference,active FROM payment_gateways WHERE tenant_id=? ORDER BY provider');$q->execute([$tenantId]);$rows=[];foreach($q->fetchAll()as$r){$code=(string)$r['provider'];$rows[$code]=['provider'=>$code,'account_reference'=>(string)$r['account_reference'],'active'=>(bool)$r['active'],'meta'=>self::PROVIDERS[$code]??['label'=>$code,'card_present'=>false,'pix'=>false,'webhook'=>false,'connection'=>'custom']];}return$rows;
    }

    private function isActive(int $tenantId,string $provider): bool
    {
        $q=Database::connection()->prepare('SELECT 1 FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 LIMIT 1');$q->execute([$tenantId,$provider]);return(bool)$q->fetchColumn();
    }
}
