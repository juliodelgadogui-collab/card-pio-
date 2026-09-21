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
  <meta name="description" content="Downloads oficiais dos aplicativos EventMenu.">
  <title>Downloads oficiais — EventMenu</title>
  <link rel="stylesheet" href="/assets/site.css">
</head>
<body>
<header class="site-header">
  <div class="shell nav-wrap">
    <a class="brand" href="/"><span class="brand-mark">E</span><span class="brand-copy"><strong>EventMenu</strong><small>downloads oficiais</small></span></a>
    <nav class="nav-links"><a href="/">Início</a><a class="nav-login" href="/1/">Entrar no sistema</a></nav>
  </div>
</header>
<main class="download-page shell">
  <div class="section-heading download-heading">
    <span class="eyebrow">DOWNLOADS OFICIAIS</span>
    <h1>Aplicativos EventMenu</h1>
    <p>Baixe por aqui para garantir que você está usando o arquivo publicado pelo EventMenu.</p>
  </div>

  <div class="app-download-grid">
    <?php foreach ($apps as $slug => $app): ?>
      <?php $current = $app['current'] ?? null; ?>
      <article class="app-download-card">
        <div class="app-card-top">
          <span class="app-icon large"><?= em_h(strtoupper(substr((string)$app['name'], 0, 1))) ?></span>
          <div><span class="platform-pill"><?= em_h((string)$app['platform']) ?></span><h2><?= em_h((string)$app['name']) ?></h2><small><?= em_h((string)$app['audience']) ?></small></div>
        </div>
        <p><?= em_h((string)$app['description']) ?></p>

        <?php if (!empty($app['available']) && is_array($current)): ?>
          <dl class="version-meta">
            <div><dt>Versão</dt><dd><?= em_h((string)$current['version']) ?></dd></div>
            <div><dt>Tamanho</dt><dd><?= em_h(em_format_bytes((int)$current['size_bytes'])) ?></dd></div>
            <div><dt>Publicada</dt><dd><?= em_h(date('d/m/Y', strtotime((string)$current['published_at']))) ?></dd></div>
          </dl>
          <a class="button primary full" href="<?= em_h((string)$app['download_url']) ?>">Baixar APK</a>
          <details class="checksum"><summary>Ver SHA-256</summary><code><?= em_h((string)$current['sha256']) ?></code></details>
        <?php else: ?>
          <div class="not-published"><strong>Aguardando primeira publicação</strong><p>O botão será liberado automaticamente assim que o primeiro APK for enviado ao servidor.</p></div>
          <span class="button disabled full">Ainda não disponível</span>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <section class="install-help">
    <div><span class="eyebrow">ANDROID</span><h2>Instalação fora da Play Store</h2><p>Ao abrir o APK, o Android pode pedir autorização para instalar aplicativos dessa fonte. Autorize somente para este download oficial e conclua a instalação.</p></div>
    <div class="security-note"><strong>Link permanente</strong><p>Os endereços <code>/apk/.../latest.apk</code> nunca precisam mudar. Eles sempre entregam a versão atual cadastrada.</p></div>
  </section>
</main>
<footer class="site-footer"><div class="shell footer-row"><div>© <?= date('Y') ?> EventMenu</div><div class="footer-links"><a href="/">Início</a><a href="/updates/apps.json">Versões</a></div></div></footer>
</body>
</html>
