<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\MarketplaceDeliveryCouponService;

Auth::requirePermission('platform.manage');
if(!Auth::isSuperAdmin()||Auth::tenantId()){
    http_response_code(403);
    exit('Acesso restrito ao Super ADM.');
}

$service=new MarketplaceDeliveryCouponService();
$allowedScopes=['default','tenant','city','state','plan'];
$moneyToCents=static function(mixed$value):int{$raw=trim((string)$value);if($raw==='')return 0;$raw=str_replace(['R$',' '],'',$raw);if(str_contains($raw,',')&&str_contains($raw,'.'))$raw=str_replace('.','',$raw);$raw=str_replace(',','.',$raw);return max(0,(int)round((float)$raw*100));};

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='save'){
            $id=max(0,(int)($_POST['id']??0));
            $existing=null;if($id){$q=$pdo->prepare('SELECT * FROM marketplace_delivery_coupons WHERE id=?');$q->execute([$id]);$existing=$q->fetch()?:throw new RuntimeException('Cupom Delivery não encontrado.');}
            $code=$existing?(string)$existing['code']:mb_strtoupper(trim((string)($_POST['code']??'')));
            $code=preg_replace('/[^A-Z0-9_-]+/','',$code)??'';$code=mb_substr($code,0,80);
            $name=mb_substr(trim((string)($_POST['name']??'')),0,160);
            $type=(string)($_POST['type']??'percent');$scope=(string)($_POST['scope_type']??'default');
            if($code===''||$name==='')throw new RuntimeException('Informe o código e o nome do cupom.');
            if(!in_array($type,['percent','fixed'],true))throw new RuntimeException('Tipo de desconto inválido.');
            if(!in_array($scope,$allowedScopes,true))throw new RuntimeException('Abrangência inválida.');
            $raw=(float)str_replace(',','.',(string)($_POST['value']??0));if($raw<=0)throw new RuntimeException('Informe um desconto maior que zero.');
            $value=$type==='percent'?(int)round($raw):$moneyToCents($raw);if($type==='percent'&&$value>100)throw new RuntimeException('O desconto percentual não pode passar de 100%.');
            $min=$moneyToCents($_POST['min_order']??0);$max=max(0,(int)($_POST['max_uses_per_tenant']??0));
            $starts=trim((string)($_POST['starts_at']??''));$ends=trim((string)($_POST['ends_at']??''));$starts=$starts?str_replace('T',' ',$starts):null;$ends=$ends?str_replace('T',' ',$ends):null;
            if($starts&&$ends&&strtotime($ends)<strtotime($starts))throw new RuntimeException('A data final não pode ser anterior à inicial.');
            $tenantId=$scope==='tenant'?max(0,(int)($_POST['tenant_id']??0)):null;$city=$scope==='city'?mb_substr(trim((string)($_POST['city']??'')),0,120):null;$state=$scope==='state'?mb_substr(mb_strtoupper(trim((string)($_POST['state']??''))),0,2):null;$plan=$scope==='plan'?mb_substr(trim((string)($_POST['plan_code']??'')),0,60):null;
            if($scope==='tenant'&&!$tenantId)throw new RuntimeException('Selecione a empresa.');if($scope==='city'&&!$city)throw new RuntimeException('Informe a cidade.');if($scope==='state'&&strlen((string)$state)!==2)throw new RuntimeException('Informe a UF.');if($scope==='plan'&&!$plan)throw new RuntimeException('Selecione o plano.');

            $result=Database::transaction(function(PDO$tx)use($id,$code,$name,$type,$value,$min,$max,$scope,$tenantId,$city,$state,$plan,$starts,$ends,$service):array{
                if($id){$s=$tx->prepare('UPDATE marketplace_delivery_coupons SET name=?,type=?,value=?,min_order_cents=?,max_uses_per_tenant=?,scope_type=?,tenant_id=?,city=?,state=?,plan_code=?,starts_at=?,ends_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$s->execute([$name,$type,$value,$min,$max?:null,$scope,$tenantId,$city,$state,$plan,$starts,$ends,$id]);$savedId=$id;}
                else{$s=$tx->prepare('INSERT INTO marketplace_delivery_coupons (code,name,type,value,min_order_cents,max_uses_per_tenant,scope_type,tenant_id,city,state,plan_code,starts_at,ends_at,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)');$s->execute([$code,$name,$type,$value,$min,$max?:null,$scope,$tenantId,$city,$state,$plan,$starts,$ends,Auth::id()]);$savedId=(int)$tx->lastInsertId();}
                $sync=$service->syncCoupon($tx,$savedId);return['id'=>$savedId,'sync'=>$sync];
            });
            Auth::audit('marketplace.delivery_coupon_saved','marketplace_delivery_coupon',(string)$result['id'],['code'=>$code,'scope'=>$scope,'targets'=>$result['sync']['targets'],'conflicts'=>count($result['sync']['conflicts'])]);
            $msg='Cupom salvo e enviado para '.(int)$result['sync']['applied'].' empresa(s).';if($result['sync']['conflicts'])$msg.=' '.count($result['sync']['conflicts']).' empresa(s) já possuem esse código e não foram alteradas.';em_flash('ok',$msg);em_go('marketplace-coupons',['edit'=>$result['id']]);
        }
        if($action==='toggle'){
            $id=max(0,(int)($_POST['id']??0));$active=(int)($_POST['active']??0)===1?1:0;
            Database::transaction(function(PDO$tx)use($id,$active,$service):void{$tx->prepare('UPDATE marketplace_delivery_coupons SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$active,$id]);$service->syncCoupon($tx,$id);});
            Auth::audit('marketplace.delivery_coupon_status','marketplace_delivery_coupon',(string)$id,['active'=>$active]);em_flash('ok',$active?'Cupom ativado e sincronizado.':'Cupom pausado no Delivery.');em_go('marketplace-coupons');
        }
        if($action==='sync'){
            $result=Database::transaction(fn(PDO$tx):array=>$service->syncAll($tx));em_flash('ok','Sincronização concluída: '.$result['applied'].' vínculo(s) atualizados e '.$result['conflicts'].' conflito(s) preservados.');em_go('marketplace-coupons');
        }
    }catch(Throwable$e){em_flash('error',$e->getMessage());em_go('marketplace-coupons');}
}

$companies=$pdo->query('SELECT t.id,t.name FROM tenants t JOIN marketplace_tenant_settings m ON m.tenant_id=t.id WHERE t.status="active" AND m.participates=1 ORDER BY t.name')->fetchAll();
$plans=$pdo->query('SELECT code,name FROM saas_plans WHERE active=1 ORDER BY sort_order,name')->fetchAll();
$cities=$pdo->query('SELECT DISTINCT city FROM marketplace_tenant_settings WHERE city IS NOT NULL AND city<>"" ORDER BY city')->fetchAll(PDO::FETCH_COLUMN);$states=$pdo->query('SELECT DISTINCT state FROM marketplace_tenant_settings WHERE state IS NOT NULL AND state<>"" ORDER BY state')->fetchAll(PDO::FETCH_COLUMN);
$editId=max(0,(int)($_GET['edit']??0));$editing=null;if($editId){$q=$pdo->prepare('SELECT * FROM marketplace_delivery_coupons WHERE id=?');$q->execute([$editId]);$editing=$q->fetch()?:null;}
$rows=$pdo->query('SELECT mc.*,(SELECT COUNT(*) FROM marketplace_delivery_coupon_targets mt WHERE mt.marketplace_coupon_id=mc.id) target_count,(SELECT COALESCE(SUM(c.uses_count),0) FROM marketplace_delivery_coupon_targets mt JOIN coupons c ON c.id=mt.coupon_id WHERE mt.marketplace_coupon_id=mc.id) uses_total FROM marketplace_delivery_coupons mc ORDER BY mc.active DESC,mc.id DESC')->fetchAll();
$scopeLabels=['default'=>'Todas as empresas','tenant'=>'Empresa específica','city'=>'Cidade','state'=>'Estado','plan'=>'Plano'];
em_header('Cupons Delivery','marketplace-coupons');
?>
<section class="page-hero"><div><span class="eyebrow">EVENTMENU DELIVERY</span><h2>Cupons para clientes do app</h2><p>Crie descontos no Super ADM e distribua automaticamente para as empresas elegíveis. Estes cupons são usados no carrinho do EventMenu Delivery.</p></div><div class="hero-actions"><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="sync"><button class="button secondary">Sincronizar empresas</button></form><a class="button secondary" href="<?=Security::e(app_url('?route=marketplace-campaigns'))?>">Campanhas</a></div></section>
<div class="grid two" style="align-items:start">
<section class="card"><span class="eyebrow"><?= $editing?'EDITAR':'NOVO CUPOM' ?></span><h3><?= $editing?Security::e((string)$editing['name']):'Criar cupom Delivery' ?></h3><p class="muted">Campanha e cupom são diferentes: campanha define regras do marketplace; cupom dá desconto ao cliente no carrinho.</p>
<form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id']??0) ?>">
<label>Código<input name="code" maxlength="80" required value="<?=Security::e((string)($editing['code']??''))?>" <?=$editing?'readonly':''?> placeholder="EX: PRIMEIRACOMPRA"><small><?= $editing?'O código não muda depois de criado.':'O cliente digita este código no app.' ?></small></label>
<label>Nome interno<input name="name" maxlength="160" required value="<?=Security::e((string)($editing['name']??''))?>" placeholder="Ex: Primeira compra"></label>
<label>Tipo<select name="type"><option value="percent"<?=em_selected((string)($editing['type']??'percent'),'percent')?>>Percentual</option><option value="fixed"<?=em_selected((string)($editing['type']??''),'fixed')?>>Valor fixo</option></select></label>
<label>Desconto<input name="value" inputmode="decimal" required value="<?= $editing?Security::e($editing['type']==='fixed'?number_format(((int)$editing['value'])/100,2,',',''):(string)$editing['value']):'' ?>" placeholder="10"></label>
<label>Pedido mínimo (R$)<input name="min_order" inputmode="decimal" value="<?= $editing?Security::e(number_format(((int)$editing['min_order_cents'])/100,2,',','')):'0' ?>"></label>
<label>Máx. usos por empresa<input type="number" min="0" name="max_uses_per_tenant" value="<?=Security::e((string)($editing['max_uses_per_tenant']??''))?>" placeholder="0 = sem limite"></label>
<label>Abrangência<select name="scope_type" data-coupon-scope><?php foreach($scopeLabels as$v=>$label):?><option value="<?=Security::e($v)?>"<?=em_selected((string)($editing['scope_type']??'default'),$v)?>><?=Security::e($label)?></option><?php endforeach;?></select></label>
<label data-coupon-target="tenant">Empresa<select name="tenant_id"><option value="0">Selecione</option><?php foreach($companies as$c):?><option value="<?= (int)$c['id'] ?>"<?=em_selected((string)($editing['tenant_id']??''),(string)$c['id'])?>><?=Security::e((string)$c['name'])?></option><?php endforeach;?></select></label>
<label data-coupon-target="city">Cidade<input name="city" list="coupon-cities" value="<?=Security::e((string)($editing['city']??''))?>"><datalist id="coupon-cities"><?php foreach($cities as$c):?><option value="<?=Security::e((string)$c)?>"><?php endforeach;?></datalist></label>
<label data-coupon-target="state">Estado (UF)<input name="state" maxlength="2" value="<?=Security::e((string)($editing['state']??''))?>"><datalist id="coupon-states"><?php foreach($states as$s):?><option value="<?=Security::e((string)$s)?>"><?php endforeach;?></datalist></label>
<label data-coupon-target="plan">Plano<select name="plan_code"><option value="">Selecione</option><?php foreach($plans as$p):?><option value="<?=Security::e((string)$p['code'])?>"<?=em_selected((string)($editing['plan_code']??''),(string)$p['code'])?>><?=Security::e((string)$p['name'])?></option><?php endforeach;?></select></label>
<label>Início<input type="datetime-local" name="starts_at" value="<?=Security::e(!empty($editing['starts_at'])?date('Y-m-d\TH:i',strtotime((string)$editing['starts_at'])):'')?>"></label><label>Fim<input type="datetime-local" name="ends_at" value="<?=Security::e(!empty($editing['ends_at'])?date('Y-m-d\TH:i',strtotime((string)$editing['ends_at'])):'')?>"></label>
<div class="actions span-2"><button class="button primary">Salvar e publicar no Delivery</button><?php if($editing):?><a class="button secondary" href="<?=Security::e(app_url('?route=marketplace-coupons'))?>">Novo cupom</a><?php endif;?></div></form></section>
<section class="card"><span class="eyebrow">COMO FUNCIONA</span><h3>Um cupom, várias empresas</h3><p>O Super ADM escolhe a abrangência. O EventMenu cria o vínculo necessário em cada restaurante sem alterar o motor de descontos já usado pelo pedido.</p><div class="alert"><strong>Exemplo:</strong> código <strong>DELIVERY5</strong>, R$ 5 de desconto, pedido mínimo R$ 30, válido para todas as empresas.</div><p class="muted">Quando uma nova empresa entrar no EventMenu Delivery, use o botão <strong>Sincronizar empresas</strong> para publicar nela os cupons compatíveis.</p></section>
</div>
<section class="card" style="margin-top:16px"><div class="section-head"><div><span class="eyebrow">CUPONS PUBLICADOS</span><h3>Cupons do EventMenu Delivery</h3></div></div><?php if(!$rows):?><div class="empty-state"><strong>Nenhum cupom Delivery criado.</strong><span>Crie o primeiro cupom acima.</span></div><?php else:?><div class="table-wrap"><table class="table"><thead><tr><th>Cupom</th><th>Desconto</th><th>Abrangência</th><th>Empresas</th><th>Usos</th><th>Validade</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($rows as$r):?><tr><td><strong><?=Security::e((string)$r['code'])?></strong><br><small><?=Security::e((string)$r['name'])?></small></td><td><?= $r['type']==='percent'?(int)$r['value'].'%':em_money((int)$r['value']) ?></td><td><?=Security::e($scopeLabels[(string)$r['scope_type']]??'Todas')?></td><td><?= (int)$r['target_count'] ?></td><td><?= (int)$r['uses_total'] ?></td><td><?=Security::e($r['ends_at']?date('d/m/Y H:i',strtotime((string)$r['ends_at'])):'Sem limite')?></td><td><span class="badge status-<?= (int)$r['active']===1?'success':'muted' ?>"><?= (int)$r['active']===1?'Ativo':'Pausado' ?></span></td><td><div class="actions"><a class="button secondary compact" href="<?=Security::e(app_url('?route=marketplace-coupons&edit='.(int)$r['id']))?>">Editar</a><form method="post"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="active" value="<?= (int)$r['active']===1?0:1 ?>"><button class="button secondary compact"><?= (int)$r['active']===1?'Pausar':'Ativar' ?></button></form></div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
<script>(()=>{const s=document.querySelector('[data-coupon-scope]');const refresh=()=>document.querySelectorAll('[data-coupon-target]').forEach(el=>el.style.display=el.dataset.couponTarget===s?.value?'grid':'none');s?.addEventListener('change',refresh);refresh()})();</script>
<?php em_footer();
