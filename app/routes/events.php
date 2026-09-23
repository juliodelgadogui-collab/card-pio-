<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;

Auth::requirePermission('events.manage');
$tenantId=em_require_tenant();
$tenantStmt=$pdo->prepare('SELECT slug FROM tenants WHERE id=? LIMIT 1');
$tenantStmt->execute([$tenantId]);
$tenantSlug=(string)($tenantStmt->fetchColumn()?:'');
$unitStmt=$pdo->prepare('SELECT id,name,code,address FROM operating_units WHERE tenant_id=? AND active=1 ORDER BY name');
$unitStmt->execute([$tenantId]);
$operatingUnits=$unitStmt->fetchAll();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $action=(string)($_POST['action']??'');
    if($action==='event-save'){
        $id=(int)($_POST['id']??0);
        $name=mb_substr(trim((string)($_POST['name']??'')),0,180);
        $starts=(string)($_POST['starts_at']??'');
        $status=(string)($_POST['status']??'draft');
        $barUnitId=(int)($_POST['bar_unit_id']??0);
        $barUnitId=$barUnitId>0?$barUnitId:null;
        if($name===''||$starts===''||!in_array($status,['draft','published','closed','cancelled'],true)){
            em_flash('error','Preencha nome, data e status válidos.');
            em_go('events');
        }
        $slugInput=trim((string)($_POST['slug']??''));
        $baseSlug=em_slug($slugInput!==''?$slugInput:$name);
        $ends=(string)($_POST['ends_at']??'');
        $starts=str_replace('T',' ',$starts);
        $ends=$ends?str_replace('T',' ',$ends):null;
        if(strtotime($starts)===false||($ends&&strtotime($ends)===false)||($ends&&strtotime($ends)<=strtotime($starts))){
            em_flash('error','Revise as datas do evento.');
            em_go('events',['edit'=>$id]);
        }
        try{
            Database::transaction(function(PDO $tx)use(&$id,$tenantId,$name,$baseSlug,$starts,$ends,$status,$barUnitId): void {
                $description=mb_substr(trim((string)($_POST['description']??'')),0,5000);
                $venue=mb_substr(trim((string)($_POST['venue']??'')),0,180);
                $address=mb_substr(trim((string)($_POST['address']??'')),0,300);
                $banner=trim((string)($_POST['banner_url']??''))?:null;
                if($banner&&!filter_var($banner,FILTER_VALIDATE_URL))throw new RuntimeException('URL do banner inválida.');
                if($barUnitId){
                    $unit=$tx->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');
                    $unit->execute([$barUnitId,$tenantId]);
                    if(!$unit->fetchColumn())throw new RuntimeException('A unidade escolhida para o bar não está disponível.');
                }

                $finalSlug=$baseSlug;
                $suffix=2;
                while(true){
                    $sql='SELECT COUNT(*) FROM events WHERE tenant_id=? AND slug=?';
                    $args=[$tenantId,$finalSlug];
                    if($id>0){$sql.=' AND id<>?';$args[]=$id;}
                    $check=$tx->prepare($sql);
                    $check->execute($args);
                    if((int)$check->fetchColumn()===0)break;
                    $finalSlug=$baseSlug.'-'.$suffix++;
                }

                if($id){
                    $oldStmt=$tx->prepare(Database::portableSql($tx,'SELECT * FROM events WHERE id=? AND tenant_id=? FOR UPDATE'));
                    $oldStmt->execute([$id,$tenantId]);
                    $old=$oldStmt->fetch();
                    if(!$old)throw new RuntimeException('Evento não encontrado.');
                    if($status==='cancelled'&&$old['status']!=='cancelled'){
                        $paid=$tx->prepare('SELECT COUNT(*) FROM tickets WHERE tenant_id=? AND event_id=? AND status IN ("paid","checked_in")');
                        $paid->execute([$tenantId,$id]);
                        if((int)$paid->fetchColumn()>0)throw new RuntimeException('Existem ingressos pagos. Faça os estornos antes de cancelar o evento.');
                        $pending=$tx->prepare('SELECT COUNT(*) FROM payments p JOIN tickets t ON t.order_id=p.order_id WHERE p.tenant_id=? AND t.event_id=? AND p.status IN ("created","pending","authorized")');
                        $pending->execute([$tenantId,$id]);
                        if((int)$pending->fetchColumn()>0)throw new RuntimeException('Existem pagamentos em andamento.');
                        $groups=$tx->prepare(Database::portableSql($tx,'SELECT batch_id,COUNT(*) qty FROM tickets WHERE tenant_id=? AND event_id=? AND status="reserved" GROUP BY batch_id FOR UPDATE'));
                        $groups->execute([$tenantId,$id]);
                        foreach($groups->fetchAll()as$g)$tx->prepare(Database::portableSql($tx,'UPDATE ticket_batches SET quantity_reserved=GREATEST(0,quantity_reserved-?) WHERE id=?'))->execute([(int)$g['qty'],$g['batch_id']]);
                        $tx->prepare('UPDATE tickets SET status="cancelled",reserved_until=NULL WHERE tenant_id=? AND event_id=? AND status="reserved"')->execute([$tenantId,$id]);
                        $tx->prepare('UPDATE orders SET status="cancelled",payment_status=CASE WHEN payment_status="unpaid" THEN "failed" ELSE payment_status END WHERE tenant_id=? AND channel="event" AND id IN (SELECT DISTINCT order_id FROM tickets WHERE tenant_id=? AND event_id=?) AND payment_status<>"paid"')->execute([$tenantId,$tenantId,$id]);
                    }
                    $u=$tx->prepare('UPDATE events SET name=?,slug=?,description=?,venue=?,address=?,starts_at=?,ends_at=?,status=?,banner_url=?,bar_unit_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');
                    $u->execute([$name,$finalSlug,$description?:null,$venue?:null,$address?:null,$starts,$ends,$status,$banner,$barUnitId,$id,$tenantId]);
                }else{
                    $s=$tx->prepare('INSERT INTO events (tenant_id,name,slug,description,venue,address,starts_at,ends_at,status,banner_url,bar_unit_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $s->execute([$tenantId,$name,$finalSlug,$description?:null,$venue?:null,$address?:null,$starts,$ends,$status,$banner,$barUnitId]);
                    $id=(int)$tx->lastInsertId();
                }
            });
            Auth::audit('event.saved','event',(string)$id,['status'=>$status,'bar_unit_id'=>$barUnitId]);
            em_flash('ok',$status==='published'?'Evento salvo e publicado.':'Evento salvo.');
            em_go('event-admin',['id'=>$id]);
        }catch(Throwable$e){
            em_flash('error',$e->getMessage());
            em_go('events',['edit'=>$id]);
        }
    }
}

