<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OperatingUnitService;

Auth::requirePermission('settings.manage');$tenantId=em_require_tenant();$service=new OperatingUnitService();
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    if($action==='save'){
        try{
            $unit=$service->save(
                (int)($_POST['id']??0)?:null,
                (string)($_POST['name']??''),
                (string)($_POST['code']??''),
                (string)($_POST['address']??''),
                !empty($_POST['active'])
            );
            em_flash('ok','Unidade '.Security::e((string)$unit['name']).' salva.');
        }catch(Throwable $e){em_flash('error',$e->getMessage());}
        em_go('units');
    }
}
$editId=(int)($_GET['edit']??0);$edit=null;if($editId){$s=$pdo->prepare('SELECT * FROM operating_units WHERE id=? AND tenant_id=?');$s->execute([$editId,$tenantId]);$edit=$s->fetch()?:null;}
$units=$service->listAll();
$counts=[];if($units){$ids=array_map('intval',array_column($units,'id'));$marks=implode(',',array_fill(0,count($ids),'?'));
    $q=$pdo->prepare('SELECT unit_id,COUNT(*) qty FROM user_unit_access WHERE tenant_id=? AND unit_id IN ('.$marks.') GROUP BY unit_id');$q->execute(array_merge([$tenantId],$ids));foreach($q->fetchAll() as $row)$counts[(int)$row['unit_id']]['users']=(int)$row['qty'];
    $q=$pdo->prepare('SELECT unit_id,COUNT(*) qty FROM restaurant_tables WHERE tenant_id=? AND unit_id IN ('.$marks.') GROUP BY unit_id');$q->execute(array_merge([$tenantId],$ids));foreach($q->fetchAll() as $row)$counts[(int)$row['unit_id']]['tables']=(int)$row['qty'];
    $q=$pdo->prepare('SELECT unit_id,COUNT(*) qty FROM work_shifts WHERE tenant_id=? AND status="open" AND unit_id IN ('.$marks.') GROUP BY unit_id');$q->execute(array_merge([$tenantId],$ids));foreach($q->fetchAll() as $row)$counts[(int)$row['unit_id']]['shifts']=(int)$row['qty'];
}
em_header('Unidades','units');
?><div class="grid" style="grid-template-columns:minmax(320px,1fr) minmax(0,2fr)"><section class="card"><h2><?= $edit?'Editar unidade':'Nova unidade' ?></h2><p class="muted">Unidades separam turnos, mesas, pedidos e caixas dentro da mesma empresa.</p><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome<input name="name" required placeholder="Ex.: Centro" value="<?= Security::e($edit['name']??'') ?>"></label><label class="span-2">Código<input name="code" placeholder="centro" value="<?= Security::e($edit['code']??'') ?>"><span class="muted">Se vazio, será criado pelo nome.</span></label><label class="span-2">Endereço<input name="address" placeholder="Rua, número, bairro" value="<?= Security::e($edit['address']??'') ?>"></label><label class="checkbox span-2"><input type="checkbox" name="active" value="1"<?= em_checked((int)($edit['active']??1)) ?>> Unidade ativa</label><button class="primary span-2">Salvar unidade</button><?php if($edit):?><a class="button secondary span-2" href="<?= Security::e(app_url('?route=units')) ?>">Cancelar</a><?php endif;?></form><p class="muted">Se nenhuma unidade for cadastrada, o EventMenu continua operando normalmente como unidade Principal.</p></section><section class="card"><div class="section-head"><h2>Unidades da empresa</h2><span class="muted"><?= count($units) ?></span></div><div class="table-wrap"><table class="table"><thead><tr><th>Unidade</th><th>Status</th><th>Equipe</th><th>Mesas</th><th>Turnos abertos</th><th></th></tr></thead><tbody><?php foreach($units as $u):$c=$counts[(int)$u['id']]??[];?><tr><td><strong><?= Security::e($u['name']) ?></strong><br><span class="muted"><?= Security::e($u['code']) ?><?= !empty($u['address'])?' · '.Security::e($u['address']):'' ?></span></td><td><?= (int)$u['active']?'Ativa':'Inativa' ?></td><td><?= (int)($c['users']??0) ?></td><td><?= (int)($c['tables']??0) ?></td><td><?= (int)($c['shifts']??0) ?></td><td><a class="button secondary" href="<?= Security::e(app_url('?route=units&edit='.(int)$u['id'])) ?>">Editar</a></td></tr><?php endforeach;?><?php if(!$units):?><tr><td colspan="6">Nenhuma unidade cadastrada. O app usará “Principal”.</td></tr><?php endif;?></tbody></table></div></section></div><?php em_footer();
