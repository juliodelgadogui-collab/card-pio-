<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\LegalService;

Auth::requirePermission('legal.manage');$service=new LegalService();
if($_SERVER['REQUEST_METHOD']==='POST'){em_post_csrf();try{$service->publish((string)($_POST['document_type']??''),(string)($_POST['version']??''),(string)($_POST['title']??''),(string)($_POST['content']??''));em_flash('ok','Documento publicado e marcado como versão ativa.');}catch(Throwable $e){em_flash('error',$e->getMessage());}em_go('legal');}
$docs=$pdo->query('SELECT d.*,(SELECT COUNT(*) FROM legal_acceptances a WHERE a.legal_document_id=d.id) acceptances FROM legal_documents d ORDER BY d.document_type,d.published_at DESC')->fetchAll();em_header('Termos e privacidade','legal');?>
<div class="grid" style="grid-template-columns:minmax(300px,1fr) minmax(0,2fr)"><section class="card"><h2>Publicar versão</h2><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><label>Documento<select name="document_type"><option value="terms">Termos de Uso</option><option value="privacy">Política de Privacidade</option></select></label><label>Versão<input name="version" required placeholder="2026.09"></label><label class="span-2">Título<input name="title" required></label><label class="span-2">Conteúdo<textarea name="content" rows="18" required></textarea></label><button class="primary span-2">Publicar</button></form></section><section class="card"><h2>Versões publicadas</h2><div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Versão</th><th>Título</th><th>Publicada</th><th>Aceites</th><th>Status</th></tr></thead><tbody><?php foreach($docs as$d):?><tr><td><?= Security::e($d['document_type']) ?></td><td><?= Security::e($d['version']) ?></td><td><?= Security::e($d['title']) ?></td><td><?= Security::e($d['published_at']) ?></td><td><?= (int)$d['acceptances'] ?></td><td><span class="badge"><?= $d['active']?'Ativa':'Histórica' ?></span></td></tr><?php endforeach;?></tbody></table></div></section></div><?php em_footer();
