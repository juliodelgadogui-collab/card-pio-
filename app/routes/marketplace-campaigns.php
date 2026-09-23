<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

Auth::requirePermission('platform.manage');
if (!Auth::isSuperAdmin() || Auth::tenantId()) {
    http_response_code(403);
    exit('Acesso restrito ao ADM Geral.');
}

$allowedScopes = ['default','tenant','city','state','plan'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'campaign-save') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $code = mb_strtoupper(trim((string)($_POST['campaign_code'] ?? '')));
            $code = preg_replace('/[^A-Z0-9._-]+/', '-', $code) ?? '';
            $code = trim($code, '-');
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160);
            $scope = strtolower(trim((string)($_POST['scope_type'] ?? 'default')));
            $priority = max(-100000, min(100000, (int)($_POST['priority'] ?? 0)));
            $startsAt = trim((string)($_POST['starts_at'] ?? '')) ?: null;
            $endsAt = trim((string)($_POST['ends_at'] ?? '')) ?: null;
            if ($code === '' || mb_strlen($code) > 80) throw new RuntimeException('Informe um código de campanha válido.');
            if ($name === '') throw new RuntimeException('Informe o nome da campanha.');
            if (!in_array($scope, $allowedScopes, true)) throw new RuntimeException('Abrangência de campanha inválida.');
            if ($startsAt && $endsAt && strtotime($endsAt) < strtotime($startsAt)) throw new RuntimeException('A data final não pode ser anterior à inicial.');

            $tenantId = $scope === 'tenant' ? max(0, (int)($_POST['tenant_id'] ?? 0)) : null;
            $city = $scope === 'city' ? mb_substr(trim((string)($_POST['city'] ?? '')), 0, 120) : null;
            $state = $scope === 'state' ? mb_substr(mb_strtoupper(trim((string)($_POST['state'] ?? ''))), 0, 2) : null;
            $plan = $scope === 'plan' ? mb_substr(trim((string)($_POST['plan_code'] ?? '')), 0, 60) : null;
            if ($scope === 'tenant' && !$tenantId) throw new RuntimeException('Selecione a empresa da campanha.');
            if ($scope === 'city' && !$city) throw new RuntimeException('Informe a cidade da campanha.');
            if ($scope === 'state' && strlen((string)$state) !== 2) throw new RuntimeException('Informe a UF com duas letras.');
            if ($scope === 'plan' && !$plan) throw new RuntimeException('Selecione o plano da campanha.');

            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE marketplace_campaign_assignments SET campaign_code=?,name=?,scope_type=?,tenant_id=?,city=?,state=?,plan_code=?,priority=?,starts_at=?,ends_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $stmt->execute([$code,$name,$scope,$tenantId,$city,$state,$plan,$priority,$startsAt,$endsAt,$id]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO marketplace_campaign_assignments (campaign_code,name,scope_type,tenant_id,city,state,plan_code,priority,starts_at,ends_at,active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)');
                $stmt->execute([$code,$name,$scope,$tenantId,$city,$state,$plan,$priority,$startsAt,$endsAt,Auth::id()]);
                $id = (int)$pdo->lastInsertId();
            }
            Auth::audit('marketplace.campaign_saved','marketplace_campaign',(string)$id,['campaign_code'=>$code,'scope'=>$scope,'priority'=>$priority]);
            em_flash('ok','Campanha salva. Novos checkouts elegíveis passam a usar esta atribuição; pedidos antigos permanecem inalterados.');
            em_go('marketplace-campaigns',['edit'=>$id]);
        }

        if ($action === 'campaign-toggle') {
            $id = max(0, (int)($_POST['id'] ?? 0));
            $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE marketplace_campaign_assignments SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $stmt->execute([$active,$id]);
            Auth::audit('marketplace.campaign_status','marketplace_campaign',(string)$id,['active'=>$active]);
            em_flash('ok',$active ? 'Campanha ativada.' : 'Campanha pausada.');
            em_go('marketplace-campaigns');
        }
    } catch (Throwable $e) {
        em_flash('error',$e->getMessage());
        em_go('marketplace-campaigns');
    }
}

