<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

Auth::requirePermission('platform.manage');
if (!Auth::isSuperAdmin()) { http_response_code(403); exit('Acesso restrito ao Super ADM.'); }

$moneyToCents = static function (mixed $value): int {
    $raw = trim((string)$value);
    if ($raw === '') return 0;
    $raw = str_replace(['R$', ' '], '', $raw);
    if (str_contains($raw, ',') && str_contains($raw, '.')) $raw = str_replace('.', '', $raw);
    $raw = str_replace(',', '.', $raw);
    return max(0, (int)round((float)$raw * 100));
};

if (isset($_GET['leave'])) {
    Auth::clearTenantContext();
    em_flash('ok', 'Você saiu do contexto da empresa.');
    em_go('super');
}

$plansReady = true;
try {
    $pdo->query('SELECT 1 FROM saas_plans LIMIT 1');
} catch (Throwable) {
    $plansReady = false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'tenant-select') {
        try {
            Auth::actAsTenant((int)($_POST['tenant_id'] ?? 0));
            em_flash('ok', 'Empresa selecionada.');
            em_go('dashboard');
        } catch (Throwable $e) {
            em_flash('error', $e->getMessage());
            em_go('super');
        }
    }

    if ($action === 'plan-save') {
        if (!$plansReady) { em_flash('error', 'Atualize o banco antes de criar planos.'); em_go('super'); }
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $code = em_slug((string)($_POST['code'] ?? $name));
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 500);
        $monthly = $moneyToCents($_POST['monthly_price'] ?? 0);
        $yearly = $moneyToCents($_POST['yearly_price'] ?? 0);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $features = array_values(array_unique(array_filter(array_map(
            static fn($v) => mb_substr(trim((string)$v), 0, 90),
            (array)($_POST['features'] ?? [])
        ))));
        $limits = [
            'users' => max(0, (int)($_POST['limit_users'] ?? 0)),
            'units' => max(0, (int)($_POST['limit_units'] ?? 0)),
            'products' => max(0, (int)($_POST['limit_products'] ?? 0)),
        ];
        if ($name === '' || $code === '') { em_flash('error', 'Nome e código do plano são obrigatórios.'); em_go('super'); }
        try {
            if ($id > 0) {
                $s = $pdo->prepare('UPDATE saas_plans SET code=?,name=?,description=?,monthly_cents=?,yearly_cents=?,active=?,sort_order=?,features_json=?,limits_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');
                $s->execute([$code,$name,$description,$monthly,$yearly,$active,$sortOrder,json_encode($features,JSON_UNESCAPED_UNICODE),json_encode($limits,JSON_UNESCAPED_UNICODE),$id]);
            } else {
                $s = $pdo->prepare('INSERT INTO saas_plans (code,name,description,monthly_cents,yearly_cents,active,sort_order,features_json,limits_json) VALUES (?,?,?,?,?,?,?,?,?)');
                $s->execute([$code,$name,$description,$monthly,$yearly,$active,$sortOrder,json_encode($features,JSON_UNESCAPED_UNICODE),json_encode($limits,JSON_UNESCAPED_UNICODE)]);
                $id = (int)$pdo->lastInsertId();
            }
            Auth::audit('platform.plan_saved', 'saas_plan', (string)$id, ['code'=>$code,'monthly_cents'=>$monthly,'yearly_cents'=>$yearly]);
            em_flash('ok', 'Plano comercial salvo.');
        } catch (Throwable $e) {
            em_flash('error', 'Não foi possível salvar o plano: '.$e->getMessage());
        }
        em_go('super', ['tab'=>'plans']);
    }

    if ($action === 'plan-toggle') {
        if (!$plansReady) { em_flash('error', 'Atualize o banco antes de alterar planos.'); em_go('super'); }
        $id = (int)($_POST['id'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;
        $pdo->prepare('UPDATE saas_plans SET active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$active,$id]);
        Auth::audit('platform.plan_status', 'saas_plan', (string)$id, ['active'=>$active]);
        em_flash('ok', $active ? 'Plano ativado.' : 'Plano arquivado.');
        em_go('super', ['tab'=>'plans']);
    }

    if ($action === 'tenant-status') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($tenantId < 1 || !in_array($status, ['active','suspended','cancelled'], true)) exit('Alteração inválida.');
        try {
            Database::transaction(function (PDO $tx) use ($tenantId,$status,&$tenant): void {
                $s = $tx->prepare(Database::portableSql($tx, 'SELECT id,name,status FROM tenants WHERE id=? FOR UPDATE'));
                $s->execute([$tenantId]);
                $tenant = $s->fetch();
                if (!$tenant) throw new RuntimeException('Empresa não encontrada.');
                $tx->prepare('UPDATE tenants SET status=? WHERE id=?')->execute([$status,$tenantId]);
                if ($status !== 'active') {
                    $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND revoked_at IS NULL')->execute([$tenantId]);
                    try { $tx->prepare('UPDATE api_refresh_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND revoked_at IS NULL')->execute([$tenantId]); } catch (Throwable) {}
                    $tx->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND status<>"revoked"')->execute([$tenantId]);
                    $tx->prepare('UPDATE nfc_payment_intents SET status="failed" WHERE tenant_id=? AND status="created"')->execute([$tenantId]);
                    $tx->prepare('UPDATE work_shifts SET status="closed",ended_at=CURRENT_TIMESTAMP,closing_notes="Empresa suspensa/cancelada" WHERE tenant_id=? AND status="open"')->execute([$tenantId]);
                }
            });
            if (Auth::actingTenantId() === $tenantId && $status !== 'active') Auth::clearTenantContext();
            Auth::audit('platform.tenant_status','tenant',(string)$tenantId,['from'=>$tenant['status'],'to'=>$status,'name'=>$tenant['name']]);
            em_flash('ok', $status === 'active' ? 'Empresa reativada.' : 'Empresa atualizada e acessos operacionais revogados.');
        } catch (Throwable $e) {
            em_flash('error',$e->getMessage());
        }
        em_go('super', ['tab'=>'companies']);
    }

    if ($action === 'tenant-plan') {
        if (!$plansReady) { em_flash('error', 'Atualize o banco antes de atribuir planos.'); em_go('super'); }
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        $cycle = (string)($_POST['billing_cycle'] ?? 'monthly');
        if (!in_array($cycle, ['monthly','yearly','custom'], true)) $cycle = 'monthly';
        $customPrice = $cycle === 'custom' ? $moneyToCents($_POST['custom_price'] ?? 0) : null;
        try {
            Database::transaction(function(PDO $tx) use ($tenantId,$planId,$cycle,$customPrice,&$plan): void {
                $p = $tx->prepare('SELECT id,code,name FROM saas_plans WHERE id=?');
                $p->execute([$planId]);
                $plan = $p->fetch();
                if (!$plan) throw new RuntimeException('Plano não encontrado.');
                $t = $tx->prepare('SELECT id FROM tenants WHERE id=?');
                $t->execute([$tenantId]);
                if (!$t->fetchColumn()) throw new RuntimeException('Empresa não encontrada.');
                $tx->prepare('UPDATE tenants SET plan=? WHERE id=?')->execute([$plan['code'],$tenantId]);
                $exists = $tx->prepare('SELECT id FROM tenant_subscriptions WHERE tenant_id=?');
                $exists->execute([$tenantId]);
                $subscriptionId = $exists->fetchColumn();
                if ($subscriptionId) {
                    $tx->prepare('UPDATE tenant_subscriptions SET plan_id=?,status="active",billing_cycle=?,custom_price_cents=?,ends_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=?')->execute([$planId,$cycle,$customPrice,$tenantId]);
                } else {
                    $tx->prepare('INSERT INTO tenant_subscriptions (tenant_id,plan_id,status,billing_cycle,custom_price_cents) VALUES (?,? ,"active",?,?)')->execute([$tenantId,$planId,$cycle,$customPrice]);
                }
            });
            Auth::audit('platform.tenant_plan','tenant',(string)$tenantId,['plan'=>$plan['code'],'billing_cycle'=>$cycle]);
            em_flash('ok', 'Plano da empresa atualizado para '.$plan['name'].'.');
        } catch (Throwable $e) {
            em_flash('error',$e->getMessage());
        }
        em_go('super', ['tab'=>'companies']);
    }

    if ($action === 'tenant-create') {
        $tenantName = trim((string)($_POST['tenant_name'] ?? ''));
        $slug = em_slug((string)($_POST['slug'] ?? $tenantName));
        $businessType = (string)($_POST['business_type'] ?? 'full');
        if ($tenantName === '') exit('Nome da empresa é obrigatório.');
        if (!in_array($businessType,['full','menu','event'],true)) $businessType='full';
        try {
            $plan = null;
            if ($plansReady) {
                $ps = $pdo->prepare('SELECT id,code FROM saas_plans WHERE id=? AND active=1');
                $ps->execute([(int)($_POST['plan_id'] ?? 0)]);
                $plan = $ps->fetch() ?: null;
            }
            $planCode = (string)($plan['code'] ?? 'premium');
            $planId = (int)($plan['id'] ?? 0);
            $created = Database::transaction(function(PDO $tx) use ($tenantName,$slug,$planCode,$planId,$businessType,$plansReady): array {
                $finalSlug = $slug; $i = 1;
                while (true) {
                    $q = $tx->prepare('SELECT COUNT(*) FROM tenants WHERE slug=?');
                    $q->execute([$finalSlug]);
                    if ((int)$q->fetchColumn() === 0) break;
                    $finalSlug = $slug.'-'.$i++;
                }
                $settings = json_encode(['business_type'=>$businessType,'points_enabled'=>true,'menu_primary_color'=>'#f4b942','menu_layout'=>'cards'],JSON_UNESCAPED_UNICODE);
                $s = $tx->prepare('INSERT INTO tenants (name,slug,plan,status,settings) VALUES (?,?,?,"active",?)');
                $s->execute([$tenantName,$finalSlug,$planCode,$settings]);
                $tenantId = (int)$tx->lastInsertId();
                if ($plansReady && $planId > 0) {
                    $tx->prepare('INSERT INTO tenant_subscriptions (tenant_id,plan_id,status,billing_cycle) VALUES (?,? ,"active","monthly")')->execute([$tenantId,$planId]);
                }
                return ['tenant_id'=>$tenantId,'slug'=>$finalSlug];
            });
            Auth::audit('platform.tenant_created','tenant',(string)$created['tenant_id'],['slug'=>$created['slug'],'plan'=>$planCode,'business_type'=>$businessType]);
            Auth::actAsTenant((int)$created['tenant_id']);
            em_flash('ok','Empresa criada. Agora cadastre o administrador em Equipe.');
            em_go('users');
        } catch (Throwable $e) {
            em_flash('error',$e->getMessage());
            em_go('super', ['tab'=>'companies']);
        }
    }
}

$plans = [];
if ($plansReady) {
    $plans = $pdo->query('SELECT * FROM saas_plans ORDER BY active DESC,sort_order,name')->fetchAll();
}
$activePlans = array_values(array_filter($plans, static fn(array $p): bool => (int)$p['active'] === 1));

$stats = [
    'tenants'=>(int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
    'active'=>(int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE status="active"')->fetchColumn(),
    'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE role<>"super_admin"')->fetchColumn(),
    'sales'=>(int)$pdo->query('SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE payment_status="paid"')->fetchColumn(),
];
$stats['mrr'] = 0;
if ($plansReady) {
    try {
        $stats['mrr'] = (int)$pdo->query('SELECT COALESCE(SUM(CASE WHEN ts.billing_cycle="yearly" THEN COALESCE(ts.custom_price_cents,p.yearly_cents)/12 ELSE COALESCE(ts.custom_price_cents,p.monthly_cents) END),0) FROM tenant_subscriptions ts JOIN saas_plans p ON p.id=ts.plan_id JOIN tenants t ON t.id=ts.tenant_id WHERE ts.status="active" AND t.status="active"')->fetchColumn();
    } catch (Throwable) {}
}

$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT t.*,(SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) users_count,(SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id) orders_count,(SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=t.id AND o.payment_status="paid") paid_total';
if ($plansReady) $sql .= ',ts.plan_id subscription_plan_id,ts.billing_cycle,ts.custom_price_cents,sp.name subscription_plan_name';
$sql .= ' FROM tenants t';
if ($plansReady) $sql .= ' LEFT JOIN tenant_subscriptions ts ON ts.tenant_id=t.id LEFT JOIN saas_plans sp ON sp.id=ts.plan_id';
$args=[];
if($q!==''){$sql.=' WHERE t.name LIKE ? OR t.slug LIKE ?';$like='%'.$q.'%';$args=[$like,$like];}
$sql.=' ORDER BY t.id DESC LIMIT 300';
$s=$pdo->prepare($sql);$s->execute($args);$tenants=$s->fetchAll();

$editPlanId = (int)($_GET['edit_plan'] ?? 0);
$editPlan = null;
foreach ($plans as $p) if ((int)$p['id'] === $editPlanId) $editPlan = $p;
$editFeatures = $editPlan ? (json_decode((string)($editPlan['features_json'] ?? '[]'), true) ?: []) : [];
$editLimits = $editPlan ? (json_decode((string)($editPlan['limits_json'] ?? '{}'), true) ?: []) : [];
$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab,['overview','plans','companies'],true)) $tab='overview';

