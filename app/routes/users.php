<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\PermissionCatalog;
use EventMenu\Core\Security;

Auth::requirePermission('users.manage');$tenantId=em_require_tenant();
$roles=['admin','manager','cashier','attendant','waiter','kitchen','delivery','promoter'];
$roleLabels=['admin'=>'Administrador','manager'=>'Gerente','cashier'=>'Caixa','attendant'=>'Balconista','waiter'=>'Garçom','kitchen'=>'Cozinha','delivery'=>'Entregador','promoter'=>'Promotor'];
$permissionLabels=PermissionCatalog::labels();
$uq=$pdo->prepare('SELECT id,name,code,active FROM operating_units WHERE tenant_id=? ORDER BY active DESC,name');$uq->execute([$tenantId]);$unitRows=$uq->fetchAll();$validUnitIds=array_map('intval',array_column(array_filter($unitRows,fn($u)=>(int)$u['active']===1),'id'));

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    if($action==='save'){
        $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$email=mb_strtolower(trim((string)($_POST['email']??'')));$phone=trim((string)($_POST['phone']??''));$role=(string)($_POST['role']??'waiter');$status=(string)($_POST['status']??'active');$password=(string)($_POST['password']??'');
        $selected=array_values(array_intersect(PermissionCatalog::all(),array_map('strval',(array)($_POST['permissions']??[]))));
        $selectedUnits=array_values(array_unique(array_intersect($validUnitIds,array_map('intval',(array)($_POST['unit_ids']??[])))));$defaultUnitId=(int)($_POST['default_unit_id']??0);if($defaultUnitId&&!in_array($defaultUnitId,$selectedUnits,true))$defaultUnitId=0;
        if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,$roles,true)||!in_array($status,['active','blocked'],true))exit('Usuário inválido.');
        if($id===Auth::id()&&($status==='blocked'||$role!=='admin')&&Auth::role()==='admin')exit('Você não pode bloquear ou remover sua própria função administrativa.');
        try{
            Database::transaction(function(PDO $tx)use(&$id,$tenantId,$name,$email,$phone,$role,$status,$password,$selected,$selectedUnits,$defaultUnitId):void{
                if($id){
                    $u=$tx->prepare(Database::portableSql($tx,'SELECT id FROM users WHERE id=? AND tenant_id=? FOR UPDATE'));$u->execute([$id,$tenantId]);if(!$u->fetchColumn())throw new RuntimeException('Usuário não encontrado.');
                    if($password!==''&&strlen($password)<10)throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');
                    if($password!==''){$s=$tx->prepare('UPDATE users SET name=?,email=?,phone=?,role=?,status=?,password_hash=? WHERE id=? AND tenant_id=?');$s->execute([$name,$email,$phone?:null,$role,$status,password_hash($password,PASSWORD_DEFAULT),$id,$tenantId]);}
                    else{$s=$tx->prepare('UPDATE users SET name=?,email=?,phone=?,role=?,status=? WHERE id=? AND tenant_id=?');$s->execute([$name,$email,$phone?:null,$role,$status,$id,$tenantId]);}
                }else{
                    if(strlen($password)<10)throw new RuntimeException('A senha precisa ter pelo menos 10 caracteres.');
                    $s=$tx->prepare('INSERT INTO users (tenant_id,name,email,phone,password_hash,role,status) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$name,$email,$phone?:null,password_hash($password,PASSWORD_DEFAULT),$role,$status]);$id=(int)$tx->lastInsertId();
                }

                $defaults=array_fill_keys(PermissionCatalog::rolePermissions($role),true);$chosen=array_fill_keys($selected,true);
                $tx->prepare('DELETE FROM user_permission_overrides WHERE tenant_id=? AND user_id=?')->execute([$tenantId,$id]);
                $ins=$tx->prepare('INSERT INTO user_permission_overrides (tenant_id,user_id,permission,allowed) VALUES (?,?,?,?)');
                foreach(PermissionCatalog::all() as $permission){$default=isset($defaults[$permission]);$allowed=isset($chosen[$permission]);if($default===$allowed)continue;$ins->execute([$tenantId,$id,$permission,$allowed?1:0]);}

                $tx->prepare('DELETE FROM user_unit_access WHERE tenant_id=? AND user_id=?')->execute([$tenantId,$id]);
                if($selectedUnits){$valid=$tx->prepare('SELECT id FROM operating_units WHERE tenant_id=? AND active=1');$valid->execute([$tenantId]);$allowedUnits=array_map('intval',array_column($valid->fetchAll(),'id'));$unitIns=$tx->prepare('INSERT INTO user_unit_access (tenant_id,user_id,unit_id,is_default) VALUES (?,?,?,?)');foreach($selectedUnits as $unitId){if(!in_array($unitId,$allowedUnits,true))throw new RuntimeException('Unidade inválida.');$unitIns->execute([$tenantId,$id,$unitId,$defaultUnitId===$unitId?1:0]);}}

                if($status==='blocked'){
                    $tx->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND revoked_at IS NULL')->execute([$tenantId,$id]);
                    $tx->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND user_id=? AND status<>"revoked"')->execute([$tenantId,$id]);
                    $tx->prepare('UPDATE nfc_payment_intents SET status="failed" WHERE tenant_id=? AND user_id=? AND status="created"')->execute([$tenantId,$id]);
                    $tx->prepare('UPDATE work_shifts SET status="closed",ended_at=CURRENT_TIMESTAMP,closing_notes="Usuário bloqueado" WHERE tenant_id=? AND user_id=? AND status="open"')->execute([$tenantId,$id]);
                }
            });
            Auth::audit('user.saved','user',(string)$id,['role'=>$role,'status'=>$status,'effective_permissions'=>$selected,'unit_ids'=>$selectedUnits,'default_unit_id'=>$defaultUnitId?:null]);
            em_flash('ok',$status==='blocked'?'Usuário bloqueado; app, NFC e turno foram encerrados.':'Usuário, permissões e unidades salvos.');
        }catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('users');
    }
}