$campaigns = $pdo->query('SELECT c.*,t.name tenant_name FROM marketplace_campaign_assignments c LEFT JOIN tenants t ON t.id=c.tenant_id ORDER BY c.active DESC,c.priority DESC,c.id DESC')->fetchAll();
$companies = $pdo->query('SELECT t.id,t.name FROM tenants t JOIN marketplace_tenant_settings m ON m.tenant_id=t.id WHERE m.participates=1 ORDER BY t.name')->fetchAll();
$plans = $pdo->query('SELECT code,name FROM saas_plans WHERE active=1 ORDER BY sort_order,name')->fetchAll();
$cities = $pdo->query('SELECT DISTINCT city FROM marketplace_tenant_settings WHERE city IS NOT NULL AND city<>"" ORDER BY city')->fetchAll(PDO::FETCH_COLUMN);
$states = $pdo->query('SELECT DISTINCT state FROM marketplace_tenant_settings WHERE state IS NOT NULL AND state<>"" ORDER BY state')->fetchAll(PDO::FETCH_COLUMN);
$editId = max(0,(int)($_GET['edit'] ?? 0));
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM marketplace_campaign_assignments WHERE id=? LIMIT 1');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}
$scopeLabels = ['default'=>'Todas as empresas','tenant'=>'Empresa específica','city'=>'Cidade','state'=>'Estado','plan'=>'Plano'];

em_header('Campanhas Delivery','marketplace-campaigns');
?>
<section class="page-hero">
  <div><span class="eyebrow">EVENTMENU DELIVERY</span><h2>Campanhas do marketplace</h2><p>Defina quem participa de cada campanha. A taxa financeira da campanha continua configurada no Financeiro Delivery.</p></div>
  <div class="hero-actions"><a class="button secondary" href="<?=Security::e(app_url('?route=marketplace-finance&tab=rules'))?>">Taxas e regras</a><a class="button secondary" href="<?=Security::e(app_url('?route=marketplace-finance'))?>">Financeiro Delivery</a></div>
</section>
<div class="grid two" style="align-items:start">
<section class="card">
  <span class="eyebrow"><?= $editing ? 'EDITAR' : 'NOVA CAMPANHA' ?></span>
  <h3><?= $editing ? Security::e((string)$editing['name']) : 'Criar atribuição' ?></h3>
  <p class="muted">O código nunca é informado pelo consumidor. O Server seleciona automaticamente a campanha elegível de maior prioridade.</p>
  <form method="post" class="form-grid">
    <input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="campaign-save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
    <label>Código da campanha<input name="campaign_code" maxlength="80" required placeholder="EX: LANCAMENTO-RJ" value="<?=Security::e((string)($editing['campaign_code'] ?? ''))?>"></label>
    <label>Nome<input name="name" maxlength="160" required placeholder="Ex: Lançamento Rio de Janeiro" value="<?=Security::e((string)($editing['name'] ?? ''))?>"></label>
    <label>Abrangência<select name="scope_type" data-campaign-scope><?php foreach($scopeLabels as $value=>$label):?><option value="<?=Security::e($value)?>"<?=em_selected((string)($editing['scope_type'] ?? 'default'),$value)?>><?=Security::e($label)?></option><?php endforeach;?></select></label>
    <label data-campaign-target="tenant">Empresa<select name="tenant_id"><option value="0">Selecione</option><?php foreach($companies as $company):?><option value="<?= (int)$company['id'] ?>"<?=em_selected((string)($editing['tenant_id'] ?? ''),(string)$company['id'])?>><?=Security::e((string)$company['name'])?></option><?php endforeach;?></select></label>
    <label data-campaign-target="city">Cidade<input name="city" list="market-cities" value="<?=Security::e((string)($editing['city'] ?? ''))?>"><datalist id="market-cities"><?php foreach($cities as $city):?><option value="<?=Security::e((string)$city)?>"><?php endforeach;?></datalist></label>
    <label data-campaign-target="state">Estado (UF)<input name="state" maxlength="2" value="<?=Security::e((string)($editing['state'] ?? ''))?>"><datalist id="market-states"><?php foreach($states as $state):?><option value="<?=Security::e((string)$state)?>"><?php endforeach;?></datalist></label>
    <label data-campaign-target="plan">Plano<select name="plan_code"><option value="">Selecione</option><?php foreach($plans as $plan):?><option value="<?=Security::e((string)$plan['code'])?>"<?=em_selected((string)($editing['plan_code'] ?? ''),(string)$plan['code'])?>><?=Security::e((string)$plan['name'])?></option><?php endforeach;?></select></label>
    <label>Prioridade<input type="number" name="priority" min="-100000" max="100000" value="<?= (int)($editing['priority'] ?? 0) ?>"><small>Maior prioridade vence quando duas campanhas se sobrepõem.</small></label>
    <label>Início<input type="datetime-local" name="starts_at" value="<?=Security::e(!empty($editing['starts_at'])?date('Y-m-d\TH:i',strtotime((string)$editing['starts_at'])):'')?>"></label>
    <label>Fim<input type="datetime-local" name="ends_at" value="<?=Security::e(!empty($editing['ends_at'])?date('Y-m-d\TH:i',strtotime((string)$editing['ends_at'])):'')?>"></label>
    <div class="actions"><button class="button primary" type="submit">Salvar campanha</button><?php if($editing):?><a class="button secondary" href="<?=Security::e(app_url('?route=marketplace-campaigns'))?>">Nova</a><?php endif;?></div>
  </form>
