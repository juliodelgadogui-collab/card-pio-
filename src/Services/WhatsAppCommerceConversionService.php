<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCommerceConversionService
{
    private ConfiguredOrderService $configured;
    private CustomerIdentityService $identity;

    public function __construct()
    {
        $this->configured=new ConfiguredOrderService();
        $this->identity=new CustomerIdentityService();
    }

    /** @return array<string,mixed> */
    public function settings(PDO $pdo,int $tenantId):array
    {
        $q=$pdo->prepare('SELECT * FROM whatsapp_commerce_conversion_settings WHERE tenant_id=? LIMIT 1');
        $q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if($row)return$row;
        try{$pdo->prepare('INSERT INTO whatsapp_commerce_conversion_settings (tenant_id) VALUES (?)')->execute([$tenantId]);}catch(Throwable){}
        $q->execute([$tenantId]);
        return$q->fetch(PDO::FETCH_ASSOC)?:[
            'tenant_id'=>$tenantId,'repeat_last_order_enabled'=>1,'upsell_enabled'=>1,
            'upsell_max_suggestions'=>3,'abandoned_cart_enabled'=>1,'abandoned_delay_minutes'=>60,
        ];
    }

    /** @return array<string,mixed> */
    public function saveSettings(PDO $pdo,int $tenantId,array $input):array
    {
        $this->settings($pdo,$tenantId);
        $max=max(1,min(5,(int)($input['upsell_max_suggestions']??3)));
        $delay=max(15,min(1440,(int)($input['abandoned_delay_minutes']??60)));
        $pdo->prepare('UPDATE whatsapp_commerce_conversion_settings SET repeat_last_order_enabled=?,upsell_enabled=?,upsell_max_suggestions=?,abandoned_cart_enabled=?,abandoned_delay_minutes=?,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')
            ->execute([!empty($input['repeat_last_order_enabled'])?1:0,!empty($input['upsell_enabled'])?1:0,$max,!empty($input['abandoned_cart_enabled'])?1:0,$delay,$tenantId]);
        return$this->settings($pdo,$tenantId);
    }

    /** @return array<string,mixed> */
    public function repeatLastOrder(PDO $pdo,int $tenantId,array $conversation):array
    {
        $settings=$this->settings($pdo,$tenantId);
        if((int)($settings['repeat_last_order_enabled']??1)!==1)return['handled'=>true,'state'=>'WELCOME','reply'=>"A opção de repetir pedido está desativada nesta empresa.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'repeat_disabled'];

        $conversationId=(int)($conversation['id']??0);if($conversationId<1)throw new RuntimeException('Conversa inválida.');
        $customerId=(int)($conversation['customer_id']??0);
        if($customerId<1){$customer=$this->identity->findByPhone($pdo,$tenantId,(string)($conversation['phone']??''));$customerId=$customer?(int)$customer['id']:0;}
        if($customerId<1)return['handled'=>true,'state'=>'WELCOME','reply'=>"Ainda não encontrei um pedido anterior neste número.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'repeat_not_found'];

        $existing=$this->validDraftId($pdo,$tenantId,(int)($conversation['draft_order_id']??0));
        if($existing>0){$pdo->prepare("UPDATE whatsapp_conversations SET state='CART',context_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$this->json(['draft_order_id'=>$existing]),$conversationId,$tenantId]);return$this->cartSnapshot($pdo,$tenantId,$conversationId,$existing,'Você já tinha um carrinho em andamento. Continue por ele:','repeat_existing_cart');}

        $q=$pdo->prepare("SELECT id,unit_id FROM orders WHERE tenant_id=? AND customer_id=? AND status NOT IN ('draft','cancelled') ORDER BY id DESC LIMIT 1");$q->execute([$tenantId,$customerId]);$previous=$q->fetch(PDO::FETCH_ASSOC);
        if(!$previous)return['handled'=>true,'state'=>'WELCOME','reply'=>"Ainda não encontrei um pedido anterior para repetir.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'repeat_not_found'];

        $unitId=(int)($previous['unit_id']??0);if($unitId>0){$u=$pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');$u->execute([$unitId,$tenantId]);if(!$u->fetchColumn())$unitId=0;}
        if($unitId<1){$u=$pdo->prepare('SELECT id FROM operating_units WHERE tenant_id=? AND active=1 ORDER BY id LIMIT 1');$u->execute([$tenantId]);$unitId=(int)($u->fetchColumn()?:0);}
        if($unitId<1)throw new RuntimeException('A empresa não possui unidade operacional ativa para repetir o pedido.');

        $token=bin2hex(random_bytes(20));
        $pdo->prepare("INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?, 'pending','WHATSAPP','draft','unpaid',0,0,0,0)")->execute([$token,$tenantId,$unitId,$customerId]);
        $draftId=(int)$pdo->lastInsertId();$copied=0;$skipped=0;$subtotal=0;

        $items=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$items->execute([(int)$previous['id']]);
        $insert=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,?,?,?)');
        foreach($items->fetchAll(PDO::FETCH_ASSOC)?:[] as$item){
            $mods=$pdo->prepare('SELECT modifier_option_id FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=? AND modifier_option_id IS NOT NULL');$mods->execute([$tenantId,(int)$previous['id'],(int)$item['id']]);$optionIds=array_map('intval',$mods->fetchAll(PDO::FETCH_COLUMN)?:[]);
            try{
                $resolved=$this->configured->resolveLine($pdo,$tenantId,['product_id'=>(int)$item['product_id'],'qty'=>(float)$item['quantity'],'option_ids'=>$optionIds,'notes'=>(string)($item['notes']??'')],true);
                $insert->execute([$draftId,$resolved['product_id'],$resolved['name'],$resolved['unit_price_cents'],$resolved['quantity'],$resolved['total_cents'],$resolved['notes']?:null]);
                $this->configured->persistModifiers($pdo,$tenantId,$draftId,(int)$pdo->lastInsertId(),$resolved);$subtotal+=(int)$resolved['total_cents'];$copied++;
            }catch(RuntimeException){$skipped++;}
        }
        if($copied<1){
            $pdo->prepare("UPDATE orders SET status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$draftId,$tenantId]);
            $pdo->prepare("UPDATE whatsapp_conversations SET draft_order_id=NULL,state='WELCOME',context_json=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$conversationId,$tenantId]);
            return['handled'=>true,'state'=>'WELCOME','reply'=>"Seu último pedido tem itens que não estão disponíveis agora.\n\n1 - Fazer um novo pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'repeat_unavailable'];
        }
        $pdo->prepare('UPDATE orders SET subtotal_cents=?,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$subtotal,$subtotal,$draftId,$tenantId]);
        $pdo->prepare("UPDATE whatsapp_conversations SET customer_id=?,draft_order_id=?,state='CART',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")
            ->execute([$customerId,$draftId,$this->json(['draft_order_id'=>$draftId,'repeated_from_order_id'=>(int)$previous['id']]),$conversationId,$tenantId]);
        $prefix='🔁 Repeti os itens disponíveis do pedido #'.(int)$previous['id'].' usando os preços atuais.';if($skipped>0)$prefix.="\n⚠️ {$skipped} item(ns) não puderam ser repetidos porque mudaram ou ficaram indisponíveis.";
        return$this->cartSnapshot($pdo,$tenantId,$conversationId,$draftId,$prefix,'repeat_order');
    }

    /** @return array<string,mixed> */
    public function beginUpsell(PDO $pdo,int $tenantId,array $conversation,string $prefix=''):array
    {
        $settings=$this->settings($pdo,$tenantId);$draftId=$this->validDraftId($pdo,$tenantId,(int)($conversation['draft_order_id']??0));
        if($draftId<1)throw new RuntimeException('O carrinho não está mais disponível.');
        if((int)($settings['upsell_enabled']??1)!==1)return$this->toFulfillment($pdo,$tenantId,$conversation,$draftId,$prefix);
        $suggestions=$this->suggestions($pdo,$tenantId,$draftId,max(1,min(5,(int)($settings['upsell_max_suggestions']??3))));
        if(!$suggestions)return$this->toFulfillment($pdo,$tenantId,$conversation,$draftId,$prefix);
        $ids=array_map(static fn(array$row):int=>(int)$row['id'],$suggestions);$context=['draft_order_id'=>$draftId,'upsell_product_ids'=>$ids];
        $pdo->prepare("UPDATE whatsapp_conversations SET state='UPSELL',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$this->json($context),(int)$conversation['id'],$tenantId]);
        $lines=[];if($prefix!=='')$lines[]=$prefix;$lines[]='';$lines[]='✨ *Que tal completar seu pedido?*';$lines[]='Quem compra itens parecidos também costuma pedir:';$lines[]='';foreach($suggestions as$i=>$row)$lines[]=($i+1).' - '.(string)$row['name'].' — '.$this->money((int)$row['price_cents']);$lines[]='';$lines[]='0 - Continuar sem adicionar';
        return['handled'=>true,'state'=>'UPSELL','draft_order_id'=>$draftId,'reply'=>trim(implode("\n",$lines)),'kind'=>'upsell_offer'];
    }

    /** @return array<string,mixed> */
    public function handleUpsell(PDO $pdo,int $tenantId,array $conversation,string $text):array
    {
        $draftId=$this->validDraftId($pdo,$tenantId,(int)($conversation['draft_order_id']??0));if($draftId<1)throw new RuntimeException('O carrinho não está mais disponível.');
        $normalized=$this->normalize($text);if(in_array($normalized,['0','continuar','não','nao','pular'],true))return$this->toFulfillment($pdo,$tenantId,$conversation,$draftId);
        $context=$this->context($conversation['context_json']??null);$ids=array_values(array_filter(array_map('intval',(array)($context['upsell_product_ids']??[])),fn(int$id):bool=>$id>0));
        if(!ctype_digit($normalized)||(int)$normalized<1||(int)$normalized>count($ids))return$this->beginUpsell($pdo,$tenantId,$conversation,'Escolha uma sugestão pelo número ou envie 0 para continuar.');
        $productId=$ids[(int)$normalized-1];
        if($this->hasActiveModifiers($pdo,$tenantId,$productId))return$this->beginUpsell($pdo,$tenantId,$conversation,'Esta sugestão mudou e precisa ser escolhida pelo cardápio. Selecione outra opção.');
        $resolved=$this->configured->resolveLine($pdo,$tenantId,['product_id'=>$productId,'qty'=>1,'option_ids'=>[],'notes'=>''],true);
        $ins=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,?,?,NULL)');$ins->execute([$draftId,$resolved['product_id'],$resolved['name'],$resolved['unit_price_cents'],1,$resolved['total_cents']]);
        $this->recalculate($pdo,$tenantId,$draftId);
        $fresh=$this->conversation($pdo,$tenantId,(int)$conversation['id']);
        return$this->beginUpsell($pdo,$tenantId,$fresh,'✅ '.(string)$resolved['name'].' adicionado ao pedido.');
    }

    public function recoverAbandonedCarts(PDO $pdo,int $limit=100):int
    {
        $limit=max(1,min(500,$limit));$sql="SELECT c.id conversation_id,c.tenant_id,c.phone,c.customer_id,c.draft_order_id,c.last_activity_at,s.abandoned_delay_minutes,o.total_cents,t.name tenant_name,cu.name customer_name FROM whatsapp_conversations c JOIN whatsapp_commerce_conversion_settings s ON s.tenant_id=c.tenant_id AND s.abandoned_cart_enabled=1 JOIN whatsapp_connections wc ON wc.tenant_id=c.tenant_id AND wc.commerce_enabled=1 JOIN orders o ON o.id=c.draft_order_id AND o.tenant_id=c.tenant_id AND o.status='draft' AND o.order_source='WHATSAPP' JOIN tenants t ON t.id=c.tenant_id AND t.status='active' LEFT JOIN customers cu ON cu.id=c.customer_id AND cu.tenant_id=c.tenant_id WHERE c.mode='auto' AND c.draft_order_id IS NOT NULL ORDER BY c.last_activity_at ASC LIMIT ".($limit*4);
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];$queued=0;$now=time();
        foreach($rows as$row){if($queued>=$limit)break;$activity=strtotime((string)($row['last_activity_at']??''));if($activity===false)continue;$age=$now-$activity;$delay=max(15,min(1440,(int)($row['abandoned_delay_minutes']??60)))*60;if($age<$delay||$age>23*3600)continue;$draftId=(int)$row['draft_order_id'];$count=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?');$count->execute([$draftId]);$itemCount=(int)$count->fetchColumn();if($itemCount<1)continue;$phone=(new WhatsAppIntegrationService())->normalizePhone((string)$row['phone']);if($phone==='')continue;
            $key=hash('sha256','cart-abandoned|'.(int)$row['tenant_id'].'|'.$draftId.'|'.$phone);$exists=$pdo->prepare('SELECT id FROM whatsapp_outbox WHERE idempotency_key=? LIMIT 1');$exists->execute([$key]);if($exists->fetchColumn())continue;
            $first=$this->firstName((string)($row['customer_name']??''));$greeting=$first!==''?'Oi, '.$first.'! 👋':'Oi! 👋';$message=$greeting."\n\nSeu pedido na ".(string)$row['tenant_name']." ficou salvo com {$itemCount} item(ns).\nTotal atual: ".$this->money((int)$row['total_cents'])."\n\nResponda *MEU PEDIDO* para continuar de onde parou ou *CANCELAR* se não quiser mais.";
            try{$pdo->prepare("INSERT INTO whatsapp_outbox (tenant_id,order_id,event_type,recipient,message_text,status,attempt_count,max_attempts,available_at,idempotency_key) VALUES (?,?, 'cart_abandoned',?,?,'desktop_queued',0,5,CURRENT_TIMESTAMP,?)")->execute([(int)$row['tenant_id'],$draftId,$phone,$message,$key]);$outboxId=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO whatsapp_messages (tenant_id,conversation_id,outbox_id,direction,message_type,message_text,status) VALUES (?,?,?,'outbound','text',?,'queued')")->execute([(int)$row['tenant_id'],(int)$row['conversation_id'],$outboxId,$message]);$pdo->prepare('UPDATE whatsapp_conversations SET last_outbound_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([(int)$row['conversation_id'],(int)$row['tenant_id']]);$queued++;}catch(\PDOException $e){$exists->execute([$key]);if(!$exists->fetchColumn())throw $e;}
        }
        return$queued;
    }

    /** @return array<int,array<string,mixed>> */
    private function suggestions(PDO $pdo,int $tenantId,int $draftId,int $limit):array
    {
        $q=$pdo->prepare('SELECT DISTINCT product_id FROM order_items WHERE order_id=? AND product_id IS NOT NULL');$q->execute([$draftId]);$current=array_values(array_filter(array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]),fn(int$id):bool=>$id>0));if(!$current)return[];
        $in=implode(',',array_fill(0,count($current),'?'));$notIn=$in;
        $sql="SELECT p.id,p.name,p.price_cents,COUNT(*) score FROM orders o JOIN order_items base ON base.order_id=o.id JOIN order_items other ON other.order_id=o.id AND other.product_id<>base.product_id JOIN products p ON p.id=other.product_id AND p.tenant_id=o.tenant_id AND p.active=1 WHERE o.tenant_id=? AND o.status NOT IN ('draft','cancelled') AND base.product_id IN ({$in}) AND other.product_id NOT IN ({$notIn}) AND NOT EXISTS (SELECT 1 FROM modifier_groups mg WHERE mg.tenant_id=p.tenant_id AND mg.product_id=p.id AND mg.active=1) GROUP BY p.id,p.name,p.price_cents ORDER BY score DESC,p.id DESC LIMIT ".$limit;
        $params=array_merge([$tenantId],$current,$current);$s=$pdo->prepare($sql);$s->execute($params);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];if($rows)return$rows;
        $sql="SELECT p.id,p.name,p.price_cents,COUNT(DISTINCT o.id) score FROM orders o JOIN order_items oi ON oi.order_id=o.id JOIN products p ON p.id=oi.product_id AND p.tenant_id=o.tenant_id AND p.active=1 WHERE o.tenant_id=? AND o.status NOT IN ('draft','cancelled') AND p.id NOT IN ({$notIn}) AND NOT EXISTS (SELECT 1 FROM modifier_groups mg WHERE mg.tenant_id=p.tenant_id AND mg.product_id=p.id AND mg.active=1) GROUP BY p.id,p.name,p.price_cents ORDER BY score DESC,p.id DESC LIMIT ".$limit;$s=$pdo->prepare($sql);$s->execute(array_merge([$tenantId],$current));return$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @return array<string,mixed> */
    private function toFulfillment(PDO $pdo,int $tenantId,array $conversation,int $draftId,string $prefix=''):array
    {
        $q=$pdo->prepare('SELECT settings FROM tenants WHERE id=? LIMIT 1');$q->execute([$tenantId]);$tenantSettings=json_decode((string)($q->fetchColumn()?:'{}'),true);if(!is_array($tenantSettings))$tenantSettings=[];$pickup=!empty($tenantSettings['delivery_pickup_enabled']);$context=['draft_order_id'=>$draftId];$pdo->prepare("UPDATE whatsapp_conversations SET state='CHOOSING_FULFILLMENT',context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?")->execute([$this->json($context),(int)$conversation['id'],$tenantId]);$lines=[];if($prefix!=='')$lines[]=$prefix;$lines[]='';$lines[]='Como deseja receber o pedido?';$lines[]='';$lines[]='1 - 🛵 Entrega';if($pickup)$lines[]='2 - 🛍️ Retirada no local';$lines[]='';$lines[]='Digite *CANCELAR* para cancelar o carrinho.';return['handled'=>true,'state'=>'CHOOSING_FULFILLMENT','draft_order_id'=>$draftId,'reply'=>trim(implode("\n",$lines)),'kind'=>'fulfillment_prompt'];
    }

    /** @return array<string,mixed> */
    private function cartSnapshot(PDO $pdo,int $tenantId,int $conversationId,int $draftId,string $prefix,string $kind):array
    {
        $q=$pdo->prepare('SELECT name_snapshot,quantity,total_cents FROM order_items WHERE order_id=? ORDER BY id');$q->execute([$draftId]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$total=$pdo->prepare('SELECT total_cents FROM orders WHERE id=? AND tenant_id=?');$total->execute([$draftId,$tenantId]);$lines=[$prefix,'','🛒 *SEU PEDIDO*',''];foreach($items as$i=>$item)$lines[]=($i+1).'. '.$this->qty((float)$item['quantity']).'x '.(string)$item['name_snapshot'].' — '.$this->money((int)$item['total_cents']);$lines[]='';$lines[]='*Total atual: '.$this->money((int)($total->fetchColumn()?:0)).'*';$lines[]='';$lines[]='1 - Adicionar mais itens';$lines[]='2 - Alterar quantidade';$lines[]='3 - Remover item';$lines[]='4 - Finalizar carrinho';$lines[]='5 - Cancelar pedido';return['handled'=>true,'state'=>'CART','draft_order_id'=>$draftId,'reply'=>implode("\n",$lines),'kind'=>$kind];
    }

    private function recalculate(PDO $pdo,int $tenantId,int $draftId):void
    {$q=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id=?');$q->execute([$draftId]);$subtotal=max(0,(int)$q->fetchColumn());$pdo->prepare("UPDATE orders SET subtotal_cents=?,discount_cents=0,delivery_fee_cents=0,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status='draft'")->execute([$subtotal,$subtotal,$draftId,$tenantId]);}
    private function validDraftId(PDO $pdo,int $tenantId,int $draftId):int
    {if($draftId<1)return 0;$q=$pdo->prepare("SELECT id FROM orders WHERE id=? AND tenant_id=? AND status='draft' AND order_source='WHATSAPP' LIMIT 1");$q->execute([$draftId,$tenantId]);return$q->fetchColumn()?$draftId:0;}
    private function hasActiveModifiers(PDO $pdo,int $tenantId,int $productId):bool
    {$q=$pdo->prepare('SELECT 1 FROM modifier_groups WHERE tenant_id=? AND product_id=? AND active=1 LIMIT 1');$q->execute([$tenantId,$productId]);return(bool)$q->fetchColumn();}
    /** @return array<string,mixed> */
    private function conversation(PDO $pdo,int $tenantId,int $conversationId):array
    {$q=$pdo->prepare('SELECT * FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$conversationId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Conversa não encontrada.');return$row;}
    /** @return array<string,mixed> */
    private function context(mixed $raw):array
    {if(!is_string($raw)||trim($raw)==='')return[];$value=json_decode($raw,true);return is_array($value)?$value:[];}
    private function json(array $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function normalize(string $value):string{$value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:");}
    private function money(int $cents):string{return'R$ '.number_format($cents/100,2,',','.');}
    private function qty(float $value):string{return abs($value-round($value))<0.0005?(string)(int)round($value):rtrim(rtrim(number_format($value,3,',','.'),'0'),',');}
    private function firstName(string $name):string{$name=trim($name);if($name==='')return'';$parts=preg_split('/\s+/u',$name)?:[];return mb_substr((string)($parts[0]??''),0,60);}
}
