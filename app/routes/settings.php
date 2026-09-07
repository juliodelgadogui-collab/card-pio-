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

    $brandPrimary = $validColor($_POST['brand_primary_color'] ?? '', '#5b34d6');
    $brandSecondary = $validColor($_POST['brand_secondary_color'] ?? '', '#159b63');
    $brandBackground = $validColor($_POST['brand_background_color'] ?? '', '#f6f7fb');
    $brandSurface = $validColor($_POST['brand_surface_color'] ?? '', '#ffffff');
    $brandText = $validColor($_POST['brand_text_color'] ?? '', '#1e1b2b');
    $brandLogo = $validUrl($_POST['brand_logo_url'] ?? '');
    $syncPublic = isset($_POST['brand_sync_public_menu']);

    $settings = array_merge($settings, [
        'delivery_fee_cents' => (int)round((float)str_replace(',','.',(string)($_POST['delivery_fee'] ?? '0')) * 100),
        'min_delivery_order_cents' => (int)round((float)str_replace(',','.',(string)($_POST['min_delivery_order'] ?? '0')) * 100),
        'points_enabled' => isset($_POST['points_enabled']),
        'whatsapp' => mb_substr(trim((string)($_POST['whatsapp'] ?? '')), 0, 30),

        'brand_display_name' => mb_substr(trim((string)($_POST['brand_display_name'] ?? '')), 0, 90),
        'brand_logo_url' => $brandLogo,
        'brand_primary_color' => $brandPrimary,
        'brand_secondary_color' => $brandSecondary,
        'brand_background_color' => $brandBackground,
        'brand_surface_color' => $brandSurface,
        'brand_text_color' => $brandText,
        'brand_apply_app' => isset($_POST['brand_apply_app']),
        'brand_apply_panel' => isset($_POST['brand_apply_panel']),
        'brand_sync_public_menu' => $syncPublic,

        'menu_message' => mb_substr(trim((string)($_POST['menu_message'] ?? '')), 0, 300),
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

    if ($syncPublic) {
        $settings['menu_primary_color'] = $brandPrimary;
        $settings['menu_background_color'] = $brandBackground;
        $settings['menu_surface_color'] = $brandSurface;
        $settings['menu_text_color'] = $brandText;
        if ($brandLogo !== '') $settings['menu_logo_url'] = $brandLogo;
    }

    $s = $pdo->prepare('UPDATE tenants SET name=?,settings=? WHERE id=?');
    $s->execute([$name, json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $tenantId]);
    Auth::audit('tenant.settings','tenant',(string)$tenantId,[
        'branding'=>true,
        'app'=>(bool)$settings['brand_apply_app'],
        'panel'=>(bool)$settings['brand_apply_panel'],
        'public_menu'=>$syncPublic,
    ]);
    em_flash('ok','Configurações salvas. A identidade será aplicada no App e no painel conforme as opções escolhidas.');
    em_go('settings');
}

$publicMenuUrl = app_url('loja.php?empresa='.rawurlencode((string)$tenant['slug']));
$primary = $validColor($settings['menu_primary_color'] ?? '', '#6236df');
$background = $validColor($settings['menu_background_color'] ?? '', '#f7f7fb');
$surface = $validColor($settings['menu_surface_color'] ?? '', '#ffffff');
$text = $validColor($settings['menu_text_color'] ?? '', '#242136');
$brandPrimary = $validColor($settings['brand_primary_color'] ?? $settings['menu_primary_color'] ?? '', '#5b34d6');
$brandSecondary = $validColor($settings['brand_secondary_color'] ?? '', '#159b63');
$brandBackground = $validColor($settings['brand_background_color'] ?? $settings['menu_background_color'] ?? '', '#f6f7fb');
$brandSurface = $validColor($settings['brand_surface_color'] ?? $settings['menu_surface_color'] ?? '', '#ffffff');
$brandText = $validColor($settings['brand_text_color'] ?? $settings['menu_text_color'] ?? '', '#1e1b2b');
$brandName = trim((string)($settings['brand_display_name'] ?? '')) ?: (string)$tenant['name'];
$brandLogo = (string)($settings['brand_logo_url'] ?? $settings['menu_logo_url'] ?? '');

em_header('Configurações','settings');
?>
<section class="page-hero">
  <div><span class="eyebrow">MINHA EMPRESA</span><h2>Marca, cardápio e operação</h2><p>Personalize o EventMenu para a identidade da sua empresa sem alterar código.</p></div>
  <?php if(TenantFeatures::menu($tenantId)):?><div class="hero-actions"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir cardápio público</a><button type="button" class="button secondary" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Link copiado ✓'">Copiar link</button></div><?php endif;?>
</section>

<form method="post" class="settings-layout">
<input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
<div class="settings-main">
<section class="card"><div class="section-head"><div><span class="eyebrow">EMPRESA</span><h2>Informações gerais</h2></div></div><div class="form-grid"><label class="span-2">Nome da empresa<input name="name" required value="<?= Security::e($tenant['name']) ?>"></label><?php if(TenantFeatures::menu($tenantId)):?><label>Taxa padrão de entrega<input name="delivery_fee" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','')) ?>"></label><label>Pedido mínimo delivery<input name="min_delivery_order" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','')) ?>"></label><label class="span-2">WhatsApp<input name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>" placeholder="5522999999999"></label><?php else:?><input type="hidden" name="delivery_fee" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,'.','')) ?>"><input type="hidden" name="min_delivery_order" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,'.','')) ?>"><input type="hidden" name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>"><?php endif;?><label class="checkbox span-2"><input type="checkbox" name="points_enabled"<?= em_checked($settings['points_enabled']??true) ?>> Programa de pontos ativo</label></div></section>

