<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppOrderReceiptService
{
    public function queue(PDO $pdo,int $tenantId,int $orderId):?int
    {
        if($tenantId<1||$orderId<1)throw new RuntimeException('Pedido inválido para envio do comprovante.');

        $q=$pdo->prepare('SELECT o.id,o.public_token,o.channel,o.status,o.payment_status,o.subtotal_cents,o.discount_cents,o.delivery_fee_cents,o.total_cents,o.delivery_address,o.created_at,c.name customer_name,c.phone customer_phone,t.name tenant_name,ou.name unit_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN operating_units ou ON ou.id=o.unit_id AND ou.tenant_id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1');
        $q->execute([$orderId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!$order)return null;

        $integration=new WhatsAppIntegrationService();
        $integration->ensureConnection($pdo,$tenantId);
        $phone=$integration->normalizePhone((string)($order['customer_phone']??''));
        if($phone==='')return null;

        $existing=$pdo->prepare("SELECT id FROM whatsapp_outbox WHERE tenant_id=? AND order_id=? AND event_type='order_received' LIMIT 1");
        $existing->execute([$tenantId,$orderId]);$existingId=$existing->fetchColumn();
        if($existingId!==false)return(int)$existingId;

        $itemsQ=$pdo->prepare('SELECT id,name_snapshot,unit_price_cents,quantity,total_cents,notes FROM order_items WHERE order_id=? ORDER BY id');
        $itemsQ->execute([$orderId]);$items=$itemsQ->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$items)return null;

        $modifiers=[];
        try{
            $mods=$pdo->prepare('SELECT order_item_id,group_name_snapshot,option_name_snapshot,total_delta_cents FROM order_item_modifiers WHERE tenant_id=? AND order_id=? ORDER BY id');
            $mods->execute([$tenantId,$orderId]);
            foreach($mods->fetchAll(PDO::FETCH_ASSOC)?:[] as$row)$modifiers[(int)$row['order_item_id']][]=$row;
        }catch(Throwable){}

        $message=$this->render($order,$items,$modifiers);
        $key=hash('sha256',implode('|',[$tenantId,$orderId,'order_received',$phone,'']));
        try{
            $pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'order_received', ?, ?, 'desktop_queued',0,5,CURRENT_TIMESTAMP,?)")
                ->execute([$tenantId,$orderId,$phone,$message,$key]);
            return(int)$pdo->lastInsertId();
        }catch(\PDOException $e){
            $existing->execute([$tenantId,$orderId]);$existingId=$existing->fetchColumn();
            if($existingId!==false)return(int)$existingId;
            throw$e;
        }
    }

    /** @param array<int,array<string,mixed>> $items @param array<int,array<int,array<string,mixed>>> $modifiers */
    private function render(array$order,array$items,array$modifiers):string
    {
        $lines=[];
        $lines[]='🧾 *COMPROVANTE DO PEDIDO*';
        $lines[]='*'.trim((string)($order['tenant_name']??'EventMenu')).'*';
        if(trim((string)($order['unit_name']??''))!=='')$lines[]='Unidade: '.trim((string)$order['unit_name']);
        $lines[]='Pedido #'.(int)$order['id'];
        $created=strtotime((string)($order['created_at']??''));
        if($created!==false)$lines[]='Data: '.date('d/m/Y H:i',$created);
        $customer=trim((string)($order['customer_name']??''));if($customer!=='')$lines[]='Cliente: '.$customer;
        $lines[]='Tipo: '.$this->channel((string)($order['channel']??''));
        $lines[]='';$lines[]='*Itens*';

        $shown=0;
        foreach($items as$item){
            if($shown>=30){$lines[]='… demais itens disponíveis no acompanhamento do pedido.';break;}
            $qty=$this->quantity($item['quantity']??1);$name=trim((string)($item['name_snapshot']??'Item'));
            $lines[]='• '.$qty.'x '.$name.' — '.$this->money((int)($item['total_cents']??0));
            foreach($modifiers[(int)($item['id']??0)]??[] as$modifier){
                $group=trim((string)($modifier['group_name_snapshot']??''));$option=trim((string)($modifier['option_name_snapshot']??''));
                if($option==='')continue;$extra=(int)($modifier['total_delta_cents']??0);$text='  + '.($group!==''?$group.': ':'').$option;if($extra!==0)$text.=' ('.($extra>0?'+':'').$this->money($extra).')';$lines[]=$text;
            }
            $note=trim((string)($item['notes']??''));if($note!=='')$lines[]='  Obs.: '.mb_substr($note,0,180);
            $shown++;
        }

        $lines[]='';
        $lines[]='Subtotal: '.$this->money((int)($order['subtotal_cents']??0));
        $discount=(int)($order['discount_cents']??0);if($discount>0)$lines[]='Desconto: -'.$this->money($discount);
        $delivery=(int)($order['delivery_fee_cents']??0);if($delivery>0)$lines[]='Entrega: '.$this->money($delivery);
        $lines[]='*Total: '.$this->money((int)($order['total_cents']??0)).'*';
        $lines[]='Pagamento: '.$this->paymentStatus((string)($order['payment_status']??''));

        $address=trim((string)($order['delivery_address']??''));if($address!==''&&($order['channel']??'')==='delivery')$lines[]='Entrega: '.mb_substr($address,0,250);
        $token=trim((string)($order['public_token']??''));if($token!==''){$lines[]='';$lines[]='Acompanhe seu pedido:';$lines[]=\app_absolute_url('pedido.php?t='.rawurlencode($token));}
        $lines[]='';$lines[]='Este comprovante confirma o recebimento do pedido. Pagamentos só são considerados confirmados após validação do EventMenu/provedor.';

        $message=trim(implode("\n",$lines));
        return mb_strlen($message)>3900?mb_substr($message,0,3820)."\n\nAcesse o link do pedido para ver os demais detalhes.":$message;
    }

    private function channel(string$value):string{return match(strtolower($value)){'delivery'=>'Delivery','pickup'=>'Retirada no local','table'=>'Mesa','counter'=>'Balcão','event_bar','bar'=>'Bar do evento',default=>'Pedido pelo cardápio'};}
    private function paymentStatus(string$value):string{return match(strtolower($value)){'paid'=>'Confirmado','pending','processing','created'=>'Aguardando confirmação','failed','cancelled'=>'Não concluído','refunded','partially_refunded'=>'Estornado',default=>'Aguardando pagamento'};}
    private function money(int$cents):string{return'R$ '.number_format($cents/100,2,',','.');}
    private function quantity(mixed$value):string{$qty=(float)$value;return abs($qty-round($qty))<0.0005?(string)(int)round($qty):rtrim(rtrim(number_format($qty,3,',','.'),'0'),',');}
}
