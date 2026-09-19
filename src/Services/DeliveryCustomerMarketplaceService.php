<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
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
            $ids=array_values(array_unique(array_map(static fn(array$s):int=>(int)$s['tenant_id'],$stores)));
            $ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare('SELECT id,settings FROM tenants WHERE id IN ('.$ph.')');$s->execute($ids);
            foreach($s->fetchAll()as$row){$settings=json_decode((string)($row['settings']??'{}'),true);$settingsByTenant[(int)$row['id']]=is_array($settings)?$settings:[];}
        }
        foreach($stores as &$store){$settings=$settingsByTenant[(int)$store['tenant_id']]??[];$store['favorite']=isset($fav[(int)$store['tenant_id']]);$store['accepting_orders']=empty($settings['delivery_paused']);$store['delivery_eta_minutes']=max(5,(int)($settings['delivery_eta_minutes']??45));$store['delivery_radius_km']=max(0,(float)($settings['delivery_radius_km']??0));$store['pickup_enabled']=!empty($settings['delivery_pickup_enabled']);$store['schedule_note']=(string)($settings['delivery_schedule_note']??'');}unset($store);
        return $stores;
    }

    public function toggleFavorite(PDO $pdo,int $accountId,int $tenantId,bool $favorite):array
    {
        $s=$pdo->prepare("SELECT t.id FROM tenants t JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id WHERE t.id=? AND t.status='active' AND ms.participates=1 AND ms.status='active' LIMIT 1");$s->execute([$tenantId]);
        if(!$s->fetchColumn())throw new RuntimeException('Restaurante indisponível.');
        if($favorite){
            try{$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO delivery_customer_favorites (account_id,tenant_id) VALUES (?,?)'))->execute([$accountId,$tenantId]);}
            catch(\Throwable $e){$check=$pdo->prepare('SELECT 1 FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?');$check->execute([$accountId,$tenantId]);if(!$check->fetchColumn())throw $e;}
        }else $pdo->prepare('DELETE FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?')->execute([$accountId,$tenantId]);
        return ['tenant_id'=>$tenantId,'favorite'=>$favorite];
    }

    public function createOrder(PDO $pdo,int $accountId,array $payload):array
    {
        $entryToken=trim((string)($payload['entry_token']??''));if($entryToken==='')throw new RuntimeException('Atualize a loja antes de finalizar o pedido.');
        $claims=(new MarketplaceEntryTokenService())->decodeAndVerify($entryToken);$tenantId=(int)$claims['tenant_id'];
        $settings=$this->tenantSettings($pdo,$tenantId);
        if(!empty($settings['delivery_paused']))throw new RuntimeException('Este restaurante pausou novos pedidos no momento.');

        $addressId=(int)($payload['address_id']??0);if($addressId<1)throw new RuntimeException('Escolha um endereço de entrega.');
        $a=$pdo->prepare('SELECT * FROM delivery_customer_addresses WHERE id=? AND account_id=? LIMIT 1');$a->execute([$addressId,$accountId]);$address=$a->fetch();if(!$address)throw new RuntimeException('Endereço de entrega não encontrado.');
        $this->assertDeliveryArea($settings,$address);

        $c=$pdo->prepare('SELECT name,email,phone FROM delivery_customer_accounts WHERE id=? AND status="active" AND email_verified_at IS NOT NULL');$c->execute([$accountId]);$customer=$c->fetch();if(!$customer)throw new RuntimeException('Confirme sua conta antes de fazer pedidos.');
        $body=$payload;$body['name']=$customer['name'];$body['phone']=$address['phone']?:$customer['phone'];$body['address']=$this->addressText($address);$body['customer_email']=$customer['email'];
        if(trim((string)$body['phone'])==='')throw new RuntimeException('Adicione um telefone ao seu perfil ou endereço.');

        $order=(new MarketplaceConsumerService())->createOrder($pdo,$entryToken,$body);$orderId=(int)$order['order_number'];
        $o=$pdo->prepare('SELECT tenant_id,subtotal_cents,total_cents FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$stored=$o->fetch();if(!$stored||(int)$stored['tenant_id']!==$tenantId)throw new RuntimeException('Pedido não encontrado.');
        $minimum=max(0,(int)($settings['min_delivery_order_cents']??0));if($minimum>0&&(int)$stored['subtotal_cents']<$minimum)throw new RuntimeException('O pedido mínimo deste restaurante é R$ '.number_format($minimum/100,2,',','.').'.');
        $pdo->prepare('INSERT INTO delivery_customer_order_links (account_id,tenant_id,order_id) VALUES (?,?,?)')->execute([$accountId,$tenantId,$orderId]);
        return $order;
    }

    public function orders(PDO $pdo,int $accountId,int $limit=50):array
    {
        $limit=max(1,min(100,$limit));$q=$pdo->prepare('SELECT o.id,o.public_token,o.tenant_id,o.status,o.payment_status,o.total_cents,o.created_at,t.name store_name,ou.name unit_name FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id JOIN tenants t ON t.id=o.tenant_id LEFT JOIN operating_units ou ON ou.id=o.unit_id WHERE l.account_id=? ORDER BY o.id DESC LIMIT '.$limit);$q->execute([$accountId]);$rows=[];
        foreach($q->fetchAll() as$row)$rows[]=['order_number'=>(int)$row['id'],'public_token'=>(string)$row['public_token'],'tenant_id'=>(int)$row['tenant_id'],'store_name'=>(string)$row['store_name'],'unit_name'=>(string)($row['unit_name']??''),'status'=>(string)$row['status'],'status_label'=>$this->statusLabel((string)$row['status']),'payment_status'=>(string)$row['payment_status'],'total_cents'=>(int)$row['total_cents'],'created_at'=>$row['created_at']];
        return $rows;
    }

    public function order(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.public_token FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$token=(string)($q->fetchColumn()?:'');
        if($token==='')throw new RuntimeException('Pedido não encontrado.');return (new MarketplaceConsumerService())->publicOrderByToken($pdo,$token);
    }

    public function reorder(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.tenant_id,o.unit_id FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $settings=$this->tenantSettings($pdo,(int)$order['tenant_id']);if(!empty($settings['delivery_paused']))throw new RuntimeException('Este restaurante pausou novos pedidos no momento.');
        $items=$pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=? AND product_id IS NOT NULL ORDER BY id');$items->execute([$orderId]);$cart=[];foreach($items->fetchAll()as$item)$cart[]=['product_id'=>(int)$item['product_id'],'quantity'=>(float)$item['quantity'],'option_ids'=>[]];
        $catalog=(new MarketplaceCatalogService())->catalog($pdo,(int)$order['tenant_id'],(int)$order['unit_id']);$available=[];foreach($catalog['products']as$p)if(!empty($p['available']))$available[(int)$p['id']]=true;
        $cart=array_values(array_filter($cart,fn(array$i):bool=>isset($available[(int)$i['product_id']])));if(!$cart)throw new RuntimeException('Os itens deste pedido não estão disponíveis agora.');
        $entry=(new MarketplaceEntryTokenService())->issue($pdo,(int)$order['tenant_id'],(int)$order['unit_id'],null);return ['store'=>$catalog['store'],'items'=>$cart,'checkout_session'=>$entry];
    }

    public function review(PDO $pdo,int $accountId,int $orderId,int $rating,string $comment=''):array
    {
        if($rating<1||$rating>5)throw new RuntimeException('A avaliação deve ser de 1 a 5 estrelas.');
        $q=$pdo->prepare('SELECT o.tenant_id,o.status FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if((string)$order['status']!=='completed')throw new RuntimeException('Você poderá avaliar depois que o pedido for entregue.');$comment=mb_substr(trim($comment),0,1000);
        $exists=$pdo->prepare('SELECT id FROM delivery_customer_reviews WHERE order_id=? LIMIT 1');$exists->execute([$orderId]);$id=$exists->fetchColumn();
        if($id)$pdo->prepare('UPDATE delivery_customer_reviews SET rating=?,comment=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND account_id=?')->execute([$rating,$comment?:null,$id,$accountId]);else{$pdo->prepare('INSERT INTO delivery_customer_reviews (account_id,tenant_id,order_id,rating,comment) VALUES (?,?,?,?,?)')->execute([$accountId,(int)$order['tenant_id'],$orderId,$rating,$comment?:null]);$id=(int)$pdo->lastInsertId();}
        return ['id'=>(int)$id,'order_id'=>$orderId,'rating'=>$rating,'comment'=>$comment];
    }

    public function paymentMethods(PDO $pdo,int $accountId,int $orderId):array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$tenantId=(int)$order['tenant_id'];$q=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1 ORDER BY id');$q->execute([$tenantId]);$pix=[];$card=[];
        foreach($q->fetchAll()as$row){$provider=(string)$row['provider'];$config=Crypto::decryptJson((string)$row['config_encrypted']);$pixEnabled=!array_key_exists('pix_enabled',$config)||filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL);if(in_array($provider,['mercadopago','pagbank'],true)&&$pixEnabled)$pix[]=['provider'=>$provider];
            if($provider==='mercadopago'&&filter_var($config['card_enabled']??false,FILTER_VALIDATE_BOOL)){ $publicKey=trim((string)($config['public_key']??''));if($publicKey!=='')$card[]=['provider'=>'mercadopago','public_key'=>$publicKey,'max_installments'=>max(1,min(12,(int)($config['max_installments']??12)))]; }
        }
        $settings=$this->tenantSettings($pdo,$tenantId);
        return ['pix'=>$pix,'card'=>$card,'cash'=>!empty($settings['delivery_cash_enabled']),'currency'=>'BRL'];
    }

    public function markCash(PDO $pdo,int $accountId,int $orderId,?int $changeForCents=null):array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$methods=$this->paymentMethods($pdo,$accountId,$orderId);if(!$methods['cash'])throw new RuntimeException('Pagamento em dinheiro não está disponível.');if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita alteração de pagamento.');$changeForCents=$changeForCents!==null?max(0,$changeForCents):null;if($changeForCents!==null&&$changeForCents<(int)$order['total_cents'])throw new RuntimeException('O valor para troco deve ser maior ou igual ao total.');
        $raw=json_encode(['method'=>'cash','change_for_cents'=>$changeForCents],JSON_UNESCAPED_UNICODE);$key='delivery-cash:'.(int)$order['tenant_id'].':'.$orderId;
        try{$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status,raw_payload) VALUES (?,?,"cash",?,?,"BRL","pending",?)')->execute([(int)$order['tenant_id'],$orderId,$key,(int)$order['total_cents'],$raw]);}catch(\PDOException){$pdo->prepare('UPDATE payments SET raw_payload=?,status="pending" WHERE tenant_id=? AND idempotency_key=?')->execute([$raw,(int)$order['tenant_id'],$key]);}
        $pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);return ['method'=>'cash','status'=>'pending','change_for_cents'=>$changeForCents];
    }

    public function ownedOrder(PDO $pdo,int $accountId,int $orderId):array
    {
        $q=$pdo->prepare('SELECT o.* FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Pedido não encontrado.');return $row;
    }

    private function tenantSettings(PDO $pdo,int $tenantId):array{$q=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$q->execute([$tenantId]);$settings=json_decode((string)($q->fetchColumn()?:'{}'),true);return is_array($settings)?$settings:[];}
    private function assertDeliveryArea(array$settings,array$address):void
    {
        $radius=max(0,(float)($settings['delivery_radius_km']??0));if($radius<=0)return;
        $fromLat=$settings['delivery_origin_latitude']??null;$fromLng=$settings['delivery_origin_longitude']??null;$toLat=$address['latitude']??null;$toLng=$address['longitude']??null;
        if(!is_numeric($fromLat)||!is_numeric($fromLng))throw new RuntimeException('O restaurante ainda não configurou a origem da área de entrega.');
        if(!is_numeric($toLat)||!is_numeric($toLng))throw new RuntimeException('Use sua localização no endereço para confirmar se a entrega atende sua região.');
        $distance=$this->distanceKm((float)$fromLat,(float)$fromLng,(float)$toLat,(float)$toLng);if($distance>$radius)throw new RuntimeException('Este endereço fica fora da área de entrega do restaurante.');
    }
    private function distanceKm(float$lat1,float$lng1,float$lat2,float$lng2):float{$earth=6371.0088;$dLat=deg2rad($lat2-$lat1);$dLng=deg2rad($lng2-$lng1);$a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;return$earth*2*atan2(sqrt($a),sqrt(max(0,1-$a)));}
    private function addressText(array $a):string{$parts=[trim((string)$a['street']).', '.trim((string)$a['number'])];if(!empty($a['complement']))$parts[]=(string)$a['complement'];if(!empty($a['neighborhood']))$parts[]=(string)$a['neighborhood'];$parts[]=trim((string)$a['city']).'/'.trim((string)$a['state']);if(!empty($a['postal_code']))$parts[]='CEP '.(string)$a['postal_code'];if(!empty($a['reference']))$parts[]='Referência: '.(string)$a['reference'];return implode(' · ',$parts);}
    private function statusLabel(string $status):string{return match(strtolower($status)){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','completed'=>'Entregue','cancelled'=>'Cancelado',default=>'Em andamento'};}
}