em_header('Gestão da plataforma','super');
?>
<section class="page-hero platform-hero">
  <div><span class="eyebrow">EVENTMENU SAAS</span><h2>Controle comercial da plataforma</h2><p>Gerencie clientes, planos, preços e operação sem entrar em telas técnicas.</p></div>
  <div class="hero-actions"><a class="button primary" href="#nova-empresa">+ Nova empresa</a><a class="button secondary" href="<?= Security::e(app_url('?route=super&tab=plans')) ?>">Gerenciar planos</a></div>
</section>
<?php if(!$plansReady):?><div class="alert error"><strong>Atualização necessária.</strong> O módulo comercial de planos ainda não existe neste banco. <a href="<?= Security::e(app_url('update.php')) ?>"><u>Aplicar atualização agora</u></a>.</div><?php endif;?>
<section class="metric-grid">
  <div class="metric-card"><span>Empresas</span><strong><?= $stats['tenants'] ?></strong><small><?= $stats['active'] ?> ativas</small></div>
  <div class="metric-card"><span>Receita recorrente estimada</span><strong><?= em_money($stats['mrr']) ?></strong><small>MRR dos contratos ativos</small></div>
  <div class="metric-card"><span>Usuários de clientes</span><strong><?= $stats['users'] ?></strong><small>Contas operacionais</small></div>
  <div class="metric-card"><span>Volume processado</span><strong><?= em_money($stats['sales']) ?></strong><small>Pedidos pagos</small></div>
