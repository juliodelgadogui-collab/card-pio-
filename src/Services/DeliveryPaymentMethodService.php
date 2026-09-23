<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use PDO;
use RuntimeException;

final class DeliveryPaymentMethodService
{
    public function forOrder(PDO $pdo,int $accountId,int $orderId):array
    {
        $order=(new DeliveryCustomerMarketplaceService())->ownedOrder($pdo,$accountId,$orderId);
        return $this->forTenant($pdo,(int)$order['tenant_id']);
    }

    public function forPublicOrder(PDO $pdo,string $publicToken):array
    {
        $publicToken=trim($publicToken);if($publicToken==='')throw new RuntimeException('Pedido inválido.');
        $q=$pdo->prepare('SELECT tenant_id FROM orders WHERE public_token=? LIMIT 1');$q->execute([$publicToken]);$tenantId=(int)($q->fetchColumn()?:0);if($tenantId<1)throw new RuntimeException('Pedido não encontrado.');
        return $this->forTenant($pdo,$tenantId);
    }

    public function forTenant(PDO $pdo,int $tenantId):array
    {
        if($tenantId<1)throw new RuntimeException('Restaurante inválido.');
        $q=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1 ORDER BY id');$q->execute([$tenantId]);$pix=[];$cards=[];
        foreach($q->fetchAll()as$row){
            $provider=strtolower((string)$row['provider']);$config=Crypto::decryptJson((string)$row['config_encrypted']);
            $pixEnabled=!array_key_exists('pix_enabled',$config)||filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL);
            if(in_array($provider,['mercadopago','pagbank','efi','inter'],true)&&$pixEnabled)$pix[]=['provider'=>$provider,'default'=>!empty($config['pix_default']),'priority'=>max(1,min(999,(int)($config['pix_priority']??100)))];
            if($provider==='mercadopago'&&filter_var($config['card_enabled']??false,FILTER_VALIDATE_BOOL)){
                $types=[];$credit=!array_key_exists('credit_enabled',$config)||filter_var($config['credit_enabled'],FILTER_VALIDATE_BOOL);$debit=!array_key_exists('debit_enabled',$config)||filter_var($config['debit_enabled'],FILTER_VALIDATE_BOOL);if($credit)$types[]='credit_card';if($debit)$types[]='debit_card';
                $publicKey=trim((string)($config['public_key']??''));if($publicKey!==''&&$types)$cards[]=['provider'=>'mercadopago','public_key'=>$publicKey,'max_installments'=>max(1,min(12,(int)($config['max_installments']??12))),'payment_types'=>$types];
            }
        }
        usort($pix,static fn(array$a,array$b):int=>($b['default']<=>$a['default'])?:($a['priority']<=>$b['priority'])?:strcmp($a['provider'],$b['provider']));
        $t=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$t->execute([$tenantId]);$settings=json_decode((string)($t->fetchColumn()?:'{}'),true);if(!is_array($settings))$settings=[];
        return ['pix'=>$pix,'card'=>$cards,'cash'=>!empty($settings['delivery_cash_enabled']),'currency'=>'BRL','nfc'=>['available'=>false,'reason'=>'Aproximação não é usada no checkout do cliente.']];
    }
}