$editId=(int)($_GET['edit']??0);
$edit=null;
if($editId){
    $s=$pdo->prepare('SELECT * FROM events WHERE id=? AND tenant_id=?');
    $s->execute([$editId,$tenantId]);
    $edit=$s->fetch()?:null;
}
$showForm=isset($_GET['new'])||$edit;
$s=$pdo->prepare('SELECT e.*,(SELECT COUNT(*) FROM tickets t WHERE t.event_id=e.id AND t.tenant_id=e.tenant_id AND t.status IN ("paid","checked_in")) sold,(SELECT COUNT(*) FROM tickets t WHERE t.event_id=e.id AND t.tenant_id=e.tenant_id AND t.status="reserved") reserved,(SELECT COUNT(*) FROM tickets t WHERE t.event_id=e.id AND t.tenant_id=e.tenant_id AND t.status="checked_in") checkins,(SELECT COALESCE(SUM(o.total_cents),0) FROM orders o WHERE o.tenant_id=e.tenant_id AND o.channel="event" AND o.payment_status="paid" AND EXISTS(SELECT 1 FROM tickets t WHERE t.order_id=o.id AND t.event_id=e.id)) revenue FROM events e WHERE e.tenant_id=? ORDER BY CASE WHEN e.starts_at>=CURRENT_TIMESTAMP THEN 0 ELSE 1 END,e.starts_at ASC');
$s->execute([$tenantId]);
$events=$s->fetchAll();
$now=time();
$upcoming=count(array_filter($events,fn($e)=>strtotime((string)$e['starts_at'])>=$now&&!in_array($e['status'],['cancelled','closed'],true)));
$published=count(array_filter($events,fn($e)=>$e['status']==='published'));
$soldTotal=array_sum(array_map(fn($e)=>(int)$e['sold'],$events));
$revenueTotal=array_sum(array_map(fn($e)=>(int)$e['revenue'],$events));
$statusLabels=['draft'=>'Rascunho','published'=>'Publicado','closed'=>'Encerrado','cancelled'=>'Cancelado'];

