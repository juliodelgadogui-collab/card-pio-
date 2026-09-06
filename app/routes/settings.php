<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;

Auth::requirePermission('settings.manage');
$tenantId = em_require_tenant();
$s = $pdo->prepare('SELECT * FROM tenants WHERE id=?');
$s->execute([$tenantId]);
$tenant = $s->fetch();
if (!$tenant) exit('Empresa não encontrada.');
$settings = json_decode((string)($tenant['settings'] ?? '{}'), true) ?: [];

$validColor = static function (mixed $value, string $fallback): string {
    $color = strtolower(trim((string)$value));
    return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : $fallback;
};
$validUrl = static function (mixed $value): string {
    $url = trim((string)$value);
    if ($url === '') return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http','https'], true) ? mb_substr($url, 0, 600) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') exit('Nome inválido.');
    $layout = (string)($_POST['menu_layout'] ?? 'cards');
    if (!in_array($layout, ['cards','compact'], true)) $layout = 'cards';
    $headerStyle = (string)($_POST['menu_header_style'] ?? 'gradient');
    if (!in_array($headerStyle, ['gradient','solid','minimal'], true)) $headerStyle = 'gradient';

    $settings = array_merge($settings, [
        'delivery_fee_cents' => (int)round((float)str_replace(',','.',(string)($_POST['delivery_fee'] ?? '0')) * 100),
        'min_delivery_order_cents' => (int)round((float)str_replace(',','.',(string)($_POST['min_delivery_order'] ?? '0')) * 100),
        'points_enabled' => isset($_POST['points_enabled']),
        'menu_message' => mb_substr(trim((string)($_POST['menu_message'] ?? '')), 0, 300),
        'whatsapp' => mb_substr(trim((string)($_POST['whatsapp'] ?? '')), 0, 30),
        'menu_public_title' => mb_substr(trim((string)($_POST['menu_public_title'] ?? '')), 0, 90),
        'menu_subtitle' => mb_substr(trim((string)($_POST['menu_subtitle'] ?? '')), 0, 180),
        'menu_primary_color' => $validColor($_POST['menu_primary_color'] ?? '', '#6236df'),
        'menu_background_color' => $validColor($_POST['menu_background_color'] ?? '', '#f7f7fb'),
        'menu_surface_color' => $validColor($_POST['menu_surface_color'] ?? '', '#ffffff'),
        'menu_text_color' => $validColor($_POST['menu_text_color'] ?? '', '#242136'),
        'menu_layout' => $layout,
        'menu_header_style' => $headerStyle,
        'menu_show_images' => isset($_POST['menu_show_images']),
        'menu_show_search' => isset($_POST['menu_show_search']),
        'menu_show_branding' => isset($_POST['menu_show_branding']),
        'menu_logo_url' => $validUrl($_POST['menu_logo_url'] ?? ''),
        'menu_cover_url' => $validUrl($_POST['menu_cover_url'] ?? ''),
    ]);
    $s = $pdo->prepare('UPDATE tenants SET name=?,settings=? WHERE id=?');
    $s->execute([$name, json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $tenantId]);
    Auth::audit('tenant.settings','tenant',(string)$tenantId,['menu_customization'=>TenantFeatures::menu($tenantId)]);
    em_flash('ok','Configurações salvas. O cardápio público já usa a nova aparência.');
    em_go('settings');
}

$publicMenuUrl = app_url('loja.php?empresa='.rawurlencode((string)$tenant['slug']));
$primary = $validColor($settings['menu_primary_color'] ?? '', '#6236df');
$background = $validColor($settings['menu_background_color'] ?? '', '#f7f7fb');
$surface = $validColor($settings['menu_surface_color'] ?? '', '#ffffff');
$text = $validColor($settings['menu_text_color'] ?? '', '#242136');

em_header('Configurações','settings');
?>
<section class="page-hero">
  <div><span class="eyebrow">MINHA EMPRESA</span><h2>Marca, cardápio e operação</h2><p>Personalize a experiência do seu cliente sem mexer em código.</p></div>
  <?php if(TenantFeatures::menu($tenantId)):?><div class="hero-actions"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir cardápio público</a><button type="button" class="button secondary" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Link copiado ✓'">Copiar link</button></div><?php endif;?>
</section>

<form method="post" class="settings-layout">
<input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
<div class="settings-main">
<section class="card"><div class="section-head"><div><span class="eyebrow">EMPRESA</span><h2>Informações gerais</h2></div></div><div class="form-grid"><label class="span-2">Nome da empresa<input name="name" required value="<?= Security::e($tenant['name']) ?>"></label><?php if(TenantFeatures::menu($tenantId)):?><label>Taxa padrão de entrega<input name="delivery_fee" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','')) ?>"></label><label>Pedido mínimo delivery<input name="min_delivery_order" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','')) ?>"></label><label class="span-2">WhatsApp<input name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>" placeholder="5522999999999"></label><?php else:?><input type="hidden" name="delivery_fee" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,'.','')) ?>"><input type="hidden" name="min_delivery_order" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,'.','')) ?>"><input type="hidden" name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>"><?php endif;?><label class="checkbox span-2"><input type="checkbox" name="points_enabled"<?= em_checked($settings['points_enabled']??true) ?>> Programa de pontos ativo</label></div></section>

