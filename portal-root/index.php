<?php
declare(strict_types=1);
require __DIR__ . '/lib/apk_registry.php';
$manifest = em_public_manifest();
$apps = $manifest['apps'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#0b0717">
  <meta name="description" content="EventMenu: seu restaurante, seus clientes, seu delivery. Gestão, pedidos, operação e aplicativos em um único ecossistema.">
  <title>EventMenu — Seu restaurante. Seus clientes. Seu delivery.</title>
  <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<header class="site-header">
  <div class="shell nav-wrap">
    <a class="brand" href="/" aria-label="EventMenu — início">
      <span class="brand-mark">E</span>
      <span class="brand-copy"><strong>EventMenu</strong><small>ecossistema para restaurantes</small></span>
    </a>
    <nav class="nav-links" aria-label="Navegação principal">
      <a href="#solucoes">Soluções</a>
      <a href="#recursos">Recursos</a>
      <a href="/download">Downloads</a>
      <a class="nav-login" href="/1/">Entrar no sistema</a>
    </nav>
  </div>
</header>

<main>
  <section class="hero shell">
    <div class="hero-copy">
      <span class="eyebrow">EVENTMENU · CANAL PRÓPRIO</span>
      <h1>Seu restaurante.<br><span>Seus clientes.</span><br>Seu delivery.</h1>
      <p>Venda diretamente, organize sua operação e transforme cada pedido em uma oportunidade de trazer o cliente de volta.</p>
      <div class="hero-actions">
        <a class="button primary" href="/download">Baixar aplicativos</a>
        <a class="button ghost" href="/1/">Já sou cliente</a>
      </div>
      <div class="hero-proof">
        <span>Pedidos</span><span>PIX</span><span>Cupons</span><span>WhatsApp</span><span>Delivery</span>
      </div>
    </div>

    <div class="hero-visual" aria-label="Prévia do ecossistema EventMenu">
      <div class="glow"></div>
      <div class="dashboard-card">
        <div class="dash-top"><span class="dash-logo">E</span><strong>Visão da operação</strong><span class="status">● Online</span></div>
        <div class="dash-grid">
          <div><small>Pedidos hoje</small><strong>128</strong><em>+18%</em></div>
          <div><small>Em preparo</small><strong>12</strong><em>agora</em></div>
          <div><small>Entregas</small><strong>09</strong><em>na rua</em></div>
        </div>
        <div class="order-list">
          <div><span>#1048</span><strong>2x X-Tudo</strong><b>Preparando</b></div>
          <div><span>#1047</span><strong>Pizza Grande</strong><b>Pronto</b></div>
          <div><span>#1046</span><strong>Açaí 500ml</strong><b>Entrega</b></div>
        </div>
      </div>
      <div class="phone-card">
        <div class="phone-speaker"></div>
        <span class="mini-brand">DELYVRE</span>
        <h3>Peça do seu jeito.</h3>
        <div class="food-shot">🍔</div>
        <div class="food-line"><strong>Combo da casa</strong><span>R$ 29,90</span></div>
        <button type="button" tabindex="-1">Adicionar ao pedido</button>
      </div>
    </div>
  </section>

  <section class="section shell" id="solucoes">
    <div class="section-heading">
      <span class="eyebrow">UM ECOSSISTEMA, VÁRIOS PONTOS DE CONTATO</span>
      <h2>Do pedido do cliente à operação da cozinha.</h2>
      <p>Cada produto tem uma função clara, mas todos trabalham juntos.</p>
    </div>
    <div class="solution-grid">
      <article class="solution featured">
        <span class="solution-tag">GESTÃO</span>
        <h3>EventMenu</h3>
        <p>Painel central para produtos, pedidos, clientes, campanhas, pagamentos, eventos e gestão da operação.</p>
      </article>
      <article class="solution">
        <span class="solution-tag">CLIENTE</span>
        <h3>DELYVRE</h3>
        <p>Canal de compra do consumidor no Android e na web, aproximando o cliente do estabelecimento.</p>
      </article>
      <article class="solution">
        <span class="solution-tag">EQUIPE</span>
        <h3>EventMenu GO</h3>
        <p>Pedidos, produção e delivery no celular para quem está na operação.</p>
      </article>
      <article class="solution">
        <span class="solution-tag">COMPUTADOR</span>
        <h3>EventMenu Desktop</h3>
        <p>Experiência para caixa, atendimento e pontos fixos de trabalho no estabelecimento.</p>
      </article>
    </div>
  </section>

  <section class="section dark-band" id="recursos">
    <div class="shell">
      <div class="section-heading compact">
        <span class="eyebrow">MENOS DEPENDÊNCIA. MAIS RELACIONAMENTO.</span>
        <h2>Seu canal de vendas trabalhando para o seu negócio.</h2>
      </div>
      <div class="feature-grid">
        <div><span>01</span><h3>Venda direta</h3><p>Leve o cliente para um canal próprio e reduza a dependência exclusiva de marketplaces.</p></div>
        <div><span>02</span><h3>Cliente recorrente</h3><p>Cupons, campanhas e relacionamento para incentivar novos pedidos.</p></div>
        <div><span>03</span><h3>Operação organizada</h3><p>Pedidos, cozinha, entrega e atendimento com mais visibilidade no dia a dia.</p></div>
        <div><span>04</span><h3>Pagamento integrado</h3><p>PIX e meios de pagamento conectados ao fluxo do pedido.</p></div>
        <div><span>05</span><h3>WhatsApp</h3><p>Comunicação automática de etapas do pedido e relacionamento com clientes.</p></div>
        <div><span>06</span><h3>Eventos</h3><p>Ingressos, convidados e operação de eventos dentro do mesmo ecossistema.</p></div>
      </div>
    </div>
  </section>

  <section class="section shell" id="downloads">
    <div class="download-panel">
      <div>
        <span class="eyebrow">CENTRAL OFICIAL DE DOWNLOADS</span>
        <h2>Baixe sempre a versão mais recente.</h2>
        <p>Os links oficiais permanecem os mesmos. Quando uma nova versão for publicada, o servidor passa a entregar automaticamente o APK atual.</p>
      </div>
      <div class="download-mini-list">
        <?php foreach ($apps as $slug => $app): ?>
          <div class="download-mini-item">
            <span class="app-icon"><?= em_h(strtoupper(substr((string)$app['name'], 0, 1))) ?></span>
            <div><strong><?= em_h((string)$app['name']) ?></strong><small><?= !empty($app['available']) ? 'Versão ' . em_h((string)$app['current']['version']) : 'Aguardando publicação' ?></small></div>
            <?php if (!empty($app['available'])): ?>
              <a href="<?= em_h((string)$app['download_url']) ?>">Baixar</a>
            <?php else: ?>
              <span class="disabled-link">Em breve</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <a class="text-link" href="/download">Abrir Central de Downloads →</a>
      </div>
    </div>
  </section>

  <section class="section shell final-cta">
    <span class="eyebrow">EVENTMENU</span>
    <h2>Mais controle para o restaurante.<br>Mais facilidade para o cliente.</h2>
    <div class="hero-actions center">
      <a class="button primary" href="/download">Baixar aplicativos</a>
      <a class="button ghost" href="/1/">Acessar meu painel</a>
    </div>
  </section>
</main>

<footer class="site-footer">
  <div class="shell footer-row">
    <div class="brand footer-brand"><span class="brand-mark">E</span><span class="brand-copy"><strong>EventMenu</strong><small>Seu restaurante. Seus clientes. Seu delivery.</small></span></div>
    <div class="footer-links"><a href="/download">Downloads</a><a href="/updates/apps.json">Versões</a><a href="/1/">Entrar</a></div>
  </div>
</footer>
</body>
</html>