</section>
<section class="card">
  <span class="eyebrow">REGRA FINANCEIRA</span><h3>Como a campanha afeta a comissão</h3>
  <p>Depois de criar a campanha, abra <strong>Taxas e regras</strong> e crie uma regra do tipo <strong>Campanha</strong> usando exatamente o mesmo código.</p>
  <p class="muted">Exemplo: campanha <strong>LANCAMENTO-RJ</strong> + regra de campanha <strong>3%</strong>. O pedido recebe o código e a comissão de 3% fica registrada no snapshot daquele pedido.</p>
  <a class="button primary" href="<?=Security::e(app_url('?route=marketplace-finance&tab=rules'))?>">Configurar taxa da campanha</a>
</section>
</div>
<section class="card" style="margin-top:16px">
  <div class="section-head"><div><span class="eyebrow">ATRIBUIÇÕES</span><h3>Campanhas configuradas</h3></div></div>
  <?php if(!$campaigns):?><div class="empty-state"><strong>Nenhuma campanha configurada.</strong><span>Sem campanha ativa, o pedido utiliza as regras normais de comissão.</span></div><?php else:?><div class="table-wrap"><table><thead><tr><th>Campanha</th><th>Abrangência</th><th>Período</th><th>Prioridade</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach($campaigns as $campaign):
    $scope=(string)$campaign['scope_type'];
    $target=match($scope){'tenant'=>(string)($campaign['tenant_name']??'Empresa removida'),'city'=>(string)($campaign['city']??''),'state'=>(string)($campaign['state']??''),'plan'=>(string)($campaign['plan_code']??''),default=>'Todas as empresas'};
    $period=($campaign['starts_at']?date('d/m/Y H:i',strtotime((string)$campaign['starts_at'])):'Agora').' → '.($campaign['ends_at']?date('d/m/Y H:i',strtotime((string)$campaign['ends_at'])):'Sem data final');
  ?><tr><td><strong><?=Security::e((string)$campaign['name'])?></strong><br><small><?=Security::e((string)$campaign['campaign_code'])?></small></td><td><?=Security::e($scopeLabels[$scope]??$scope)?><br><small><?=Security::e($target)?></small></td><td><?=Security::e($period)?></td><td><?= (int)$campaign['priority'] ?></td><td><span class="badge <?= (int)$campaign['active']===1?'success':'muted' ?>"><?= (int)$campaign['active']===1?'Ativa':'Pausada' ?></span></td><td><div class="actions"><a class="button secondary small" href="<?=Security::e(app_url('?route=marketplace-campaigns&edit='.(int)$campaign['id']))?>">Editar</a><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=em_csrf()?>"><input type="hidden" name="action" value="campaign-toggle"><input type="hidden" name="id" value="<?= (int)$campaign['id'] ?>"><input type="hidden" name="active" value="<?= (int)$campaign['active']===1?0:1 ?>"><button class="button secondary small" type="submit"><?= (int)$campaign['active']===1?'Pausar':'Ativar' ?></button></form></div></td></tr><?php endforeach;?>
  </tbody></table></div><?php endif;?>
</section>
<script>
(()=>{const select=document.querySelector('[data-campaign-scope]');const refresh=()=>{document.querySelectorAll('[data-campaign-target]').forEach(el=>{el.style.display=el.dataset.campaignTarget===select?.value?'grid':'none'})};select?.addEventListener('change',refresh);refresh()})();
</script>
<?php em_footer();
