<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('audit.view');$tenantId=em_require_tenant();$q=trim((string)($_GET['q']??''));$sql='SELECT a.*,u.name user_name,u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.tenant_id=?';$args=[$tenantId];if($q!==''){$sql.=' AND (a.action LIKE ? OR a.entity_type LIKE ? OR a.entity_id LIKE ? OR u.name LIKE ?)';$like='%'.$q.'%';array_push($args,$like,$like,$like,$like);}$sql.=' ORDER BY a.id DESC LIMIT 400';$s=$pdo->prepare($sql);$s->execute($args);$logs=$s->fetchAll();
em_header('Auditoria','audit');
?><section class="card"><form method="get" class="actions"><input type="hidden" name="route" value="audit"><input name="q" value="<?= Security::e($q) ?>" placeholder="Ação, entidade ou usuário"><button class="secondary">Buscar</button></form><div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>IP</th><th>Dados</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?= Security::e($l['created_at']) ?></td><td><?= Security::e($l['user_name']??'Sistema') ?><br><span class="muted"><?= Security::e($l['user_email']??'') ?></span></td><td><code><?= Security::e($l['action']) ?></code></td><td><?= Security::e(($l['entity_type']??'').' '.($l['entity_id']??'')) ?></td><td><?= Security::e($l['ip_address']??'') ?></td><td><code><?= Security::e(mb_strimwidth((string)($l['metadata']??''),0,120,'…')) ?></code></td></tr><?php endforeach;?></tbody></table></div></section><?php em_footer();
