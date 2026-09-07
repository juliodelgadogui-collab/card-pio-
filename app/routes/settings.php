<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\TenantBrandService;

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
        'whatsapp' => mb_substr(trim((string)($_POST['whatsapp'] ?? '')), 0, 30),

        // Identidade única da empresa — painel Web + EventMenu GO.
        'brand_display_name' => mb_substr(trim((string)($_POST['brand_display_name'] ?? '')), 0, 100),
        'brand_tagline' => mb_substr(trim((string)($_POST['brand_tagline'] ?? '')), 0, 160),
        'brand_logo_url' => $validUrl($_POST['brand_logo_url'] ?? ''),
        'brand_primary_color' => $validColor($_POST['brand_primary_color'] ?? '', '#5b34d6'),
        'brand_secondary_color' => $validColor($_POST['brand_secondary_color'] ?? '', '#159b63'),
        'brand_background_color' => $validColor($_POST['brand_background_color'] ?? '', '#f6f7fb'),
        'brand_surface_color' => $validColor($_POST['brand_surface_color'] ?? '', '#ffffff'),
        'brand_text_color' => $validColor($_POST['brand_text_color'] ?? '', '#1e1b2b'),
        'brand_apply_app' => isset($_POST['brand_apply_app']),
        'brand_apply_web' => isset($_POST['brand_apply_web']),
        'brand_show_eventmenu' => isset($_POST['brand_show_eventmenu']),

        // Personalização específica do cardápio público.
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

    $s = $pdo->prepare('UPDATE tenants SET name=?,settings=? WHERE id=?');
    $s->execute([$name, json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $tenantId]);
    $brand = (new TenantBrandService())->get($tenantId);
    Auth::audit('tenant.settings','tenant',(string)$tenantId,[
        'brand' => [
            'display_name' => $brand['display_name'],
            'primary_color' => $brand['primary_color'],
            'apply_app' => $brand['apply_app'],
            'apply_web' => $brand['apply_web'],
        ],
        'menu_customization' => TenantFeatures::menu($tenantId),
    ]);
    em_flash('ok','Configurações salvas. A identidade será usada no app e no painel conforme as opções escolhidas.');
    em_go('settings');
}

$brand = (new TenantBrandService())->get($tenantId);
$publicMenuUrl = app_url('loja.php?empresa='.rawurlencode((string)$tenant['slug']));
$primary = $validColor($settings['menu_primary_color'] ?? '', '#6236df');
$background = $validColor($settings['menu_background_color'] ?? '', '#f7f7fb');
$surface = $validColor($settings['menu_surface_color'] ?? '', '#ffffff');
$text = $validColor($settings['menu_text_color'] ?? '', '#242136');

em_header('Configurações','settings');
?>
<section class="page-hero">
  <div><span class="eyebrow">MINHA EMPRESA</span><h2>Marca, operação e canais</h2><p>Configure uma vez e use a identidade da empresa no painel Web e no EventMenu GO.</p></div>
  <?php if(TenantFeatures::menu($tenantId)):?><div class="hero-actions"><a class="button primary" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir cardápio público</a><button type="button" class="button secondary" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Link copiado ✓'">Copiar link</button></div><?php endif;?>
</section>

<form method="post" class="settings-layout">
<input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
<div class="settings-main">

