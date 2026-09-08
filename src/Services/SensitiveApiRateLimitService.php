<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class SensitiveApiRateLimitService
{
    /** @var array<string,array{bucket:string,limit:int,window:int,message:string}> */
    private const POLICIES = [
        'cancel.request' => ['bucket'=>'cancel.request','limit'=>20,'window'=>300,'message'=>'Muitas solicitações de cancelamento em pouco tempo. Aguarde alguns minutos.'],
        'cancel.approve' => ['bucket'=>'cancel.approve','limit'=>60,'window'=>300,'message'=>'Muitas aprovações de cancelamento em pouco tempo. Aguarde alguns instantes.'],
        'cancel.reject' => ['bucket'=>'cancel.reject','limit'=>60,'window'=>300,'message'=>'Muitas recusas de cancelamento em pouco tempo. Aguarde alguns instantes.'],
        'discount.request' => ['bucket'=>'discount.request','limit'=>30,'window'=>300,'message'=>'Muitas solicitações de desconto em pouco tempo. Aguarde alguns minutos.'],
        'discount.approve' => ['bucket'=>'discount.approve','limit'=>60,'window'=>300,'message'=>'Muitas aprovações de desconto em pouco tempo. Aguarde alguns instantes.'],
        'discount.reject' => ['bucket'=>'discount.reject','limit'=>60,'window'=>300,'message'=>'Muitas recusas de desconto em pouco tempo. Aguarde alguns instantes.'],
        'loyalty.apply' => ['bucket'=>'loyalty.apply','limit'=>30,'window'=>300,'message'=>'Muitas tentativas de uso de pontos em pouco tempo. Aguarde alguns instantes.'],
        'loyalty.remove' => ['bucket'=>'loyalty.remove','limit'=>60,'window'=>300,'message'=>'Muitas alterações de resgate de pontos em pouco tempo. Aguarde alguns instantes.'],
        'qr.issue' => ['bucket'=>'qr.issue','limit'=>30,'window'=>3600,'message'=>'Muitos QR Codes emitidos por este aparelho. Aguarde antes de emitir novos códigos.'],
        'qr.revoke' => ['bucket'=>'qr.revoke','limit'=>60,'window'=>3600,'message'=>'Muitas revogações de QR Code em pouco tempo. Aguarde alguns minutos.'],
        'device.request_nfc' => ['bucket'=>'device.request_nfc','limit'=>5,'window'=>3600,'message'=>'Muitas solicitações de autorização NFC. Aguarde antes de tentar novamente.'],
        'tab.group_create' => ['bucket'=>'tab.group_create','limit'=>60,'window'=>300,'message'=>'Muitas divisões de conta criadas em pouco tempo. Aguarde alguns instantes.'],
        'tab.group_cancel' => ['bucket'=>'tab.group_cancel','limit'=>60,'window'=>300,'message'=>'Muitos cancelamentos de divisão de conta em pouco tempo. Aguarde alguns instantes.'],
        'tab.pix_create' => ['bucket'=>'tab.pix_create','limit'=>30,'window'=>300,'message'=>'Muitas cobranças Pix criadas em pouco tempo. Aguarde alguns instantes.'],
        'tab.nfc_intent' => ['bucket'=>'tab.nfc_intent','limit'=>30,'window'=>300,'message'=>'Muitas tentativas de pagamento por aproximação em pouco tempo. Aguarde alguns instantes.'],
        'tab.nfc_verify' => ['bucket'=>'tab.nfc_verify','limit'=>45,'window'=>300,'message'=>'Muitas verificações de pagamento por aproximação em pouco tempo. Aguarde alguns instantes.'],
    ];

    public function assertAllowed(string $action,array $user,string $deviceId):void
    {
        $policy=self::POLICIES[$action]??null;
        if(!$policy)throw new RuntimeException('Política de rate limit não configurada para esta ação.');
        $tenantId=(int)($user['tenant_id']??0);$userId=(int)($user['id']??0);$tokenId=(int)($user['token_id']??0);
        if($tenantId<1||$userId<1)throw new RuntimeException('Sessão inválida para proteção da operação.');
        $deviceId=trim($deviceId);$devicePart=$deviceId!==''?$deviceId:'token-'.$tokenId;
        $subject=$tenantId.':'.$userId.':'.$devicePart;
        (new ApiRateLimitService())->assertAllowed($policy['bucket'],$subject,$policy['limit'],$policy['window'],$policy['message']);
    }

    /** @return array{bucket:string,limit:int,window:int,message:string}|null */
    public function policy(string $action):?array
    {
        return self::POLICIES[$action]??null;
    }

    /** @return list<string> */
    public function actions():array
    {
        return array_keys(self::POLICIES);
    }
}
