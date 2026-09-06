<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OrderFulfillmentService;

if (!Auth::can('orders.fulfill') && !Auth::can('orders.delivery')) {
    http_response_code(403);
    exit('Sua função não possui permissão para retirada por QR.');
}
$tenantId = em_require_tenant();
$service = new OrderFulfillmentService();
$token = trim((string)($_GET['token'] ?? ''));

function pickup_qty(float|int|string $value): string
{
    $qty = (float)$value;
    if (abs($qty - round($qty)) < 0.0005) return (string)(int)round($qty);
    return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',');
}
function pickup_channel(string $channel): string
{
    return match ($channel) {
        'counter' => 'Balcão / PDV',
        'pickup' => 'Retirada no local',
        'delivery' => 'Delivery',
        default => $channel,
    };
}
function pickup_progress_label(string $status): string
{
    return match ($status) {
        'fulfilled' => 'Tudo entregue',
        'partial' => 'Retirada parcial',
        default => 'Aguardando retirada',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'locate') {
            $normalized = $service->normalizeToken((string)($_POST['code'] ?? ''));
            em_go('pickup', ['token'=>$normalized]);
        }
        if ($action === 'fulfill') {
            $normalized = $service->normalizeToken((string)($_POST['token'] ?? ''));
            $details = $service->fulfill(
                $normalized,
                is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [],
                (string)($_POST['batch_key'] ?? ''),
                (string)($_POST['notes'] ?? '')
            );
            $remaining = pickup_qty($details['progress']['remaining_quantity'] ?? 0);
            $message = ($details['progress']['status'] ?? '') === 'fulfilled'
                ? 'Retirada registrada. Todos os itens deste pedido foram entregues.'
                : 'Retirada parcial registrada. Ainda restam '.$remaining.' item(ns) para entregar.';
            em_flash('ok', $message);
            em_go('pickup', ['token'=>$normalized]);
        }
    } catch (Throwable $e) {
        em_flash('error', $e->getMessage());
        if ($token !== '') em_go('pickup', ['token'=>$token]);
        em_go('pickup');
    }
}

$details = null;
if ($token !== '') {
    try {
        $details = $service->detailsByToken($token);
        $token = (string)$details['order']['public_token'];
    } catch (Throwable $e) {
        em_flash('error', $e->getMessage());
        $token = '';
    }
}

em_header('Retirada por QR', 'pickup');
?>
<section class="page-hero">
    <div>
        <span class="eyebrow">CONFERÊNCIA E ENTREGA</span>
        <h2>Retirada por QR</h2>
        <p>Leia o QR apresentado pelo cliente ou pelo pedido. A entrega pode ser parcial: o sistema mantém o total original e registra exatamente o que saiu e o saldo restante.</p>
    </div>
</section>

<section class="card" style="margin-bottom:18px">
    <div class="section-head">
        <div>
            <span class="eyebrow">LOCALIZAR PEDIDO</span>
            <h2>QR ou código do pedido</h2>
        </div>
    </div>
    <form method="post" class="actions">
        <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
        <input type="hidden" name="action" value="locate">
        <input name="code" required autocomplete="off" placeholder="Cole o código ou conteúdo do QR" style="flex:1;min-width:230px">
        <button class="primary">Abrir pedido</button>
    </form>
    <p class="muted" style="margin-bottom:0">No celular, o QR pode ser lido pela câmera normal. O código abre esta tela já no pedido correto; se a sessão estiver fechada, o sistema pedirá login.</p>
</section>

<?php if ($details):
    $order = $details['order'];
    $progress = $details['progress'];
    $paid = (string)$order['payment_status'] === 'paid' || (int)$order['total_cents'] === 0;
    $closed = in_array((string)$order['status'], ['cancelled','completed'], true);
    $canFulfill = !$closed && $paid && (float)$progress['remaining_quantity'] > 0;