<section class="card">
  <div class="section-head"><div><span class="eyebrow">EMPRESA</span><h2>Informações gerais</h2></div></div>
  <div class="form-grid">
    <label class="span-2">Nome cadastrado da empresa<input name="name" required value="<?= Security::e($tenant['name']) ?>"></label>
    <?php if(TenantFeatures::menu($tenantId)):?>
      <label>Taxa padrão de entrega<input name="delivery_fee" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,',','')) ?>"></label>
      <label>Pedido mínimo delivery<input name="min_delivery_order" inputmode="decimal" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,',','')) ?>"></label>
      <label class="span-2">WhatsApp<input name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>" placeholder="5522999999999"></label>
    <?php else:?>
      <input type="hidden" name="delivery_fee" value="<?= Security::e(number_format(((int)($settings['delivery_fee_cents']??0))/100,2,'.','')) ?>">
      <input type="hidden" name="min_delivery_order" value="<?= Security::e(number_format(((int)($settings['min_delivery_order_cents']??0))/100,2,'.','')) ?>">
      <input type="hidden" name="whatsapp" value="<?= Security::e($settings['whatsapp']??'') ?>">
    <?php endif;?>
    <label class="checkbox span-2"><input type="checkbox" name="points_enabled"<?= em_checked($settings['points_enabled']??true) ?>> Programa de pontos ativo</label>
  </div>
</section>

<section class="card" id="identidade">
  <div class="section-head">
    <div><span class="eyebrow">IDENTIDADE VISUAL</span><h2>App e painel Web</h2></div>
    <span class="status-pill active">Por empresa</span>
  </div>
  <p class="muted">Essas opções pertencem somente a esta empresa. Funcionários verão a identidade no EventMenu GO após o próximo acesso/sincronização, e o painel Web usa as mesmas cores.</p>
  <div class="form-grid" style="margin-top:14px">
    <label class="span-2">Nome exibido no sistema<input name="brand_display_name" value="<?= Security::e($settings['brand_display_name']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label>
    <label class="span-2">Frase da empresa<input name="brand_tagline" value="<?= Security::e($settings['brand_tagline']??'') ?>" placeholder="Ex.: Sabor feito do nosso jeito"></label>
    <label class="span-2">Logo da empresa (URL opcional)<input type="url" name="brand_logo_url" value="<?= Security::e($settings['brand_logo_url']??($settings['menu_logo_url']??'')) ?>" placeholder="https://..."></label>
  </div>
  <div class="appearance-grid" style="margin-top:16px">
    <label>Cor principal<div class="color-field"><input type="color" name="brand_primary_color" value="<?= Security::e($brand['primary_color']) ?>"><span><?= Security::e($brand['primary_color']) ?></span></div></label>
    <label>Cor de apoio<div class="color-field"><input type="color" name="brand_secondary_color" value="<?= Security::e($brand['secondary_color']) ?>"><span><?= Security::e($brand['secondary_color']) ?></span></div></label>
    <label>Fundo<div class="color-field"><input type="color" name="brand_background_color" value="<?= Security::e($brand['background_color']) ?>"><span><?= Security::e($brand['background_color']) ?></span></div></label>
    <label>Cards<div class="color-field"><input type="color" name="brand_surface_color" value="<?= Security::e($brand['surface_color']) ?>"><span><?= Security::e($brand['surface_color']) ?></span></div></label>
    <label>Texto<div class="color-field"><input type="color" name="brand_text_color" value="<?= Security::e($brand['text_color']) ?>"><span><?= Security::e($brand['text_color']) ?></span></div></label>
  </div>
  <div class="check-grid" style="margin-top:16px">
    <label class="checkbox"><input type="checkbox" name="brand_apply_app"<?= em_checked($brand['apply_app']) ?>> Usar identidade no EventMenu GO</label>
    <label class="checkbox"><input type="checkbox" name="brand_apply_web"<?= em_checked($brand['apply_web']) ?>> Usar identidade no painel Web</label>
    <label class="checkbox"><input type="checkbox" name="brand_show_eventmenu"<?= em_checked($brand['show_eventmenu_brand']) ?>> Mostrar assinatura “EventMenu”</label>
  </div>
  <div class="brand-inline-preview" style="--brand-primary:<?= Security::e($brand['primary_color']) ?>;--brand-secondary:<?= Security::e($brand['secondary_color']) ?>;--brand-bg:<?= Security::e($brand['background_color']) ?>;--brand-surface:<?= Security::e($brand['surface_color']) ?>;--brand-text:<?= Security::e($brand['text_color']) ?>">
    <div class="brand-inline-mark"><?= Security::e(mb_strtoupper(mb_substr($brand['display_name'],0,1))) ?></div>
    <div><strong><?= Security::e($brand['display_name']) ?></strong><span><?= Security::e($brand['tagline'] ?: 'Sua identidade no app e no painel') ?></span></div>
    <button type="button">Ação principal</button>
  </div>