</section>
<nav class="subtabs"><a class="<?= $tab==='overview'?'active':'' ?>" href="<?= Security::e(app_url('?route=super&tab=overview')) ?>">Visão geral</a><a class="<?= $tab==='companies'?'active':'' ?>" href="<?= Security::e(app_url('?route=super&tab=companies')) ?>">Empresas</a><a class="<?= $tab==='plans'?'active':'' ?>" href="<?= Security::e(app_url('?route=super&tab=plans')) ?>">Planos e preços</a></nav>

<?php if($tab==='overview'):?>
<div class="grid commercial-grid">
  <section class="card"><span class="eyebrow">CARTEIRA</span><h2>Empresas recentes</h2><p class="muted">Acesse uma empresa para suporte ou acompanhe o plano contratado.</p><?php foreach(array_slice($tenants,0,6) as $t):?><div class="list-row"><div><strong><?= Security::e($t['name']) ?></strong><small><?= Security::e($t['slug']) ?> · <?= Security::e((string)($t['subscription_plan_name']??$t['plan'])) ?></small></div><span class="status-pill <?= Security::e($t['status']) ?>"><?= Security::e($t['status']) ?></span></div><?php endforeach;?></section>
  <section class="card"><span class="eyebrow">PLANOS</span><h2>Portfólio comercial</h2><p class="muted">Preços e benefícios que aparecem na operação comercial do SaaS.</p><div class="plan-mini-grid"><?php foreach(array_slice($activePlans,0,3) as $p):?><article class="plan-mini"><strong><?= Security::e($p['name']) ?></strong><b><?= em_money($p['monthly_cents']) ?><small>/mês</small></b><span><?= Security::e($p['description']??'') ?></span></article><?php endforeach;?></div><a class="button secondary" href="<?= Security::e(app_url('?route=super&tab=plans')) ?>">Editar planos</a></section>
