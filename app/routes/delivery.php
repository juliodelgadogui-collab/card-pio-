<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\DeliveryService;

Auth::requirePermission('delivery.assign');
$tenantId=em_require_tenant();

function delivery_cents(string $value):int{
    $value=trim($value);
    if($value==='')return 0;
    $value=str_replace(['R$',' '],'',$value);
    if(str_contains($value,','))$value=str_replace(['.',','],['','.'],$value);
    if(!is_numeric($value))throw new RuntimeException('Valor monetário inválido.');
    return max(0,(int)round(((float)$value)*100));
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try{
        if($action==='save-zone'){
            $id=(int)($_POST['id']??0);
            $name=trim((string)($_POST['name']??''));
            $matchType=(string)($_POST['match_type']??'postal_prefix');
            $matchValue=trim((string)($_POST['match_value']??''));
            $fee=delivery_cents((string)($_POST['fee']??'0'));
            $minimum=delivery_cents((string)($_POST['minimum']??'0'));
            $freeRaw=trim((string)($_POST['free_above']??''));
            $freeAbove=$freeRaw===''?null:delivery_cents($freeRaw);
            $etaMin=max(5,min(1440,(int)($_POST['eta_min']??30)));
            $etaMax=max(5,min(1440,(int)($_POST['eta_max']??60)));
            $sort=(int)($_POST['sort_order']??0);
            $active=isset($_POST['active'])?1:0;
            if($name===''||$matchValue===''||!in_array($matchType,['postal_prefix','neighborhood','city'],true))throw new RuntimeException('Preencha nome e área da zona.');
            if($etaMax<$etaMin)throw new RuntimeException('O prazo máximo não pode ser menor que o mínimo.');
            if($matchType==='postal_prefix'){
                $matchValue=preg_replace('/\D+/','',$matchValue)??'';
                if(strlen($matchValue)<3||strlen($matchValue)>8)throw new RuntimeException('Prefixo de CEP deve ter entre 3 e 8 dígitos.');
            }
            if($freeAbove!==null&&$freeAbove<$minimum)throw new RuntimeException('O valor de frete grátis não pode ser menor que o pedido mínimo.');

            if($id>0){
                $s=$pdo->prepare('UPDATE delivery_zones SET name=?,match_type=?,match_value=?,fee_cents=?,min_order_cents=?,free_above_cents=?,eta_min_minutes=?,eta_max_minutes=?,sort_order=?,active=? WHERE id=? AND tenant_id=?');
                $s->execute([$name,$matchType,$matchValue,$fee,$minimum,$freeAbove,$etaMin,$etaMax,$sort,$active,$id,$tenantId]);
                if($s->rowCount()===0){$check=$pdo->prepare('SELECT id FROM delivery_zones WHERE id=? AND tenant_id=?');$check->execute([$id,$tenantId]);if(!$check->fetchColumn())throw new RuntimeException('Zona não encontrada.');}
            }else{
                $s=$pdo->prepare('INSERT INTO delivery_zones (tenant_id,name,match_type,match_value,fee_cents,min_order_cents,free_above_cents,eta_min_minutes,eta_max_minutes,sort_order,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$tenantId,$name,$matchType,$matchValue,$fee,$minimum,$freeAbove,$etaMin,$etaMax,$sort,$active]);
                $id=(int)$pdo->lastInsertId();
            }
            Auth::audit('delivery.zone_saved','delivery_zone',(string)$id,['match_type'=>$matchType,'match_value'=>$matchValue,'fee_cents'=>$fee,'active'=>(bool)$active]);
            em_flash('ok','Zona de entrega salva.');
            em_go('delivery');
        }
        if($action==='toggle-zone'){
            $id=(int)($_POST['id']??0);
            $pdo->prepare('UPDATE delivery_zones SET active=IF(active=1,0,1) WHERE id=? AND tenant_id=?')->execute([$id,$tenantId]);
            Auth::audit('delivery.zone_toggled','delivery_zone',(string)$id);
            em_flash('ok','Status da zona atualizado.');
            em_go('delivery');
        }
        if($action==='assign'){
            $orderId=(int)($_POST['order_id']??0);
            $userId=(int)($_POST['delivery_user_id']??0);
            (new DeliveryService())->assign($tenantId,$orderId,$userId>0?$userId:null);
            em_flash('ok',$userId>0?'Entregador atribuído.':'Entregador removido.');
            em_go('delivery');
        }
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('delivery');}
}

$editId=(int)($_GET['edit']??0);
$edit=null;
if($editId){$s=$pdo->prepare('SELECT * FROM delivery_zones WHERE id=? AND tenant_id=?');$s->execute([$editId,$tenantId]);$edit=$s->fetch()?:null;}
$z=$pdo->prepare('SELECT * FROM delivery_zones WHERE tenant_id=? ORDER BY active DESC,sort_order,id');$z->execute([$tenantId]);$zones=$z->fetchAll();
$d=$pdo->prepare('SELECT id,name FROM users WHERE tenant_id=? AND role="delivery" AND status="active" ORDER BY name');$d->execute([$tenantId]);$drivers=$d->fetchAll();
$q=$pdo->prepare('SELECT o.*,c.name customer_name,u.name delivery_name,dz.name zone_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN delivery_zones dz ON dz.id=o.delivery_zone_id WHERE o.tenant_id=? AND o.channel="delivery" AND o.status NOT IN ("completed","cancelled") ORDER BY FIELD(o.status,"out_for_delivery","ready","preparing","confirmed","pending"),o.id DESC LIMIT 100');$q->execute([$tenantId]);$queue=$q->fetchAll();
$metrics=['waiting'=>0,'ready'=>0,'route'=>0];foreach($queue as$o){if(in_array($o['status'],['pending','confirmed','preparing'],true))$metrics['waiting']++;if($o['status']==='ready')$metrics['ready']++;if($o['status']==='out_for_delivery')$metrics['route']++;}

em_header('Delivery','delivery');
?><section class="grid"><div class="card metric"><span class="muted">Em produção</span><strong><?= $metrics['waiting'] ?></strong></div><div class="card metric"><span class="muted">Prontos para sair</span><strong><?= $metrics['ready'] ?></strong></div><div class="card metric"><span class="muted">Na rua</span><strong><?= $metrics['route'] ?></strong></div></section>
<div class="grid" style="grid-template-columns:minmax(310px,1fr) minmax(0,2fr);margin-top:18px"><section class="card"><div class="section-head"><h2><?= $edit?'Editar zona':'Nova zona' ?></h2><?php if($edit):?><a class="button secondary" href="/?route=delivery">Cancelar</a><?php endif;?></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save-zone"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome<input name="name" required value="<?= Security::e($edit['name']??'') ?>" placeholder="Centro"></label><label>Correspondência<select name="match_type"><option value="postal_prefix"<?= em_selected($edit['match_type']??'postal_prefix','postal_prefix') ?>>Prefixo de CEP</option><option value="neighborhood"<?= em_selected($edit['match_type']??'','neighborhood') ?>>Bairro</option><option value="city"<?= em_selected($edit['match_type']??'','city') ?>>Cidade</option></select></label><label>Valor da área<input name="match_value" required value="<?= Security::e($edit['match_value']??'') ?>" placeholder="28300 ou Centro"></label><label>Taxa de entrega (R$)<input name="fee" inputmode="decimal" value="<?= $edit?number_format(((int)$edit['fee_cents'])/100,2,',','.'):'0,00' ?>"></label><label>Pedido mínimo (R$)<input name="minimum" inputmode="decimal" value="<?= $edit?number_format(((int)$edit['min_order_cents'])/100,2,',','.'):'0,00' ?>"></label><label class="span-2">Frete grátis a partir de (R$)<input name="free_above" inputmode="decimal" value="<?= $edit&&$edit['free_above_cents']!==null?number_format(((int)$edit['free_above_cents'])/100,2,',','.') : '' ?>" placeholder="Vazio = nunca"></label><label>Prazo mín. (min)<input type="number" name="eta_min" min="5" max="1440" value="<?= (int)($edit['eta_min_minutes']??30) ?>"></label><label>Prazo máx. (min)<input type="number" name="eta_max" min="5" max="1440" value="<?= (int)($edit['eta_max_minutes']??60) ?>"></label><label>Prioridade<input type="number" name="sort_order" value="<?= (int)($edit['sort_order']??0) ?>"></label><label class="checkbox"><input type="checkbox" name="active"<?= em_checked($edit['active']??1) ?>> Ativa</label><button class="primary span-2">Salvar zona</button></form><p class="muted">Prioridade de correspondência: CEP → bairro → cidade. Dentro do mesmo tipo, menor número de prioridade vence.</p></section><section class="card"><div class="section-head"><h2>Zonas configuradas</h2><span class="muted"><?= count($zones) ?> zonas</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Zona</th><th>Regra</th><th>Taxa</th><th>Mínimo</th><th>Frete grátis</th><th>Prazo</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($zones as$zone):?><tr><td><strong><?= Security::e($zone['name']) ?></strong></td><td><?= Security::e($zone['match_type']) ?><br><code><?= Security::e($zone['match_value']) ?></code></td><td><?= em_money($zone['fee_cents']) ?></td><td><?= em_money($zone['min_order_cents']) ?></td><td><?= $zone['free_above_cents']!==null?em_money($zone['free_above_cents']):'—' ?></td><td><?= (int)$zone['eta_min_minutes'] ?>–<?= (int)$zone['eta_max_minutes'] ?> min</td><td><span class="badge"><?= $zone['active']?'Ativa':'Inativa' ?></span></td><td><div class="actions"><a class="button secondary" href="/?route=delivery&edit=<?= (int)$zone['id'] ?>">Editar</a><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="toggle-zone"><input type="hidden" name="id" value="<?= (int)$zone['id'] ?>"><button class="secondary"><?= $zone['active']?'Desativar':'Ativar' ?></button></form></div></td></tr><?php endforeach;?></tbody></table></div></section></div>
<section class="card" style="margin-top:18px"><div class="section-head"><h2>Fila de entregas</h2><span class="muted">Pedidos em andamento</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Zona / endereço</th><th>Status</th><th>Frete / ETA</th><th>Entregador</th><th></th></tr></thead><tbody><?php foreach($queue as$o):?><tr><td><a href="/?route=orders&view=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a><br><small class="muted"><?= Security::e($o['payment_status']) ?></small></td><td><?= Security::e($o['customer_name']??'Consumidor') ?></td><td><strong><?= Security::e($o['zone_name']??'Padrão') ?></strong><br><span class="muted"><?= Security::e($o['delivery_address']??'') ?></span></td><td><span class="badge"><?= Security::e($o['status']) ?></span></td><td><?= em_money($o['delivery_fee_cents']) ?><br><small class="muted"><?= (int)($o['delivery_eta_min_minutes']??0) ?>–<?= (int)($o['delivery_eta_max_minutes']??0) ?> min</small></td><td><?= Security::e($o['delivery_name']??'Não atribuído') ?><form method="post" class="actions" style="margin-top:6px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><select name="delivery_user_id"><option value="0">Sem entregador</option><?php foreach($drivers as$driver):?><option value="<?= (int)$driver['id'] ?>"<?= em_selected($o['assigned_delivery_user_id']??0,$driver['id']) ?>><?= Security::e($driver['name']) ?></option><?php endforeach;?></select><button class="secondary">Salvar</button></form></td><td><a class="button secondary" href="/?route=orders&view=<?= (int)$o['id'] ?>">Detalhes</a></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();