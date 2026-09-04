<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\FulfillmentService;

Auth::requirePermission('fulfillment.manage');
$tenantId=em_require_tenant();$unitId=Auth::unitId();
$service=new FulfillmentService();
$assertUnit=function(array $summary)use($unitId):void{if($unitId&&(int)($summary['unit_id']??0)!==$unitId)throw new RuntimeException('Venda pertence a outra unidade.');};

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='lookup'){
            $raw=trim((string)($_POST['code']??''));
            if(preg_match('/^#?(\d+)$/',$raw,$m)){
                $token=$service->ensureToken($tenantId,(int)$m[1]);
            }else{
                $token=$service->extractToken($raw);
                if($token!=='')$assertUnit($service->byToken($token));
            }
            if($token==='')throw new RuntimeException('Leia ou informe o QR, código ou número da venda.');
            em_go('fulfillment',['token'=>$token]);
        }
        if($action==='fulfill'){
            $token=$service->extractToken((string)($_POST['token']??''));
            $summary=$service->byToken($token);
            if((int)$summary['tenant_id']!==$tenantId)throw new RuntimeException('Venda pertence a outra empresa.');$assertUnit($summary);
            $updated=$service->fulfill(
                (int)$summary['id'],
                (int)($_POST['order_item_id']??0),
                (string)($_POST['quantity']??'1'),
                'scan',
                (string)($_POST['notes']??''),
                (string)($_POST['idempotency_key']??'')
            );
            if((string)($_POST['after']??'')==='print'){
                header('Location: '.em_url('/retirada.php?t='.rawurlencode($token).'&auto=1'),true,303);exit;
            }
            $remaining=(float)($updated['remaining_items']??0);
            em_flash('ok',$remaining>0.000001?'Retirada registrada. O saldo restante foi atualizado.':'Retirada registrada. Todos os itens desta venda foram entregues.');
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
        if((int)$summary['tenant_id']!==$tenantId)throw new RuntimeException('Venda pertence a outra empresa.');$assertUnit($summary);
        $history=$service->history($tenantId,(int)$summary['id']);
    }catch(Throwable $e){em_flash('error',$e->getMessage());$summary=null;}
}

$sql='SELECT o.id,o.fulfillment_token,o.fulfillment_status,o.created_at,c.name customer_name,COALESCE(SUM(oi.quantity-oi.fulfilled_quantity),0) remaining_qty FROM orders o LEFT JOIN customers c ON c.id=o.customer_id JOIN order_items oi ON oi.order_id=o.id WHERE o.tenant_id=?'.($unitId?' AND o.unit_id=?':'').' AND o.channel IN ("counter","pickup") AND o.payment_status="paid" AND o.fulfillment_status<>"fulfilled" AND o.status<>"cancelled" GROUP BY o.id,o.fulfillment_token,o.fulfillment_status,o.created_at,c.name ORDER BY o.id DESC LIMIT 50';
$args=[$tenantId];if($unitId)$args[]=$unitId;$recentStmt=$pdo->prepare($sql);$recentStmt->execute($args);$recent=$recentStmt->fetchAll();

function fulfill_qty(mixed $v):string{$n=(float)$v;return rtrim(rtrim(number_format($n,3,',','.'),'0'),',');}

em_header('Retirada de produtos','fulfillment');
?>
<?php if($unitId):?><div class="alert"><strong>Retiradas da unidade selecionada.</strong> QR ou venda de outra filial será recusado pelo servidor.</div><?php endif;?>
<section class="card" style="margin-bottom:18px">
 <div class="section-head"><div><h2>Ler venda / QR</h2><p class="muted">Use leitor de código, digite o número da venda (ex.: 123), o código ou cole a URL impressa.</p></div></div>
 <form method="post" class="actions">
  <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="lookup">
  <input name="code" autofocus autocomplete="off" placeholder="QR, código ou nº da venda" style="min-width:300px;flex:1" required>
  <button class="primary">Abrir venda</button>
 </form>
</section>

<?php if($summary):?>
<section class="card" style="margin-bottom:18px">
 <div class="section-head"><div><h2>Venda #<?= (int)$summary['id'] ?></h2><p class="muted"><?= Security::e($summary['customer_name']??'Consumidor') ?> · <?= Security::e((string)$summary['channel']) ?></p></div><div class="actions"><span class="badge"><?= Security::e((string)$summary['payment_status']) ?></span><span class="badge"><?= Security::e((string)$summary['fulfillment_status']) ?></span><a class="button secondary" target="_blank" rel="noopener" href="<?= Security::e(em_url('/retirada.php?t='.rawurlencode($token))) ?>">Imprimir comprovante</a></div></div>
 <?php if($summary['payment_status']!=='paid'):?><div class="alert error">Esta venda ainda não está totalmente paga. Nenhuma retirada será liberada.</div><?php elseif($summary['fulfillment_status']==='fulfilled'):?><div class="alert ok">Todos os itens desta venda já foram entregues.</div><?php else:?><div class="alert"><strong>Retirada parcial ativa.</strong> Entregue somente a quantidade informada. O saldo ficará disponível para a próxima leitura deste mesmo QR.</div><?php endif;?>
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
    <div class="actions" style="margin-top:10px"><button class="primary" type="submit">Confirmar retirada</button><button class="secondary" type="submit" name="after" value="print" formtarget="_blank">Confirmar e imprimir</button></div>
   </form>
   <?php else:?><span class="badge"><?= $remaining<=0.000001?'Entregue':'Bloqueado' ?></span><?php endif;?>
  </article>
 <?php endforeach;?>
 </div>
</section>

<section class="card" style="margin-bottom:18px"><div class="section-head"><h2>Histórico de retiradas</h2><span class="muted"><?= count($history) ?> movimentos</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Item</th><th>Quantidade</th><th>Operador</th><th>Origem</th><th>Data UTC</th><th>Observação</th></tr></thead><tbody><?php foreach($history as$h):?><tr><td><?= Security::e($h['name_snapshot']) ?></td><td><strong><?= Security::e(fulfill_qty($h['quantity'])) ?></strong></td><td><?= Security::e($h['user_name']??'Sistema') ?></td><td><?= Security::e($h['source']) ?></td><td><?= Security::e($h['created_at']) ?></td><td><?= Security::e($h['notes']??'—') ?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="6" class="muted">Nenhuma retirada registrada.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?>

<section class="card"><div class="section-head"><h2>Vendas com saldo para retirar</h2><span class="muted"><?= count($recent) ?> recentes</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Venda</th><th>Cliente</th><th>Status</th><th>Saldo de unidades</th><th>Data UTC</th><th></th></tr></thead><tbody><?php foreach($recent as$r):?><tr><td>#<?= (int)$r['id'] ?></td><td><?= Security::e($r['customer_name']??'Consumidor') ?></td><td><span class="badge"><?= Security::e($r['fulfillment_status']) ?></span></td><td><strong><?= Security::e(fulfill_qty($r['remaining_qty'])) ?></strong></td><td><?= Security::e($r['created_at']) ?></td><td><a class="button secondary" href="<?= Security::e(em_url('/?route=fulfillment&order='.(int)$r['id'])) ?>">Abrir</a></td></tr><?php endforeach;?><?php if(!$recent):?><tr><td colspan="6" class="muted">Nenhuma venda paga com saldo pendente nesta unidade.</td></tr><?php endif;?></tbody></table></div></section>
<?php em_footer();
