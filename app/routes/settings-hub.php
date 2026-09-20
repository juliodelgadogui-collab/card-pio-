<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Core\TenantFeatures;
use EventMenu\Services\MailSettingsService;

Auth::requirePermission('settings.manage');
$tenantId = em_require_tenant();
$mail = (new MailSettingsService())->get($tenantId);

em_header('Configurações','settings');
?>
<section class="page-hero">
  <div>
    <span class="eyebrow">CENTRAL DE CONFIGURAÇÕES</span>
    <h2>Deixe o sistema com a cara e as regras da sua empresa</h2>
    <p>As opções foram separadas para ficar mais fácil encontrar o que você precisa sem mexer em configurações técnicas.</p>
  </div>
  <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=onboarding')) ?>">Checklist de implantação</a></div>
</section>

<div class="metric-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));margin-bottom:20px">
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=settings-general')) ?>">
    <span class="eyebrow">EMPRESA</span><h3>Geral e aparência</h3><p class="muted">Nome, operação, cores, identidade do painel e personalização do cardápio.</p><span class="button secondary compact">Abrir</span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=media-settings')) ?>">
    <span class="eyebrow">IMAGENS</span><h3>Logo e capa</h3><p class="muted">Envie arquivos direto do celular ou computador, sem precisar colar URL.</p><span class="button secondary compact">Gerenciar imagens</span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=email-settings')) ?>">
    <span class="eyebrow">E-MAIL</span><h3>Servidor SMTP</h3><p class="muted"><?= !empty($mail['enabled']) ? 'Envio de e-mail está configurado e ativo.' : 'Configure remetente, servidor, usuário, senha e teste de envio.' ?></p><span class="status-pill <?= !empty($mail['enabled']) ? 'active' : '' ?>"><?= !empty($mail['enabled']) ? 'Ativo' : 'Configurar' ?></span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=customers')) ?>">
    <span class="eyebrow">FIDELIDADE</span><h3>Clientes e pontos</h3><p class="muted">Programa de pontos, saldos, extrato e regras de resgate.</p><span class="button secondary compact">Abrir pontos</span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=receipt-settings')) ?>">
    <span class="eyebrow">IMPRESSÃO</span><h3>Cupom e impressora</h3><p class="muted">Ajuste o comprovante operacional e a impressão térmica.</p><span class="button secondary compact">Configurar</span>
  </a>
  <?php if(TenantFeatures::menu($tenantId)):?>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('delivery-settings.php')) ?>">
    <span class="eyebrow">DELIVERY</span><h3>App do cliente</h3><p class="muted">Taxa, pedido mínimo, raio, Pix, cartão, dinheiro e disponibilidade no EventMenu Delivery.</p><span class="button secondary compact">Configurar Delivery</span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('?route=gateways')) ?>">
    <span class="eyebrow">PAGAMENTOS</span><h3>Mercado Pago / PagBank</h3><p class="muted">Pix, cartão online e PagBank Tap On. Mercado Pago não é oferecido como NFC interno.</p><span class="button secondary compact">Abrir pagamentos</span>
  </a>
  <a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(app_url('bank-pix-settings.php')) ?>">
    <span class="eyebrow">PIX BANCÁRIO</span><h3>Efí e Banco Inter</h3><p class="muted">Conecte APIs Pix com OAuth2, certificado e conciliação automática.</p><span class="button secondary compact">Configurar bancos</span>
  </a>
  <?php endif;?>
</div>

<section class="card">
  <div class="section-head"><div><span class="eyebrow">PRIMEIRO ACESSO</span><h2>Precisa configurar uma empresa nova?</h2></div></div>
  <p class="muted">O checklist orienta a sequência recomendada: empresa, imagens, unidade, produtos, pagamentos, equipe e e-mail.</p>
  <a class="button primary" href="<?= Security::e(app_url('?route=onboarding')) ?>">Abrir checklist</a>
</section>
<?php em_footer(); ?>
