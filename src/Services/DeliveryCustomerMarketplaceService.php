<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class DeliveryCustomerMarketplaceService
{
    public function stores(PDO $pdo,int $accountId,array $filters=[]): array
    {
        $stores=(new MarketplaceCatalogService())->stores($pdo,$filters);
        $q=$pdo->prepare('SELECT tenant_id FROM delivery_customer_favorites WHERE account_id=?');$q->execute([$accountId]);$fav=array_fill_keys(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)),true);
        foreach($stores as &$store)$store['favorite']=isset($fav[(int)$store['tenant_id']]);unset($store);
        return $stores;
    }

    public function toggleFavorite(PDO $pdo,int $accountId,int $tenantId,bool $favorite): array
    {
        $s=$pdo->prepare("SELECT t.id FROM tenants t JOIN marketplace_tenant_settings ms ON ms.tenant_id=t.id WHERE t.id=? AND t.status='active' AND ms.participates=1 AND ms.status='active' LIMIT 1");$s->execute([$tenantId]);if(!$s->fetchColumn())throw new RuntimeException('Restaurante indisponível.');
        if($favorite){try{$pdo->prepare(Database::portableSql($pdo,'INSERT IGNORE INTO delivery_customer_favorites (account_id,tenant_id) VALUES (?,?)'))->execute([$accountId,$tenantId]);}catch(\Throwable){$check=$pdo->prepare('SELECT 1 FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?');$check->execute([$accountId,$tenantId]);if(!$check->fetchColumn())throw;}}
        else $pdo->prepare('DELETE FROM delivery_customer_favorites WHERE account_id=? AND tenant_id=?')->execute([$accountId,$tenantId]);
        return ['tenant_id'=>$tenantId,'favorite'=>$favorite];
    }

    public function createOrder(PDO $pdo,int $accountId,array $payload): array
    {
        $entryToken=trim((string)($payload['entry_token']??''));if($entryToken==='')throw new RuntimeException('Atualize a loja antes de finalizar o pedido.');
        $addressId=(int)($payload['address_id']??0);if($addressId<1)throw new RuntimeException('Escolha um endereço de entrega.');
        $a=$pdo->prepare('SELECT * FROM delivery_customer_addresses WHERE id=? AND account_id=? LIMIT 1');$a->execute([$addressId,$accountId]);$address=$a->fetch();if(!$address)throw new RuntimeException('Endereço de entrega não encontrado.');
        $c=$pdo->prepare('SELECT name,email,phone FROM delivery_customer_accounts WHERE id=? AND status="active" AND email_verified_at IS NOT NULL');$c->execute([$accountId]);$customer=$c->fetch();if(!$customer)throw new RuntimeException('Confirme sua conta antes de fazer pedidos.');
        $addressText=$this->addressText($address);
        $body=$payload;$body['name']=$customer['name'];$body['phone']=$address['phone']?:$customer['phone'];$body['address']=$addressText;
        if(trim((string)$body['phone'])==='')throw new RuntimeException('Adicione um telefone ao seu perfil ou endereço.');
        $order=(new MarketplaceConsumerService())->createOrder($pdo,$entryToken,$body);$orderId=(int)$order['order_number'];
        $o=$pdo->prepare('SELECT tenant_id FROM orders WHERE id=? LIMIT 1');$o->execute([$orderId]);$tenantId=(int)$o->fetchColumn();if($tenantId<1)throw new RuntimeException('Pedido não encontrado.');
        $pdo->prepare('INSERT INTO delivery_customer_order_links (account_id,tenant_id,order_id) VALUES (?,?,?)')->execute([$accountId,$tenantId,$orderId]);
        return $order;
    }

    public function orders(PDO $pdo,int $accountId,int $limit=50): array
    {
        $limit=max(1,min(100,$limit));$q=$pdo->prepare('SELECT o.id,o.public_token,o.tenant_id,o.status,o.payment_status,o.total_cents,o.created_at,t.name store_name,ou.name unit_name FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id JOIN tenants t ON t.id=o.tenant_id LEFT JOIN operating_units ou ON ou.id=o.unit_id WHERE l.account_id=? ORDER BY o.id DESC LIMIT '.$limit);$q->execute([$accountId]);$rows=[];
        foreach($q->fetchAll() as $row){$rows[]=['order_number'=>(int)$row['id'],'public_token'=>(string)$row['public_token'],'tenant_id'=>(int)$row['tenant_id'],'store_name'=>(string)$row['store_name'],'unit_name'=>(string)($row['unit_name']??''),'status'=>(string)$row['status'],'status_label'=>$this->statusLabel((string)$row['status']),'payment_status'=>(string)$row['payment_status'],'total_cents'=>(int)$row['total_cents'],'created_at'=>$row['created_at']];}
        return $rows;
    }

    public function order(PDO $pdo,int $accountId,int $orderId): array
    {
        $q=$pdo->prepare('SELECT o.public_token FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$token=(string)($q->fetchColumn()?:'');if($token==='')throw new RuntimeException('Pedido não encontrado.');return (new MarketplaceConsumerService())->publicOrderByToken($pdo,$token);
    }

    public function reorder(PDO $pdo,int $accountId,int $orderId): array
    {
        $q=$pdo->prepare('SELECT o.tenant_id,o.unit_id FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');
        $items=$pdo->prepare('SELECT oi.product_id,oi.quantity FROM order_items oi WHERE oi.order_id=? AND oi.product_id IS NOT NULL ORDER BY oi.id');$items->execute([$orderId]);$cart=[];foreach($items->fetchAll() as $item)$cart[]=['product_id'=>(int)$item['product_id'],'quantity'=>(float)$item['quantity'],'option_ids'=>[]];
        $catalog=(new MarketplaceCatalogService())->catalog($pdo,(int)$order['tenant_id'],(int)$order['unit_id']);$available=[];foreach($catalog['products'] as$p)if(!empty($p['available']))$available[(int)$p['id']]=true;$cart=array_values(array_filter($cart,fn(array$i):bool=>isset($available[(int)$i['product_id']])));if(!$cart)throw new RuntimeException('Os itens deste pedido não estão disponíveis agora.');
        $entry=(new MarketplaceEntryTokenService())->issue($pdo,(int)$order['tenant_id'],(int)$order['unit_id'],null);
        return ['store'=>$catalog['store'],'items'=>$cart,'checkout_session'=>$entry];
    }

    public function review(PDO $pdo,int $accountId,int $orderId,int $rating,string $comment=''): array
    {
        if($rating<1||$rating>5)throw new RuntimeException('A avaliação deve ser de 1 a 5 estrelas.');$q=$pdo->prepare("SELECT o.tenant_id,o.status FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1");$q->execute([$accountId,$orderId]);$order=$q->fetch();if(!$order)throw new RuntimeException('Pedido não encontrado.');if((string)$order['status']!=='completed')throw new RuntimeException('Você poderá avaliar depois que o pedido for entregue.');$comment=mb_substr(trim($comment),0,1000);
        $exists=$pdo->prepare('SELECT id FROM delivery_customer_reviews WHERE order_id=? LIMIT 1');$exists->execute([$orderId]);$id=$exists->fetchColumn();if($id)$pdo->prepare('UPDATE delivery_customer_reviews SET rating=?,comment=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND account_id=?')->execute([$rating,$comment?:null,$id,$accountId]);else{$pdo->prepare('INSERT INTO delivery_customer_reviews (account_id,tenant_id,order_id,rating,comment) VALUES (?,?,?,?,?)')->execute([$accountId,(int)$order['tenant_id'],$orderId,$rating,$comment?:null]);$id=(int)$pdo->lastInsertId();}
        return ['id'=>(int)$id,'order_id'=>$orderId,'rating'=>$rating,'comment'=>$comment];
    }

    public function paymentMethods(PDO $pdo,int $accountId,int $orderId): array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$tenantId=(int)$order['tenant_id'];$g=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1 ORDER BY id');$g->execute([$tenantId]);$pix=[];$card=[];
        foreach($g->fetchAll() as$row){$provider=(string)$row['provider'];$config=Crypto::decryptJson((string)$row['config_encrypted']);if(in_array($provider,['mercadopago','pagbank'],true)&&(!array_key_exists('pix_enabled',$config)||filter_var($config['pix_enabled'],FILTER_VALIDATE_BOOL)))$pix[]=$provider;if(in_array($provider,['mercadopago','pagbank','stripe'],true)&&(!array_key_exists('card_enabled',$config)||filter_var($config['card_enabled'],FILTER_VALIDATE_BOOL)))$card[]=$provider;}
        $t=$pdo->prepare('SELECT settings FROM tenants WHERE id=?');$t->execute([$tenantId]);$settings=json_decode((string)$t->fetchColumn(),true);if(!is_array($settings))$settings=[];$cash=!empty($settings['delivery_cash_enabled']);
        return ['pix'=>array_values(array_unique($pix)),'card'=>array_values(array_unique($card)),'cash'=>$cash,'currency'=>'BRL'];
    }

    public function markCash(PDO $pdo,int $accountId,int $orderId,?int $changeForCents=null): array
    {
        $order=$this->ownedOrder($pdo,$accountId,$orderId);$methods=$this->paymentMethods($pdo,$accountId,$orderId);if(!$methods['cash'])throw new RuntimeException('Pagamento em dinheiro não está disponível.');if(in_array((string)$order['status'],['cancelled','completed'],true)||(string)$order['payment_status']==='paid')throw new RuntimeException('Este pedido não aceita alteração de pagamento.');$changeForCents=$changeForCents!==null?max(0,$changeForCents):null;if($changeForCents!==null&&$changeForCents<(int)$order['total_cents'])throw new RuntimeException('O valor para troco deve ser maior ou igual ao total.');
        $payload=json_encode(['method'=>'cash','change_for_cents'=>$changeForCents],JSON_UNESCAPED_UNICODE);$key='delivery-cash:'.(int)$order['tenant_id'].':'.$orderId;try{$pdo->prepare('INSERT INTO payments (tenant_id,order_id,provider,idempotency_key,amount_cents,currency,status,raw_payload) VALUES (?,?,"cash",?,?,"BRL","pending",?)')->execute([(int)$order['tenant_id'],$orderId,$key,(int)$order['total_cents'],$payload]);}catch(\PDOException){$pdo->prepare('UPDATE payments SET raw_payload=?,status="pending" WHERE tenant_id=? AND idempotency_key=?')->execute([$payload,(int)$order['tenant_id'],$key]);}$pdo->prepare('UPDATE orders SET payment_status="pending" WHERE id=?')->execute([$orderId]);return ['method'=>'cash','status'=>'pending','change_for_cents'=>$changeForCents];
    }

    public function ownedOrder(PDO $pdo,int $accountId,int $orderId): array
    {
        $q=$pdo->prepare('SELECT o.* FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id WHERE l.account_id=? AND l.order_id=? LIMIT 1');$q->execute([$accountId,$orderId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Pedido não encontrado.');return $row;
    }

    private function addressText(array $a): string
    {
        $parts=[trim((string)$a['street']).', '.trim((string)$a['number'])];if(!empty($a['complement']))$parts[]=(string)$a['complement'];if(!empty($a['neighborhood']))$parts[]=(string)$a['neighborhood'];$parts[]=trim((string)$a['city']).'/'.trim((string)$a['state']);if(!empty($a['postal_code']))$parts[]='CEP '.(string)$a['postal_code'];if(!empty($a['reference']))$parts[]='Referência: '.(string)$a['reference'];return implode(' · ',$parts);
    }
    private function statusLabel(string $status): string{return match(strtolower($status)){'pending'=>'Pedido recebido','confirmed'=>'Confirmado','preparing'=>'Em preparo','ready'=>'Pronto','out_for_delivery'=>'Saiu para entrega','completed'=>'Entregue','cancelled'=>'Cancelado',default=>'Em andamento'};}
}