</div>
<?php endif;?>

<?php if($tab==='plans'):?>
<div class="grid admin-two-col">
<section class="card"><span class="eyebrow">PLANO COMERCIAL</span><h2><?= $editPlan?'Editar plano':'Criar novo plano' ?></h2><p class="muted">Defina preço, limites e benefícios. Planos arquivados continuam vinculados aos clientes existentes.</p>
<form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="plan-save"><input type="hidden" name="id" value="<?= (int)($editPlan['id']??0) ?>">
<label class="span-2">Nome do plano<input name="name" required value="<?= Security::e($editPlan['name']??'') ?>" placeholder="Ex.: Essencial"></label><label>Código interno<input name="code" value="<?= Security::e($editPlan['code']??'') ?>" placeholder="essencial"></label><label>Ordem<input type="number" name="sort_order" value="<?= (int)($editPlan['sort_order']??0) ?>"></label>
<label>Mensalidade<input name="monthly_price" inputmode="decimal" value="<?= $editPlan?Security::e(number_format(((int)$editPlan['monthly_cents'])/100,2,',','')):'' ?>" placeholder="149,00"></label><label>Plano anual<input name="yearly_price" inputmode="decimal" value="<?= $editPlan?Security::e(number_format(((int)$editPlan['yearly_cents'])/100,2,',','')):'' ?>" placeholder="1490,00"></label>
<label class="span-2">Descrição<textarea name="description" placeholder="Para quem é este plano?"><?= Security::e($editPlan['description']??'') ?></textarea></label>
<label>Máx. usuários<input type="number" min="0" name="limit_users" value="<?= (int)($editLimits['users']??0) ?>"><small>0 = sem limite comercial</small></label><label>Máx. unidades<input type="number" min="0" name="limit_units" value="<?= (int)($editLimits['units']??0) ?>"></label><label>Máx. produtos<input type="number" min="0" name="limit_products" value="<?= (int)($editLimits['products']??0) ?>"></label><label class="checkbox"><input type="checkbox" name="active"<?= em_checked($editPlan?($editPlan['active']??0):1) ?>> Disponível para venda</label>
<div class="span-2"><span class="field-title">Benefícios do plano</span><div class="check-grid"><?php $featureOptions=['Cardápio digital','Pedidos','Mesas e comandas','Delivery','Estoque','Clientes e pontos','Relatórios','Pagamentos e NFC','Eventos e ingressos','Múltiplas unidades','Auditoria avançada','Personalização premium'];foreach($featureOptions as $feature):?><label class="checkbox"><input type="checkbox" name="features[]" value="<?= Security::e($feature) ?>"<?= em_checked(in_array($feature,$editFeatures,true)) ?>> <?= Security::e($feature) ?></label><?php endforeach;?></div></div>
<button class="primary span-2"><?= $editPlan?'Salvar alterações':'Criar plano' ?></button><?php if($editPlan):?><a class="button secondary span-2" href="<?= Security::e(app_url('?route=super&tab=plans')) ?>">Cancelar edição</a><?php endif;?></form></section>
<section><div class="plan-card-grid"><?php foreach($plans as $p):$features=json_decode((string)($p['features_json']??'[]'),true)?:[];$limits=json_decode((string)($p['limits_json']??'{}'),true)?:[];?><article class="pricing-card<?= (int)$p['active']===0?' is-archived':'' ?>"><div class="pricing-head"><div><span class="eyebrow"><?= (int)$p['active']===1?'ATIVO':'ARQUIVADO' ?></span><h3><?= Security::e($p['name']) ?></h3></div><a class="button secondary compact" href="<?= Security::e(app_url('?route=super&tab=plans&edit_plan='.(int)$p['id'])) ?>">Editar</a></div><p><?= Security::e($p['description']??'') ?></p><div class="price-big"><?= em_money($p['monthly_cents']) ?><small>/mês</small></div><?php if((int)$p['yearly_cents']>0):?><div class="muted"><?= em_money($p['yearly_cents']) ?> por ano</div><?php endif;?><ul><?php foreach($features as $feature):?><li>✓ <?= Security::e($feature) ?></li><?php endforeach;?></ul><div class="limit-line">Usuários: <?= (int)($limits['users']??0)?:'Ilimitado' ?> · Unidades: <?= (int)($limits['units']??0)?:'Ilimitado' ?></div><form method="post" class="actions"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="plan-toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="active" value="<?= (int)$p['active']===1?'':'1' ?>"><?php if((int)$p['active']===1):?><button class="secondary">Arquivar</button><?php else:?><button class="primary">Reativar</button><?php endif;?></form></article><?php endforeach;?></div></section>
</div>
<?php endif;?>