<?php if(TenantFeatures::menu($tenantId)):?>
<section class="card" id="cardapio"><div class="section-head"><div><span class="eyebrow">CARDÁPIO PÚBLICO</span><h2>Identidade e conteúdo</h2></div><span class="status-pill active">Publicado</span></div><div class="public-link-box"><div><small>Seu link público</small><strong><?= Security::e($publicMenuUrl) ?></strong></div><div class="actions"><a class="button secondary compact" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir</a><button type="button" class="secondary compact" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Copiado ✓'">Copiar</button></div></div><div class="form-grid"><label class="span-2">Título exibido<input name="menu_public_title" value="<?= Security::e($settings['menu_public_title']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label><label class="span-2">Frase de destaque<input name="menu_subtitle" value="<?= Security::e($settings['menu_subtitle']??'') ?>" placeholder="Peça do seu jeito. Rápido e fácil."></label><label class="span-2">Mensagem no cardápio<textarea name="menu_message" placeholder="Ex.: Entregamos todos os dias até 23h."><?= Security::e($settings['menu_message']??'') ?></textarea></label><label class="span-2">Logo (URL opcional)<input type="url" name="menu_logo_url" value="<?= Security::e($settings['menu_logo_url']??'') ?>" placeholder="https://..."></label><label class="span-2">Capa (URL opcional)<input type="url" name="menu_cover_url" value="<?= Security::e($settings['menu_cover_url']??'') ?>" placeholder="https://..."></label></div></section>

<section class="card"><div class="section-head"><div><span class="eyebrow">APARÊNCIA</span><h2>Cores e layout</h2></div></div><div class="appearance-grid"><label>Cor principal<div class="color-field"><input type="color" name="menu_primary_color" value="<?= Security::e($primary) ?>"><span><?= Security::e($primary) ?></span></div></label><label>Fundo<div class="color-field"><input type="color" name="menu_background_color" value="<?= Security::e($background) ?>"><span><?= Security::e($background) ?></span></div></label><label>Cards<div class="color-field"><input type="color" name="menu_surface_color" value="<?= Security::e($surface) ?>"><span><?= Security::e($surface) ?></span></div></label><label>Texto<div class="color-field"><input type="color" name="menu_text_color" value="<?= Security::e($text) ?>"><span><?= Security::e($text) ?></span></div></label></div><div class="form-grid" style="margin-top:18px"><label>Layout dos produtos<select name="menu_layout"><option value="cards"<?= em_selected($settings['menu_layout']??'cards','cards') ?>>Cards modernos</option><option value="compact"<?= em_selected($settings['menu_layout']??'','compact') ?>>Lista compacta</option></select></label><label>Cabeçalho<select name="menu_header_style"><option value="gradient"<?= em_selected($settings['menu_header_style']??'gradient','gradient') ?>>Destaque em degradê</option><option value="solid"<?= em_selected($settings['menu_header_style']??'','solid') ?>>Cor sólida</option><option value="minimal"<?= em_selected($settings['menu_header_style']??'','minimal') ?>>Minimalista</option></select></label><div class="span-2 check-grid"><label class="checkbox"><input type="checkbox" name="menu_show_images"<?= em_checked($settings['menu_show_images']??true) ?>> Mostrar fotos dos produtos</label><label class="checkbox"><input type="checkbox" name="menu_show_search"<?= em_checked($settings['menu_show_search']??true) ?>> Mostrar busca</label><label class="checkbox"><input type="checkbox" name="menu_show_branding"<?= em_checked($settings['menu_show_branding']??true) ?>> Mostrar “Powered by EventMenu”</label></div></div></section>
<?php endif;?>
<button class="primary save-settings">Salvar e publicar alterações</button>
</div>

<?php if(TenantFeatures::menu($tenantId)):?><aside class="card live-preview"><span class="eyebrow">PRÉVIA</span><div class="preview-phone" style="--preview-primary:<?= Security::e($primary) ?>;--preview-bg:<?= Security::e($background) ?>;--preview-surface:<?= Security::e($surface) ?>;--preview-text:<?= Security::e($text) ?>"><div class="preview-cover"></div><div class="preview-body"><small>Seu cardápio</small><h3><?= Security::e(($settings['menu_public_title']??'') ?: $tenant['name']) ?></h3><p><?= Security::e(($settings['menu_subtitle']??'') ?: 'Peça do seu jeito. Rápido e fácil.') ?></p><div class="preview-search">Buscar no cardápio</div><div class="preview-product"><div><strong>Produto em destaque</strong><small>Descrição curta do produto</small></div><b>R$ 24,90</b></div><div class="preview-product"><div><strong>Outro produto</strong><small>Seu cardápio fica assim no celular</small></div><b>R$ 18,00</b></div><div class="preview-button">Adicionar ao pedido</div></div></div><p class="muted preview-note">A prévia mostra a identidade. Produtos e categorias reais aparecem no link público.</p></aside><?php endif;?>
</form>
<?php if(Auth::isSuperAdmin()):?><details class="technical-details"><summary>Informações técnicas</summary><div class="card"><p>Slug: <code><?= Security::e($tenant['slug']) ?></code><br>Plano: <span class="badge"><?= Security::e($tenant['plan']) ?></span><br>Operação: <span class="badge"><?= Security::e(TenantFeatures::label($tenantId)) ?></span><br>Status: <span class="badge"><?= Security::e($tenant['status']) ?></span></p><a class="button secondary" href="<?= Security::e(app_url('update.php')) ?>">Manutenção do banco</a></div></details><?php endif;?>
<?php em_footer();
