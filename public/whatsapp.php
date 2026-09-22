<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/../app/admin_helpers.php';

$tenantId = \EventMenu\Core\Auth::tenantId();
if ($tenantId && !\EventMenu\Core\TenantFeatures::moduleEnabled('whatsapp', (int)$tenantId)) {
    http_response_code(403);
    em_header('Módulo não habilitado', 'settings');
    echo '<section class="card"><span class="eyebrow">WHATSAPP</span><h2>Módulo não habilitado</h2><p class="muted">O WhatsApp Commerce e a Central de Atendimento estão desativados para esta empresa pelo Super ADM.</p><a class="button primary" href="'.\EventMenu\Core\Security::e(app_url('?route=settings')).'">Voltar às configurações</a></section>';
    em_footer();
    exit;
}

require __DIR__.'/../app/routes/whatsapp.php';
