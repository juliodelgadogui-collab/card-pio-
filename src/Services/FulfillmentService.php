<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FulfillmentService
{
    public function ensureToken(int $tenantId,int $orderId):string
    {
        return Database::transaction(function(PDO $pdo)use($tenantId,$orderId):string{
            $s=$pdo->prepare('SELECT fulfillment_token,channel FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $s->execute([$orderId,$tenantId]);$order=$s->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(!in_array($order['channel'],['counter','pickup'],true))throw new RuntimeException('Este pedido não usa retirada parcial.');
            if(!empty($order['fulfillment_token']))return(string)$order['fulfillment_token'];
            $token=bin2hex(random_bytes(20));
            $pdo->prepare('UPDATE orders SET fulfillment_token=? WHERE id=? AND tenant_id=?')->execute([$token,$orderId,$tenantId]);
            return $token;
        });
    }

    public function byToken(string $token):array
    {
        $token=$this->extractToken($token);
        if(!preg_match('/^[a-f0-9]{40}$/i',$token))throw new RuntimeException('Código de retirada inválido.');
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.*,t.name tenant_name,c.name customer_name,c.phone customer_phone FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.fulfillment_token=? LIMIT 1');
        $s->execute([$token]);$order=$s->fetch();
        if(!$order)throw new RuntimeException('Venda não encontrada.');
        return $this->hydrate($pdo,$order);
    }

    public function byOrder(int $tenantId,int $orderId):array
    {
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT o.*,t.name tenant_name,c.name customer_name,c.phone customer_phone FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');
        $s->execute([$orderId,$tenantId]);$order=$s->fetch();
        if(!$order)throw new RuntimeException('Venda não encontrada.');
        return $this->hydrate($pdo,$order);
    }

    public function fulfill(int $orderId,int $orderItemId,string|float|int $quantity,string $source='counter',string $notes='',?string $idempotencyKey=null):array
    {
        Auth::requirePermission('fulfillment.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||!$userId)throw new RuntimeException('Sessão inválida para retirada.');
        if(!in_array($source,['counter','scan','admin'],true))$source='counter';
        $qty=$this->normalizeQuantity($quantity);
        $key=$idempotencyKey?:('fulfill:'.$tenantId.':'.$orderId.':'.$orderItemId.':'.bin2hex(random_bytes(12)));
        if(strlen($key)<12||strlen($key)>190)throw new RuntimeException('Chave de idempotência inválida.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$orderId,$orderItemId,$qty,$source,$notes,$key):array{
            $dupe=$pdo->prepare('SELECT order_id,order_item_id,quantity FROM order_fulfillments WHERE tenant_id=? AND idempotency_key=? LIMIT 1 FOR UPDATE');
            $dupe->execute([$tenantId,$key]);$previous=$dupe->fetch();
            if($previous){
                if((int)$previous['order_id']!==$orderId||(int)$previous['order_item_id']!==$orderItemId||abs((float)$previous['quantity']-$qty)>0.000001)throw new RuntimeException('Chave de idempotência já utilizada em outra retirada.');
                return $this->summaryLocked($pdo,$tenantId,$orderId);
            }

            $o=$pdo->prepare('SELECT * FROM orders WHERE id=? AND tenant_id=? FOR UPDATE');
            $o->execute([$orderId,$tenantId]);$order=$o->fetch();
            if(!$order)throw new RuntimeException('Venda não encontrada.');
            if(!in_array($order['channel'],['counter','pickup'],true))throw new RuntimeException('Retirada parcial disponível somente para balcão/retirada.');
            if($order['payment_status']!=='paid')throw new RuntimeException('Somente venda totalmente paga pode liberar retirada.');
            if($order['status']==='cancelled')throw new RuntimeException('Venda cancelada não pode liberar itens.');

            $i=$pdo->prepare('SELECT * FROM order_items WHERE id=? AND order_id=? FOR UPDATE');
            $i->execute([$orderItemId,$orderId]);$item=$i->fetch();
            if(!$item)throw new RuntimeException('Item da venda não encontrado.');
            $remaining=(float)$item['quantity']-(float)$item['fulfilled_quantity'];
            if($remaining<=0.000001)throw new RuntimeException('Este item já foi retirado por completo.');
            if($qty>$remaining+0.000001)throw new RuntimeException('Quantidade maior que o saldo disponível. Restam '.$this->formatQuantity($remaining).'.');

            $up=$pdo->prepare('UPDATE order_items SET fulfilled_quantity=fulfilled_quantity+? WHERE id=? AND order_id=? AND fulfilled_quantity+?<=quantity');
            $up->execute([$qty,$orderItemId,$orderId,$qty]);
            if($up->rowCount()!==1)throw new RuntimeException('Saldo mudou durante a retirada. Atualize e tente novamente.');

            $ins=$pdo->prepare('INSERT INTO order_fulfillments (tenant_id,order_id,order_item_id,user_id,quantity,source,idempotency_key,notes) VALUES (?,?,?,?,?,?,?,?)');
            $ins->execute([$tenantId,$orderId,$orderItemId,$userId,$qty,$source,$key,mb_substr(trim($notes),0,500)?:null]);

            $pending=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND fulfilled_quantity+0.000001<quantity');
            $pending->execute([$orderId]);$remainingItems=(int)$pending->fetchColumn();
            $any=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=? AND fulfilled_quantity>0');$any->execute([$orderId]);$hasAny=(int)$any->fetchColumn()>0;
            $status=$remainingItems===0?'fulfilled':($hasAny?'partial':'pending');
            $pdo->prepare('UPDATE orders SET fulfillment_status=?,fulfilled_at='.($status==='fulfilled'?'NOW()':'NULL').' WHERE id=? AND tenant_id=?')->execute([$status,$orderId,$tenantId]);

            Auth::audit('fulfillment.item','order',(string)$orderId,['order_item_id'=>$orderItemId,'quantity'=>$qty,'source'=>$source]);
            return $this->summaryLocked($pdo,$tenantId,$orderId);
        });
    }

    public function history(int $tenantId,int $orderId):array
    {
        $s=Database::connection()->prepare('SELECT f.*,oi.name_snapshot,u.name user_name FROM order_fulfillments f JOIN order_items oi ON oi.id=f.order_item_id LEFT JOIN users u ON u.id=f.user_id WHERE f.tenant_id=? AND f.order_id=? ORDER BY f.id DESC');
        $s->execute([$tenantId,$orderId]);return$s->fetchAll();
    }

    public function extractToken(string $value):string
    {
        $value=trim($value);
        if($value==='')return '';
        if(filter_var($value,FILTER_VALIDATE_URL)){
            $query=[];parse_str((string)parse_url($value,PHP_URL_QUERY),$query);
            foreach(['t','token','retirada'] as$key)if(!empty($query[$key]))return trim((string)$query[$key]);
        }
        if(str_contains($value,'t=')){
            $query=[];parse_str((string)(parse_url($value,PHP_URL_QUERY)?:$value),$query);
            if(!empty($query['t']))return trim((string)$query['t']);
        }
        return preg_replace('/\s+/','',$value)??$value;
    }

    private function hydrate(PDO $pdo,array $order):array
    {
        if(!in_array($order['channel'],['counter','pickup'],true))throw new RuntimeException('Esta venda não possui retirada parcial.');
        $i=$pdo->prepare('SELECT id,product_id,name_snapshot,quantity,fulfilled_quantity,unit_price_cents,total_cents FROM order_items WHERE order_id=? ORDER BY id');
        $i->execute([$order['id']]);$items=$i->fetchAll();
        foreach($items as&$item){$item['remaining_quantity']=max(0,(float)$item['quantity']-(float)$item['fulfilled_quantity']);}unset($item);
        $order['items']=$items;
        $order['remaining_items']=array_sum(array_map(fn(array$x)=>(float)$x['remaining_quantity'],$items));
        return$order;
    }

    private function summaryLocked(PDO $pdo,int $tenantId,int $orderId):array
    {
        $s=$pdo->prepare('SELECT o.*,t.name tenant_name,c.name customer_name,c.phone customer_phone FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');
        $s->execute([$orderId,$tenantId]);$order=$s->fetch();
        if(!$order)throw new RuntimeException('Venda não encontrada.');
        return$this->hydrate($pdo,$order);
    }

    private function normalizeQuantity(string|float|int $quantity):float
    {
        if(is_string($quantity))$quantity=str_replace(',','.',trim($quantity));
        if(!is_numeric((string)$quantity))throw new RuntimeException('Quantidade inválida.');
        $qty=round((float)$quantity,3);
        if($qty<=0||$qty>9999)throw new RuntimeException('Quantidade inválida para retirada.');
        return$qty;
    }

    private function formatQuantity(float $qty):string
    {
        return rtrim(rtrim(number_format($qty,3,'.',''),'0'),'.');
    }
}