<section class="card" id="identidade"><div class="section-head"><div><span class="eyebrow">IDENTIDADE DO SISTEMA</span><h2>App e painel Web</h2></div><span class="status-pill active">Por empresa</span></div><p class="muted">Essa identidade aparece para a equipe depois do login. O aplicativo instalado continua sendo EventMenu GO, mas a experiência interna assume a marca da empresa.</p><div class="form-grid" style="margin-top:16px"><label class="span-2">Nome exibido no App e painel<input name="brand_display_name" value="<?= Security::e($settings['brand_display_name']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label><label class="span-2">Logo da empresa (URL)<input type="url" name="brand_logo_url" value="<?= Security::e($brandLogo) ?>" placeholder="https://..."></label></div><div class="appearance-grid" style="margin-top:16px"><label>Cor principal<div class="color-field"><input type="color" name="brand_primary_color" value="<?= Security::e($brandPrimary) ?>"><span><?= Security::e($brandPrimary) ?></span></div></label><label>Cor secundária<div class="color-field"><input type="color" name="brand_secondary_color" value="<?= Security::e($brandSecondary) ?>"><span><?= Security::e($brandSecondary) ?></span></div></label><label>Fundo<div class="color-field"><input type="color" name="brand_background_color" value="<?= Security::e($brandBackground) ?>"><span><?= Security::e($brandBackground) ?></span></div></label><label>Cards<div class="color-field"><input type="color" name="brand_surface_color" value="<?= Security::e($brandSurface) ?>"><span><?= Security::e($brandSurface) ?></span></div></label><label>Texto<div class="color-field"><input type="color" name="brand_text_color" value="<?= Security::e($brandText) ?>"><span><?= Security::e($brandText) ?></span></div></label></div><div class="check-grid" style="margin-top:16px"><label class="checkbox"><input type="checkbox" name="brand_apply_app"<?= em_checked($settings['brand_apply_app']??true) ?>> Aplicar no EventMenu GO</label><label class="checkbox"><input type="checkbox" name="brand_apply_panel"<?= em_checked($settings['brand_apply_panel']??true) ?>> Aplicar no painel Web</label><?php if(TenantFeatures::menu($tenantId)):?><label class="checkbox"><input type="checkbox" name="brand_sync_public_menu"<?= em_checked($settings['brand_sync_public_menu']??false) ?>> Usar também no cardápio público</label><?php endif;?></div><div class="brand-preview" style="margin-top:16px;--brand-p:<?= Security::e($brandPrimary) ?>;--brand-s:<?= Security::e($brandSecondary) ?>;--brand-bg:<?= Security::e($brandBackground) ?>;--brand-card:<?= Security::e($brandSurface) ?>;--brand-text:<?= Security::e($brandText) ?>"><div class="brand-preview-head"><?php if($brandLogo!==''):?><img src="<?= Security::e($brandLogo) ?>" alt="" loading="lazy"><?php else:?><span class="brand-preview-logo"><?= Security::e(mb_strtoupper(mb_substr($brandName,0,1))) ?></span><?php endif;?><div><small>Prévia do sistema</small><strong><?= Security::e($brandName) ?></strong></div></div><div class="brand-preview-cards"><span>Pedidos</span><span>Caixa</span><span>Financeiro</span></div><button type="button">Ação principal</button></div></section>

<?php if(TenantFeatures::menu($tenantId)):?>
<section class="card" id="cardapio"><div class="section-head"><div><span class="eyebrow">CARDÁPIO PÚBLICO</span><h2>Identidade e conteúdo</h2></div><span class="status-pill active">Publicado</span></div><div class="public-link-box"><div><small>Seu link público</small><strong><?= Security::e($publicMenuUrl) ?></strong></div><div class="actions"><a class="button secondary compact" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir</a><button type="button" class="secondary compact" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Copiado ✓'">Copiar</button></div></div><div class="form-grid"><label class="span-2">Título exibido<input name="menu_public_title" value="<?= Security::e($settings['menu_public_title']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label><label class="span-2">Frase de destaque<input name="menu_subtitle" value="<?= Security::e($settings['menu_subtitle']??'') ?>" placeholder="Peça do seu jeito. Rápido e fácil."></label><label class="span-2">Mensagem no cardápio<textarea name="menu_message" placeholder="Ex.: Entregamos todos os dias até 23h."><?= Security::e($settings['menu_message']??'') ?></textarea></label><label class="span-2">Logo do cardápio (URL opcional)<input type="url" name="menu_logo_url" value="<?= Security::e($settings['menu_logo_url']??'') ?>" placeholder="https://..."></label><label class="span-2">Capa (URL opcional)<input type="url" name="menu_cover_url" value="<?= Security::e($settings['menu_cover_url']??'') ?>" placeholder="https://..."></label></div></section>

