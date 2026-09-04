<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Services\CounterOrderService;
use EventMenu\Services\FulfillmentService;
use EventMenu\Services\PaymentService;

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['pos_v2'])){
    Auth::requirePermission('orders.create');$tenantId=em_require_tenant();em_post_csrf();$result=null;
    try{
        $cart=[];$deliverNow=[];
        foreach((array)($_POST['qty']??[])as$id=>$qty){
            $id=(int)$id;$q=max(0,min(50,(int)$qty));if($q<1)continue;
            $now=max(0,min($q,(int)(($_POST['deliver_now'][$id]??0))));
            $cart[]=['product_id'=>$id,'qty'=>$q,'option_ids'=>(array)($_POST['option_ids'][$id]??[])];
            if($now>0)$deliverNow[$id]=$now;
        }
        $manualMethod=(string)($_POST['manual_method']??'');
        if($deliverNow&&$manualMethod==='')throw new RuntimeException('Para entregar itens na hora, selecione uma forma de pagamento e confirme o recebimento.');
        if($deliverNow&&(!Auth::can('payments.manage')||!Auth::can('fulfillment.manage')))throw new RuntimeException('Seu perfil não pode receber e liberar retirada na mesma operação.');

        $result=(new CounterOrderService())->create($cart,['name'=>(string)($_POST['customer_name']??''),'phone'=>(string)($_POST['customer_phone']??'')],(string)($_POST['notes']??''));
        if($manualMethod!==''&&Auth::can('payments.manage')){
            if(!in_array($manualMethod,['cash','card','pix','other'],true))throw new RuntimeException('Forma de recebimento inválida.');
            $payment=new PaymentService();$payment->create((int)$result['order_id'],'manual','pos-manual:'.$tenantId.':'.$result['order_id'].':'.bin2hex(random_bytes(8)));
            $payment->confirmVerified(['tenant_id'=>$tenantId,'order_id'=>(int)$result['order_id'],'provider'=>'manual','provider_payment_id'=>'POS-'.strtoupper(bin2hex(random_bytes(10))),'amount_cents'=>(int)$result['total_cents'],'currency'=>'BRL','account_reference'=>'manual','manual_method'=>$manualMethod]);
            Auth::audit('pos.sale_paid','order',(string)$result['order_id'],['method'=>$manualMethod]);

            if($deliverNow){
                try{
                    $pdo=EventMenu\Core\Database::connection();$q=$pdo->prepare('SELECT id,product_id,quantity FROM order_items WHERE order_id=? ORDER BY id');$q->execute([(int)$result['order_id']]);$byProduct=[];foreach($q->fetchAll()as$row)$byProduct[(int)$row['product_id']]=$row;
                    $fulfillment=new FulfillmentService();
                    foreach($deliverNow as$productId=>$qtyNow){$row=$byProduct[$productId]??null;if(!$row)throw new RuntimeException('Item da venda não encontrado para retirada imediata.');$fulfillment->fulfill((int)$result['order_id'],(int)$row['id'],$qtyNow,'counter','Entregue no ato da venda','pos-now:'.$tenantId.':'.$result['order_id'].':'.$row['id'].':'.bin2hex(random_bytes(8)));}
                }catch(Throwable$e){em_flash('error','Pagamento confirmado, mas a retirada imediata precisa ser conferida: '.$e->getMessage());em_go('fulfillment',['order'=>(int)$result['order_id']]);}
            }
            header('Location: '.em_url('/retirada.php?t='.rawurlencode((string)$result['fulfillment_token']).'&auto=1'),true,303);exit;
        }
        em_flash('ok','Pedido de balcão #'.$result['order_id'].' criado. Estoque comprometido. Confirme o pagamento para liberar a retirada.');
        em_go(Auth::role()==='counter'?'counter-orders':'orders',Auth::role()==='counter'?[]:['view'=>(int)$result['order_id']]);
    }catch(Throwable$e){
        if(isset($result['order_id'])){em_flash('error','Venda #'.$result['order_id'].' foi criada, mas o pagamento não foi confirmado: '.$e->getMessage());em_go(Auth::role()==='counter'?'counter-orders':'orders',Auth::role()==='counter'?[]:['view'=>(int)$result['order_id']]);}
        em_flash('error',$e->getMessage());em_go('pos');
    }
}

ob_start();require __DIR__.'/pos_legacy.php';$html=(string)ob_get_clean();
if(Auth::can('payments.manage')&&Auth::can('fulfillment.manage')){
    $enhancement=<<<'HTML'
<style>.pos-deliver-now{display:grid;grid-template-columns:1fr 70px;gap:7px;align-items:center;margin-top:6px;padding:7px 8px;border-radius:9px;background:#f8f6ff;border:1px solid #e9e3ff}.pos-deliver-now span{font-size:10px;font-weight:800;color:#5c4aa3}.pos-deliver-now input{min-height:32px;padding:5px;text-align:center}</style>
<script>(function(){const form=document.getElementById('legacyPosForm');if(!form)return;const marker=document.createElement('input');marker.type='hidden';marker.name='pos_v2';marker.value='1';form.appendChild(marker);document.querySelectorAll('.pos-product').forEach(card=>{const qty=card.querySelector('input[name^="qty["]');if(!qty)return;const m=qty.name.match(/qty\[(\d+)\]/);if(!m)return;const id=m[1],box=document.createElement('label');box.className='pos-deliver-now';box.innerHTML='<span>Entregar agora</span><input type="number" name="deliver_now['+id+']" value="0" min="0" step="1" inputmode="numeric">';const now=box.querySelector('input');qty.closest('.pos-controls')?.insertAdjacentElement('afterend',box);const sync=()=>{const max=Math.max(0,Number(qty.value||0));now.max=String(max);if(Number(now.value||0)>max)now.value=String(max)};qty.addEventListener('input',sync);card.querySelector('[data-plus]')?.addEventListener('click',sync);card.querySelector('[data-minus]')?.addEventListener('click',sync);now.addEventListener('input',sync);sync()});form.addEventListener('submit',e=>{let bad=false;form.querySelectorAll('[name^="deliver_now["]').forEach(now=>{const m=now.name.match(/deliver_now\[(\d+)\]/),qty=m?form.querySelector('[name="qty['+m[1]+']"]'):null;if(Number(now.value||0)>Number(qty?.value||0))bad=true});if(bad){e.preventDefault();alert('A quantidade para entregar agora não pode ser maior que a quantidade vendida.')}})})();</script>
HTML;
    if(str_contains($html,'</body>'))$html=str_replace('</body>',$enhancement.'</body>',$html);else$html.=$enhancement;
}
echo$html;
