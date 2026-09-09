<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class WhatsAppSettingsService
{
    public function scopeKey(?int $tenantId): string
    {
        return $tenantId ? 'tenant:'.$tenantId : 'platform';
    }

    /** @return array<string,mixed> */
    public function get(?int $tenantId, bool $fallbackToPlatform=false): array
    {
        $row=$this->find($this->scopeKey($tenantId));
        if($row&&(int)$row['enabled']===1)return $this->publicRow($row);
        if($fallbackToPlatform&&$tenantId!==null){
            $platform=$this->find('platform');
            if($platform&&(int)$platform['enabled']===1)return $this->publicRow($platform);
        }
        return $row?$this->publicRow($row):$this->defaults($tenantId);
    }

    /** @return array<string,mixed>|null */
    public function effective(?int $tenantId): ?array
    {
        $row=$this->find($this->scopeKey($tenantId));
        if(!$row||(int)$row['enabled']!==1){
            if($tenantId!==null)$row=$this->find('platform');
        }
        if(!$row||(int)$row['enabled']!==1)return null;
        $token='';
        if(!empty($row['access_token_encrypted']))$token=Crypto::decrypt((string)$row['access_token_encrypted']);
        if($token==='')return null;
        return $this->publicRow($row)+['access_token'=>$token];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(?int $tenantId,array $input):array
    {
        $scope=$this->scopeKey($tenantId);
        $enabled=!empty($input['enabled'])?1:0;
        $phoneId=preg_replace('/\D+/','',(string)($input['phone_number_id']??''))?:'';
        $graph=trim((string)($input['graph_version']??'v25.0'));
        $confirmation=trim((string)($input['confirmation_template']??'eventmenu_order_confirmation'));
        $tracking=trim((string)($input['tracking_template']??'eventmenu_delivery_tracking'));
        $language=trim((string)($input['language_code']??'pt_BR'));
        $token=trim((string)($input['access_token']??''));

        if(!preg_match('/^v\d{1,2}\.\d$/',$graph))throw new RuntimeException('Versão da Graph API inválida. Ex.: v25.0.');
        foreach([$confirmation,$tracking] as $template){
            if(!preg_match('/^[a-z0-9_]{1,120}$/',$template))throw new RuntimeException('Nome de template do WhatsApp inválido. Use somente letras minúsculas, números e _.');
        }
        if(!preg_match('/^[A-Za-z]{2,5}(?:_[A-Za-z]{2,5})?$/',$language))throw new RuntimeException('Código de idioma inválido. Ex.: pt_BR.');
        if($enabled&&$phoneId==='')throw new RuntimeException('Informe o Phone Number ID do WhatsApp Cloud API.');

        $pdo=Database::connection();$existing=$this->find($scope);$encrypted=$existing['access_token_encrypted']??null;
        if($token!=='')$encrypted=Crypto::encrypt($token);
        if($enabled&&!$encrypted)throw new RuntimeException('Informe o access token permanente do WhatsApp Cloud API.');

        if($existing){
            $sql='UPDATE whatsapp_settings SET tenant_id=?,enabled=?,phone_number_id=?,access_token_encrypted=?,graph_version=?,confirmation_template=?,tracking_template=?,language_code=?,updated_at=CURRENT_TIMESTAMP WHERE scope_key=?';
            $pdo->prepare($sql)->execute([$tenantId,$enabled,$phoneId?:null,$encrypted,$graph,$confirmation,$tracking,$language,$scope]);
        }else{
            $sql='INSERT INTO whatsapp_settings (scope_key,tenant_id,enabled,phone_number_id,access_token_encrypted,graph_version,confirmation_template,tracking_template,language_code) VALUES (?,?,?,?,?,?,?,?,?)';
            $pdo->prepare($sql)->execute([$scope,$tenantId,$enabled,$phoneId?:null,$encrypted,$graph,$confirmation,$tracking,$language]);
        }
        return $this->get($tenantId);
    }

    /** @return array<string,mixed>|null */
    private function find(string $scope):?array
    {
        $s=Database::connection()->prepare('SELECT * FROM whatsapp_settings WHERE scope_key=? LIMIT 1');$s->execute([$scope]);$row=$s->fetch(PDO::FETCH_ASSOC);return$row?:null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function publicRow(array $row):array
    {
        return[
            'scope_key'=>(string)$row['scope_key'],'tenant_id'=>$row['tenant_id']!==null?(int)$row['tenant_id']:null,
            'enabled'=>(int)$row['enabled']===1,'phone_number_id'=>(string)($row['phone_number_id']??''),
            'graph_version'=>(string)($row['graph_version']??'v25.0'),'confirmation_template'=>(string)($row['confirmation_template']??'eventmenu_order_confirmation'),
            'tracking_template'=>(string)($row['tracking_template']??'eventmenu_delivery_tracking'),'language_code'=>(string)($row['language_code']??'pt_BR'),
            'has_access_token'=>!empty($row['access_token_encrypted']),
        ];
    }

    /** @return array<string,mixed> */
    private function defaults(?int $tenantId):array
    {
        return['scope_key'=>$this->scopeKey($tenantId),'tenant_id'=>$tenantId,'enabled'=>false,'phone_number_id'=>'','graph_version'=>'v25.0','confirmation_template'=>'eventmenu_order_confirmation','tracking_template'=>'eventmenu_delivery_tracking','language_code'=>'pt_BR','has_access_token'=>false];
    }
}