$editId=(int)($_GET['edit']??0);$edit=null;$selectedPermissions=[];$selectedUnits=[];$defaultUnitId=0;
if($editId){
    $s=$pdo->prepare('SELECT * FROM users WHERE id=? AND tenant_id=?');$s->execute([$editId,$tenantId]);$edit=$s->fetch()?:null;
    if($edit){$selectedPermissions=PermissionCatalog::effectiveForUser($tenantId,(int)$edit['id'],(string)$edit['role']);$u=$pdo->prepare('SELECT unit_id,is_default FROM user_unit_access WHERE tenant_id=? AND user_id=?');$u->execute([$tenantId,$editId]);foreach($u->fetchAll() as $row){$selectedUnits[]=(int)$row['unit_id'];if((int)$row['is_default'])$defaultUnitId=(int)$row['unit_id'];}}
}else{$selectedPermissions=PermissionCatalog::rolePermissions('waiter');}
$s=$pdo->prepare('SELECT u.*,(SELECT COUNT(*) FROM nfc_devices d WHERE d.user_id=u.id AND d.status="active") active_devices,(SELECT COUNT(*) FROM api_tokens at WHERE at.user_id=u.id AND at.revoked_at IS NULL AND at.expires_at>CURRENT_TIMESTAMP) active_app_tokens,(SELECT COUNT(*) FROM work_shifts ws WHERE ws.user_id=u.id AND ws.status="open") open_shifts FROM users u WHERE tenant_id=? ORDER BY status,name');$s->execute([$tenantId]);$users=$s->fetchAll();
$unitMap=[];$q=$pdo->prepare('SELECT uua.user_id,ou.name,uua.is_default FROM user_unit_access uua JOIN operating_units ou ON ou.id=uua.unit_id WHERE uua.tenant_id=? ORDER BY uua.user_id,uua.is_default DESC,ou.name');$q->execute([$tenantId]);foreach($q->fetchAll() as $row)$unitMap[(int)$row['user_id']][]=(string)$row['name'].((int)$row['is_default']?' ★':'');
$roleDefaults=[];foreach($roles as $r)$roleDefaults[$r]=PermissionCatalog::rolePermissions($r);

