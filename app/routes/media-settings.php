<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\BrandingMediaService;

Auth::requirePermission('settings.manage');
$tenantId = em_require_tenant();
$s=$pdo->prepare('SELECT name,settings FROM tenants WHERE id=?');$s->execute([$tenantId]);$tenant=$s->fetch();if(!$tenant)exit('Empresa não encontrada.');
$settings=json_decode((string)($tenant['settings']??'{}'),true)?:[];
$media=new BrandingMediaService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    $slot=(string)($_POST['slot']??'');
    $map=['brand-logo'=>['field'=>'brand_logo_url','file'=>'image'],'menu-logo'=>['field'=>'menu_logo_url','file'=>'image'],'menu-cover'=>['field'=>'menu_cover_url','file'=>'image']];
    if(!isset($map[$slot])){em_flash('error','Tipo de imagem inválido.');em_go('media-settings');}
    try{
        $field=$map[$slot]['field'];
        if(isset($_POST['remove'])){
            $old=(string)($settings[$field]??'');$media->removeLocalUrl($tenantId,$old);$settings[$field]='';
            if($slot==='brand-logo'&&($settings['menu_logo_url']??'')===$old)$settings['menu_logo_url']='';
            $pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);
            Auth::audit('tenant.media_removed','tenant',(string)$tenantId,['slot'=>$slot]);em_flash('ok','Imagem removida.');em_go('media-settings');
        }
        $file=$_FILES['image']??null;if(!is_array($file))throw new RuntimeException('Escolha uma imagem.');
        $old=(string)($settings[$field]??'');$stored=$media->store($tenantId,$file,$slot);$settings[$field]=$stored['url'];
        if($slot==='brand-logo'&&trim((string)($settings['menu_logo_url']??''))==='')$settings['menu_logo_url']=$stored['url'];
        $pdo->prepare('UPDATE tenants SET settings=? WHERE id=?')->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);
        $media->removeLocalUrl($tenantId,$old);
        Auth::audit('tenant.media_uploaded','tenant',(string)$tenantId,['slot'=>$slot,'mime'=>$stored['mime'],'size'=>$stored['size']]);
        em_flash('ok','Imagem enviada e aplicada com sucesso.');em_go('media-settings');
    }catch(Throwable $e){em_flash('error',$e->getMessage());em_go('media-settings');}
}

$cards=[
 ['slot'=>'brand-logo','title'=>'Logo principal','desc'=>'Usada no painel e no EventMenu GO quando a identidade da empresa estiver ativa.','url'=>(string)($settings['brand_logo_url']??'')],
 ['slot'=>'menu-logo','title'=>'Logo do cardápio','desc'=>'Opcional. Se vazio, o cardápio pode usar a logo principal.','url'=>(string)($settings['menu_logo_url']??'')],
 ['slot'=>'menu-cover','title'=>'Capa do cardápio','desc'=>'Imagem horizontal exibida no topo do cardápio público.','url'=>(string)($settings['menu_cover_url']??'')],
];

em_header('Logo e capa','settings');
?>
<section class="page-hero"><div><span class="eyebrow">IMAGENS DA EMPRESA</span><h2>Envie a imagem direto do aparelho</h2><p>Não precisa mais hospedar a imagem em outro site nem copiar URL. JPG, PNG e WebP de até 5 MB.</p></div><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=settings')) ?>">Voltar às configurações</a></div></section>
<div class="metric-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
<?php foreach($cards as $card):?>
<section class="card">
  <div class="section-head"><div><span class="eyebrow">IMAGEM</span><h2><?= Security::e($card['title']) ?></h2></div></div>
  <p class="muted"><?= Security::e($card['desc']) ?></p>
  <div style="margin:16px 0;min-height:150px;border:1px dashed #d8d3e8;border-radius:16px;display:grid;place-items:center;background:#faf9fd;overflow:hidden">
    <?php if($card['url']!==''):?><img src="<?= Security::e($card['url']) ?>" alt="<?= Security::e($card['title']) ?>" style="max-width:100%;max-height:220px;object-fit:contain"><?php else:?><span class="muted">Nenhuma imagem enviada</span><?php endif;?>
  </div>
  <form method="post" enctype="multipart/form-data" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="slot" value="<?= Security::e($card['slot']) ?>">
    <label class="span-2">Escolher arquivo<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
    <div class="span-2 actions"><button class="primary" type="submit">Enviar imagem</button></div>
  </form>
  <?php if($card['url']!==''):?><form method="post" style="margin-top:10px" onsubmit="return confirm('Remover esta imagem?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="slot" value="<?= Security::e($card['slot']) ?>"><input type="hidden" name="remove" value="1"><button class="secondary compact" type="submit">Remover</button></form><?php endif;?>
</section>
<?php endforeach;?>
</div>
<section class="card" style="margin-top:20px"><span class="eyebrow">ARMAZENAMENTO</span><h3>Protegido fora da pasta pública</h3><p class="muted">Os arquivos ficam em <strong>storage</strong> e são entregues por uma rota controlada apenas como imagem. Isso evita permitir upload de PHP ou outros arquivos executáveis.</p></section>
<?php em_footer(); ?>
