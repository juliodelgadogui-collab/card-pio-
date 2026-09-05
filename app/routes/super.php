<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

Auth::requirePermission('platform.manage');
if(!Auth::isSuperAdmin()){http_response_code(403);exit('Acesso restrito ao Super ADM.');}

if(isset($_GET['leave'])){
    Auth::clearTenantContext();
    em_flash('ok','Você saiu do contexto da empresa.');
    em_go('super');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');

    if($action==='tenant-select'){
        try{Auth::actAsTenant((int)($_POST['tenant_id']??0));em_flash('ok','Empresa selecionada.');em_go('dashboard');}
        catch(Throwable $e){em_flash('error',$e->getMessage());em_go('super');}
    }

    if($action==='tenant-status'){
        $tenantId=(int)($_POST['tenant_id']??0);$status=(string)($_POST['status']??'');
        if($tenantId<1||!in_array($status,['active','suspended','cancelled'],true))exit('Alteração inválida.');
        $s=$pdo->prepare('SELECT id,name,status FROM tenants WHERE id=?');$s->execute([$tenantId]);$tenant=$s->fetch();if(!$tenant)exit('Empresa não encontrada.');
        $pdo->prepare('UPDATE tenants SET status=? WHERE id=?')->execute([$status,$tenantId]);
        if(Auth::actingTenantId()===$tenantId&&$status!=='active')Auth::clearTenantContext();
        Auth::audit('platform.tenant_status','tenant',(string)$tenantId,['from'=>$tenant['status'],'to'=>$status,'name'=>$tenant['name']]);
        em_flash('ok','Status da empresa atualizado.');em_go('super');
    }

    if($action==='tenant-create'){
        $tenantName=trim((string)($_POST['tenant_name']??''));$slug=em_slug((string)($_POST['slug']??$tenantName));$plan=(string)($_POST['plan']??'premium');
        if($tenantName==='')exit('Nome da empresa é obrigatório.');
        if(!in_array($plan,['premium','pro','enterprise','event','menu'],true))$plan='premium';
        try{
            $created=Database::transaction(function(PDO $tx)use($tenantName,$slug,$plan):array{
                $finalSlug=$slug;$i=1;while(true){$q=$tx->prepare('SELECT COUNT(*) FROM tenants WHERE slug=?');$q->execute([$finalSlug]);if((int)$q->fetchColumn()===0)break;$finalSlug=$slug.'-'.$i++;}
                $s=$tx->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,?,"active")');$s->execute([$tenantName,$finalSlug,$plan]);
                return ['tenant_id'=>(int)$tx->lastInsertId(),'slug'=>$finalSlug];
            });
            Auth::audit('platform.tenant_created','tenant',(string)$created['tenant_id'],['slug'=>$created['slug'],'plan'=>$plan]);
            Auth::actAsTenant((int)$created['tenant_id']);
            em_flash('ok','Empresa criada. Agora cadastre o administrador em Equipe.');em_go('users');
        }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('super');}
    }
}

$stats=[
    'tenants'=>(int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
    'active'=>(int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE status="active"')->fetchColumn(),
    'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE role<>"super_admin"')->fetchColumn(),
    'sales'=>(int)$pdo->query('SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE payment_status="paid"')->fetchColumn(),
];
$q=trim((string)($_GET['q']??''));$sql='SELECT t.*,(SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) users_count,(SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id) orders_count,(SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=t.id AND o.payment_status="paid") paid_total FROM tenants t';$args=[];
if($q!==''){$sql.=' WHERE t.name LIKE ? OR t.slug LIKE ?';$like='%'.$q.'%';$args=[$like,$like];}$sql.=' ORDER BY t.id DESC LIMIT 300';$s=$pdo->prepare($sql);$s->execute($args);$tenants=$s->fetchAll();

em_header('Super ADM','super');
?><section class="grid"><div class="card metric"><span class="muted">Empresas</span><strong><?= $stats['tenants'] ?></strong></div><div class="card metric"><span class="muted">Ativas</span><strong><?= $stats['active'] ?></strong></div><div class="card metric"><span class="muted">Usuários</span><strong><?= $stats['users'] ?></strong></div><div class="card metric"><span class="muted">Volume pago</span><strong><?= em_money($stats['sales']) ?></strong></div></section>
<div class="grid" style="grid-template-columns:minmax(300px,1fr) minmax(0,2fr);margin-top:18px">
<section class="card"><h2>Nova empresa</h2><p class="muted">Após criar, você entrará na empresa para cadastrar o administrador em Equipe.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-create"><label class="span-2">Empresa<input name="tenant_name" required></label><label class="span-2">Slug<input name="slug" placeholder="gerado automaticamente"></label><label class="span-2">Plano<select name="plan"><option value="premium">Premium</option><option value="pro">Pro</option><option value="enterprise">Enterprise</option><option value="event">Eventos</option><option value="menu">Cardápio</option></select></label><button class="primary span-2">Criar empresa</button></form></section>
<section class="card"><form method="get" class="actions"><input type="hidden" name="route" value="super"><input name="q" value="<?= Security::e($q) ?>" placeholder="Buscar empresa ou slug"><button class="secondary">Buscar</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Empresa</th><th>Plano</th><th>Status</th><th>Usuários</th><th>Pedidos</th><th>Pago</th><th>Ações</th></tr></thead><tbody><?php foreach($tenants as $t):?><tr><td><strong><?= Security::e($t['name']) ?></strong><br><code><?= Security::e($t['slug']) ?></code></td><td><?= Security::e($t['plan']) ?></td><td><span class="badge"><?= Security::e($t['status']) ?></span></td><td><?= (int)$t['users_count'] ?></td><td><?= (int)$t['orders_count'] ?></td><td><?= em_money($t['paid_total']) ?></td><td><div class="actions"><?php if($t['status']==='active'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-select"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="primary">Entrar</button></form><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-status"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><select name="status"><option value="active"<?= em_selected($t['status'],'active') ?>>Ativa</option><option value="suspended"<?= em_selected($t['status'],'suspended') ?>>Suspensa</option><option value="cancelled"<?= em_selected($t['status'],'cancelled') ?>>Cancelada</option></select><button class="secondary">Salvar</button></form></div></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();