em_header('Equipe e permissões','users');
?><div class="grid" style="grid-template-columns:minmax(330px,1fr) minmax(0,2fr)"><section class="card"><h2><?= $edit?'Editar usuário':'Novo usuário' ?></h2><form method="post" class="form-grid" id="user-form"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome<input name="name" required value="<?= Security::e($edit['name']??'') ?>"></label><label class="span-2">E-mail<input type="email" name="email" required value="<?= Security::e($edit['email']??'') ?>"></label><label class="span-2">Telefone<input name="phone" value="<?= Security::e($edit['phone']??'') ?>"></label><label>Função-base<select name="role" id="user-role"><?php foreach($roles as $r):?><option value="<?= $r ?>"<?= em_selected($edit['role']??'waiter',$r) ?>><?= Security::e($roleLabels[$r]??$r) ?></option><?php endforeach;?></select></label><label>Status<select name="status"><option value="active"<?= em_selected($edit['status']??'active','active') ?>>Ativo</option><option value="blocked"<?= em_selected($edit['status']??'','blocked') ?>>Bloqueado</option></select></label><label class="span-2"><?= $edit?'Nova senha (deixe vazio para manter)':'Senha' ?><input type="password" name="password" minlength="10"<?= $edit?'':' required' ?>></label>
<?php if($unitRows):?><div class="span-2"><h3>Unidades permitidas</h3><p class="muted">Sem nenhuma marcada = acesso a todas as unidades ativas. Marcando unidades, o funcionário fica restrito a elas no EventMenu GO.</p><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px"><?php foreach($unitRows as $unit):if(!(int)$unit['active']&&!in_array((int)$unit['id'],$selectedUnits,true))continue;?><label class="checkbox"><input type="checkbox" name="unit_ids[]" value="<?= (int)$unit['id'] ?>"<?= in_array((int)$unit['id'],$selectedUnits,true)?' checked':'' ?>> <?= Security::e($unit['name']) ?><?= !(int)$unit['active']?' (inativa)':'' ?></label><?php endforeach;?></div><label style="margin-top:10px">Unidade padrão<select name="default_unit_id"><option value="0">Automática / escolher no app</option><?php foreach($unitRows as $unit):if(!(int)$unit['active'])continue;?><option value="<?= (int)$unit['id'] ?>"<?= em_selected($defaultUnitId,(int)$unit['id']) ?>><?= Security::e($unit['name']) ?></option><?php endforeach;?></select></label></div><?php endif;?>
<div class="span-2"><h3>Permissões efetivas</h3><p class="muted">O cargo-base é apenas um preset. Marque exatamente o que este funcionário poderá fazer no painel e no EventMenu GO.</p><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:8px"><?php foreach($permissionLabels as $permission=>$label):?><label class="checkbox"><input class="perm-check" type="checkbox" name="permissions[]" value="<?= Security::e($permission) ?>"<?= in_array($permission,$selectedPermissions,true)?' checked':'' ?>> <?= Security::e($label) ?></label><?php endforeach;?></div><button type="button" class="secondary" id="apply-role" style="margin-top:10px">Aplicar preset da função</button></div><button class="primary span-2">Salvar usuário</button><?php if($edit):?><a class="button secondary span-2" href="<?= Security::e(app_url('?route=users')) ?>">Cancelar</a><?php endif;?></form><p class="muted">Alterar permissão ou unidade tem efeito no servidor. O funcionário não consegue liberar funções ou filiais modificando o aplicativo.</p></section><section class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Usuário</th><th>Função-base</th><th>Unidades</th><th>Status</th><th>Turno</th><th>App</th><th>NFC</th><th></th></tr></thead><tbody><?php foreach($users as $u):?><tr><td><strong><?= Security::e($u['name']) ?></strong><br><span class="muted"><?= Security::e($u['email']) ?></span></td><td><span class="badge"><?= Security::e($roleLabels[$u['role']]??$u['role']) ?></span></td><td><?= Security::e(isset($unitMap[(int)$u['id']])?implode(', ',$unitMap[(int)$u['id']]):'Todas') ?></td><td><?= Security::e($u['status']) ?></td><td><?= (int)$u['open_shifts']?'Aberto':'Fechado' ?></td><td><?= (int)$u['active_app_tokens'] ?></td><td><?= (int)$u['active_devices'] ?></td><td><a class="button secondary" href="<?= Security::e(app_url('?route=users&edit='.(int)$u['id'])) ?>">Editar</a></td></tr><?php endforeach;?></tbody></table></div></section></div>
<script>const roleDefaults=<?= json_encode($roleDefaults,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;document.getElementById('apply-role')?.addEventListener('click',()=>{const role=document.getElementById('user-role').value;const set=new Set(roleDefaults[role]||[]);document.querySelectorAll('.perm-check').forEach(el=>el.checked=set.has(el.value));});</script><?php em_footer();
