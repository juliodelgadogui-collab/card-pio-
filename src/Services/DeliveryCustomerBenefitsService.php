<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryCustomerBenefitsService
{
    public function summary(PDO $pdo,int $accountId,int $tenantId):array
    {
        if($accountId<1||$tenantId<1)throw new RuntimeException('Cliente ou restaurante inválido.');
        $account=$this->account($pdo,$accountId);
        $customer=(new CustomerIdentityService())->findByPhone($pdo,$tenantId,(string)$account['phone']);
        $loyalty=new LoyaltyPointsService();$config=$loyalty->config($tenantId,$pdo);$balance=0;
        if($customer){$loyaltySummary=$loyalty->summary($tenantId,(int)$customer['id'],$pdo);$balance=(int)$loyaltySummary['available'];}
        $pointValueCents=(int)round((int)$config['redeem_value_cents']/max(1,(int)$config['redeem_points']));
        $earnRate=100/max(1,(int)$config['earn_amount_cents']);
        return [
            'loyalty'=>[
                'enabled'=>(bool)$config['enabled'],
                'balance_points'=>$balance,
                'point_value_cents'=>$pointValueCents,
                'min_redeem_points'=>(int)$config['min_redeem_points'],
                'max_redeem_percent'=>(int)$config['max_redeem_percent'],
                'earn_rate'=>$earnRate,
            ],
            'coupon'=>['enabled'=>$this->hasActiveCoupons($pdo,$tenantId)],
        ];
    }

    public function couponQuote(PDO $pdo,int $tenantId,string $code,int $subtotalCents):array
    {
        if($subtotalCents<0)throw new RuntimeException('Subtotal inválido.');
        $coupon=$this->coupon($pdo,$tenantId,$code,false);$this->assertCouponUsable($coupon,$subtotalCents);
        $discount=(new CouponPricingService())->discount($coupon,$subtotalCents);
        return [
            'id'=>(int)$coupon['id'],
            'code'=>(string)$coupon['code'],
            'type'=>(string)$coupon['type'],
            'discount_cents'=>$discount,
            'min_order_cents'=>(int)$coupon['min_order_cents'],
            'max_discount_cents'=>$coupon['max_discount_cents']!==null?(int)$coupon['max_discount_cents']:null,
        ];
    }

    public function applyToOrder(PDO $pdo,int $accountId,int $tenantId,int $orderId,array $payload):array
    {
        if($accountId<1||$tenantId<1||$orderId<1)throw new RuntimeException('Pedido inválido.');
        $q=$pdo->prepare(Database::portableSql($pdo,'SELECT o.* FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id AND o.tenant_id=l.tenant_id WHERE l.account_id=? AND l.tenant_id=? AND l.order_id=? FOR UPDATE'));
        $q->execute([$accountId,$tenantId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita novos benefícios.');
        $active=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized","paid")');$active->execute([$tenantId,$orderId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Aplique cupom e pontos antes de iniciar o pagamento.');

        $couponCode=mb_strtoupper(trim((string)($payload['coupon_code']??'')));
        $redeemPoints=max(0,(int)($payload['redeem_points']??0));
        $couponResult=null;$loyaltyResult=null;

        if($couponCode!==''){
            if(!empty($order['coupon_id']))throw new RuntimeException('Este pedido já possui cupom.');
            $coupon=$this->coupon($pdo,$tenantId,$couponCode,true);$subtotal=(int)$order['subtotal_cents'];$this->assertCouponUsable($coupon,$subtotal);$discount=(new CouponPricingService())->discount($coupon,$subtotal);
            $currentTotal=(int)$order['total_cents'];if($discount<=0)throw new RuntimeException('Este cupom não gera desconto para o pedido.');if($discount>=$currentTotal)throw new RuntimeException('Este cupom quitaria integralmente o pedido. Escolha outro benefício para esta compra.');
            $expires=gmdate('Y-m-d H:i:s',time()+86400);
            $pdo->prepare('INSERT INTO coupon_reservations (tenant_id,coupon_id,order_id,discount_cents,status,expires_at) VALUES (?,?,?,?,"reserved",?)')->execute([$tenantId,(int)$coupon['id'],$orderId,$discount,$expires]);
            $pdo->prepare('UPDATE coupons SET reserved_count=reserved_count+1 WHERE id=? AND tenant_id=?')->execute([(int)$coupon['id'],$tenantId]);
            $pdo->prepare('UPDATE orders SET coupon_id=?,discount_cents=discount_cents+?,total_cents=total_cents-? WHERE id=? AND tenant_id=?')->execute([(int)$coupon['id'],$discount,$discount,$orderId,$tenantId]);
            $couponResult=['code'=>(string)$coupon['code'],'discount_cents'=>$discount,'expires_at'=>$expires];
        }

        if($redeemPoints>0){
            $refresh=$pdo->prepare('SELECT customer_id,total_cents FROM orders WHERE id=? AND tenant_id=?');$refresh->execute([$orderId,$tenantId]);$afterCoupon=$refresh->fetch();$customerId=(int)($afterCoupon['customer_id']??0);if($customerId<1)throw new RuntimeException('Não foi possível identificar o cliente para usar pontos.');
            $loyaltyResult=(new LoyaltyPointsService())->applyToExistingOrder($tenantId,$customerId,$orderId,$redeemPoints);
        }

        $final=$pdo->prepare('SELECT subtotal_cents,discount_cents,delivery_fee_cents,total_cents,coupon_id,customer_id FROM orders WHERE id=? AND tenant_id=?');$final->execute([$orderId,$tenantId]);$totals=$final->fetch()?:throw new RuntimeException('Pedido não encontrado.');
        $loyaltySummary=(new LoyaltyPointsService())->summary($tenantId,(int)$totals['customer_id'],$pdo);$balance=(int)$loyaltySummary['available'];
        return [
            'coupon'=>$couponResult,
            'loyalty'=>$loyaltyResult,
            'loyalty_balance_points'=>$balance,
            'subtotal_cents'=>(int)$totals['subtotal_cents'],
            'discount_cents'=>(int)$totals['discount_cents'],
            'delivery_fee_cents'=>(int)$totals['delivery_fee_cents'],
            'total_cents'=>(int)$totals['total_cents'],
        ];
    }

    private function account(PDO $pdo,int $accountId):array
    {
        $q=$pdo->prepare('SELECT id,name,email,phone FROM delivery_customer_accounts WHERE id=? AND status="active" AND email_verified_at IS NOT NULL LIMIT 1');$q->execute([$accountId]);return $q->fetch()?:throw new RuntimeException('Conta inválida.');
    }

    private function hasActiveCoupons(PDO $pdo,int $tenantId):bool
    {
        $q=$pdo->prepare('SELECT 1 FROM coupons WHERE tenant_id=? AND active=1 AND (starts_at IS NULL OR starts_at<=CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at>=CURRENT_TIMESTAMP) LIMIT 1');$q->execute([$tenantId]);if($q->fetchColumn())return true;
        try{
            $definitions=$pdo->query('SELECT * FROM marketplace_delivery_coupons WHERE active=1 AND (starts_at IS NULL OR starts_at<=CURRENT_TIMESTAMP) AND (ends_at IS NULL OR ends_at>=CURRENT_TIMESTAMP)')->fetchAll();
            $service=new MarketplaceDeliveryCouponService();
            foreach($definitions as$definition)if(in_array($tenantId,$service->targetTenantIds($pdo,$definition),true))return true;
        }catch(\Throwable){
        }
        return false;
    }

    private function coupon(PDO $pdo,int $tenantId,string $code,bool $lock):array
    {
        $code=mb_strtoupper(trim($code));if($code==='')throw new RuntimeException('Informe o cupom.');
        $coupon=$this->findCoupon($pdo,$tenantId,$code,$lock);
        if($coupon)return$coupon;

        // Cupons do Super ADM são materializados para a empresa no primeiro uso.
        // Isso elimina a dependência do botão manual "Sincronizar empresas".
        (new MarketplaceDeliveryCouponService())->ensureForTenantCode($pdo,$tenantId,$code);
        $coupon=$this->findCoupon($pdo,$tenantId,$code,$lock);
        return$coupon?:throw new RuntimeException('Cupom inválido.');
    }

    private function findCoupon(PDO $pdo,int $tenantId,string $code,bool $lock):?array
    {
        $sql='SELECT * FROM coupons WHERE tenant_id=? AND UPPER(code)=? AND active=1 LIMIT 1';
        if($lock)$sql=Database::portableSql($pdo,str_replace(' LIMIT 1',' FOR UPDATE',$sql));
        $q=$pdo->prepare($sql);$q->execute([$tenantId,$code]);$row=$q->fetch();return$row?:null;
    }

    private function assertCouponUsable(array $coupon,int $subtotalCents):void
    {
        $now=time();$starts=trim((string)($coupon['starts_at']??''));$ends=trim((string)($coupon['ends_at']??''));
        if($starts!==''&&($ts=strtotime($starts))!==false&&$now<$ts)throw new RuntimeException('Cupom ainda não está válido.');
        if($ends!==''&&($ts=strtotime($ends))!==false&&$now>$ts)throw new RuntimeException('Cupom expirado.');
        if($coupon['max_uses']!==null&&((int)$coupon['uses_count']+(int)($coupon['reserved_count']??0))>=(int)$coupon['max_uses'])throw new RuntimeException('Limite do cupom atingido.');
        if($subtotalCents<(int)$coupon['min_order_cents'])throw new RuntimeException('Valor mínimo do cupom não atingido.');
    }
}
