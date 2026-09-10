<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\PlatformFailoverService;

Auth::requirePermission('platform.manage');
if (!Auth::isSuperAdmin()) { http_response_code(403); exit('Acesso restrito ao Super ADM.'); }

$service = new PlatformFailoverService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $saved = $service->save([
                'enabled' => isset($_POST['enabled']),
                'primary_url' => $_POST['primary_url'] ?? '',
                'contingency_url' => $_POST['contingency_url'] ?? '',
                'mode' => $_POST['mode'] ?? 'read_only',
                'new_secret' => $_POST['new_secret'] ?? '',
            ]);
            if (!empty($saved['_secret_once'])) $_SESSION['_failover_secret_once'] = (string)$saved['_secret_once'];
            em_flash('ok', 'Configuração de contingência salva. Use "Testar servidor adicional" depois de subir e configurar o segundo servidor.');
            em_go('platform-failover');
        }

        if ($action === 'test') {
            $result = $service->probeSecondary();
            $ok = (string)($result['status'] ?? '') === 'healthy';
            em_flash($ok ? 'ok' : 'error', (string)($result['message'] ?? 'Teste concluído.'));
            em_go('platform-failover');
        }

        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $e) {
        em_flash('error', $e->getMessage());
        em_go('platform-failover');
    }
}

$settings = $service->get();
$secretOnce = (string)($_SESSION['_failover_secret_once'] ?? '');
unset($_SESSION['_failover_secret_once']);
$status = (string)$settings['last_health_status'];
$statusLabels = [
    'unknown' => 'Ainda não testado',
    'healthy' => 'Disponível',
    'degraded' => 'Atenção',
    'offline' => 'Offline',
    'misconfigured' => 'Configuração incompleta',
];

em_header('Servidor de contingência', 'platform-failover');
?>
<section class="page-hero">
  <div>
    <span class="eyebrow">ALTA DISPONIBILIDADE</span>
    <h2>Servidor principal + servidor de contingência</h2>
    <p>O EventMenu GO guarda a rota do servidor adicional e troca automaticamente quando o principal fica indisponível. A troca é feita com regras que evitam repetir pedidos e pagamentos.</p>
  </div>
  <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=system-health')) ?>">Saúde do sistema</a></div>
</section>

<div class="grid" style="grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:18px;align-items:start">
<section class="card">
  <div class="section-head"><div><span class="eyebrow">ROTEAMENTO</span><h2>Configuração do cluster</h2></div><span class="status-pill <?= $status === 'healthy' ? 'active' : '' ?>"><?= Security::e($statusLabels[$status] ?? $status) ?></span></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
    <input type="hidden" name="action" value="save">
    <label class="checkbox span-2"><input type="checkbox" name="enabled"<?= em_checked($settings['enabled']) ?>> Ativar contingência no EventMenu GO</label>
    <label class="span-2">Servidor principal<input name="primary_url" value="<?= Security::e((string)$settings['primary_url']) ?>" placeholder="https://principal.exemplo.com/1" required><small>Use a URL completa da instalação, incluindo /1 quando existir.</small></label>
    <label class="span-2">Servidor adicional<input name="contingency_url" value="<?= Security::e((string)$settings['contingency_url']) ?>" placeholder="https://backup.exemplo.com/1"><small>Precisa usar HTTPS e conter a mesma versão do EventMenu.</small></label>
    <label>Modo<select name="mode">
      <option value="read_only"<?= em_selected($settings['mode'], 'read_only') ?>>Somente leitura</option>
      <option value="shared_db"<?= em_selected($settings['mode'], 'shared_db') ?>>Completo — leitura e escrita</option>
    </select></label>
    <label>Novo segredo do cluster<input type="password" name="new_secret" autocomplete="new-password" placeholder="Deixe vazio para manter"><small>Preencha apenas para trocar o segredo. Mínimo de 32 caracteres.</small></label>
    <div class="span-2 actions"><button class="primary" type="submit">Salvar configuração</button></div>
  </form>

  <?php if($secretOnce !== ''): ?>
  <div class="alert" style="margin-top:16px">
    <strong>Segredo criado agora — copie e guarde:</strong>
    <div style="font-family:monospace;word-break:break-all;margin-top:8px"><?= Security::e($secretOnce) ?></div>
    <p style="margin-bottom:0">Se o servidor adicional usar o mesmo banco e a mesma APP_KEY, ele consegue ler a configuração compartilhada. Em uma instalação separada, use este valor em <code>EVENTMENU_CLUSTER_SECRET</code>.</p>
  </div>
  <?php endif; ?>
