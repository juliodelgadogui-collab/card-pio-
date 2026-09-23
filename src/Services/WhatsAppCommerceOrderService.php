<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class WhatsAppCommerceOrderService
{
    private const PAGE_SIZE=8;
    private const OPTION_PAGE_SIZE=7;
    private ConfiguredOrderService $configured;
    private CustomerIdentityService $identity;

    public function __construct()
    {
        $this->configured=new ConfiguredOrderService();
        $this->identity=new CustomerIdentityService();
    }

    /** @return array<string,mixed> */
    public function handle(PDO $pdo,int $tenantId,array $conversation,string $text,string $displayName=''):array
    {
        $state=(string)($conversation['state']??'WELCOME');
        $context=$this->context($conversation['context_json']??null);
        $normalized=$this->normalize($text);
        $conversationId=(int)$conversation['id'];
        $phone=(string)$conversation['phone'];
        $customerId=$this->identifyCustomer($pdo,$tenantId,$conversationId,$phone);

        if($normalized==='cancelar')return $this->cancelDraft($pdo,$tenantId,$conversationId,$conversation);
        if($normalized==='meu pedido')return $this->cartResult($pdo,$tenantId,$conversationId,$conversation,$context);

        if($state==='WELCOME'){
            if($normalized!=='1')return ['handled'=>false];
            $draftId=$this->ensureDraft($pdo,$tenantId,$conversationId,$conversation,$customerId);
            $context=['draft_order_id'=>$draftId,'category_page'=>0];
            return $this->categoriesResult($pdo,$tenantId,$conversationId,$context);
        }

        if($state==='SELECTING_CATEGORY'){
            if($normalized==='0')return $this->stateResult($pdo,$tenantId,$conversationId,'WELCOME',[],"Voltamos ao início.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'welcome');
            $page=max(0,(int)($context['category_page']??0));
            if(in_array($normalized,['9','mais','próxima','proxima'],true)){$context['category_page']=$page+1;return $this->categoriesResult($pdo,$tenantId,$conversationId,$context);}
            if(in_array($normalized,['anterior','voltar'],true)&&$page>0){$context['category_page']=$page-1;return $this->categoriesResult($pdo,$tenantId,$conversationId,$context);}
            if(!ctype_digit($normalized))return $this->categoriesResult($pdo,$tenantId,$conversationId,$context,'Escolha uma categoria pelo número.');
            $rows=$this->categories($pdo,$tenantId,$page);
            $choice=(int)$normalized;
            if($choice<1||$choice>min(self::PAGE_SIZE,count($rows)))return $this->categoriesResult($pdo,$tenantId,$conversationId,$context,'Categoria inválida.');
            $category=$rows[$choice-1];
            $context['category_id']=(int)$category['id'];$context['category_name']=(string)$category['name'];$context['product_page']=0;
            return $this->productsResult($pdo,$tenantId,$conversationId,$context);
        }

        if($state==='SELECTING_PRODUCT'){
            if(in_array($normalized,['0','voltar'],true)){$context['category_page']=0;unset($context['category_id'],$context['category_name'],$context['product_page']);return $this->categoriesResult($pdo,$tenantId,$conversationId,$context);}
            $page=max(0,(int)($context['product_page']??0));
            if(in_array($normalized,['9','mais','próxima','proxima'],true)){$context['product_page']=$page+1;return $this->productsResult($pdo,$tenantId,$conversationId,$context);}
            if($normalized==='anterior'&&$page>0){$context['product_page']=$page-1;return $this->productsResult($pdo,$tenantId,$conversationId,$context);}
            if(!ctype_digit($normalized))return $this->productsResult($pdo,$tenantId,$conversationId,$context,'Escolha um produto pelo número.');
            $rows=$this->products($pdo,$tenantId,(int)($context['category_id']??0),$page);
            $choice=(int)$normalized;
            if($choice<1||$choice>min(self::PAGE_SIZE,count($rows)))return $this->productsResult($pdo,$tenantId,$conversationId,$context,'Produto inválido.');
            $product=$rows[$choice-1];
            $context['pending_item']=['product_id'=>(int)$product['id'],'product_name'=>(string)$product['name'],'option_ids'=>[],'group_index'=>0,'option_page'=>0];
            return $this->modifierOrQuantityResult($pdo,$tenantId,$conversationId,$context);
        }

        if(in_array($state,['SELECTING_VARIANT','SELECTING_ADDONS'],true)){
            return $this->handleModifierSelection($pdo,$tenantId,$conversationId,$context,$normalized);
        }

        if($state==='SELECTING_QUANTITY'){
            if(!ctype_digit($normalized)||(int)$normalized<1||(int)$normalized>99)return $this->stateResult($pdo,$tenantId,$conversationId,'SELECTING_QUANTITY',$context,"Informe a quantidade usando um número entre 1 e 99.\n\n0 - Voltar",'quantity_invalid');
            $context['pending_item']['quantity']=(int)$normalized;
            return $this->stateResult($pdo,$tenantId,$conversationId,'ENTERING_NOTE',$context,"Deseja adicionar alguma observação?\n\nExemplo: sem cebola\n\nDigite a observação ou envie:\n0 - Sem observação",'note_prompt');
        }

        if($state==='ENTERING_NOTE'){
            $note=$normalized==='0'?'':trim($text);
            if(mb_strlen($note)>300)return $this->stateResult($pdo,$tenantId,$conversationId,'ENTERING_NOTE',$context,'A observação pode ter no máximo 300 caracteres. Envie novamente ou digite 0 para continuar sem observação.','note_too_long');
            $context['pending_item']['notes']=$note;
            $draftId=$this->draftId($pdo,$tenantId,$conversation);
            if($draftId<1)$draftId=$this->ensureDraft($pdo,$tenantId,$conversationId,$conversation,$customerId);
            $this->addPendingItem($pdo,$tenantId,$draftId,$context['pending_item']??[]);
            $context=['draft_order_id'=>$draftId];
            return $this->cartResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId],$context,'Item adicionado ao carrinho. ✅');
        }

        if($state==='CART')return $this->handleCart($pdo,$tenantId,$conversationId,$conversation,$context,$normalized);

        if($state==='CHOOSING_FULFILLMENT'){
            return $this->stateResult($pdo,$tenantId,$conversationId,'CHOOSING_FULFILLMENT',$context,"Seu carrinho está pronto. ✅\n\nA escolha entre entrega e retirada será habilitada na próxima etapa do WhatsApp Commerce. Nenhum pedido operacional foi criado ainda.\n\nDigite *MEU PEDIDO* para revisar ou *MENU* para voltar ao início.",'cart_ready');
        }

        return ['handled'=>false];
    }

    /** @return array<string,mixed> */
    private function handleCart(PDO $pdo,int $tenantId,int $conversationId,array $conversation,array $context,string $normalized):array
    {
        $draftId=$this->draftId($pdo,$tenantId,$conversation);if($draftId<1)return $this->categoriesResult($pdo,$tenantId,$conversationId,['category_page'=>0],'Seu carrinho anterior não está mais disponível. Vamos começar um novo pedido.');
        $action=(string)($context['cart_action']??'');
        if($action==='qty_item'){
            if(!ctype_digit($normalized))return $this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'qty_item','Escolha o item pelo número.');
            $item=$this->cartItemByPosition($pdo,$tenantId,$draftId,(int)$normalized);if(!$item)return $this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'qty_item','Item inválido.');
            $context['cart_action']='qty_value';$context['selected_order_item_id']=(int)$item['id'];
            return $this->stateResult($pdo,$tenantId,$conversationId,'CART',$context,'Informe a nova quantidade de “'.(string)$item['name_snapshot'].'” (1 a 99):','cart_qty_value');
        }
        if($action==='qty_value'){
            if(!ctype_digit($normalized)||(int)$normalized<1||(int)$normalized>99)return $this->stateResult($pdo,$tenantId,$conversationId,'CART',$context,'Quantidade inválida. Informe um número entre 1 e 99.','cart_qty_invalid');
            $this->updateQuantity($pdo,$tenantId,$draftId,(int)($context['selected_order_item_id']??0),(int)$normalized);unset($context['cart_action'],$context['selected_order_item_id']);
            return $this->cartResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId],$context,'Quantidade atualizada. ✅');
        }
        if($action==='remove_item'){
            if(!ctype_digit($normalized))return $this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'remove_item','Escolha o item que deseja remover.');
            $item=$this->cartItemByPosition($pdo,$tenantId,$draftId,(int)$normalized);if(!$item)return $this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'remove_item','Item inválido.');
            $this->removeItem($pdo,$tenantId,$draftId,(int)$item['id']);unset($context['cart_action']);
            return $this->cartResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId],$context,'Item removido.');
        }
        return match($normalized){
            '1'=>$this->categoriesResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId,'category_page'=>0]),
            '2'=>$this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'qty_item','Qual item deseja alterar?'),
            '3'=>$this->cartItemChoiceResult($pdo,$tenantId,$conversationId,$draftId,$context,'remove_item','Qual item deseja remover?'),
            '4'=>$this->finalizeCartBoundary($pdo,$tenantId,$conversationId,$draftId,$context),
            '5'=>$this->cancelDraft($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId]),
            default=>$this->cartResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId],$context,'Escolha uma opção do carrinho.'),
        };
    }

    /** @return array<string,mixed> */
    private function handleModifierSelection(PDO $pdo,int $tenantId,int $conversationId,array $context,string $normalized):array
    {
        $pending=$context['pending_item']??[];$productId=(int)($pending['product_id']??0);if($productId<1)throw new RuntimeException('Produto da conversa não encontrado.');
        $groups=$this->configured->catalogModifiers($pdo,$tenantId,[$productId])[$productId]??[];$index=max(0,(int)($pending['group_index']??0));if(!isset($groups[$index]))return $this->quantityResult($pdo,$tenantId,$conversationId,$context);
        $group=$groups[$index];$options=array_values($group['options']??[]);$page=max(0,(int)($pending['option_page']??0));$pages=max(1,(int)ceil(count($options)/self::OPTION_PAGE_SIZE));if($page>=$pages)$page=$pages-1;$slice=array_slice($options,$page*self::OPTION_PAGE_SIZE,self::OPTION_PAGE_SIZE);
        $selected=array_values(array_unique(array_map('intval',$pending['option_ids']??[])));$selectedInGroup=[];foreach($options as$o)if(in_array((int)$o['id'],$selected,true))$selectedInGroup[]=(int)$o['id'];$min=max((int)$group['min_select'],(int)$group['required']?1:0);$max=max(1,(int)$group['max_select']);$single=$max===1;
        if(in_array($normalized,['mais','9'],true)&&$page+1<$pages){$context['pending_item']['option_page']=$page+1;return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index);}
        if(in_array($normalized,['anterior','voltar'],true)&&$page>0){$context['pending_item']['option_page']=$page-1;return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index);}
        if($normalized==='0'&&$min===0){$selected=array_values(array_diff($selected,$selectedInGroup));$context['pending_item']['option_ids']=$selected;return $this->advanceModifier($pdo,$tenantId,$conversationId,$context,$groups,$index);}
        if($normalized==='ok'&&!$single){$count=count($selectedInGroup);if($count<$min)return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index,'Selecione pelo menos '.$min.' opção(ões) antes de continuar.');return $this->advanceModifier($pdo,$tenantId,$conversationId,$context,$groups,$index);}
        if(!ctype_digit($normalized))return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index,'Escolha uma opção pelo número.');
        $choice=(int)$normalized;if($choice<1||$choice>count($slice))return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index,'Opção inválida.');$option=$slice[$choice-1];$optionId=(int)$option['id'];
        if($single){$selected=array_values(array_diff($selected,$selectedInGroup));$selected[]=$optionId;$context['pending_item']['option_ids']=array_values(array_unique($selected));return $this->advanceModifier($pdo,$tenantId,$conversationId,$context,$groups,$index);}
        if(in_array($optionId,$selectedInGroup,true)){$selected=array_values(array_diff($selected,[$optionId]));}else{if(count($selectedInGroup)>=$max)return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index,'Este grupo permite no máximo '.$max.' opção(ões).');$selected[]=$optionId;}
        $context['pending_item']['option_ids']=array_values(array_unique($selected));return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index);
    }

    /** @return array<string,mixed> */
    private function advanceModifier(PDO $pdo,int $tenantId,int $conversationId,array $context,array $groups,int $index):array
    {
        $context['pending_item']['group_index']=$index+1;$context['pending_item']['option_page']=0;
        if(isset($groups[$index+1]))return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,$index+1);
        return $this->quantityResult($pdo,$tenantId,$conversationId,$context);
    }

    /** @return array<string,mixed> */
    private function modifierOrQuantityResult(PDO $pdo,int $tenantId,int $conversationId,array $context):array
    {
        $productId=(int)($context['pending_item']['product_id']??0);$groups=$this->configured->catalogModifiers($pdo,$tenantId,[$productId])[$productId]??[];
        if(!$groups)return $this->quantityResult($pdo,$tenantId,$conversationId,$context);
        return $this->modifierPrompt($pdo,$tenantId,$conversationId,$context,$groups,0);
    }

    /** @return array<string,mixed> */
    private function modifierPrompt(PDO $pdo,int $tenantId,int $conversationId,array $context,array $groups,int $index,string $prefix=''):array
    {
        $group=$groups[$index];$options=array_values($group['options']??[]);$page=max(0,(int)($context['pending_item']['option_page']??0));$pages=max(1,(int)ceil(count($options)/self::OPTION_PAGE_SIZE));if($page>=$pages)$page=$pages-1;$context['pending_item']['option_page']=$page;$slice=array_slice($options,$page*self::OPTION_PAGE_SIZE,self::OPTION_PAGE_SIZE);$selected=array_values(array_map('intval',$context['pending_item']['option_ids']??[]));$min=max((int)$group['min_select'],(int)$group['required']?1:0);$max=max(1,(int)$group['max_select']);$single=$max===1;
        $lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='*'.(string)$group['name'].'*';$lines[]=$single?'Escolha uma opção:':'Escolha até '.$max.' opção(ões). Toque novamente no número para desmarcar e envie *OK* quando terminar.';$lines[]='';foreach($slice as$i=>$option){$mark=in_array((int)$option['id'],$selected,true)?' ✅':'';$delta=(int)$option['price_delta_cents'];$price=$delta===0?'':($delta>0?' + ':' - ').$this->money(abs($delta));$lines[]=($i+1).' - '.(string)$option['name'].$price.$mark;}if($pages>1&&$page+1<$pages)$lines[]='9 - Mais opções';if($page>0)$lines[]='Digite *ANTERIOR* para a página anterior.';if($min===0)$lines[]='0 - Sem adicionais deste grupo';
        $state=$single&&$min>=1?'SELECTING_VARIANT':'SELECTING_ADDONS';return $this->stateResult($pdo,$tenantId,$conversationId,$state,$context,trim(implode("\n",$lines)),'modifier_prompt');
    }

    /** @return array<string,mixed> */
    private function quantityResult(PDO $pdo,int $tenantId,int $conversationId,array $context):array
    {return $this->stateResult($pdo,$tenantId,$conversationId,'SELECTING_QUANTITY',$context,"Quantas unidades deseja?\n\nDigite um número de 1 a 99.",'quantity_prompt');}

    /** @return array<string,mixed> */
    private function categoriesResult(PDO $pdo,int $tenantId,int $conversationId,array $context,string $prefix=''):array
    {
        $page=max(0,(int)($context['category_page']??0));$rows=$this->categories($pdo,$tenantId,$page);if(!$rows)throw new RuntimeException('Nenhuma categoria com produtos está disponível agora.');$shown=array_slice($rows,0,self::PAGE_SIZE);$lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='🍽️ *Escolha uma categoria:*';$lines[]='';foreach($shown as$i=>$row)$lines[]=($i+1).' - '.(string)$row['name'];if(count($rows)>self::PAGE_SIZE)$lines[]='9 - Mais categorias';if($page>0)$lines[]='Digite *ANTERIOR* para voltar uma página.';$lines[]='0 - Voltar ao início';$context['category_page']=$page;return $this->stateResult($pdo,$tenantId,$conversationId,'SELECTING_CATEGORY',$context,trim(implode("\n",$lines)),'categories');
    }

    /** @return array<string,mixed> */
    private function productsResult(PDO $pdo,int $tenantId,int $conversationId,array $context,string $prefix=''):array
    {
        $categoryId=(int)($context['category_id']??0);$page=max(0,(int)($context['product_page']??0));$rows=$this->products($pdo,$tenantId,$categoryId,$page);if(!$rows)return $this->categoriesResult($pdo,$tenantId,$conversationId,$context,'Esta categoria não possui produtos disponíveis agora.');$shown=array_slice($rows,0,self::PAGE_SIZE);$lines=[];if($prefix!=='')$lines[]='⚠️ '.$prefix;$lines[]='';$lines[]='*'.(string)($context['category_name']??'Produtos').'*';$lines[]='';foreach($shown as$i=>$row)$lines[]=($i+1).' - '.(string)$row['name'].' — '.$this->money((int)$row['price_cents']);if(count($rows)>self::PAGE_SIZE)$lines[]='9 - Mais produtos';if($page>0)$lines[]='Digite *ANTERIOR* para voltar uma página.';$lines[]='0 - Voltar às categorias';$context['product_page']=$page;return $this->stateResult($pdo,$tenantId,$conversationId,'SELECTING_PRODUCT',$context,trim(implode("\n",$lines)),'products');
    }

    /** @return array<string,mixed> */
    private function cartResult(PDO $pdo,int $tenantId,int $conversationId,array $conversation,array $context,string $prefix=''):array
    {
        $draftId=$this->draftId($pdo,$tenantId,$conversation);if($draftId<1)return $this->categoriesResult($pdo,$tenantId,$conversationId,['category_page'=>0],'Você ainda não possui itens no carrinho.');$errors=$this->refreshDraft($pdo,$tenantId,$draftId);$items=$this->cartItems($pdo,$tenantId,$draftId);if(!$items)return $this->categoriesResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId,'category_page'=>0],'Seu carrinho está vazio. Adicione o primeiro item.');
        $q=$pdo->prepare('SELECT subtotal_cents,delivery_fee_cents,total_cents FROM orders WHERE id=? AND tenant_id=? AND status=\'draft\'');$q->execute([$draftId,$tenantId]);$order=$q->fetch(PDO::FETCH_ASSOC)?:[];$lines=[];if($prefix!=='')$lines[]=$prefix;$lines[]='';$lines[]='🛒 *SEU PEDIDO*';$lines[]='';foreach($items as$i=>$item){$lines[]=($i+1).'. '.$this->qty((float)$item['quantity']).'x '.(string)$item['name_snapshot'].' — '.$this->money((int)$item['total_cents']);foreach($item['modifiers'] as$m)$lines[]='   + '.(string)$m['option_name_snapshot'];if(trim((string)($item['notes']??''))!=='')$lines[]='   - '.trim((string)$item['notes']);if(isset($errors[(int)$item['id']]))$lines[]='   ⚠️ '.$errors[(int)$item['id']];}$lines[]='';$lines[]='Subtotal: '.$this->money((int)($order['subtotal_cents']??0));$lines[]='Total atual: '.$this->money((int)($order['total_cents']??0));$lines[]='';$lines[]='1 - Adicionar mais itens';$lines[]='2 - Alterar quantidade';$lines[]='3 - Remover item';$lines[]='4 - Finalizar carrinho';$lines[]='5 - Cancelar pedido';$context=['draft_order_id'=>$draftId];return $this->stateResult($pdo,$tenantId,$conversationId,'CART',$context,trim(implode("\n",$lines)),'cart');
    }

    /** @return array<string,mixed> */
    private function cartItemChoiceResult(PDO $pdo,int $tenantId,int $conversationId,int $draftId,array $context,string $action,string $title):array
    {
        $items=$this->cartItems($pdo,$tenantId,$draftId);if(!$items)return $this->categoriesResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId,'category_page'=>0],'Seu carrinho está vazio.');$context=['draft_order_id'=>$draftId,'cart_action'=>$action];$lines=[$title,''];foreach($items as$i=>$item)$lines[]=($i+1).' - '.$this->qty((float)$item['quantity']).'x '.(string)$item['name_snapshot'];return $this->stateResult($pdo,$tenantId,$conversationId,'CART',$context,implode("\n",$lines),'cart_item_choice');
    }

    /** @return array<string,mixed> */
    private function finalizeCartBoundary(PDO $pdo,int $tenantId,int $conversationId,int $draftId,array $context):array
    {
        $errors=$this->refreshDraft($pdo,$tenantId,$draftId);if($errors)return $this->cartResult($pdo,$tenantId,$conversationId,['draft_order_id'=>$draftId],$context,'⚠️ Há item indisponível ou configuração inválida. Remova e adicione novamente antes de finalizar.');$q=$pdo->prepare('SELECT COUNT(*) FROM order_items WHERE order_id=?');$q->execute([$draftId]);if((int)$q->fetchColumn()<1)throw new RuntimeException('Carrinho vazio.');
        return $this->stateResult($pdo,$tenantId,$conversationId,'CHOOSING_FULFILLMENT',['draft_order_id'=>$draftId],"Carrinho finalizado. ✅\n\nPedido #{$draftId} continua como *rascunho* e ainda não foi enviado para produção.\n\nNa próxima etapa você escolherá *Entrega* ou *Retirada*.",'cart_finalized');
    }

    /** @return array<string,mixed> */
    private function cancelDraft(PDO $pdo,int $tenantId,int $conversationId,array $conversation):array
    {
        $draftId=$this->draftId($pdo,$tenantId,$conversation);if($draftId>0)$pdo->prepare('UPDATE orders SET status=\'cancelled\',updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status=\'draft\'')->execute([$draftId,$tenantId]);$pdo->prepare('UPDATE whatsapp_conversations SET draft_order_id=NULL,state=\'WELCOME\',context_json=NULL,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$conversationId,$tenantId]);return ['handled'=>true,'state'=>'WELCOME','context'=>[],'draft_order_id'=>null,'reply'=>"Pedido em montagem cancelado.\n\n1 - Fazer um pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'kind'=>'cart_cancelled'];
    }

    private function ensureDraft(PDO $pdo,int $tenantId,int $conversationId,array $conversation,?int $customerId):int
    {
        $draftId=$this->draftId($pdo,$tenantId,$conversation);if($draftId>0){if($customerId)$pdo->prepare('UPDATE orders SET customer_id=COALESCE(customer_id,?) WHERE id=? AND tenant_id=? AND status=\'draft\'')->execute([$customerId,$draftId,$tenantId]);return $draftId;}
        $unit=$pdo->prepare('SELECT id FROM operating_units WHERE tenant_id=? AND active=1 ORDER BY id LIMIT 1');$unit->execute([$tenantId]);$unitId=(int)($unit->fetchColumn()?:0);if($unitId<1)throw new RuntimeException('A empresa não possui unidade operacional ativa para iniciar pedidos.');$token=bin2hex(random_bytes(20));$s=$pdo->prepare('INSERT INTO orders (public_token,tenant_id,unit_id,customer_id,channel,order_source,status,payment_status,subtotal_cents,discount_cents,delivery_fee_cents,total_cents) VALUES (?,?,?,?,\'pending\',\'WHATSAPP\',\'draft\',\'unpaid\',0,0,0,0)');$s->execute([$token,$tenantId,$unitId,$customerId]);$draftId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE whatsapp_conversations SET customer_id=COALESCE(customer_id,?),draft_order_id=?,state=\'SELECTING_CATEGORY\',last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$customerId,$draftId,$conversationId,$tenantId]);return $draftId;
    }

    private function identifyCustomer(PDO $pdo,int $tenantId,int $conversationId,string $phone):?int
    {
        $customer=$this->identity->findByPhone($pdo,$tenantId,$phone);$customerId=$customer?(int)$customer['id']:null;if($customerId)$pdo->prepare('UPDATE whatsapp_conversations SET customer_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$customerId,$conversationId,$tenantId]);return $customerId;
    }

    private function addPendingItem(PDO $pdo,int $tenantId,int $draftId,array $pending):void
    {
        $this->assertDraft($pdo,$tenantId,$draftId);$resolved=$this->configured->resolveLine($pdo,$tenantId,['product_id'=>(int)($pending['product_id']??0),'qty'=>(int)($pending['quantity']??1),'option_ids'=>$pending['option_ids']??[],'notes'=>(string)($pending['notes']??'')],true);$s=$pdo->prepare('INSERT INTO order_items (order_id,product_id,name_snapshot,unit_price_cents,quantity,total_cents,notes) VALUES (?,?,?,?,?,?,?)');$s->execute([$draftId,$resolved['product_id'],$resolved['name'],$resolved['unit_price_cents'],$resolved['quantity'],$resolved['total_cents'],$resolved['notes']?:null]);$this->configured->persistModifiers($pdo,$tenantId,$draftId,(int)$pdo->lastInsertId(),$resolved);$this->recalculate($pdo,$tenantId,$draftId);
    }

    private function updateQuantity(PDO $pdo,int $tenantId,int $draftId,int $itemId,int $qty):void
    {
        $this->assertDraft($pdo,$tenantId,$draftId);$q=$pdo->prepare('SELECT * FROM order_items WHERE id=? AND order_id=? LIMIT 1');$q->execute([$itemId,$draftId]);$item=$q->fetch(PDO::FETCH_ASSOC);if(!$item)throw new RuntimeException('Item não encontrado no carrinho.');$mods=$pdo->prepare('SELECT modifier_option_id FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=? AND modifier_option_id IS NOT NULL');$mods->execute([$tenantId,$draftId,$itemId]);$optionIds=array_map('intval',$mods->fetchAll(PDO::FETCH_COLUMN)?:[]);$resolved=$this->configured->resolveLine($pdo,$tenantId,['product_id'=>(int)$item['product_id'],'qty'=>$qty,'option_ids'=>$optionIds,'notes'=>(string)($item['notes']??'')],true);$pdo->prepare('UPDATE order_items SET name_snapshot=?,unit_price_cents=?,quantity=?,total_cents=? WHERE id=? AND order_id=?')->execute([$resolved['name'],$resolved['unit_price_cents'],$resolved['quantity'],$resolved['total_cents'],$itemId,$draftId]);$pdo->prepare('DELETE FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=?')->execute([$tenantId,$draftId,$itemId]);$this->configured->persistModifiers($pdo,$tenantId,$draftId,$itemId,$resolved);$this->recalculate($pdo,$tenantId,$draftId);
    }

    private function removeItem(PDO $pdo,int $tenantId,int $draftId,int $itemId):void
    { $this->assertDraft($pdo,$tenantId,$draftId);$pdo->prepare('DELETE FROM order_items WHERE id=? AND order_id=?')->execute([$itemId,$draftId]);$this->recalculate($pdo,$tenantId,$draftId); }

    /** @return array<int,string> */
    private function refreshDraft(PDO $pdo,int $tenantId,int $draftId):array
    {
        $this->assertDraft($pdo,$tenantId,$draftId);$q=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$q->execute([$draftId]);$errors=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as$item){$mods=$pdo->prepare('SELECT modifier_option_id FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=? AND modifier_option_id IS NOT NULL');$mods->execute([$tenantId,$draftId,(int)$item['id']]);$optionIds=array_map('intval',$mods->fetchAll(PDO::FETCH_COLUMN)?:[]);try{$resolved=$this->configured->resolveLine($pdo,$tenantId,['product_id'=>(int)$item['product_id'],'qty'=>(float)$item['quantity'],'option_ids'=>$optionIds,'notes'=>(string)($item['notes']??'')],true);$pdo->prepare('UPDATE order_items SET name_snapshot=?,unit_price_cents=?,total_cents=? WHERE id=? AND order_id=?')->execute([$resolved['name'],$resolved['unit_price_cents'],$resolved['total_cents'],(int)$item['id'],$draftId]);$pdo->prepare('DELETE FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id=?')->execute([$tenantId,$draftId,(int)$item['id']]);$this->configured->persistModifiers($pdo,$tenantId,$draftId,(int)$item['id'],$resolved);}catch(RuntimeException $e){$errors[(int)$item['id']]=$e->getMessage();$pdo->prepare('UPDATE order_items SET total_cents=0 WHERE id=? AND order_id=?')->execute([(int)$item['id'],$draftId]);}}
        $this->recalculate($pdo,$tenantId,$draftId);return $errors;
    }

    private function recalculate(PDO $pdo,int $tenantId,int $draftId):void
    {
        $q=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0) FROM order_items WHERE order_id=?');$q->execute([$draftId]);$subtotal=max(0,(int)$q->fetchColumn());$pdo->prepare('UPDATE orders SET subtotal_cents=?,discount_cents=0,delivery_fee_cents=0,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status=\'draft\'')->execute([$subtotal,$subtotal,$draftId,$tenantId]);
    }

    private function assertDraft(PDO $pdo,int $tenantId,int $draftId):void
    { $q=$pdo->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND status=\'draft\' AND order_source=\'WHATSAPP\' LIMIT 1');$q->execute([$draftId,$tenantId]);if(!$q->fetchColumn())throw new RuntimeException('Carrinho do WhatsApp não está mais disponível.'); }

    private function draftId(PDO $pdo,int $tenantId,array $conversation):int
    {
        $id=(int)($conversation['draft_order_id']??0);if($id<1&&isset($conversation['id'])){$q=$pdo->prepare('SELECT draft_order_id FROM whatsapp_conversations WHERE id=? AND tenant_id=?');$q->execute([(int)$conversation['id'],$tenantId]);$id=(int)($q->fetchColumn()?:0);}if($id<1)return 0;$q=$pdo->prepare('SELECT id FROM orders WHERE id=? AND tenant_id=? AND status=\'draft\' AND order_source=\'WHATSAPP\' LIMIT 1');$q->execute([$id,$tenantId]);return $q->fetchColumn()?(int)$id:0;
    }

    /** @return array<int,array<string,mixed>> */
    private function categories(PDO $pdo,int $tenantId,int $page):array
    { $offset=max(0,$page)*self::PAGE_SIZE;$sql='SELECT c.id,c.name FROM categories c WHERE c.tenant_id=? AND c.active=1 AND EXISTS (SELECT 1 FROM products p WHERE p.tenant_id=c.tenant_id AND p.category_id=c.id AND p.active=1) ORDER BY c.sort_order,c.id LIMIT '.(self::PAGE_SIZE+1).' OFFSET '.$offset;$q=$pdo->prepare($sql);$q->execute([$tenantId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    /** @return array<int,array<string,mixed>> */
    private function products(PDO $pdo,int $tenantId,int $categoryId,int $page):array
    { $offset=max(0,$page)*self::PAGE_SIZE;$sql='SELECT id,name,price_cents FROM products WHERE tenant_id=? AND category_id=? AND active=1 ORDER BY name,id LIMIT '.(self::PAGE_SIZE+1).' OFFSET '.$offset;$q=$pdo->prepare($sql);$q->execute([$tenantId,$categoryId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    /** @return array<int,array<string,mixed>> */
    private function cartItems(PDO $pdo,int $tenantId,int $draftId):array
    { $q=$pdo->prepare('SELECT * FROM order_items WHERE order_id=? ORDER BY id');$q->execute([$draftId]);$items=$q->fetchAll(PDO::FETCH_ASSOC)?:[];if(!$items)return[];$ids=array_map(fn($i)=>(int)$i['id'],$items);$marks=implode(',',array_fill(0,count($ids),'?'));$m=$pdo->prepare('SELECT order_item_id,option_name_snapshot FROM order_item_modifiers WHERE tenant_id=? AND order_id=? AND order_item_id IN ('.$marks.') ORDER BY id');$m->execute(array_merge([$tenantId,$draftId],$ids));$by=[];foreach($m->fetchAll(PDO::FETCH_ASSOC) as$row)$by[(int)$row['order_item_id']][]=$row;foreach($items as&$item)$item['modifiers']=$by[(int)$item['id']]??[];unset($item);return$items; }

    private function cartItemByPosition(PDO $pdo,int $tenantId,int $draftId,int $position):?array
    { $items=$this->cartItems($pdo,$tenantId,$draftId);return $position>=1&&isset($items[$position-1])?$items[$position-1]:null; }

    /** @return array<string,mixed> */
    private function stateResult(PDO $pdo,int $tenantId,int $conversationId,string $state,array $context,string $reply,string $kind):array
    { $json=$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;$pdo->prepare('UPDATE whatsapp_conversations SET state=?,context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$state,$json,$conversationId,$tenantId]);return ['handled'=>true,'state'=>$state,'context'=>$context,'reply'=>$reply,'kind'=>$kind,'draft_order_id'=>$context['draft_order_id']??null]; }

    private function context(mixed $raw):array
    { if(is_array($raw))return$raw;if(!is_string($raw)||trim($raw)==='')return[];$decoded=json_decode($raw,true);return is_array($decoded)?$decoded:[]; }
    private function normalize(string $value):string
    { $value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:"); }
    private function money(int $cents):string{return 'R$ '.number_format($cents/100,2,',','.');}
    private function qty(float $qty):string{return abs($qty-round($qty))<0.0001?(string)(int)round($qty):rtrim(rtrim(number_format($qty,3,',','.'),'0'),',');}
}