?>
<section class="card" style="margin-bottom:18px">
    <div class="section-head">
        <div>
            <span class="eyebrow"><?= Security::e(pickup_channel((string)$order['channel'])) ?></span>
            <h2>Pedido #<?= (int)$order['id'] ?></h2>
            <div class="muted"><?= Security::e($order['customer_name'] ?: 'Consumidor') ?><?= !empty($order['unit_name'])?' · '.Security::e($order['unit_name']):'' ?></div>
        </div>
        <div class="actions">
            <span class="status-pill <?= ($progress['status'] ?? '')==='fulfilled'?'active':'' ?>"><?= Security::e(pickup_progress_label((string)$progress['status'])) ?></span>
            <span class="status-pill <?= $paid?'active':'suspended' ?>"><?= $paid?'Pagamento confirmado':'Pagamento pendente' ?></span>
        </div>
    </div>

    <div class="metric-grid" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="metric-card"><span>Pedido</span><strong><?= pickup_qty($progress['ordered_quantity']) ?></strong><small>quantidade total</small></div>
        <div class="metric-card"><span>Já entregue</span><strong><?= pickup_qty($progress['fulfilled_quantity']) ?></strong><small>registrado por funcionário</small></div>
        <div class="metric-card"><span>Ainda falta</span><strong><?= pickup_qty($progress['remaining_quantity']) ?></strong><small>saldo para retirada</small></div>
    </div>

    <?php if (!$paid && (int)$order['total_cents'] > 0):?>
        <div class="alert error"><strong>Não libere produtos ainda.</strong> O pagamento de <?= em_money($order['total_cents']) ?> ainda não foi confirmado pelo servidor.</div>
    <?php elseif ((string)$order['status'] === 'cancelled'):?>
        <div class="alert error">Este pedido foi cancelado. Nenhum item pode ser entregue.</div>
    <?php elseif (($progress['status'] ?? '') === 'fulfilled'):?>
        <div class="alert ok">Todos os itens deste pedido já foram entregues.</div>
    <?php endif;?>

    <form method="post" id="fulfillment-form">
        <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
        <input type="hidden" name="action" value="fulfill">
        <input type="hidden" name="token" value="<?= Security::e($token) ?>">
        <input type="hidden" name="batch_key" value="<?= Security::e(bin2hex(random_bytes(16))) ?>">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Item</th><th>Pedido</th><th>Entregue</th><th>Falta</th><th>Retirar agora</th></tr></thead>
                <tbody>
                <?php foreach ($details['items'] as $item):
                    $remaining = (float)$item['remaining_quantity'];
                    $step = abs((float)$item['ordered_quantity'] - round((float)$item['ordered_quantity'])) < 0.0005 ? '1' : '0.001';
                ?>
                    <tr>
                        <td><strong><?= Security::e($item['name_snapshot']) ?></strong><?php if(!empty($item['notes'])):?><br><small class="muted"><?= Security::e($item['notes']) ?></small><?php endif;?></td>
                        <td><?= pickup_qty($item['ordered_quantity']) ?></td>
                        <td><?= pickup_qty($item['fulfilled_quantity']) ?></td>
                        <td><strong><?= pickup_qty($remaining) ?></strong></td>
                        <td>
                            <?php if ($remaining > 0):?>
                                <div class="actions" style="flex-wrap:nowrap">
                                    <input class="pickup-qty" type="number" name="qty[<?= (int)$item['id'] ?>]" min="0" max="<?= Security::e((string)$remaining) ?>" step="<?= $step ?>" value="0" style="width:92px"<?= $canFulfill?'':' disabled' ?>>
                                    <button type="button" class="button secondary compact pickup-all" data-max="<?= Security::e((string)$remaining) ?>"<?= $canFulfill?'':' disabled' ?>>Tudo</button>
                                </div>
                            <?php else:?><span class="status-pill active">Entregue</span><?php endif;?>
                        </td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php if ($canFulfill):?>
            <label style="margin-top:14px">Observação desta retirada<textarea name="notes" maxlength="500" placeholder="Opcional: ex. cliente retirou somente parte do pedido"></textarea></label>
            <button class="primary" style="width:100%;margin-top:14px" onclick="return confirm('Confirmar somente as quantidades informadas como entregues?')">Confirmar retirada selecionada</button>
        <?php endif;?>
    </form>
</section>

<?php if ($details['history']):?>
<section class="card">
    <div class="section-head"><div><span class="eyebrow">AUDITORIA</span><h2>Histórico de retiradas</h2></div></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Quando</th><th>Item</th><th>Qtd.</th><th>Responsável</th><th>Observação</th></tr></thead><tbody>
    <?php foreach ($details['history'] as $row):?><tr><td><?= Security::e($row['created_at']) ?></td><td><?= Security::e($row['name_snapshot']) ?></td><td><?= pickup_qty($row['quantity']) ?></td><td><?= Security::e($row['fulfilled_by_name'] ?: 'Usuário') ?></td><td><?= Security::e($row['notes'] ?? '') ?></td></tr><?php endforeach;?>
    </tbody></table></div>
</section>
<?php endif;?>
<script>
document.querySelectorAll('.pickup-all').forEach(btn=>btn.addEventListener('click',()=>{const input=btn.parentElement?.querySelector('.pickup-qty');if(input)input.value=btn.dataset.max||'0';}));
</script>
<?php endif;?>
<?php em_footer();
