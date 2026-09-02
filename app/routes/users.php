<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\NfcDeviceService;

Auth::requirePermission('users.manage');
$tenantId=em_require_tenant();
$roles=['admin','manager','cashier','waiter','kitchen','delivery','promoter'];
$paymentRoles=['admin','manager','cashier'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $name=trim((string)($_POST['name']??''));
        $email=mb_strtolower(trim((string)($_POST['email']??'')));
        $phone=trim((string)($_POST['phone']??''));
        $role=(string)($_POST['role']??'waiter');
        $status=(string)($_POST['status']??'active');
        $password=(string)($_POST['password']??'');
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,$roles,true)||!in_array($status,['active','blocked'],true))exit('Usuário inválido.');
        if($id===Auth::id()&&($status==='blocked'||$role!=='admin')&&Auth::role()==='admin')exit('Você não pode bloquear ou remover sua própria função administrativa.');

        $pdo->beginTransaction();
        try{
            if($id){
                $u=$pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=? FOR UPDATE');
                $u->execute([$id,$tenantId]);
                if(!$u->fetchColumn())throw new RuntimeException('Usuário não encontrado.');
                if($password!==''&&strlen($password)<10)throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');
                if($password!==''){
                    $s=$pdo->prepare('UPDATE users SET name=?,email=?,phone=?,role=?,status=?,password_hash=? WHERE id=? AND tenant_id=?');
                    $s->execute([$name,$email,$phone?:null,$role,$status,password_hash($password,PASSWORD_DEFAULT),$id,$tenantId]);
                }else{
                    $s=$pdo->prepare('UPDATE users SET name=?,email=?,phone=?,role=?,status=? WHERE id=? AND tenant_id=?');
                    $s->execute([$name,$email,$phone?:null,$role,$status,$id,$tenantId]);
                }
            }else{
                if(strlen($password)<10)throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');
                $s=$pdo->prepare('INSERT INTO users (tenant_id,name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?,?)');
                $s->execute([$tenantId,$name,$email,$phone?:null,password_hash($password,PASSWORD_DEFAULT),$role,$status]);
                $id=(int)$pdo->lastInsertId();
            }

            $revoked=0;
            if($status==='blocked'||!in_array($role,$paymentRoles,true)){
                $reason=$status==='blocked'?'user_blocked':'payment_role_removed';
                $revoked=(new NfcDeviceService())->revokeForUserChange($pdo,$tenantId,$id,$reason);
            }
            $pdo->commit();

            if($revoked>0)Auth::audit('nfc.revoked_by_user_change','user',(string)$id,['role'=>$role,'status'=>$status,'devices'=>$revoked]);
            Auth::audit('user.saved','user',(string)$id,['role'=>$role,'status'=>$status]);
            em_flash('ok','Usuário salvo.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            em_flash('error',$e->getMessage());
        }
        em_go('users');
    }
}

$editId=(int)($_GET['edit']??0);
$edit=null;
if($editId){$s=$pdo->prepare('SELECT * FROM users WHERE id=? AND tenant_id=?');$s->execute([$editId,$tenantId]);$edit=$s->fetch()?:null;}
$s=$pdo->prepare('SELECT u.*,(SELECT COUNT(*) FROM nfc_devices d WHERE d.user_id=u.id AND d.status="active") active_devices FROM users u WHERE tenant_id=? ORDER BY status,name');$s->execute([$tenantId]);$users=$s->fetchAll();

em_header('Equipe e permissões','users');
?><div class="grid" style="grid-template-columns:minmax(300px,1fr) minmax(0,2fr)"><section class="card"><h2><?= $edit?'Editar usuário':'Novo usuário' ?></h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome<input name="name" required value="<?= Security::e($edit['name']??'') ?>"></label><label class="span-2">E-mail<input type="email" name="email" required value="<?= Security::e($edit['email']??'') ?>"></label><label class="span-2">Telefone<input name="phone" value="<?= Security::e($edit['phone']??'') ?>"></label><label>Função<select name="role"><?php foreach($roles as $r):?><option value="<?= $r ?>"<?= em_selected($edit['role']??'waiter',$r) ?>><?= Security::e($r) ?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="active"<?= em_selected($edit['status']??'active','active') ?>>Ativo</option><option value="blocked"<?= em_selected($edit['status']??'','blocked') ?>>Bloqueado</option></select></label><label class="span-2"><?= $edit?'Nova senha (deixe vazio para manter)':'Senha' ?><input type="password" name="password" minlength="10"<?= $edit?'':' required' ?>></label><button class="primary span-2">Salvar usuário</button><?php if($edit):?><a class="button secondary span-2" href="/?route=users">Cancelar</a><?php endif;?></form><p class="muted">Garçom e entregador não recebem permissão de confirmação de pagamentos. Dispositivos NFC só podem permanecer vinculados a administrador, gerente ou caixa; bloqueio ou mudança para outra função revoga o aparelho automaticamente, com motivo registrado.</p></section><section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Usuário</th><th>Função</th><th>Status</th><th>Último login</th><th>NFC ativos</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?= Security::e($u['name']) ?></strong><br><span class="muted"><?= Security::e($u['email']) ?></span></td><td><span class="badge"><?= Security::e($u['role']) ?></span></td><td><?= Security::e($u['status']) ?></td><td><?= Security::e($u['last_login_at']??'—') ?></td><td><?= (int)$u['active_devices'] ?></td><td><a class="button secondary" href="/?route=users&edit=<?= (int)$u['id'] ?>">Editar</a></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();
