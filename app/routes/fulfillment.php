<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\FulfillmentService;

Auth::requirePermission('fulfillment.manage');
$tenantId=em_require_tenant();
$service=new FulfillmentService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='lookup'){
            $token=$service->extractToken((string)($_POST['code']??''));
            if($token==='')throw new RuntimeException('Leia ou informe o código da venda.');
            em_go('fulfillment',['token'=>$token]);
        }
        if($action==='fulfill'){
            $token=$service->extractToken((string)($_POST['token']??''));
            $summary=$service->byToken($token);
            if((int)$summary['tenant_id']!==$tenantId)throw new RuntimeException('Venda pertence a outra empresa.');
            $service->fulfill(
                (int)$summary['id'],
                (int)($_POST['order_item_id']??0),
                (string)($_POST['quantity']??'1'),
                'scan',
                (string)($_POST['notes']??''),
                (string)($_POST['idempotency_key']??'')
            );
            em_flash('ok','Retirada registrada. O estoque não foi baixado novamente.');
            em_go('fulfillment',['token'=>$token]);
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('fulfillment');}
}

$orderId=(int)($_GET['order']??0);
if($orderId>0){
    try{$token=$service->ensureToken($tenantId,$orderId);em_go('fulfillment',['token'=>$token]);}
    catch(Throwable $e){em_flash('error',$e->getMessage());em_go('fulfillment');}
}

$token=$service->extractToken((string)($_GET['token']??''));
$summary=null;$history=[];
if($token!==''){
    try{
        $summary=$service->byToken($token);
        if((int)$summary['tenant_id']!==$tenantId)throw new RuntimeException('Venda pertence a outra empresa.');
        $history=$service->history($tenantId,(int)$summary['id']);
    }catch(Throwable $e){em_flash('error',$e->getMessage());$summary=null;}
}

function fulfill_qty(mixed $v):string{$n=(float)$v;return rtrim(rtrim(number_format($n,3,',','.'),'0'),',');}

em_header('Retirada de produtos','fulfillment');
?>
<section class="card" style="margin-bottom:18px">
 <div class="section-head"><div><h2>Ler venda / QR</h2><p class="muted">Use leitor de código, digite o código ou cole a URL impressa no comprovante.</p></div></div>
 <form method="post" class="actions">
  <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="lookup">
  <input name="code" autofocus autocomplete="off" placeholder="Código ou URL da retirada" style="min-width:300px;flex:1" required>
  <button class="primary">Abrir venda</button>
 </form>
</section>

<?php if($summary):?>
<section class="card" style="margin-bottom:18px">
 <div class="section-head"><div><h2>Venda #<?= (int)$summary['id'] ?></h2><p class="muted"><?= Security::e($summary['customer_name']??'Consumidor') ?> · <?= Security::e((string)$summary['channel']) ?></p></div><div class="actions"><span class="badge"><?= Security::e((string)$summary['payment_status']) ?></span><span class="badge"><?= Security::e((string)$summary['fulfillment_status']) ?></span><a class="button secondary" target="_blank" href="/retirada.php?t=<?= rawurlencode($token) ?>">Imprimir comprovante</a></div></div>
 <?php if($summary['payment_status']!=='paid'):?><div class="alert error">Esta venda ainda não está totalmente paga. Nenhuma retirada será liberada.</div><?php elseif($summary['fulfillment_status']==='fulfilled'):?><div class="alert ok">Todos os itens desta venda já foram entregues.</div><?php endif;?>
 <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
 <?php foreach($summary['items'] as$item):$remaining=(float)$item['remaining_quantity'];?>
  <article style="padding:16px;border:1px solid var(--line);border-radius:18px">
   <h3><?= Security::e($item['name_snapshot']) ?></h3>
   <p><strong>Comprado:</strong> <?= Security::e(fulfill_qty($item['quantity'])) ?><br><strong>Retirado:</strong> <?= Security::e(fulfill_qty($item['fulfilled_quantity'])) ?><br><strong>Saldo:</strong> <span style="font-size:1.3em"><?= Security::e(fulfill_qty($remaining)) ?></span></p>
   <?php if($summary['payment_status']==='paid'&&$remaining>0.000001):?>
   <form method="post" onsubmit="return confirm('Confirmar esta entrega? O saldo será reduzido, mas o estoque NÃO será baixado novamente.')">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="fulfill"><input type="hidden" name="token" value="<?= Security::e($token) ?>"><input type="hidden" name="order_item_id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="idempotency_key" value="fulfill:<?= (int)$tenantId ?>:<?= (int)$summary['id'] ?>:<?= (int)$item['id'] ?>:<?= bin2hex(random_bytes(10)) ?>">
    <label>Entregar agora<input name="quantity" type="number" min="0.001" step="0.001" max="<?= Security::e((string)$remaining) ?>" value="<?= $remaining>=1?'1':Security::e((string)$remaining) ?>" required></label>
    <label>Observação<input name="notes" maxlength="500" placeholder="Opcional"></label>
    <button class="primary" style="width:100%">Confirmar retirada</button>
   </form>
   <?php else:?><span class="badge"><?= $remaining<=0.000001?'Entregue':'Bloqueado' ?></span><?php endif;?>
  </article>
 <?php endforeach;?>
 </div>
</section>

<section class="card"><div class="section-head"><h2>Histórico de retiradas</h2><span class="muted"><?= count($history) ?> movimentos</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Quantidade</th><th>Operador</th><th>Origem</th><th>Data UTC</th><th>Observação</th></tr></thead><tbody><?php foreach($history as$h):?><tr><td><?= Security::e($h['name_snapshot']) ?></td><td><strong><?= Security::e(fulfill_qty($h['quantity'])) ?></strong></td><td><?= Security::e($h['user_name']??'Sistema') ?></td><td><?= Security::e($h['source']) ?></td><td><?= Security::e($h['created_at']) ?></td><td><?= Security::e($h['notes']??'—') ?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="6" class="muted">Nenhuma retirada registrada.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>
<?php em_footer();
