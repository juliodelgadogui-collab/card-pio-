<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/lib/apk_registry.php';

$token = trim((string) getenv('EVENTMENU_APK_ADMIN_TOKEN'));
$enabled = strlen($token) >= 20;
$error = null;
$success = null;

if (isset($_GET['logout'])) {
    unset($_SESSION['apk_admin_ok']);
    header('Location: /admin-apks.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $provided = (string) ($_POST['token'] ?? '');
    if ($enabled && hash_equals($token, $provided)) {
        session_regenerate_id(true);
        $_SESSION['apk_admin_ok'] = true;
        $_SESSION['apk_csrf'] = bin2hex(random_bytes(24));
        header('Location: /admin-apks.php');
        exit;
    }
    $error = 'Token administrativo inválido.';
}

$authenticated = $enabled && !empty($_SESSION['apk_admin_ok']);

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish') {
    if (!hash_equals((string)($_SESSION['apk_csrf'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $error = 'Sessão expirada. Atualize a página.';
    } elseif (!isset($_FILES['apk']) || !is_array($_FILES['apk'])) {
        $error = 'Selecione um APK.';
    } elseif ((int)$_FILES['apk']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Falha no upload. Código: ' . (int)$_FILES['apk']['error'] . '. Verifique upload_max_filesize e post_max_size no PHP.';
    } else {
        try {
            $meta = em_publish_apk(
                strtolower(trim((string)($_POST['app'] ?? ''))),
                trim((string)($_POST['version'] ?? '')),
                (int)($_POST['version_code'] ?? 0),
                (string)$_FILES['apk']['tmp_name'],
                (string)$_FILES['apk']['name']
            );
            $success = 'APK publicado. Versão ' . $meta['version'] . ' agora é a versão atual.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$manifest = em_public_manifest();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0b0717">
  <meta name="robots" content="noindex,nofollow">
  <title>Publicar APK — EventMenu</title>
  <link rel="stylesheet" href="/assets/site.css">
</head>
<body class="admin-body">
<main class="admin-shell">
  <a class="brand admin-brand" href="/"><span class="brand-mark">E</span><span class="brand-copy"><strong>EventMenu</strong><small>publicação de aplicativos</small></span></a>

  <?php if (!$enabled): ?>
    <section class="admin-card"><span class="eyebrow">CONFIGURAÇÃO NECESSÁRIA</span><h1>Publicação de APK desativada</h1><p>Defina no servidor a variável <code>EVENTMENU_APK_ADMIN_TOKEN</code> com um token forte de pelo menos 20 caracteres. Sem essa variável, esta página não aceita login nem uploads.</p><a class="button ghost" href="/">Voltar ao portal</a></section>
  <?php elseif (!$authenticated): ?>
    <section class="admin-card narrow"><span class="eyebrow">ÁREA RESTRITA</span><h1>Publicar aplicativos</h1><p>Digite o token administrativo configurado no servidor.</p><?php if ($error): ?><div class="alert error"><?= em_h($error) ?></div><?php endif; ?><form method="post" class="admin-form"><input type="hidden" name="action" value="login"><label>Token<input type="password" name="token" required autocomplete="current-password"></label><button class="button primary full" type="submit">Entrar</button></form></section>
  <?php else: ?>
    <div class="admin-head"><div><span class="eyebrow">CENTRAL DE PUBLICAÇÃO</span><h1>APKs oficiais</h1><p>Ao publicar, a versão atual vira anterior e a terceira versão é apagada automaticamente.</p></div><a class="button ghost" href="?logout=1">Sair</a></div>
    <?php if ($error): ?><div class="alert error"><?= em_h($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= em_h($success) ?></div><?php endif; ?>

    <div class="admin-grid">
      <section class="admin-card">
        <h2>Publicar nova versão</h2>
        <form method="post" enctype="multipart/form-data" class="admin-form">
          <input type="hidden" name="action" value="publish">
          <input type="hidden" name="_csrf" value="<?= em_h((string)($_SESSION['apk_csrf'] ?? '')) ?>">
          <label>Aplicativo<select name="app" required><?php foreach ($manifest['apps'] as $slug => $app): ?><option value="<?= em_h($slug) ?>"><?= em_h((string)$app['name']) ?></option><?php endforeach; ?></select></label>
          <div class="form-row"><label>Versão<input type="text" name="version" placeholder="1.4.2" required></label><label>Version Code<input type="number" name="version_code" min="1" step="1" placeholder="142" required></label></div>
          <label>Arquivo APK<input type="file" name="apk" accept=".apk,application/vnd.android.package-archive" required></label>
          <button class="button primary full" type="submit">Publicar APK</button>
        </form>
      </section>

      <section class="admin-card">
        <h2>Versões no servidor</h2>
        <div class="admin-app-list">
          <?php foreach ($manifest['apps'] as $slug => $app): ?>
            <div class="admin-app-item"><div><strong><?= em_h((string)$app['name']) ?></strong><small><?= em_h($slug) ?></small></div><div><?php if (!empty($app['available'])): ?><strong>v<?= em_h((string)$app['current']['version']) ?></strong><small><?= em_h(em_format_bytes((int)$app['current']['size_bytes'])) ?></small><?php else: ?><strong>Sem APK</strong><small>não publicado</small><?php endif; ?></div></div>
          <?php endforeach; ?>
        </div>
        <p class="admin-tip">Uso recomendado: atual + anterior por aplicativo. O sistema faz essa rotação automaticamente para preservar o espaço de 500 MB.</p>
      </section>
    </div>
  <?php endif; ?>
</main>
</body>
</html>