</section>

<?php if(TenantFeatures::menu($tenantId)):?>
<section class="card" id="cardapio">
  <div class="section-head"><div><span class="eyebrow">CARDÁPIO PÚBLICO</span><h2>Conteúdo do cliente</h2></div><span class="status-pill active">Publicado</span></div>
  <div class="public-link-box"><div><small>Seu link público</small><strong><?= Security::e($publicMenuUrl) ?></strong></div><div class="actions"><a class="button secondary compact" target="_blank" rel="noopener" href="<?= Security::e($publicMenuUrl) ?>">Abrir</a><button type="button" class="secondary compact" onclick="navigator.clipboard?.writeText(<?= Security::e(json_encode($publicMenuUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) ?>);this.textContent='Copiado ✓'">Copiar</button></div></div>
  <div class="form-grid">
    <label class="span-2">Título exibido<input name="menu_public_title" value="<?= Security::e($settings['menu_public_title']??'') ?>" placeholder="<?= Security::e($tenant['name']) ?>"></label>
    <label class="span-2">Frase de destaque<input name="menu_subtitle" value="<?= Security::e($settings['menu_subtitle']??'') ?>" placeholder="Peça do seu jeito. Rápido e fácil."></label>
    <label class="span-2">Mensagem no cardápio<textarea name="menu_message" placeholder="Ex.: Entregamos todos os dias até 23h."><?= Security::e($settings['menu_message']??'') ?></textarea></label>
    <label class="span-2">Logo específica do cardápio (opcional)<input type="url" name="menu_logo_url" value="<?= Security::e($settings['menu_logo_url']??'') ?>" placeholder="Se vazio, pode usar a marca principal"></label>
    <label class="span-2">Capa (URL opcional)<input type="url" name="menu_cover_url" value="<?= Security::e($settings['menu_cover_url']??'') ?>" placeholder="https://..."></label>
  </div>
</section>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">CARDÁPIO</span><h2>Aparência da página pública</h2></div></div>
  <p class="muted">Use estas cores quando quiser que o cardápio público tenha uma apresentação diferente do painel interno.</p>
  <div class="appearance-grid">
    <label>Cor principal<div class="color-field"><input type="color" name="menu_primary_color" value="<?= Security::e($primary) ?>"><span><?= Security::e($primary) ?></span></div></label>
    <label>Fundo<div class="color-field"><input type="color" name="menu_background_color" value="<?= Security::e($background) ?>"><span><?= Security::e($background) ?></span></div></label>
    <label>Cards<div class="color-field"><input type="color" name="menu_surface_color" value="<?= Security::e($surface) ?>"><span><?= Security::e($surface) ?></span></div></label>
    <label>Texto<div class="color-field"><input type="color" name="menu_text_color" value="<?= Security::e($text) ?>"><span><?= Security::e($text) ?></span></div></label>
  </div>
  <div class="form-grid" style="margin-top:18px">
    <label>Layout dos produtos<select name="menu_layout"><option value="cards"<?= em_selected($settings['menu_layout']??'cards','cards') ?>>Cards modernos</option><option value="compact"<?= em_selected($settings['menu_layout']??'','compact') ?>>Lista compacta</option></select></label>
    <label>Cabeçalho<select name="menu_header_style"><option value="gradient"<?= em_selected($settings['menu_header_style']??'gradient','gradient') ?>>Destaque em degradê</option><option value="solid"<?= em_selected($settings['menu_header_style']??'','solid') ?>>Cor sólida</option><option value="minimal"<?= em_selected($settings['menu_header_style']??'','minimal') ?>>Minimalista</option></select></label>
    <div class="span-2 check-grid">
      <label class="checkbox"><input type="checkbox" name="menu_show_images"<?= em_checked($settings['menu_show_images']??true) ?>> Mostrar fotos dos produtos</label>
      <label class="checkbox"><input type="checkbox" name="menu_show_search"<?= em_checked($settings['menu_show_search']??true) ?>> Mostrar busca</label>
      <label class="checkbox"><input type="checkbox" name="menu_show_branding"<?= em_checked($settings['menu_show_branding']??true) ?>> Mostrar “Powered by EventMenu”</label>
    </div>
  </div>
