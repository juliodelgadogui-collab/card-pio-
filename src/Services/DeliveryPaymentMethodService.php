<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use PDO;

final class DeliveryPaymentMethodService
{
    public function forOrder(PDO $pdo,int $accountId,int $orderId):array
    {
        $order=(new DeliveryCustomerMarketplaceService())->ownedOrder($pdo,$accountId,$orderId);$tenantId=(int)$order['tenant_id'];
        $q=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1 ORDER BY id');$q->execute([$tenantId]);$pix=[];$cards=[];
        foreach($q->fetchAll()as$row){
            $provider=strtolower((string)$row['provider']);$config=Crypto::decryptJson((string)$row['config_encrypted']);
            $pixEnabled=!array_key_exists('pix_enabled',$config)||filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL);
            if(in_array($provider,['mercadopago','pagbank','efi','inter'],true)&&$pixEnabled){
                $pix[]=['provider'=>$provider,'default'=>!empty($config['pix_default']),'priority'=>max(1,min(999,(int)($config['pix_priority']??100)))];
            }
            if($provider==='mercadopago'&&filter_var($config['card_enabled']??false,FILTER_VALIDATE_BOOL)){
                $publicKey=trim((string)($config['public_key']??''));if($publicKey!=='')$cards[]=['provider'=>'mercadopago','public_key'=>$publicKey,'max_installments'=>max(1,min(12,(int)($config['max_installments']??12))),'payment_types'=>['credit_card','debit_card']];
            }
        }
        usort($pix,static fn(array$a,array$b):int=>($b['default']<=>$a['default'])?:($a['priority']<=>$b['priority'])?:strcmp($a['provider'],$b['provider']));
        $t=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$t->execute([$tenantId]);$settings=json_decode((string)($t->fetchColumn()?:'{}'),true);if(!is_array($settings))$settings=[];
        return ['pix'=>$pix,'card'=>$cards,'cash'=>!empty($settings['delivery_cash_enabled']),'currency'=>'BRL','nfc'=>['available'=>false,'reason'=>'Aproximação não é usada no checkout do cliente.']];
    }
}
