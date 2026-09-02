<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PublicOrderService
{
    public function createDelivery(int $tenantId,array $cart,array $buyer,string $address,?string $couponCode=null,?string $scheduledFor=null):array
    {
        return Database::transaction(function(PDO $pdo)use($tenantId,$cart,$buyer,$address,$couponCode,$scheduledFor){
            $tenant=$pdo->prepare('SELECT id,status FROM tenants WHERE id=? FOR UPDATE');
            $tenant->execute([$tenantId]);
            $t=$tenant->fetch();
            if(!$t||$t['status']!=='active')throw new RuntimeException('Empresa indisponível.');

            $schedule=(new OrderSchedulingService())->reserve($pdo,$tenantId,'delivery',$scheduledFor);
            [$items,$subtotal]=$this->validatedItems($pdo,$tenantId,$cart);
            $name=trim((string)($buyer['name']??''));
            $phone=trim((string)($buyer['phone']??''));
            $email=mb_strtolower(trim((string)($buyer['email']??'')));
            $postalCode=trim((string)($buyer['postal_code']??''));
            $neighborhood=trim((string)($buyer['neighborhood']??''));
            $city=trim((string)($buyer['city']??''));
            $address=trim($address);
            if($name===''||$phone===''||$address==='')throw new RuntimeException('Nome, telefone e endereço são obrigatórios.');

            $delivery=$this->resolveDeliveryZone($pdo,$tenantId,$subtotal,$buyer);
            $customerId=$this->upsertCustomer($pdo,$tenantId,$name,$phone,$email);
            [$couponId,$discount]=$this->reserveCouponData($pdo,$tenantId,$subtotal,$couponCode);
            $fee=(int)$delivery['fee_cents'];
            $total=max(0,$subtotal-$discount)+$fee;
            $publicToken=bin2hex(random_bytes(20));
            $expires=(new \DateTimeImmutable('+20 minutes'))->format('Y-m-d H:i:s');

            $s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,coupon_id,delivery_zone_id,channel,status,payment_status,expires_at,scheduled_for,scheduled_slot_minutes,subtotal_cents,discount_cents,delivery_fee_cents,delivery_eta_min_minutes,delivery_eta_max_minutes,total_cents,delivery_address,delivery_postal_code,delivery_neighborhood,delivery_city) VALUES (?,?,?,?,?,"delivery","pending","unpaid",?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$publicToken,$tenantId,$customerId,$couponId,$delivery['zone_id'],$expires,$schedule['scheduled_for'],$schedule['slot_minutes'],$subtotal,$discount,$fee,$delivery['eta_min_minutes'],$delivery['eta_max_minutes'],$total,$address,$postalCode?:null,$neighborhood?:null,$city?:null]);
            $orderId=(int)$pdo->lastInsertId();
            $this->insertItems($pdo,$orderId,$items);
            $this->createCouponReservation($pdo,$tenantId,$couponId,$orderId,$discount,$expires);

            $pdo->prepare('INSERT INTO delivery_events (tenant_id,order_id,user_id,event_type,metadata) VALUES (?,?,NULL,"created",?)')->execute([$tenantId,$orderId,json_encode([
                'zone_id'=>$delivery['zone_id'],'zone'=>$delivery['zone_name'],'fee_cents'=>$fee,
                'eta_min_minutes'=>$delivery['eta_min_minutes'],'eta_max_minutes'=>$delivery['eta_max_minutes'],
                'postal_code'=>$postalCode,'neighborhood'=>$neighborhood,'city'=>$city,
                'scheduled_for'=>$schedule['scheduled_for'],'schedule_label'=>$schedule['label'],
            ],JSON_UNESCAPED_UNICODE)]);

            return [
                'order_id'=>$orderId,'public_token'=>$publicToken,'channel'=>'delivery',
                'subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'delivery_fee_cents'=>$fee,
                'delivery_zone'=>$delivery['zone_name'],'eta_min_minutes'=>$delivery['eta_min_minutes'],'eta_max_minutes'=>$delivery['eta_max_minutes'],
                'scheduled_for'=>$schedule['scheduled_for'],'schedule_label'=>$schedule['label'],
                'total_cents'=>$total,'expires_at'=>$expires,
            ];
        });
    }

    public function createPickup(int $tenantId,array $cart,array $buyer,?string $couponCode=null,?string $scheduledFor=null):array
    {
        return Database::transaction(function(PDO $pdo)use($tenantId,$cart,$buyer,$couponCode,$scheduledFor){
            $tenant=$pdo->prepare('SELECT id,status FROM tenants WHERE id=? FOR UPDATE');
            $tenant->execute([$tenantId]);
            $t=$tenant->fetch();
            if(!$t||$t['status']!=='active')throw new RuntimeException('Empresa indisponível.');

            $schedule=(new OrderSchedulingService())->reserve($pdo,$tenantId,'pickup',$scheduledFor);
            [$items,$subtotal]=$this->validatedItems($pdo,$tenantId,$cart);
            $name=trim((string)($buyer['name']??''));
            $phone=trim((string)($buyer['phone']??''));
            $email=mb_strtolower(trim((string)($buyer['email']??'')));
            if($name===''||$phone==='')throw new RuntimeException('Nome e telefone são obrigatórios para retirada.');
            $customerId=$this->upsertCustomer($pdo,$tenantId,$name,$phone,$email);
            [$couponId,$discount]=$this->reserveCouponData($pdo,$tenantId,$subtotal,$couponCode);
            $total=max(0,$subtotal-$discount);
            $publicToken=bin2hex(random_bytes(20));
            $expires=(new \DateTimeImmutable('+20 minutes'))->format('Y-m-d H:i:s');

            $s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,coupon_id,channel,status,payment_status,expires_at,scheduled_for,scheduled_slot_minutes,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,? ,"pickup","pending","unpaid",?,?,?,?,?,0,?)');
            $s->execute([$publicToken,$tenantId,$customerId,$couponId,$expires,$schedule['scheduled_for'],$schedule['slot_minutes'],$subtotal,$discount,$total]);
            $orderId=(int)$pdo->lastInsertId();
            $this->insertItems($pdo,$orderId,$items);
            $this->createCouponReservation($pdo,$tenantId,$couponId,$orderId,$discount,$expires);

            return [
                'order_id'=>$orderId,'public_token'=>$publicToken,'channel'=>'pickup',
                'subtotal_cents'=>$subtotal,'discount_cents'=>$discount,'delivery_fee_cents'=>0,
                'scheduled_for'=>$schedule['scheduled_for'],'schedule_label'=>$schedule['label'],
                'total_cents'=>$total,'expires_at'=>$expires,
            ];
        });
    }

    public function createTable(string $tableToken,array $cart,array $buyer=[]):array
    {
        return Database::transaction(function(PDO $pdo)use($tableToken,$cart,$buyer){
            $s=$pdo->prepare('SELECT rt.*,t.status tenant_status FROM restaurant_tables rt JOIN tenants t ON t.id=rt.tenant_id WHERE rt.qr_token=? FOR UPDATE');
            $s->execute([$tableToken]);
            $table=$s->fetch();
            if(!$table||$table['tenant_status']!=='active'||$table['status']==='inactive')throw new RuntimeException('Mesa indisponível.');
            $tenantId=(int)$table['tenant_id'];
            [$items,$subtotal]=$this->validatedItems($pdo,$tenantId,$cart);
            $name=trim((string)($buyer['name']??''));
            $phone=trim((string)($buyer['phone']??''));
            $customerId=null;
            if($name!==''||$phone!=='')$customerId=$this->upsertCustomer($pdo,$tenantId,$name!==''?$name:'Cliente da mesa',$phone,'');

            $tab=$pdo->prepare('SELECT * FROM tabs WHERE tenant_id=? AND table_id=? AND status="open" LIMIT 1 FOR UPDATE');
            $tab->execute([$tenantId,$table['id']]);
            $open=$tab->fetch();
            if(!$open){
                $label=$name!==''?$name:'Mesa '.$table['name'];
                $pdo->prepare('INSERT INTO tabs (tenant_id,table_id,customer_id,label,status) VALUES (?,?,?,?,"open")')->execute([$tenantId,$table['id'],$customerId,$label]);
                $tabId=(int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE restaurant_tables SET status="occupied" WHERE id=?')->execute([$table['id']]);
            }else{
                $tabId=(int)$open['id'];
                if($customerId&&!$open['customer_id'])$pdo->prepare('UPDATE tabs SET customer_id=? WHERE id=?')->execute([$customerId,$tabId]);
            }

            $publicToken=bin2hex(random_bytes(20));
            $s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,customer_id,table_id,tab_id,channel,status,payment_status,subtotal_cents,total_cents) VALUES (?,?,?,?,?,"table","confirmed","unpaid",?,?)');
            $s->execute([$publicToken,$tenantId,$customerId,$table['id'],$tabId,$subtotal,$subtotal]);
            $orderId=(int)$pdo->lastInsertId();
            $this->insertItems($pdo,$orderId,$items);
            (new StockService())->commitForOrder($pdo,$tenantId,$orderId);
            return ['order_id'=>$orderId,'public_token'=>$publicToken,'tab_id'=>$tabId,'table_name'=>$table['name'],'total_cents'=>$subtotal];
        });
    }

    private function createCouponReservation(PDO $pdo,int $tenantId,?int $couponId,int $orderId,int $discount,string $expires):void
    {
        if(!$couponId)return;
        $pdo->prepare('INSERT INTO coupon_reservations (tenant_id,coupon_id,order_id,discount_cents,status,expires_at) VALUES (?,?,?,?,"reserved",?)')->execute([$tenantId,$couponId,$orderId,$discount,$expires]);
        $pdo->prepare('UPDATE coupons SET reserved_count=reserved_count+1 WHERE id=?')->execute([$couponId]);
    }

    private function resolveDeliveryZone(PDO $pdo,int $tenantId,int $subtotal,array $buyer):array
    {
        $count=$pdo->prepare('SELECT COUNT(*) FROM delivery_zones WHERE tenant_id=? AND active=1');
        $count->execute([$tenantId]);
        if((int)$count->fetchColumn()===0){
            $s=$pdo->prepare('SELECT settings FROM tenants WHERE id=?');$s->execute([$tenantId]);
            $settings=json_decode((string)($s->fetchColumn()?:'{}'),true);if(!is_array($settings))$settings=[];
            $minimum=max(0,(int)($settings['min_delivery_order_cents']??0));
            if($subtotal<$minimum)throw new RuntimeException('Pedido mínimo para delivery: R$ '.number_format($minimum/100,2,',','.').'.');
            $etaMin=max(5,(int)($settings['default_delivery_eta_min']??30));
            $etaMax=max($etaMin,(int)($settings['default_delivery_eta_max']??60));
            return ['zone_id'=>null,'zone_name'=>'Padrão','fee_cents'=>max(0,(int)($settings['delivery_fee_cents']??0)),'eta_min_minutes'=>$etaMin,'eta_max_minutes'=>$etaMax];
        }

        $postal=preg_replace('/\D+/','',(string)($buyer['postal_code']??''))??'';
        $neighborhood=$this->normalizeArea((string)($buyer['neighborhood']??''));
        $city=$this->normalizeArea((string)($buyer['city']??''));
        if($postal===''&&$neighborhood===''&&$city==='')throw new RuntimeException('Informe CEP, bairro e cidade para validar a área de entrega.');

        $stmt=$pdo->prepare("SELECT * FROM delivery_zones WHERE tenant_id=? AND active=1 ORDER BY FIELD(match_type,'postal_prefix','neighborhood','city'),sort_order,id");
        $stmt->execute([$tenantId]);
        $matched=null;
        foreach($stmt->fetchAll() as $zone){
            $value=(string)$zone['match_value'];
            if($zone['match_type']==='postal_prefix'){
                $prefix=preg_replace('/\D+/','',$value)??'';
                if($prefix!==''&&$postal!==''&&str_starts_with($postal,$prefix)){$matched=$zone;break;}
            }elseif($zone['match_type']==='neighborhood'&&$neighborhood!==''&&$neighborhood===$this->normalizeArea($value)){$matched=$zone;break;}
            elseif($zone['match_type']==='city'&&$city!==''&&$city===$this->normalizeArea($value)){$matched=$zone;break;}
        }

        if(!$matched)throw new RuntimeException('Este endereço está fora da área de entrega configurada.');
        if($subtotal<(int)$matched['min_order_cents'])throw new RuntimeException('Pedido mínimo para '.$matched['name'].': R$ '.number_format(((int)$matched['min_order_cents'])/100,2,',','.').'.');
        $fee=(int)$matched['fee_cents'];
        if($matched['free_above_cents']!==null&&$subtotal>=(int)$matched['free_above_cents'])$fee=0;
        $etaMin=max(1,(int)$matched['eta_min_minutes']);$etaMax=max($etaMin,(int)$matched['eta_max_minutes']);
        return ['zone_id'=>(int)$matched['id'],'zone_name'=>(string)$matched['name'],'fee_cents'=>$fee,'eta_min_minutes'=>$etaMin,'eta_max_minutes'=>$etaMax];
    }

    private function normalizeArea(string $value):string
    {
        $value=trim(mb_strtolower($value));if($value==='')return '';
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);$value=$ascii!==false?$ascii:$value;
        return trim(preg_replace('/\s+/',' ',preg_replace('/[^a-z0-9\s-]/','',$value)??'')??'');
    }

    private function validatedItems(PDO $pdo,int $tenantId,array $cart):array
    {
        $normalized=[];foreach($cart as $row){$id=(int)($row['product_id']??0);$qty=max(0,min(50,(int)($row['qty']??0)));if($id>0&&$qty>0)$normalized[$id]=($normalized[$id]??0)+$qty;}
        if(!$normalized)throw new RuntimeException('Carrinho vazio.');
        $items=[];$subtotal=0;$stmt=$pdo->prepare('SELECT id,name,price_cents,track_stock,stock_qty FROM products WHERE id=? AND tenant_id=? AND active=1');
        foreach($normalized as $id=>$qty){$stmt->execute([$id,$tenantId]);$p=$stmt->fetch();if(!$p)throw new RuntimeException('Um produto do carrinho não está mais disponível.');if((int)$p['track_stock']&&$p['stock_qty']!==null&&(float)$p['stock_qty']<$qty)throw new RuntimeException('Estoque insuficiente para '.$p['name'].'.');$line=(int)$p['price_cents']*$qty;$subtotal+=$line;$items[]=['product_id'=>$id,'name'=>$p['name'],'unit_price_cents'=>(int)$p['price_cents'],'qty'=>$qty,'total_cents'=>$line];}
        return [$items,$subtotal];
    }

    private function upsertCustomer(PDO $pdo,int $tenantId,string $name,string $phone,string $email):int
    {
        $id=null;if($phone!==''){$s=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone=? LIMIT 1');$s->execute([$tenantId,$phone]);$id=$s->fetchColumn()?:null;}if(!$id&&$email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL)){$s=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND email=? LIMIT 1');$s->execute([$tenantId,$email]);$id=$s->fetchColumn()?:null;}
        if($id){$pdo->prepare('UPDATE customers SET name=?,phone=COALESCE(NULLIF(?,""),phone),email=COALESCE(NULLIF(?,""),email) WHERE id=? AND tenant_id=?')->execute([$name,$phone,$email,$id,$tenantId]);return(int)$id;}
        $pdo->prepare('INSERT INTO customers (tenant_id,name,phone,email) VALUES (?,?,?,?)')->execute([$tenantId,$name,$phone?:null,filter_var($email,FILTER_VALIDATE_EMAIL)?$email:null]);return(int)$pdo->lastInsertId();
    }

    private function reserveCouponData(PDO $pdo,int $tenantId,int $subtotal,?string $couponCode):array
    {
        $code=strtoupper(trim((string)$couponCode));if($code==='')return[null,0];$now=new \DateTimeImmutable();$s=$pdo->prepare('SELECT * FROM coupons WHERE tenant_id=? AND code=? AND active=1 FOR UPDATE');$s->execute([$tenantId,$code]);$c=$s->fetch();if(!$c)throw new RuntimeException('Cupom inválido.');if($c['starts_at']&&$now<new \DateTimeImmutable($c['starts_at']))throw new RuntimeException('Cupom ainda não está válido.');if($c['ends_at']&&$now>new \DateTimeImmutable($c['ends_at']))throw new RuntimeException('Cupom expirado.');if($c['max_uses']!==null&&((int)$c['uses_count']+(int)$c['reserved_count'])>=(int)$c['max_uses'])throw new RuntimeException('Limite do cupom atingido.');if($subtotal<(int)$c['min_order_cents'])throw new RuntimeException('Valor mínimo do cupom não atingido.');$discount=$c['type']==='percent'?(int)round($subtotal*min(100,(int)$c['value'])/100):min($subtotal,(int)$c['value']);return[(int)$c['id'],$discount];
    }

    private function insertItems(PDO $pdo,int $orderId,array $items):void
    {
        $ins=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents) VALUES (?,?,?,?,?,?)');foreach($items as $i)$ins->execute([$orderId,$i['product_id'],$i['name'],$i['unit_price_cents'],$i['qty'],$i['total_cents']]);
    }
}
