<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\OnlineOrderingService;
use EventMenu\Services\OrderSchedulingService;
use EventMenu\Services\PublicOrderService;

$pdo=Database::connection();
$slug=trim((string)($_GET['empresa']??''));
$s=$pdo->prepare('SELECT * FROM tenants WHERE slug=? AND status="active" LIMIT 1');
$s->execute([$slug]);
$tenant=$s->fetch();
if(!$tenant){http_response_code(404);exit('Empresa não encontrada.');}
$tenantId=(int)$tenant['id'];
$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];
$pickupEnabled=$settings['pickup_enabled']??true;
$schedulingEnabled=!empty($settings['scheduling_enabled']);
$ordering=new OnlineOrderingService();
$deliveryStatus=$ordering->statusAt($pdo,$tenantId,'delivery');
$pickupStatus=$pickupEnabled?$ordering->statusAt($pdo,$tenantId,'pickup'):['open'=>false,'reason'=>'Retirada indisponível.'];
$scheduler=new OrderSchedulingService();
$deliverySlots=$schedulingEnabled?$scheduler->availableSlots($pdo,$tenantId,'delivery',50):[];
$pickupSlots=$schedulingEnabled&&$pickupEnabled?$scheduler->availableSlots($pdo,$tenantId,'pickup',50):[];