</section>

<aside class="grid" style="gap:12px">
  <section class="card">
    <span class="eyebrow">STATUS</span><h3><?= Security::e($statusLabels[$status] ?? $status) ?></h3>
    <p class="muted"><?= Security::e((string)($settings['last_health_message'] ?: 'O servidor adicional ainda não foi verificado.')) ?></p>
    <?php if($settings['last_health_checked_at']): ?><p><strong>Último teste:</strong> <?= Security::e((string)$settings['last_health_checked_at']) ?></p><?php endif; ?>
    <?php if($settings['verified_at']): ?><p><strong>Verificado desde:</strong> <?= Security::e((string)$settings['verified_at']) ?></p><?php endif; ?>
    <?php if($settings['last_health_db_driver']): ?><p><strong>Banco informado:</strong> <?= Security::e((string)$settings['last_health_db_driver']) ?><br><strong>Escrita:</strong> <?= !empty($settings['last_health_writable']) ? 'sim' : 'não' ?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="test"><button class="secondary" style="width:100%"<?= empty($settings['enabled']) || empty($settings['contingency_url']) ? ' disabled' : '' ?>>Testar servidor adicional</button></form>
  </section>
  <section class="card"><span class="eyebrow">CLUSTER</span><h3>Identificação</h3><p class="muted">ID usado para impedir que o aplicativo seja redirecionado para outro EventMenu por engano.</p><code style="word-break:break-all"><?= Security::e((string)($settings['cluster_id'] ?: 'Será criado ao salvar')) ?></code><p class="muted" style="margin-bottom:0">Versão da configuração: <?= (int)$settings['config_version'] ?></p></section>
</aside>
</div>

<section class="card" style="margin-top:18px">
  <div class="section-head"><div><span class="eyebrow">SERVIDOR ADICIONAL</span><h2>Como deixar o segundo servidor pronto</h2></div></div>
  <p>No modo <strong>Completo</strong>, os dois servidores web devem apontar para o <strong>mesmo MySQL/MariaDB disponível fora do servidor principal</strong> (ou para uma replicação de banco com failover próprio). Use também a mesma <strong>APP_KEY</strong>, porque as credenciais criptografadas precisam ser lidas pelos dois nós.</p>
  <p>Na instalação adicional, configure <code>APP_URL</code> com a URL do servidor adicional, mantenha o mesmo <code>APP_BASE_PATH</code> e use <code>EVENTMENU_NODE_ROLE=contingency</code>. Depois volte aqui e clique em <strong>Testar servidor adicional</strong>.</p>
  <div class="alert">Dois arquivos SQLite independentes não entram no modo de escrita automática. Isso evitaria divergência de estoque, pagamentos e pedidos. Para SQLite, use o modo <strong>Somente leitura</strong>.</div>
</section>

<section class="card" style="margin-top:18px">
  <span class="eyebrow">COMPORTAMENTO DO APK</span><h2>Troca sem duplicar ações</h2>
  <p class="muted">Consultas GET podem ser repetidas automaticamente no servidor adicional. Se uma gravação POST cair no meio da transmissão, o app troca o servidor ativo mas não repete aquela mesma gravação automaticamente; ele pede para o operador repetir a ação. Isso evita criar pedido, baixa de estoque ou pagamento duas vezes quando o principal processou a requisição mas a resposta se perdeu.</p>
</section>
<?php em_footer(); ?>
