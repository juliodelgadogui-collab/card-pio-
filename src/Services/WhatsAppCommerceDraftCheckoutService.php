<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class WhatsAppCommerceDraftCheckoutService
{
    private ConfiguredOrderService $configured;

    public function __construct()
    {
        $this->configured=new ConfiguredOrderService();
    }

    /** @return array<string,mixed> */
    public function validateAndRefresh(PDO $pdo,int $tenantId,int $draftId):array
    {
        $order=$pdo->prepare("SELECT * FROM orders WHERE id=? AND tenant_id=? AND status='draft' AND order_source='WHATSAPP' LIMIT 1");
        $order->execute([$draftId,$tenantId]);
        $draft=$order->fetch(PDO::FETCH_ASSOC);
        if(!$draft)throw new RuntimeException('Carrinho do WhatsApp não está mais disponível.');

        $q=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');
        $q->execute([$draftId]);
        $items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$items)throw new RuntimeException('Carrinho vazio.');

        $subtotal=0;
        foreach($items as$item){
            $mods=$pdo->prepare('SELECT modifier_option_id FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=? AND modifier_option_id IS NOT NULL');
            $mods->execute([$tenantId,$draftId,(int)$item['id']]);
            $optionIds=array_map('intval',$mods->fetchAll(PDO::FETCH_COLUMN)?:[]);
            try{
                $resolved=$this->configured->resolveLine($pdo,$tenantId,[
                    'product_id'=>(int)$item['product_id'],
                    'qty'=>(float)$item['quantity'],
                    'option_ids'=>$optionIds,
                    'notes'=>(string)($item['notes']??''),
                ],true);
            }catch(RuntimeException $e){
                throw new RuntimeException('Revise “'.(string)$item['name_snapshot'].'”: '.$e->getMessage(),0,$e);
            }
            $pdo->prepare('UPDATE order_items SET name_snapshot=?,unit_price_cents=?,quantity=?,total_cents=? WHERE id=? AND order_id=?')
                ->execute([$resolved['name'],$resolved['unit_price_cents'],$resolved['quantity'],$resolved['total_cents'],(int)$item['id'],$draftId]);
            $pdo->prepare('DELETE FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=?')
                ->execute([$tenantId,$draftId,(int)$item['id']]);
            $this->configured->persistModifiers($pdo,$tenantId,$draftId,(int)$item['id'],$resolved);
            $subtotal+=(int)$resolved['total_cents'];
        }

        if($subtotal<=0)throw new RuntimeException('O carrinho não possui valor válido.');
        $pdo->prepare("UPDATE orders SET subtotal_cents=?,discount_cents=0,delivery_fee_cents=0,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status='draft' AND order_source='WHATSAPP'")
            ->execute([$subtotal,$subtotal,$draftId,$tenantId]);
        $order->execute([$draftId,$tenantId]);
        return $order->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Não foi possível recarregar o carrinho.');
    }
}