em_header('Eventos','events');
?>
<section class="page-hero"><div><span class="eyebrow">EVENTOS E INGRESSOS</span><h2>Seus eventos</h2><p>Crie, publique, venda ingressos e acompanhe a operação de cada evento em um workspace próprio.</p></div><div class="hero-actions"><a class="button primary" href="<?= Security::e(app_url('?route=events&new=1')) ?>">+ Criar evento</a><a class="button secondary" href="<?= Security::e(app_url('?route=tickets')) ?>">Check-in</a></div></section>
<section class="metric-grid" style="margin-bottom:18px"><div class="metric-card"><span>Próximos eventos</span><strong><?= $upcoming ?></strong><small>agenda ativa</small></div><div class="metric-card"><span>Publicados</span><strong><?= $published ?></strong><small>vendendo/visíveis</small></div><div class="metric-card"><span>Ingressos vendidos</span><strong><?= $soldTotal ?></strong><small>pagos</small></div><div class="metric-card"><span>Receita dos eventos</span><strong><?= em_money($revenueTotal) ?></strong><small>pagamentos confirmados</small></div></section>

<?php if($showForm):?><section class="card" style="margin-bottom:18px"><div class="section-head"><div><span class="eyebrow"><?= $edit?'EDITAR EVENTO':'NOVO EVENTO' ?></span><h2><?= $edit?'Informações principais':'Comece pelo essencial' ?></h2><p class="muted">Depois você configura ingressos, lotes, identidade, promotores, cupons e operação no painel do evento.</p></div><?php if($edit):?><a class="button primary" href="<?= Security::e(app_url('?route=event-admin&id='.(int)$edit['id'])) ?>">Abrir gestão completa</a><?php endif;?></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="event-save"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><label class="span-2">Nome do evento<input name="name" required value="<?= Security::e($edit['name']??'') ?>" placeholder="Ex.: Sunset Lake 2026"></label><label>Data e hora de início<input type="datetime-local" name="starts_at" required value="<?= $edit?Security::e(date('Y-m-d\TH:i',strtotime($edit['starts_at']))):'' ?>"></label><label>Data e hora de término<input type="datetime-local" name="ends_at" value="<?= $edit&&$edit['ends_at']?Security::e(date('Y-m-d\TH:i',strtotime($edit['ends_at']))):'' ?>"></label><label>Local<input name="venue" value="<?= Security::e($edit['venue']??'') ?>" placeholder="Nome do espaço"></label><label>Status<select name="status"><option value="draft"<?= em_selected($edit['status']??'draft','draft') ?>>Rascunho</option><option value="published"<?= em_selected($edit['status']??'','published') ?>>Publicado</option><option value="closed"<?= em_selected($edit['status']??'','closed') ?>>Encerrado</option><option value="cancelled"<?= em_selected($edit['status']??'','cancelled') ?>>Cancelado</option></select></label><label class="span-2">Unidade que atende o bar<select name="bar_unit_id"><option value="0">Não definida — pedido público do bar desativado</option><?php foreach($operatingUnits as$unit):?><option value="<?= (int)$unit['id'] ?>"<?= em_selected((string)($edit['bar_unit_id']??0),(string)$unit['id']) ?>><?= Security::e($unit['name']) ?><?= !empty($unit['address'])?' · '.Security::e($unit['address']):'' ?></option><?php endforeach;?></select><small>Esta unidade define estoque, produção e qual equipe poderá entregar pedidos feitos pelo público no evento.</small></label><label class="span-2">Endereço<input name="address" value="<?= Security::e($edit['address']??'') ?>"></label><label class="span-2">Descrição<textarea name="description" rows="4"><?= Security::e($edit['description']??'') ?></textarea></label><label class="span-2">Banner / capa (URL)<input type="url" name="banner_url" value="<?= Security::e($edit['banner_url']??'') ?>" placeholder="https://..."></label><label class="span-2">Link público<input name="slug" value="<?= Security::e($edit['slug']??'') ?>" placeholder="gerado automaticamente pelo nome"><small>Se ficar vazio, será criado pelo nome do evento. Conflitos recebem -2, -3 e assim por diante.</small></label><div class="actions span-2"><button class="primary"><?= $edit?'Salvar informações':'Criar evento e continuar' ?></button><a class="button secondary" href="<?= Security::e(app_url('?route=events')) ?>">Cancelar</a></div></form></section><?php endif;?>

