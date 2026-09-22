<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use EventMenu\Core\TenantFeatures;
use PDO;
use RuntimeException;

final class DeliveryCustomerMarketplaceService
{
    public function stores(PDO $pdo,int $accountId,array $filters=[]):array
    {
        $stores=(new MarketplaceCatalogService())->stores($pdo,$filters);
        $q=$pdo->prepare('SELECT tenant_id FROM delivery_customer_favorites WHERE account_id=?');$q->execute([$accountId]);
        $fav=array_fill_keys(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true);
        $settingsByTenant=[];
        if($stores){
            $ids=array_values(array_unique(array_map(static fn(array $s):int=>(int)$s['tenant_id'],$stores)));
            $ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare('SELECT id,settings FROM tenants WHERE id IN ('.$ph.')');$s->execute($ids);
            foreach($s->fetchAll() as $row){$settings=json_decode((string)($row['settings']??'{}'),true);$settingsByTenant[(int)$row['id']]=is_array($settings)?$settings:[];}
        }
        foreach($stores as &$store){$settings=$settingsByTenant[(int)$store['tenant_id']]??[];$store['favorite']=isset($fav[(int)$store['tenant_id']]);$store['accepting_orders']=empty($settings['delivery_paused']);$store['delivery_eta_minutes']=max(5,(int)($settings['delivery_eta_minutes']??45));$store['delivery_radius_km']=max(0,(float)($settings['delivery_radius_km']??0));$store['pickup_enabled']=!empty($settings['delivery_pickup_enabled']);$store['schedule_note']=(string)($settings['delivery_schedule_note']??'');}unset($store);
        return $stores;
    }

