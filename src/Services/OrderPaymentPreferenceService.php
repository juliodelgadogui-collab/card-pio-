<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class OrderPaymentPreferenceService
{
    private const METHODS=['pix','card_credit','card_debit','cash'];
    private const PROVIDERS=['mercadopago','pagbank','efi','inter'];

    public function set(PDO $pdo,int $tenantId,int $orderId,string $method,?string $provider=null,?int $changeForCents=null,string $source='unknown'):array
    {
        if($tenantId<1||$orderId<1)throw new RuntimeException('Pedido inválido.');
        $method=strtolower(trim($method));if(!in_array($method,self::METHODS,true))throw new RuntimeException('Forma de pagamento inválida.');
        $provider=$provider!==null?strtolower(trim($provider)):null;if($provider==='')$provider=null;if($provider!==null&&!in_array($provider,self::PROVIDERS,true))throw new RuntimeException('Provedor de pagamento inválido.');
        if($method==='cash')$provider=null;
        if($method!=='cash')$changeForCents=null;else $changeForCents=$changeForCents!==null?max(0,$changeForCents):null;
        $source=strtolower(trim($source));$source=preg_replace('/[^a-z0-9_-]+/','_',$source)??'unknown';$source=substr(trim($source,'_'),0,30);if($source==='')$source='unknown';

        $order=$pdo->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? LIMIT 1');$order->execute([$orderId,$tenantId]);if(!$order->fetchColumn())throw new RuntimeException('Pedido não encontrado.');
        $exists=$pdo->prepare('SELECT order_id FROM order_payment_preferences WHERE order_id=? AND tenant_id=? LIMIT 1');$exists->execute([$orderId,$tenantId]);
        if($exists->fetchColumn())$pdo->prepare('UPDATE order_payment_preferences SET method=?,provider=?,change_for_cents=?,source=?,updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND tenant_id=?')->execute([$method,$provider,$changeForCents,$source,$orderId,$tenantId]);
        else $pdo->prepare('INSERT INTO order_payment_preferences (order_id,tenant_id,method,provider,change_for_cents,source) VALUES (?,?,?,?,?,?)')->execute([$orderId,$tenantId,$method,$provider,$changeForCents,$source]);
        return $this->get($pdo,$tenantId,$orderId)??throw new RuntimeException('Não foi possível registrar a forma de pagamento.');
    }

    public function get(PDO $pdo,int $tenantId,int $orderId):?array
    {
        if($tenantId<1||$orderId<1)return null;$q=$pdo->prepare('SELECT method,provider,change_for_cents,source,updated_at FROM order_payment_preferences WHERE order_id=? AND tenant_id=? LIMIT 1');$q->execute([$orderId,$tenantId]);$row=$q->fetch();if(!$row)return null;
        return ['method'=>(string)$row['method'],'provider'=>$row['provider']!==null?(string)$row['provider']:null,'change_for_cents'=>$row['change_for_cents']!==null?(int)$row['change_for_cents']:null,'source'=>(string)$row['source'],'updated_at'=>$row['updated_at']];
    }
}