<section><div class="section-head"><div><span class="eyebrow">PORTFÓLIO</span><h2><?= $events?'Eventos cadastrados':'Nenhum evento ainda' ?></h2></div><span class="muted"><?= count($events) ?> evento(s)</span></div><?php if(!$events):?><div class="card" style="text-align:center;padding:48px"><h2>Crie seu primeiro evento</h2><p class="muted">A página pública, venda de ingressos, QR, check-in, lotes e relatórios serão configurados depois.</p><a class="button primary" href="<?= Security::e(app_url('?route=events&new=1')) ?>">Criar evento</a></div><?php else:?><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px"><?php foreach($events as$e):$date=strtotime((string)$e['starts_at']);$eventPublicUrl=app_url('evento.php?empresa='.rawurlencode($tenantSlug).'&evento='.rawurlencode((string)$e['slug']));?><article class="card" style="overflow:hidden;padding:0"><div style="height:145px;background:<?= $e['banner_url']?'linear-gradient(180deg,rgba(32,19,79,.05),rgba(32,19,79,.55)),url('.Security::e($e['banner_url']).') center/cover':'linear-gradient(135deg,#20134f,#6236df)' ?>"></div><div style="padding:18px"><div class="section-head"><div><span class="eyebrow"><?= Security::e(strtoupper($statusLabels[$e['status']]??$e['status'])) ?></span><h2 style="margin:4px 0"><?= Security::e($e['name']) ?></h2><p class="muted"><?= Security::e(date('d/m/Y · H:i',$date)) ?><?= $e['venue']?' · '.Security::e($e['venue']):'' ?></p></div><span class="status-pill <?= Security::e($e['status']) ?>"><?= Security::e($statusLabels[$e['status']]??$e['status']) ?></span></div><div class="metric-grid" style="grid-template-columns:repeat(3,1fr);margin:14px 0"><div class="metric-card"><span>Vendidos</span><strong><?= (int)$e['sold'] ?></strong></div><div class="metric-card"><span>Check-ins</span><strong><?= (int)$e['checkins'] ?></strong></div><div class="metric-card"><span>Receita</span><strong style="font-size:18px"><?= em_money((int)$e['revenue']) ?></strong></div></div><div class="actions"><a class="button primary" href="<?= Security::e(app_url('?route=event-admin&id='.(int)$e['id'])) ?>">Gerenciar evento</a><a class="button secondary" target="_blank" rel="noopener" href="<?= Security::e($eventPublicUrl) ?>">Página pública</a><a class="button secondary compact" href="<?= Security::e(app_url('?route=events&edit='.(int)$e['id'])) ?>">Editar dados</a></div></div></article><?php endforeach;?></div><?php endif;?></section>
<?php em_footer();
