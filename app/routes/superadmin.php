<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

if (Auth::role() !== 'super_admin') { http_response_code(403); exit('Acesso restrito ao Super ADM.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    try {
        if ($action === 'select-tenant') {
            $tenantId=(int)($_POST['tenant_id']??0);
            Auth::selectTenant($tenantId > 0 ? $tenantId : null);
            Auth::audit('superadmin.tenant_selected','tenant',$tenantId > 0 ? (string)$tenantId : null);
            em_flash('ok',$tenantId > 0 ? 'Empresa selecionada.' : 'Contexto global selecionado.');
            em_go('superadmin');
        }
        if ($action === 'tenant-status') {
            $tenantId=(int)($_POST['tenant_id']??0);$status=(string)($_POST['status']??'active');
            if(!in_array($status,['active','suspended','cancelled'],true)) throw new RuntimeException('Status inválido.');
            $s=$pdo->prepare('UPDATE tenants SET status=? WHERE id=?');$s->execute([$status,$tenantId]);
            if($s->rowCount()!==1) throw new RuntimeException('Empresa não encontrada.');
            if(Auth::tenantId()===$tenantId && $status!=='active') Auth::selectTenant(null);
            Auth::audit('superadmin.tenant_status','tenant',(string)$tenantId,['status'=>$status]);
            em_flash('ok','Status da empresa atualizado.');em_go('superadmin');
        }
        if ($action === 'tenant-create') {
            $company=trim((string)($_POST['company']??''));$admin=trim((string)($_POST['admin_name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));$password=(string)($_POST['password']??'');
            if($company===''||$admin===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10) throw new RuntimeException('Preencha empresa, administrador, e-mail válido e senha com 10+ caracteres.');
            $slug=em_slug($company).'-'.substr(bin2hex(random_bytes(3)),0,6);
            $pdo->beginTransaction();
            try {
                $s=$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?, ?,"premium","active")');$s->execute([$company,$slug]);$tenantId=(int)$pdo->lastInsertId();
                $u=$pdo->prepare('INSERT INTO users (tenant_id,name,email,password_hash,role,status) VALUES (?,?,?,?,"admin","active")');$u->execute([$tenantId,$admin,$email,password_hash($password,PASSWORD_DEFAULT)]);
                $pdo->commit();
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
            Auth::audit('superadmin.tenant_created','tenant',(string)$tenantId,['admin_email'=>$email]);
            em_flash('ok','Empresa e administrador criados.');em_go('superadmin');
        }
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); em_flash('error',$e->getMessage()); em_go('superadmin'); }
}

$s=$pdo->query('SELECT t.*,(SELECT COUNT(*) FROM users u WHERE u.tenant_id=t.id) users_count,(SELECT COUNT(*) FROM orders o WHERE o.tenant_id=t.id) orders_count,(SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=t.id AND o.payment_status="paid") paid_total FROM tenants t ORDER BY t.id DESC');$tenants=$s->fetchAll();
$selected=Auth::tenantId();
em_header('Super ADM','superadmin');
?><div class="grid" style="grid-template-columns:minmax(300px,1fr) minmax(0,2fr)"><section class="card"><h2>Nova empresa</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-create"><label class="span-2">Empresa<input name="company" required></label><label class="span-2">Administrador<input name="admin_name" required></label><label class="span-2">E-mail<input type="email" name="email" required></label><label class="span-2">Senha inicial<input type="password" name="password" minlength="10" required></label><button class="primary span-2">Criar empresa</button></form><hr style="border-color:var(--line);margin:24px 0"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="select-tenant"><input type="hidden" name="tenant_id" value="0"><button class="secondary">Voltar ao contexto global</button></form></section><section class="card"><div class="section-head"><h2>Empresas</h2><span class="muted"><?= count($tenants) ?> cadastradas</span></div><div class="table-wrap"><table class="table"><thead><tr><th>Empresa</th><th>Status</th><th>Usuários</th><th>Pedidos</th><th>Recebido</th><th>Ações</th></tr></thead><tbody><?php foreach($tenants as $t):?><tr><td><strong><?= Security::e($t['name']) ?></strong><br><code><?= Security::e($t['slug']) ?></code><?= $selected===(int)$t['id']?'<br><span class="badge">Selecionada</span>':'' ?></td><td><?= Security::e($t['status']) ?></td><td><?= (int)$t['users_count'] ?></td><td><?= (int)$t['orders_count'] ?></td><td><?= em_money($t['paid_total']) ?></td><td><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="select-tenant"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><button class="secondary">Entrar</button></form><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="tenant-status"><input type="hidden" name="tenant_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="status" value="<?= $t['status']==='active'?'suspended':'active' ?>"><button class="secondary"><?= $t['status']==='active'?'Suspender':'Ativar' ?></button></form></div></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();
