<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\LegalService;

$service=new LegalService();$pending=$service->pendingForUser((int)Auth::id());
if($_SERVER['REQUEST_METHOD']==='POST'){em_post_csrf();try{foreach($pending as$d){if(empty($_POST['accept_'.$d['id']]))throw new RuntimeException('É necessário aceitar todos os documentos ativos para continuar.');$service->accept((int)(Auth::tenantId()??0),(int)Auth::id(),(int)$d['id']);}em_go(Auth::homeRoute());}catch(Throwable $e){em_flash('error',$e->getMessage());em_go('legal-accept');}}
if(!$pending)em_go(Auth::homeRoute());em_header('Termos e privacidade','');?>
<section class="card" style="max-width:900px;margin:auto"><h2>Atualização de documentos legais</h2><p class="muted">Antes de continuar, leia e aceite as versões vigentes.</p><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><?php foreach($pending as$d):?><article style="padding:18px 0;border-top:1px solid var(--line)"><h3><?= Security::e($d['title']) ?> <span class="badge">v<?= Security::e($d['version']) ?></span></h3><div style="max-height:320px;overflow:auto;white-space:pre-wrap;padding:16px;background:var(--surface-2);border-radius:14px"><?= Security::e($d['content']) ?></div><label class="checkbox" style="margin-top:14px"><input type="checkbox" name="accept_<?= (int)$d['id'] ?>" value="1" required> Li e aceito este documento.</label></article><?php endforeach;?><button class="primary" style="margin-top:18px">Aceitar e continuar</button></form></section><?php em_footer();