<section class="card"><div class="section-head"><div><span class="eyebrow">APARÊNCIA DO CARDÁPIO</span><h2>Cores e layout</h2></div></div><div class="appearance-grid"><label>Cor principal<div class="color-field"><input type="color" name="menu_primary_color" value="<?= Security::e($primary) ?>"><span><?= Security::e($primary) ?></span></div></label><label>Fundo<div class="color-field"><input type="color" name="menu_background_color" value="<?= Security::e($background) ?>"><span><?= Security::e($background) ?></span></div></label><label>Cards<div class="color-field"><input type="color" name="menu_surface_color" value="<?= Security::e($surface) ?>"><span><?= Security::e($surface) ?></span></div></label><label>Texto<div class="color-field"><input type="color" name="menu_text_color" value="<?= Security::e($text) ?>"><span><?= Security::e($text) ?></span></div></label></div><div class="form-grid" style="margin-top:18px"><label>Layout dos produtos<select name="menu_layout"><option value="cards"<?= em_selected($settings['menu_layout']??'cards','cards') ?>>Cards modernos</option><option value="compact"<?= em_selected($settings['menu_layout']??'','compact') ?>>Lista compacta</option></select></label><label>Cabeçalho<select name="menu_header_style"><option value="gradient"<?= em_selected($settings['menu_header_style']??'gradient','gradient') ?>>Destaque em degradê</option><option value="solid"<?= em_selected($settings['menu_header_style']??'','solid') ?>>Cor sólida</option><option value="minimal"<?= em_selected($settings['menu_header_style']??'','minimal') ?>>Minimalista</option></select></label><div class="span-2 check-grid"><label class="checkbox"><input type="checkbox" name="menu_show_images"<?= em_checked($settings['menu_show_images']??true) ?>> Mostrar fotos dos produtos</label><label class="checkbox"><input type="checkbox" name="menu_show_search"<?= em_checked($settings['menu_show_search']??true) ?>> Mostrar busca</label><label class="checkbox"><input type="checkbox" name="menu_show_branding"<?= em_checked($settings['menu_show_branding']??true) ?>> Mostrar “Powered by EventMenu”</label></div></div></section>
<?php endif;?>
<button class="primary save-settings">Salvar alterações</button>
</div>

<?php if(TenantFeatures::menu($tenantId)):?><aside class="card live-preview"><span class="eyebrow">PRÉVIA DO CARDÁPIO</span><div class="preview-phone" style="--preview-primary:<?= Security::e($primary) ?>;--preview-bg:<?= Security::e($background) ?>;--preview-surface:<?= Security::e($surface) ?>;--preview-text:<?= Security::e($text) ?>"><div class="preview-cover"></div><div class="preview-body"><small>Seu cardápio</small><h3><?= Security::e(($settings['menu_public_title']??'') ?: $tenant['name']) ?></h3><p><?= Security::e(($settings['menu_subtitle']??'') ?: 'Peça do seu jeito. Rápido e fácil.') ?></p><div class="preview-search">Buscar no cardápio</div><div class="preview-product"><div><strong>Produto em destaque</strong><small>Descrição curta do produto</small></div><b>R$ 24,90</b></div><div class="preview-product"><div><strong>Outro produto</strong><small>Seu cardápio fica assim no celular</small></div><b>R$ 18,00</b></div><div class="preview-button">Adicionar ao pedido</div></div></div><p class="muted preview-note">A prévia mostra a identidade. Produtos e categorias reais aparecem no link público.</p></aside><?php endif;?>
</form>
<?php if(Auth::isSuperAdmin()):?><details class="technical-details"><summary>Informações técnicas</summary><div class="card"><p>Slug: <code><?= Security::e($tenant['slug']) ?></code><br>Plano: <span class="badge"><?= Security::e($tenant['plan']) ?></span><br>Operação: <span class="badge"><?= Security::e(TenantFeatures::label($tenantId)) ?></span><br>Status: <span class="badge"><?= Security::e($tenant['status']) ?></span></p><a class="button secondary" href="<?= Security::e(app_url('update.php')) ?>">Manutenção do banco</a></div></details><?php endif;?>
<?php em_footer();