</section>
<?php else:?>
  <input type="hidden" name="menu_public_title" value="<?= Security::e($settings['menu_public_title']??'') ?>">
  <input type="hidden" name="menu_subtitle" value="<?= Security::e($settings['menu_subtitle']??'') ?>">
  <input type="hidden" name="menu_message" value="<?= Security::e($settings['menu_message']??'') ?>">
  <input type="hidden" name="menu_logo_url" value="<?= Security::e($settings['menu_logo_url']??'') ?>">
  <input type="hidden" name="menu_cover_url" value="<?= Security::e($settings['menu_cover_url']??'') ?>">
  <input type="hidden" name="menu_primary_color" value="<?= Security::e($primary) ?>">
  <input type="hidden" name="menu_background_color" value="<?= Security::e($background) ?>">
  <input type="hidden" name="menu_surface_color" value="<?= Security::e($surface) ?>">
  <input type="hidden" name="menu_text_color" value="<?= Security::e($text) ?>">
  <input type="hidden" name="menu_layout" value="<?= Security::e($settings['menu_layout']??'cards') ?>">
  <input type="hidden" name="menu_header_style" value="<?= Security::e($settings['menu_header_style']??'gradient') ?>">
<?php endif;?>

<button class="primary save-settings">Salvar alterações</button>
</div>

<?php if(TenantFeatures::menu($tenantId)):?><aside class="card live-preview"><span class="eyebrow">PRÉVIA DO CARDÁPIO</span><div class="preview-phone" style="--preview-primary:<?= Security::e($primary) ?>;--preview-bg:<?= Security::e($background) ?>;--preview-surface:<?= Security::e($surface) ?>;--preview-text:<?= Security::e($text) ?>"><div class="preview-cover"></div><div class="preview-body"><small>Seu cardápio</small><h3><?= Security::e(($settings['menu_public_title']??'') ?: $tenant['name']) ?></h3><p><?= Security::e(($settings['menu_subtitle']??'') ?: 'Peça do seu jeito. Rápido e fácil.') ?></p><div class="preview-search">Buscar no cardápio</div><div class="preview-product"><div><strong>Produto em destaque</strong><small>Descrição curta do produto</small></div><b>R$ 24,90</b></div><div class="preview-product"><div><strong>Outro produto</strong><small>Seu cardápio fica assim no celular</small></div><b>R$ 18,00</b></div><div class="preview-button">Adicionar ao pedido</div></div></div><p class="muted preview-note">A prévia mostra a página pública. A identidade geral do app e painel é configurada acima.</p></aside><?php endif;?>
</form>

<?php if(Auth::isSuperAdmin()):?><details class="technical-details"><summary>Informações técnicas</summary><div class="card"><p>Slug: <code><?= Security::e($tenant['slug']) ?></code><br>Plano: <span class="badge"><?= Security::e($tenant['plan']) ?></span><br>Operação: <span class="badge"><?= Security::e(TenantFeatures::label($tenantId)) ?></span><br>Status: <span class="badge"><?= Security::e($tenant['status']) ?></span></p><a class="button secondary" href="<?= Security::e(app_url('update.php')) ?>">Manutenção do banco</a></div></details><?php endif;?>
<?php em_footer();
