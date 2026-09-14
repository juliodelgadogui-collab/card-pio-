<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\ClientPolicyService;
use EventMenu\Services\ClientReleaseService;
use EventMenu\Services\ClusterSyncService;
use EventMenu\Services\PlatformFailoverService;

Auth::requirePermission('platform.manage');
if (!Auth::isSuperAdmin()) { http_response_code(403); exit('Acesso restrito ao Super ADM.'); }

$service = new PlatformFailoverService();
$policyService = new ClientPolicyService();
$releaseService = new ClientReleaseService();
$syncService = new ClusterSyncService();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    try {
        if ($action === 'save') {
            $service->save([
                'enabled' => isset($_POST['enabled']),
                'primary_url' => $_POST['primary_url'] ?? '',
                'contingency_url' => $_POST['contingency_url'] ?? '',
                'mode' => $_POST['mode'] ?? 'read_only',
                'new_secret' => $_POST['new_secret'] ?? '',
            ]);
            em_flash('ok', 'Configuração salva. Se o servidor adicional ainda não foi pareado, use "Inicializar servidor adicional".');
            em_go('platform-failover');
        }

        if ($action === 'bootstrap') {
            $result = $syncService->bootstrapSecondary((string)($_POST['bootstrap_token'] ?? ''));
            em_flash(!empty($result['ok']) ? 'ok' : 'error', (string)($result['message'] ?? 'Inicialização concluída.'));
            em_go('platform-failover');
        }

        if ($action === 'test') {
            $result = $service->probeSecondary();
            $ok = (string)($result['status'] ?? '') === 'healthy';
            em_flash($ok ? 'ok' : 'error', (string)($result['message'] ?? 'Teste concluído.'));
            em_go('platform-failover');
        }

        if ($action === 'sync') {
            $result = $syncService->pushControlPlane();
            em_flash(!empty($result['ok']) ? 'ok' : 'error', (string)($result['message'] ?? 'Sincronização concluída.'));
            em_go('platform-failover');
        }

        if ($action === 'policy-save') {
            $platform = strtolower(trim((string)($_POST['platform'] ?? 'android')));
            $features = [];
            foreach ((array)($_POST['features'] ?? []) as $feature) {
                $name = preg_replace('/[^a-z0-9_.-]/i', '', (string)$feature) ?? '';
                if ($name !== '') $features[$name] = true;
            }

            $pdo = Database::connection();
            $started = !$pdo->inTransaction();
            if ($started) $pdo->beginTransaction();
            try {
                $policyService->save($platform, [
                    'enabled' => isset($_POST['policy_enabled']),
                    'maintenance' => isset($_POST['maintenance']),
                    'maintenance_message' => $_POST['maintenance_message'] ?? '',
                    'min_version' => $_POST['min_version'] ?? '',
                    'recommended_version' => $_POST['recommended_version'] ?? '',
                    'ttl_seconds' => $_POST['ttl_seconds'] ?? 86400,
                    'features' => $features,
                    'allowed_signing_fingerprints' => $_POST['allowed_signing_fingerprints'] ?? '',
                ]);
                $releaseService->save($platform, [
                    'published' => isset($_POST['release_published']),
                    'version' => $_POST['release_version'] ?? '',
                    'download_url' => $_POST['release_url'] ?? '',
                    'sha256' => $_POST['release_sha256'] ?? '',
                    'release_notes' => $_POST['release_notes'] ?? '',
                ]);
                if ($started && $pdo->inTransaction()) $pdo->commit();
            } catch (Throwable $e) {
                if ($started && $pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $settings = $service->get();
            $autoSynced = false;
            if (!empty($settings['enabled']) && (string)$settings['last_health_status'] === 'healthy') {
                try { $syncService->pushControlPlane(); $autoSynced = true; } catch (Throwable) {}
            }
            em_flash('ok', 'Política de ' . strtoupper($platform) . ' salva.' . ($autoSynced ? ' O servidor adicional também foi atualizado.' : ' Sincronize o servidor adicional quando ele estiver disponível.'));
            em_go('platform-failover');
        }

        throw new RuntimeException('Ação inválida.');
    } catch (Throwable $e) {
        em_flash('error', $e->getMessage());
        em_go('platform-failover');
    }
}

$settings = $service->get();
$androidPolicy = $policyService->get('android');
$windowsPolicy = $policyService->get('windows');
$androidRelease = $releaseService->get('android');
$windowsRelease = $releaseService->get('windows');
$policyKey = null;
try { $policyKey = $policyService->publicKeyBundle(); } catch (Throwable) {}
$status = (string)$settings['last_health_status'];
$statusLabels = [
    'unknown' => 'Ainda não testado',
    'healthy' => 'Disponível',
    'degraded' => 'Atenção',
    'offline' => 'Offline',
    'misconfigured' => 'Configuração incompleta',
];

$renderPolicy = static function(array $policy, array $release, string $title, array $featureLabels): void {
    $platform = (string)$policy['platform'];
    $features = (array)$policy['features'];
    $fingerprints = implode("\n", (array)$policy['allowed_signing_fingerprints']);
    ?>
    <section class="card">
      <div class="section-head"><div><span class="eyebrow">AUTORIZAÇÃO REMOTA</span><h2><?= Security::e($title) ?></h2></div><span class="badge">v<?= (int)$policy['config_version'] ?></span></div>
      <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
        <input type="hidden" name="action" value="policy-save">
        <input type="hidden" name="platform" value="<?= Security::e($platform) ?>">
        <label class="checkbox"><input type="checkbox" name="policy_enabled"<?= em_checked($policy['enabled']) ?>> Permitir este aplicativo/programa</label>
        <label class="checkbox"><input type="checkbox" name="maintenance"<?= em_checked($policy['maintenance']) ?>> Modo manutenção</label>
        <label>Versão mínima<input name="min_version" value="<?= Security::e((string)$policy['min_version']) ?>" placeholder="0.2.0"></label>
        <label>Versão recomendada<input name="recommended_version" value="<?= Security::e((string)$policy['recommended_version']) ?>" placeholder="0.3.0"></label>
        <label class="span-2">Mensagem de manutenção<input name="maintenance_message" maxlength="500" value="<?= Security::e((string)$policy['maintenance_message']) ?>" placeholder="Atualização temporária. Tente novamente em alguns minutos."></label>
        <label>Validade do arquivo<select name="ttl_seconds">
          <?php foreach([3600=>'1 hora',21600=>'6 horas',86400=>'24 horas',259200=>'3 dias',604800=>'7 dias'] as $ttl=>$label): ?>
            <option value="<?= $ttl ?>"<?= em_selected($policy['ttl_seconds'], $ttl) ?>><?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select></label>
        <label>Assinaturas permitidas<textarea name="allowed_signing_fingerprints" rows="3" placeholder="SHA-256, uma por linha"><?= Security::e($fingerprints) ?></textarea><small>Opcional. O arquivo é assinado pelo servidor; este campo permite também restringir o certificado do cliente.</small></label>

        <div class="span-2" style="margin-top:6px"><strong>Distribuição da atualização</strong><p class="muted" style="margin-top:4px">O arquivo pode ficar no servidor de contingência. O cliente recebe URL e SHA-256 dentro do manifesto assinado pelo principal.</p></div>
        <label class="checkbox span-2"><input type="checkbox" name="release_published"<?= em_checked($release['published']) ?>> Publicar esta atualização para os clientes</label>
        <label>Versão do arquivo<input name="release_version" value="<?= Security::e((string)$release['version']) ?>" placeholder="0.3.0"></label>
        <label>SHA-256 do arquivo<input name="release_sha256" value="<?= Security::e((string)$release['sha256']) ?>" maxlength="95" placeholder="64 caracteres hexadecimais"></label>
        <label class="span-2">URL HTTPS para baixar<input name="release_url" value="<?= Security::e((string)$release['download_url']) ?>" placeholder="https://backup.exemplo.com/updates/EventMenu-GO.apk"><small>O servidor não envia chave privada nem credencial junto com o arquivo.</small></label>
        <label class="span-2">Notas da versão<textarea name="release_notes" rows="3" maxlength="1000" placeholder="Resumo das mudanças desta versão"><?= Security::e((string)$release['release_notes']) ?></textarea></label>

        <div class="span-2"><strong>Recursos publicados no manifesto</strong><div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:8px">
          <?php foreach($featureLabels as $key=>$label): ?><label class="checkbox"><input type="checkbox" name="features[]" value="<?= Security::e($key) ?>"<?= em_checked(!empty($features[$key])) ?>> <?= Security::e($label) ?></label><?php endforeach; ?>
        </div></div>
        <div class="span-2 actions"><button class="primary" type="submit">Salvar política de <?= Security::e($title) ?></button></div>
      </form>
    </section>
    <?php
};

em_header('Servidor de contingência', 'platform-failover');
?>
<section class="page-hero">
  <div>
    <span class="eyebrow">ALTA DISPONIBILIDADE</span>
    <h2>Principal, contingência e autorização remota</h2>
    <p>O servidor principal controla o endereço de contingência e publica políticas assinadas para o EventMenu GO e para o programa Windows. O segundo servidor guarda uma cópia dessas políticas para continuar atendendo os clientes quando o principal cair.</p>
  </div>
  <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=system-health')) ?>">Saúde do sistema</a></div>
</section>

<div class="grid" style="grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:18px;align-items:start">
<section class="card">
  <div class="section-head"><div><span class="eyebrow">ROTEAMENTO</span><h2>Configuração do cluster</h2></div><span class="status-pill <?= $status === 'healthy' ? 'active' : '' ?>"><?= Security::e($statusLabels[$status] ?? $status) ?></span></div>
  <form method="post" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
    <input type="hidden" name="action" value="save">
    <label class="checkbox span-2"><input type="checkbox" name="enabled"<?= em_checked($settings['enabled']) ?>> Ativar contingência nos clientes EventMenu</label>
    <label class="span-2">Servidor principal<input name="primary_url" value="<?= Security::e((string)$settings['primary_url']) ?>" placeholder="https://principal.exemplo.com/1" required><small>Use a URL completa da instalação, incluindo /1 quando existir.</small></label>
    <label class="span-2">Servidor adicional<input name="contingency_url" value="<?= Security::e((string)$settings['contingency_url']) ?>" placeholder="https://backup.exemplo.com/1"><small>Precisa usar HTTPS e conter a mesma versão do EventMenu.</small></label>
    <label>Modo<select name="mode">
      <option value="read_only"<?= em_selected($settings['mode'], 'read_only') ?>>Somente leitura</option>
      <option value="shared_db"<?= em_selected($settings['mode'], 'shared_db') ?>>Completo — leitura e escrita</option>
    </select></label>
    <label>Rotacionar segredo do cluster<input type="password" name="new_secret" autocomplete="new-password" placeholder="Deixe vazio para manter"><small>Uso avançado. O segredo não é exibido pelo painel.</small></label>
    <div class="span-2 actions"><button class="primary" type="submit">Salvar configuração</button></div>
  </form>
</section>

<aside class="grid" style="gap:12px">
  <section class="card">
    <span class="eyebrow">STATUS</span><h3><?= Security::e($statusLabels[$status] ?? $status) ?></h3>
    <p class="muted"><?= Security::e((string)($settings['last_health_message'] ?: 'O servidor adicional ainda não foi verificado.')) ?></p>
    <?php if($settings['last_health_checked_at']): ?><p><strong>Último teste:</strong> <?= Security::e((string)$settings['last_health_checked_at']) ?></p><?php endif; ?>
    <?php if($settings['verified_at']): ?><p><strong>Verificado:</strong> <?= Security::e((string)$settings['verified_at']) ?></p><?php endif; ?>
    <?php if($settings['last_health_db_driver']): ?><p><strong>Banco:</strong> <?= Security::e((string)$settings['last_health_db_driver']) ?><br><strong>Escrita:</strong> <?= !empty($settings['last_health_writable']) ? 'sim' : 'não' ?></p><?php endif; ?>
    <form method="post" style="margin-bottom:8px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="test"><button class="secondary" style="width:100%"<?= empty($settings['enabled']) || empty($settings['contingency_url']) ? ' disabled' : '' ?>>Testar servidor adicional</button></form>
    <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="sync"><button class="secondary" style="width:100%"<?= $status !== 'healthy' ? ' disabled' : '' ?>>Sincronizar agora</button></form>
  </section>
  <section class="card"><span class="eyebrow">ASSINATURA DOS CLIENTES</span><h3>Raiz de confiança</h3><p class="muted">A chave privada fica criptografada no principal. O contingência recebe somente a chave pública e os manifestos já assinados.</p><p><strong>Key ID:</strong><br><code style="word-break:break-all"><?= Security::e((string)($policyKey['key_id'] ?? 'Será criada automaticamente')) ?></code></p></section>
</aside>
</div>

<section class="card" style="margin-top:18px">
  <div class="section-head"><div><span class="eyebrow">PRIMEIRA ATIVAÇÃO</span><h2>Deixe o principal configurar o servidor adicional</h2></div></div>
  <p>No segundo servidor, depois de enviar os arquivos e instalar, configure apenas <code>EVENTMENU_NODE_ROLE=contingency</code> e um <code>EVENTMENU_CLUSTER_BOOTSTRAP_TOKEN</code> temporário com pelo menos 24 caracteres. Depois informe esse código abaixo. Ele não é armazenado no servidor principal.</p>
  <form method="post" class="form-grid">
    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="bootstrap">
    <label class="span-2">Código temporário do servidor adicional<input type="password" name="bootstrap_token" minlength="24" autocomplete="one-time-code" placeholder="Código criado apenas para o primeiro pareamento" required></label>
    <div class="span-2 actions"><button class="primary" type="submit"<?= empty($settings['contingency_url']) ? ' disabled' : '' ?>>Inicializar servidor adicional</button></div>
  </form>
  <div class="alert">Depois que a inicialização for aceita uma vez, o código temporário deixa de funcionar. O principal passa a sincronizar o nó usando a identidade interna do cluster.</div>
</section>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px;margin-top:18px">
<?php
$renderPolicy($androidPolicy, $androidRelease, 'EventMenu GO / Android', [
    'delivery' => 'Delivery e GPS',
    'hub' => 'EventMenu Hub',
    'expedition' => 'Expedição',
    'inventory_alerts' => 'Alertas de estoque',
    'events' => 'Eventos',
]);
$renderPolicy($windowsPolicy, $windowsRelease, 'EventMenu Desktop / Windows', [
    'desktop' => 'Operação Desktop',
    'hub' => 'EventMenu Hub',
    'printing' => 'Impressão e periféricos',
    'kds' => 'Cozinha / KDS',
    'tef' => 'TEF / PINPad',
]);
?>
</div>

<section class="card" style="margin-top:18px">
  <span class="eyebrow">COMO FUNCIONA</span><h2>Arquivo de autorização sem recompilar</h2>
  <p>Android e Windows podem baixar periodicamente um manifesto assinado contendo versão mínima, manutenção, recursos liberados, atualização publicada e endereços dos servidores. Alterar esse manifesto no principal não exige recompilar o aplicativo. O arquivo não contém senha, token de usuário nem chave privada.</p>
  <p>A autenticação real continua no backend. O manifesto serve para controlar <strong>qual versão pode operar</strong>, ativar/desativar recursos, publicar uma atualização e orientar o cliente para principal/contingência. Isso impede que um simples arquivo baixado vire uma forma de burlar login ou pagamentos.</p>
</section>

<section class="card" style="margin-top:18px">
  <span class="eyebrow">BANCO DE DADOS</span><h2>Quando a contingência pode gravar</h2>
  <p>No modo <strong>Completo</strong>, os dois servidores web devem apontar para o mesmo MySQL/MariaDB disponível fora do servidor principal, ou para uma arquitetura de banco com replicação/failover próprio. Dois SQLite independentes não entram em escrita automática.</p>
  <p class="muted">Consultas podem trocar automaticamente de servidor. Uma gravação que cair exatamente durante a troca não é repetida automaticamente; o operador repete a ação após a mudança para evitar pedido ou pagamento duplicado.</p>
</section>
<?php em_footer(); ?>