    public function toggleFavorite(PDO $pdo,int $accountId,int $tenantId,bool $favorite):array
    {
        $this->assertMarketplaceTenant($pdo,$tenantId);
        if($favorite){
            try{$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO delivery_customer_favorites (account_id,tenant_id) VALUES (?,?)'))->execute([$accountId,$tenantId]);}
            catch(\Throwable $e){$check=$pdo->prepare('SELECT 1 FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?');$check->execute([$accountId,$tenantId]);if(!$check->fetchColumn())throw $e;}
        }else $pdo->prepare('DELETE FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?')->execute([$accountId,$tenantId]);
        return ['tenant_id'=>$tenantId,'favorite'=>$favorite];
    }

    public function benefits(PDO $pdo,int $accountId,int $tenantId):array
    {
        $this->assertMarketplaceTenant($pdo,$tenantId);
        return (new DeliveryCustomerBenefitsService())->summary($pdo,$accountId,$tenantId);
    }

    public function couponQuote(PDO $pdo,int $accountId,int $tenantId,string $code,int $subtotalCents):array
    {
        $this->assertMarketplaceTenant($pdo,$tenantId);
        (new DeliveryCustomerBenefitsService())->summary($pdo,$accountId,$tenantId);
        return (new DeliveryCustomerBenefitsService())->couponQuote($pdo,$tenantId,$code,$subtotalCents);
    }

    public function createOrder(PDO $pdo,int $accountId,array $payload):array
    {
        $entryToken=trim((string)($payload['entry_token']??''));if($entryToken==='')throw new RuntimeException('Atualize a loja antes de finalizar o pedido.');
        $claims=(new MarketplaceEntryTokenService())->decodeAndVerify($entryToken);$tenantId=(int)$claims['tenant_id'];
        $this->assertMarketplaceTenant($pdo,$tenantId);
        $settings=$this->tenantSettings($pdo,$tenantId);if(!empty($settings['delivery_paused']))throw new RuntimeException('Este restaurante pausou novos pedidos no momento.');

        $fulfillment=strtolower(trim((string)($payload['fulfillment']??$payload['channel']??'delivery')));
        if(!in_array($fulfillment,['delivery','pickup'],true))throw new RuntimeException('Escolha entrega ou retirada no local.');
        if($fulfillment==='pickup'&&empty($settings['delivery_pickup_enabled']))throw new RuntimeException('Retirada no local não está disponível neste restaurante.');

        $c=$pdo->prepare('SELECT name,email,phone FROM delivery_customer_accounts WHERE id=? AND status="active" AND email_verified_at IS NOT NULL');$c->execute([$accountId]);$customer=$c->fetch();if(!$customer)throw new RuntimeException('Confirme sua conta antes de fazer pedidos.');
        $address=null;$addressId=0;
        if($fulfillment==='delivery'){
            $addressId=(int)($payload['address_id']??0);if($addressId<1)throw new RuntimeException('Escolha um endereço de entrega.');
            $a=$pdo->prepare('SELECT * FROM delivery_customer_addresses WHERE id=? AND account_id=? LIMIT 1');$a->execute([$addressId,$accountId]);$address=$a->fetch();if(!$address)throw new RuntimeException('Endereço de entrega não encontrado.');
            $this->assertDeliveryArea($settings,$address);
        }

        $body=$payload;$body['fulfillment']=$fulfillment;$body['name']=$customer['name'];$body['phone']=$fulfillment==='delivery'&&$address?($address['phone']?:$customer['phone']):$customer['phone'];$body['address']=$fulfillment==='delivery'&&$address?$this->addressText($address):'';$body['customer_email']=$customer['email'];
        if(strlen((new CustomerIdentityService())->normalizePhone((string)$body['phone']))<10)throw new RuntimeException('Adicione um telefone válido ao seu perfil'.($fulfillment==='delivery'?' ou endereço':'').' para continuar.');

        [$idempotencyKey,$requestHash]=$this->orderRequestIdentity($accountId,$entryToken,$payload,$fulfillment,$addressId);
        $existing=$this->findOrderRequest($pdo,$accountId,$idempotencyKey);
        if($existing){
            if(!hash_equals((string)$existing['request_hash'],$requestHash))throw new RuntimeException('Esta tentativa de pedido já foi usada com outros dados. Atualize o carrinho e tente novamente.');
            $existingOrderId=(int)($existing['order_id']??0);
            if($existingOrderId>0){$this->ensureOrderLink($pdo,$accountId,$tenantId,$existingOrderId);$order=$this->order($pdo,$accountId,$existingOrderId);$order['idempotent_replay']=true;return $order;}
        }else{
            try{$pdo->prepare('INSERT INTO delivery_customer_order_requests (account_id,tenant_id,idempotency_key,request_hash) VALUES (?,?,?,?)')->execute([$accountId,$tenantId,$idempotencyKey,$requestHash]);}
            catch(\Throwable $e){$existing=$this->findOrderRequest($pdo,$accountId,$idempotencyKey);if(!$existing)throw $e;if(!hash_equals((string)$existing['request_hash'],$requestHash))throw new RuntimeException('Esta tentativa de pedido já foi usada com outros dados. Atualize o carrinho e tente novamente.');if((int)($existing['order_id']??0)>0){$existingOrderId=(int)$existing['order_id'];$this->ensureOrderLink($pdo,$accountId,$tenantId,$existingOrderId);$order=$this->order($pdo,$accountId,$existingOrderId);$order['idempotent_replay']=true;return $order;}}
        }

        $created=(new MarketplaceConsumerService())->createOrder($pdo,$entryToken,$body);$orderId=(int)$created['order_number'];
        $o=$pdo->prepare('SELECT tenant_id,channel,subtotal_cents,total_cents FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$stored=$o->fetch();if(!$stored||(int)$stored['tenant_id']!==$tenantId)throw new RuntimeException('Pedido não encontrado.');
        if((string)$stored['channel']!==$fulfillment)throw new RuntimeException('A modalidade do pedido não foi gravada corretamente.');
        $this->ensureOrderLink($pdo,$accountId,$tenantId,$orderId);

        $couponCode=trim((string)($payload['coupon_code']??''));$redeemPoints=max(0,(int)($payload['redeem_points']??0));$benefits=null;
        if($couponCode!==''||$redeemPoints>0)$benefits=(new DeliveryCustomerBenefitsService())->applyToOrder($pdo,$accountId,$tenantId,$orderId,['coupon_code'=>$couponCode,'redeem_points'=>$redeemPoints]);
        $pdo->prepare('UPDATE delivery_customer_order_requests SET order_id=?,updated_at=CURRENT_TIMESTAMP WHERE account_id=? AND idempotency_key=?')->execute([$orderId,$accountId,$idempotencyKey]);

        $order=$this->order($pdo,$accountId,$orderId);if($benefits!==null)$order['benefits']=$benefits;$order['idempotent_replay']=false;return $order;
    }

    public function orders(PDO $pdo,int $accountId,int $limit=50):array
    {
        $limit=max(1,min(100,$limit));$q=$pdo->prepare('SELECT o.id,o.public_token,o.tenant_id,o.channel,o.status,o.payment_status,o.total_cents,o.created_at,t.name store_name,ou.name unit_name FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id JOIN tenants t ON t.id=o.tenant_id LEFT JOIN operating_units ou ON ou.id=o.unit_id WHERE l.account_id=? ORDER BY o.id DESC LIMIT '.$limit);$q->execute([$accountId]);$rows=[];
        foreach($q->fetchAll() as $row)$rows[]=['order_number'=>(int)$row['id'],'public_token'=>(string)$row['public_token'],'tenant_id'=>(int)$row['tenant_id'],'store_name'=>(string)$row['store_name'],'unit_name'=>(string)($row['unit_name']??''),'channel'=>(string)$row['channel'],'fulfillment'=>(string)$row['channel']==='pickup'?'pickup':'delivery','status'=>(string)$row['status'],'status_label'=>$this->statusLabel((string)$row['status'],(string)$row['channel']),'payment_status'=>(string)$row['payment_status'],'total_cents'=>(int)$row['total_cents'],'created_at'=>$row['created_at']];
        return $rows;
    }

    public function order(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.public_token FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$token=(string)($q->fetchColumn()?:'');
        if($token==='')throw new RuntimeException('Pedido não encontrado.');return (new MarketplaceConsumerService())->publicOrderByToken($pdo,$token);
    }

    public function reorder(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.tenant_id,o.unit_id,o.channel FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $tenantId=(int)$order['tenant_id'];$unitId=(int)$order['unit_id'];$this->assertMarketplaceTenant($pdo,$tenantId);
        $settings=$this->tenantSettings($pdo,$tenantId);if(!empty($settings['delivery_paused']))throw new RuntimeException('Este restaurante pausou novos pedidos no momento.');

        $items=$pdo->prepare('SELECT id,product_id,quantity,notes FROM order_items WHERE order_id=? AND product_id IS NOT NULL ORDER BY id');$items->execute([$orderId]);$historic=$items->fetchAll();
        $modifierMap=[];if($historic){$m=$pdo->prepare('SELECT order_item_id,modifier_option_id FROM order_item_modifiers WHERE tenant_id=? AND order_id=? ORDER BY id');$m->execute([$tenantId,$orderId]);foreach($m->fetchAll() as $row)$modifierMap[(int)$row['order_item_id']][]=(int)$row['modifier_option_id'];}
        $catalog=(new MarketplaceCatalogService())->catalog($pdo,$tenantId,$unitId);$products=[];
        foreach($catalog['products'] as $p){$allowed=[];foreach((array)($p['modifier_groups']??[]) as $group)foreach((array)($group['options']??[]) as $option)$allowed[(int)$option['id']]=true;$p['_allowed_options']=$allowed;$products[(int)$p['id']]=$p;}
        $cart=[];$warnings=[];
        foreach($historic as $item){$productId=(int)$item['product_id'];$current=$products[$productId]??null;if(!$current||empty($current['available'])){$warnings[]='Um item antigo não está disponível e foi removido do carrinho.';continue;}$oldOptions=$modifierMap[(int)$item['id']]??[];$optionIds=[];foreach($oldOptions as $optionId){if(isset($current['_allowed_options'][$optionId]))$optionIds[]=$optionId;else $warnings[]='Um adicional antigo não está mais disponível e foi removido.';}$cart[]=['product_id'=>$productId,'quantity'=>(float)$item['quantity'],'option_ids'=>array_values(array_unique($optionIds)),'notes'=>mb_substr((string)($item['notes']??''),0,500)];}
        if(!$cart)throw new RuntimeException('Os itens deste pedido não estão disponíveis agora.');
        $entry=(new MarketplaceEntryTokenService())->issue($pdo,$tenantId,$unitId,null);
        $preferred=(string)$order['channel']==='pickup'&&!empty($settings['delivery_pickup_enabled'])?'pickup':'delivery';
        return ['store'=>$catalog['store'],'items'=>$cart,'preferred_fulfillment'=>$preferred,'warnings'=>array_values(array_unique($warnings)),'checkout_session'=>$entry];
    }

    public function review(PDO $pdo,int $accountId,int $orderId,int $rating,string $comment=''):array
    {
        if($rating<1||$rating>5)throw new RuntimeException('A avaliação deve ser de 1 a 5 estrelas.');
        $q=$pdo->prepare('SELECT o.tenant_id,o.status FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if((string)$order['status']!=='completed')throw new RuntimeException('Você poderá avaliar depois que o pedido for concluído.');$comment=mb_substr(trim($comment),0,1000);
        $exists=$pdo->prepare('SELECT id FROM delivery_customer_reviews WHERE order_id=? LIMIT 1');$exists->execute([$orderId]);$id=$exists->fetchColumn();
        if($id)$pdo->prepare('UPDATE delivery_customer_reviews SET rating=?,comment=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND account_id=?')->execute([$rating,$comment?:null,$id,$accountId]);else{$pdo->prepare('INSERT INTO delivery_customer_reviews (account_id,tenant_id,order_id,rating,comment) VALUES (?,?,?,?,?)')->execute([$accountId,(int)$order['tenant_id'],$orderId,$rating,$comment?:null]);$id=(int)$pdo->lastInsertId();}
        return ['id'=>(int)$id,'order_id'=>$orderId,'rating'=>$rating,'comment'=>$comment];
    }

    public function paymentMethods(PDO $pdo,int $accountId,int $orderId):array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$this->assertPaymentModule((int)$order['tenant_id']);return (new DeliveryPaymentMethodService())->forOrder($pdo,$accountId,$orderId);
    }

    public function markCash(PDO $pdo,int $accountId,int $orderId,?int $changeForCents=null):array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$this->assertPaymentModule((int)$order['tenant_id']);$methods=$this->paymentMethods($pdo,$accountId,$orderId);if(!$methods['cash'])throw new RuntimeException('Pagamento em dinheiro não está disponível.');
        if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita alteração de pagamento.');
        $changeForCents=$changeForCents!==null?max(0,$changeForCents):null;if($changeForCents!==null&&$changeForCents<(int)$order['total_cents'])throw new RuntimeException('O valor para troco deve ser maior ou igual ao total.');
        $active=$pdo->prepare('SELECT id FROM payments WHERE tenant_id=? AND order_id=? AND status IN ("created","pending","authorized") LIMIT 1');$active->execute([(int)$order['tenant_id'],$orderId]);if($active->fetchColumn())throw new RuntimeException('Já existe uma cobrança eletrônica em andamento. Aguarde o resultado antes de trocar para dinheiro.');
        $existing=$pdo->prepare('SELECT order_id FROM delivery_customer_payment_preferences WHERE order_id=? LIMIT 1');$existing->execute([$orderId]);
        if($existing->fetchColumn())$pdo->prepare('UPDATE delivery_customer_payment_preferences SET method="cash",change_for_cents=?,updated_at=CURRENT_TIMESTAMP WHERE order_id=? AND account_id=?')->execute([$changeForCents,$orderId,$accountId]);
        else $pdo->prepare('INSERT INTO delivery_customer_payment_preferences (order_id,account_id,tenant_id,method,change_for_cents) VALUES (?,?,?,"cash",?)')->execute([$orderId,$accountId,(int)$order['tenant_id'],$changeForCents]);
        (new OrderPaymentPreferenceService())->set($pdo,(int)$order['tenant_id'],$orderId,'cash',null,$changeForCents,'delivery_app');
        if((string)$order['payment_status']==='failed')$pdo->prepare('UPDATE orders SET payment_status="unpaid" WHERE id=? AND tenant_id=?')->execute([$orderId,(int)$order['tenant_id']]);
        return ['method'=>'cash','status'=>'selected','change_for_cents'=>$changeForCents];
    }

    public function ownedOrder(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.* FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Pedido não encontrado.');if(!TenantFeatures::moduleEnabled('delivery',(int)$row['tenant_id']))throw new RuntimeException('EventMenu Delivery está desativado para esta empresa.');return $row;
    }

    private function assertMarketplaceTenant(PDO $pdo,int $tenantId):void
    {
        if(!TenantFeatures::moduleEnabled('delivery',$tenantId))throw new RuntimeException('EventMenu Delivery está desativado para esta empresa.');
        $q=$pdo->prepare("SELECT 1 FROM tenants t JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id WHERE t.id=? AND t.status='active' AND ms.participates=1 AND ms.status='active' LIMIT 1");$q->execute([$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Restaurante indisponível.');
    }

    private function assertPaymentModule(int $tenantId):void{if(!TenantFeatures::moduleEnabled('payments',$tenantId))throw new RuntimeException('Pagamentos estão desativados para esta empresa.');}
    private function tenantSettings(PDO $pdo,int $tenantId):array{$q=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$q->execute([$tenantId]);$settings=json_decode((string)($q->fetchColumn()?:'{}'),true);return is_array($settings)?$settings:[];}
    private function assertDeliveryArea(array $settings,array $address):void
    {
        $radius=max(0,(float)($settings['delivery_radius_km']??0));if($radius<=0)return;
        $fromLat=$settings['delivery_origin_latitude']??null;$fromLng=$settings['delivery_origin_longitude']??null;$toLat=$address['latitude']??null;$toLng=$address['longitude']??null;
        if(!is_numeric($fromLat)||!is_numeric($fromLng))throw new RuntimeException('O restaurante ainda não configurou a origem da área de entrega.');
        if(!is_numeric($toLat)||!is_numeric($toLng))throw new RuntimeException('Use sua localização no endereço para confirmar se a entrega atende sua região.');
        $distance=$this->distanceKm((float)$fromLat,(float)$fromLng,(float)$toLat,(float)$toLng);if($distance>$radius)throw new RuntimeException('Este endereço fica fora da área de entrega do restaurante.');
    }
    private function distanceKm(float $lat1,float $lng1,float $lat2,float $lng2):float{$earth=6371.0088;$dLat=deg2rad($lat2-$lat1);$dLng=deg2rad($lng2-$lng1);$a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;return $earth*2*atan2(sqrt($a),sqrt(max(0,1-$a)));}
    private function addressText(array $a):string{$parts=[trim((string)$a['street']).', '.trim((string)$a['number'])];if(!empty($a['complement']))$parts[]=(string)$a['complement'];if(!empty($a['neighborhood']))$parts[]=(string)$a['neighborhood'];$parts[]=trim((string)$a['city']).'/'.trim((string)$a['state']);if(!empty($a['postal_code']))$parts[]='CEP '.(string)$a['postal_code'];if(!empty($a['reference']))$parts[]='Referência: '.(string)$a['reference'];return implode(' · ',$parts);}
    private function statusLabel(string $status,string $channel='delivery'):string{return match(strtolower($status)){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>$channel==='pickup'?'Pronto para retirada':'Pronto','out_for_delivery'=>'Saiu para entrega','completed'=>$channel==='pickup'?'Retirado':'Entregue','cancelled'=>'Cancelado',default=>'Em andamento'};}

    private function orderRequestIdentity(int $accountId,string $entryToken,array $payload,string $fulfillment,int $addressId):array
    {
        $client=mb_substr(trim((string)($payload['client_request_id']??$payload['idempotency_key']??'')),0,160);
        $source=$client!==''?'client:'.$client:'entry:'.hash('sha256',$entryToken);
        $key=hash('sha256',$accountId.'|'.$source);
        $items=[];foreach((array)($payload['items']??[]) as $row){if(!is_array($row))continue;$opts=$row['option_ids']??[];if(!is_array($opts))$opts=[];$opts=array_values(array_unique(array_map('intval',$opts)));sort($opts);$items[]=['product_id'=>(int)($row['product_id']??0),'quantity'=>(float)($row['quantity']??$row['qty']??0),'option_ids'=>$opts,'notes'=>mb_substr(trim((string)($row['notes']??'')),0,500)];}
        $canonical=['fulfillment'=>$fulfillment,'address_id'=>$fulfillment==='delivery'?$addressId:0,'items'=>$items,'coupon_code'=>mb_strtoupper(trim((string)($payload['coupon_code']??''))),'redeem_points'=>max(0,(int)($payload['redeem_points']??0))];
        return [$key,hash('sha256',json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION))];
    }
    private function findOrderRequest(PDO $pdo,int $accountId,string $key):array|false{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM delivery_customer_order_requests WHERE account_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE'));$q->execute([$accountId,$key]);return $q->fetch();}
    private function ensureOrderLink(PDO $pdo,int $accountId,int $tenantId,int $orderId):void{try{$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO delivery_customer_order_links (account_id,tenant_id,order_id) VALUES (?,?,?)'))->execute([$accountId,$tenantId,$orderId]);}catch(\Throwable $e){$q=$pdo->prepare('SELECT 1 FROM delivery_customer_order_links WHERE account_id=? AND order_id=?');$q->execute([$accountId,$orderId]);if(!$q->fetchColumn())throw $e;}}
}
