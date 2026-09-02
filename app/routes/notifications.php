<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\NotificationService;

Auth::requirePermission('notifications.view');$tenantId=em_require_tenant();$service=new NotificationService();
if($_SERVER['REQUEST_METHOD']==='POST'){em_post_csrf();$action=(string)($_POST['action']??'');if($action==='read')$service->markRead($tenantId,(int)($_POST['id']??0),Auth::id());elseif($action==='all')$service->markAllRead($tenantId,Auth::id());em_go('notifications');}
$s=$pdo->prepare('SELECT * FROM notifications WHERE tenant_id=? AND (target_user_id IS NULL OR target_user_id=?) ORDER BY created_at DESC LIMIT 100');$s->execute([$tenantId,Auth::id()]);$rows=$s->fetchAll();$unread=count(array_filter($rows,fn($n)=>$n['status']==='unread'));
em_header('Notificações','notifications');?>
<section class="card"><div class="section-head"><div><h2>Central de notificações</h2><span class="muted"><?= $unread ?> não lidas</span></div><?php if($unread):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="all"><button class="secondary">Marcar todas como lidas</button></form><?php endif;?></div><?php foreach($rows as$n):?><article style="padding:16px 0;border-bottom:1px solid var(--line);opacity:<?= $n['status']==='unread'?'1':'.72' ?>"><div class="section-head"><div><span class="badge"><?= Security::e($n['type']) ?></span><h3 style="margin:8px 0 4px"><?= Security::e($n['title']) ?></h3><p style="margin:0"><?= Security::e($n['message']) ?></p><small class="muted"><?= Security::e($n['created_at']) ?></small></div><?php if($n['status']==='unread'):?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="read"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="secondary">Lida</button></form><?php endif;?></div></article><?php endforeach;?><?php if(!$rows):?><p class="muted">Nenhuma notificação.</p><?php endif;?></section><?php em_footer();