$cartKey='menu:'.$tenantId;
if(!isset($_SESSION['public_carts'][$cartKey]))$_SESSION['public_carts'][$cartKey]=[];
$cart=&$_SESSION['public_carts'][$cartKey];
$error=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Security::validateCsrf($_POST['_csrf']??null))$error='Sessão expirada. Atualize a página.';
    else{
        $action=(string)($_POST['action']??'');
        try{
            if($action==='add'){
                $id=(int)($_POST['product_id']??0);$p=$pdo->prepare('SELECT id FROM products WHERE id=? AND tenant_id=? AND active=1');$p->execute([$id,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Produto indisponível.');$cart[$id]=min(50,(int)($cart[$id]??0)+1);header('Location: /menu.php?empresa='.urlencode($slug).'#carrinho');exit;
            }
            if($action==='qty'){
                $id=(int)($_POST['product_id']??0);$qty=max(0,min(50,(int)($_POST['qty']??0)));if($qty===0)unset($cart[$id]);else$cart[$id]=$qty;header('Location: /menu.php?empresa='.urlencode($slug).'#carrinho');exit;
            }
            if($action==='clear'){$cart=[];header('Location: /menu.php?empresa='.urlencode($slug));exit;}
            if($action==='checkout'){
                $payload=[];foreach($cart as$id=>$qty)$payload[]=['product_id'=>(int)$id,'qty'=>(int)$qty];
                $buyer=['name'=>(string)($_POST['name']??''),'phone'=>(string)($_POST['phone']??''),'email'=>(string)($_POST['email']??''),'postal_code'=>(string)($_POST['postal_code']??''),'neighborhood'=>(string)($_POST['neighborhood']??''),'city'=>(string)($_POST['city']??'')];
                $mode=(string)($_POST['fulfillment']??'delivery');
                $scheduled=trim((string)($_POST['scheduled_for']??''));
                $service=new PublicOrderService();
                if($mode==='pickup')$result=$service->createPickup($tenantId,$payload,$buyer,(string)($_POST['coupon']??''),$scheduled!==''?$scheduled:null);
                elseif($mode==='delivery')$result=$service->createDelivery($tenantId,$payload,$buyer,(string)($_POST['address']??''),(string)($_POST['coupon']??''),$scheduled!==''?$scheduled:null);
                else throw new RuntimeException('Forma de recebimento inválida.');
                $cart=[];header('Location: /pedido.php?t='.urlencode($result['public_token']),true,303);exit;
            }
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}

$c=$pdo->prepare('SELECT * FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=$c->fetchAll();
$p=$pdo->prepare('SELECT * FROM products WHERE tenant_id=? AND active=1 ORDER BY category_id,name');$p->execute([$tenantId]);$products=$p->fetchAll();
$byId=[];foreach($products as$prod)$byId[(int)$prod['id']]=$prod;$cartTotal=0;foreach($cart as$id=>$qty)if(isset($byId[$id]))$cartTotal+=(int)$byId[$id]['price_cents']*(int)$qty;
$zoneCount=$pdo->prepare('SELECT COUNT(*) FROM delivery_zones WHERE tenant_id=? AND active=1');try{$zoneCount->execute([$tenantId]);$hasZones=(int)$zoneCount->fetchColumn()>0;}catch(Throwable){$hasZones=false;}
function menu_money(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
function menu_slots(array $slots):string{$html='<option value="">O mais rápido possível</option>';foreach($slots as$slot){$label=Security::e((string)$slot['label']);if($slot['remaining']!==null)$label.=' · '.(int)$slot['remaining'].' vagas';$html.='<option value="'.Security::e((string)$slot['value']).'">'.$label.'</option>';}return $html;}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0b0f14"><title><?= Security::e($tenant['name']) ?> — Cardápio</title><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="/assets/app.css"><style>.menu-shell{max-width:1180px;margin:auto;padding:22px 16px 90px}.hero{padding:34px;border:1px solid var(--line);border-radius:28px;background:linear-gradient(135deg,#181e2a,#11151d);margin-bottom:22px}.hero h1{font-size:clamp(34px,7vw,68px);line-height:.95;margin:18px 0}.menu-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(320px,1fr);gap:18px}.product{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:center}.product+.product{border-top:1px solid var(--line);margin-top:16px;padding-top:16px}.product-image{width:86px;height:86px;border-radius:16px;object-fit:cover;background:#171b22}.price{font-size:1.12rem;font-weight:850;color:var(--accent)}.cart{position:sticky;top:18px}.cart-line{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line)}.qty{display:flex;gap:7px;align-items:center}.qty input{width:62px}.choice{display:grid;grid-template-columns:1fr 1fr;gap:8px}.choice label{border:1px solid var(--line);padding:12px;border-radius:14px}.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:currentColor;margin-right:5px}.floating-cart{display:none}@media(max-width:820px){.menu-grid{grid-template-columns:1fr}.cart{position:static}.floating-cart{display:block;position:fixed;z-index:10;bottom:14px;left:14px;right:14px}.hero{padding:26px}}</style></head><body><main class="menu-shell"><section class="hero"><div class="brand">EventMenu <span>Premium</span></div><h1><?= Security::e($tenant['name']) ?></h1><p class="muted">Cardápio digital · delivery · retirada · pagamento online protegido</p><?php if(!empty($settings['menu_message'])):?><p><?= Security::e($settings['menu_message']) ?></p><?php endif;?></section><?php if($error):?><div class="alert error"><?= Security::e($error) ?></div><?php endif;?><div class="menu-grid"><section><?php foreach($categories as$cat):?><article class="card" style="margin-bottom:16px"><div class="section-head"><h2><?= Security::e($cat['name']) ?></h2></div><?php $found=false;foreach($products as$prod):if((int)$prod['category_id']!==(int)$cat['id'])continue;$found=true;?><div class="product"><div style="display:flex;gap:14px;align-items:center"><?php if(!empty($prod['image_url'])):?><img class="product-image" src="<?= Security::e($prod['image_url']) ?>" alt=""><?php endif;?><div><strong><?= Security::e($prod['name']) ?></strong><div class="muted"><?= Security::e($prod['description']) ?></div><div class="price"><?= menu_money((int)$prod['price_cents']) ?></div><?php if((int)$prod['track_stock']):?><small class="muted">Estoque: <?= Security::e((string)$prod['stock_qty']) ?></small><?php endif;?></div></div><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= (int)$prod['id'] ?>"><button class="primary">Adicionar</button></form></div><?php endforeach;if(!$found):?><p class="muted">Nenhum produto nesta categoria.</p><?php endif;?></article><?php endforeach;?></section><aside id="carrinho" class="card cart"><div class="section-head"><h2>Seu pedido</h2><?php if($cart):?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="clear"><button class="secondary">Limpar</button></form><?php endif;?></div><?php if(!$cart):?><p class="muted">Adicione itens para começar.</p><?php else:?><?php foreach($cart as$id=>$qty):if(!isset($byId[$id]))continue;$i=$byId[$id];?><div class="cart-line"><div><strong><?= Security::e($i['name']) ?></strong><br><span class="muted"><?= menu_money((int)$i['price_cents']*(int)$qty) ?></span></div><form method="post" class="qty"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="qty"><input type="hidden" name="product_id" value="<?= (int)$id ?>"><input type="number" name="qty" min="0" max="50" value="<?= (int)$qty ?>"><button class="secondary">OK</button></form></div><?php endforeach;?><p style="font-size:1.2rem"><strong>Produtos <span style="float:right"><?= menu_money($cartTotal) ?></span></strong></p><p class="muted">Preço, estoque, cupom, frete e horário são validados novamente no servidor.</p><form method="post" class="form-grid" id="checkout-form"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="checkout"><div class="span-2"><strong>Como deseja receber?</strong><div class="choice"><label><input type="radio" name="fulfillment" value="delivery" checked> Delivery<br><small class="muted"><span class="status-dot"></span><?= Security::e($deliveryStatus['open']?'Aberto agora':(string)$deliveryStatus['reason']) ?></small></label><?php if($pickupEnabled):?><label><input type="radio" name="fulfillment" value="pickup"> Retirar no local<br><small class="muted"><span class="status-dot"></span><?= Security::e($pickupStatus['open']?'Aberto agora':(string)$pickupStatus['reason']) ?></small></label><?php endif;?></div></div><label class="span-2 schedule-field">Quando?<select name="scheduled_for" id="schedule-select" data-delivery='<?= Security::e(json_encode($deliverySlots,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>' data-pickup='<?= Security::e(json_encode($pickupSlots,JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><option value="">O mais rápido possível</option></select><small class="muted"><?= $schedulingEnabled?'Você pode escolher um horário disponível.':'Agendamento não habilitado.' ?></small></label><label class="span-2">Nome<input name="name" required autocomplete="name"></label><label>Telefone<input name="phone" required autocomplete="tel"></label><label>E-mail<input type="email" name="email" autocomplete="email"></label><div class="span-2" id="delivery-fields"><div class="form-grid"><label>CEP<input name="postal_code" inputmode="numeric" autocomplete="postal-code" placeholder="00000-000"></label><label>Bairro<input name="neighborhood" autocomplete="address-level3"></label><label class="span-2">Cidade<input name="city" autocomplete="address-level2"></label><label class="span-2">Endereço completo<textarea name="address" autocomplete="street-address" placeholder="Rua, número e complemento"></textarea></label></div></div><label class="span-2">Cupom<input name="coupon" placeholder="Opcional"></label><?php if($hasZones):?><p class="muted span-2" id="zone-help">A taxa, o pedido mínimo e o prazo são calculados pela área de entrega. Endereços fora das zonas configuradas não são aceitos.</p><?php endif;?><button class="primary span-2">Continuar para pagamento</button></form><?php endif;?></aside></div><?php if($cart):?><a class="button primary floating-cart" href="#carrinho">Ver carrinho · <?= menu_money($cartTotal) ?></a><?php endif;?></main><script>
(function(){const form=document.getElementById('checkout-form');if(!form)return;const delivery=document.getElementById('delivery-fields'),select=document.getElementById('schedule-select'),zone=document.getElementById('zone-help');const parse=(v)=>{try{return JSON.parse(v||'[]')}catch(e){return[]}};const render=()=>{const mode=form.querySelector('input[name="fulfillment"]:checked').value;delivery.style.display=mode==='delivery'?'block':'none';if(zone)zone.style.display=mode==='delivery'?'block':'none';const slots=parse(select.dataset[mode]);select.innerHTML='<option value="">O mais rápido possível</option>'+slots.map(s=>'<option value="'+s.value+'">'+s.label+(s.remaining===null?'':' · '+s.remaining+' vagas')+'</option>').join('');};form.querySelectorAll('input[name="fulfillment"]').forEach(el=>el.addEventListener('change',render));render();})();
if('serviceWorker'in navigator){navigator.serviceWorker.register('/sw.js').catch(()=>{});}
</script></body></html>