<?php if($tab==='companies'):?>
<section class="card" id="nova-empresa"><div class="section-head"><div><span class="eyebrow">NOVO CLIENTE</span><h2>Criar empresa</h2></div></div><form method="post" class="form-grid company-create"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-create"><label>Empresa<input name="tenant_name" required placeholder="Nome comercial"></label><label>Link público<input name="slug" placeholder="gerado automaticamente"></label><label>Plano<select name="plan_id"><?php foreach($activePlans as $p):?><option value="<?= (int)$p['id'] ?>"><?= Security::e($p['name']) ?> · <?= em_money($p['monthly_cents']) ?>/mês</option><?php endforeach;?></select></label><label>Tipo de operação<select name="business_type"><option value="menu">Cardápio / Restaurante</option><option value="event">Eventos</option><option value="full">Completo</option></select></label><button class="primary span-2">Criar empresa e continuar</button></form></section>
<section class="card" style="margin-top:18px"><div class="section-head"><div><span class="eyebrow">CLIENTES</span><h2>Empresas da plataforma</h2></div><form method="get" class="actions"><input type="hidden" name="route" value="super"><input type="hidden" name="tab" value="companies"><input name="q" value="<?= Security::e($q) ?>" placeholder="Buscar empresa"><button class="secondary">Buscar</button></form></div><div class="table-wrap"><table class="table"><thead><tr><th>Empresa</th><th>Plano</th><th>Status</th><th>Uso</th><th>Volume pago</th><th>Ações</th></tr></thead><tbody><?php foreach($tenants as $t):?><tr><td><strong><?= Security::e($t['name']) ?></strong><br><span class="muted"><?= Security::e($t['slug']) ?></span></td><td><form method="post" class="inline-plan-form"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-plan"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><select name="plan_id"><?php foreach($plans as $p):?><option value="<?= (int)$p['id'] ?>"<?= em_selected($t['subscription_plan_id']??0,$p['id']) ?>><?= Security::e($p['name']) ?></option><?php endforeach;?></select><select name="billing_cycle"><option value="monthly"<?= em_selected($t['billing_cycle']??'monthly','monthly') ?>>Mensal</option><option value="yearly"<?= em_selected($t['billing_cycle']??'','yearly') ?>>Anual</option><option value="custom"<?= em_selected($t['billing_cycle']??'','custom') ?>>Personalizado</option></select><button class="secondary compact">Aplicar</button></form></td><td><span class="status-pill <?= Security::e($t['status']) ?>"><?= Security::e($t['status']) ?></span></td><td><?= (int)$t['users_count'] ?> usuários<br><span class="muted"><?= (int)$t['orders_count'] ?> pedidos</span></td><td><?= em_money($t['paid_total']) ?></td><td><div class="actions"><?php if($t['status']==='active'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-select"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="primary compact">Abrir painel</button></form><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-status"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><select name="status"><option value="active"<?= em_selected($t['status'],'active') ?>>Ativa</option><option value="suspended"<?= em_selected($t['status'],'suspended') ?>>Suspensa</option><option value="cancelled"<?= em_selected($t['status'],'cancelled') ?>>Cancelada</option></select><button class="secondary compact">Salvar</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php em_footer();
