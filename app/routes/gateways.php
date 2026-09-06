<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\GatewayService;

Auth::requirePermission('gateways.manage');
$tenantId = em_require_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'gateway-save') {
        $provider = (string)($_POST['provider'] ?? '');
        if (!in_array($provider, ['stripe', 'pagbank', 'mercadopago'], true)) {
            exit('Gateway inválido.');
        }
        $stmt = $pdo->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=?');
        $stmt->execute([$tenantId, $provider]);
        $existing = $stmt->fetch();
        $config = $existing ? Crypto::decryptJson($existing['config_encrypted']) : [];
        foreach (['secret_key','access_token','token','api_base','tap_on_app_key','tap_on_app_name','tap_on_app_version','legacy_email','legacy_token','tap_on_query_base'] as $key) {
            $value = trim((string)($_POST[$key] ?? ''));
            if ($value !== '') {
                $config[$key] = $value;
            }
        }
        if ($provider === 'pagbank') {
            $config['tap_on_tax_pass_through'] = isset($_POST['tap_on_tax_pass_through']);
        }
        $account = trim((string)($_POST['account_reference'] ?? ($existing['account_reference'] ?? '')));
        $webhook = trim((string)($_POST['webhook_secret'] ?? ''));
        try {
            (new GatewayService())->save($provider, $account, $config, $webhook, isset($_POST['active']));
            em_flash('ok', 'Configuração de pagamento salva com segurança.');
        } catch (Throwable $e) {
            em_flash('error', $e->getMessage());
        }
        em_go('gateways');
    }

    if ($action === 'nfc-pair') {
        Auth::requirePermission('nfc.manage');
        $identifier = trim((string)($_POST['device_identifier'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $userId = (int)($_POST['user_id'] ?? 0);
        if (strlen($identifier) < 8) exit('Identificador do dispositivo inválido.');
        if ($userId) {
            $u = $pdo->prepare('SELECT id FROM users WHERE id=? AND tenant_id=? AND status="active"');
            $u->execute([$userId, $tenantId]);
            if (!$u->fetchColumn()) exit('Usuário inválido.');
        }
        $hash = hash('sha256', $identifier);
        try {
            Database::transaction(function (PDO $tx) use ($tenantId, $hash, $userId, $name, &$id): void {
                $d = $tx->prepare(Database::portableSql($tx, 'SELECT * FROM nfc_devices WHERE tenant_id=? AND device_identifier_hash=? FOR UPDATE'));
                $d->execute([$tenantId, $hash]);
                $device = $d->fetch();
                if ($device && (int)$device['pairing_attempts'] >= 5 && $device['status'] !== 'active') {
                    throw new RuntimeException('Limite de tentativas de pareamento atingido. Revogue o registro antes de nova tentativa.');
                }
                if ($device) {
                    $tx->prepare('UPDATE nfc_devices SET user_id=?,name=?,status="active",pairing_attempts=pairing_attempts+1,paired_at=CURRENT_TIMESTAMP,revoked_at=NULL WHERE id=?')
                        ->execute([$userId ?: null, $name ?: null, $device['id']]);
                    $id = (int)$device['id'];
                } else {
                    $tx->prepare('INSERT INTO nfc_devices (tenant_id,user_id,provider,device_identifier_hash,name,status,pairing_attempts,paired_at) VALUES (?,?,"pagbank",?,?,"active",1,CURRENT_TIMESTAMP)')
                        ->execute([$tenantId, $userId ?: null, $hash, $name ?: null]);
                    $id = (int)$tx->lastInsertId();
                }
            });
            Auth::audit('nfc.paired', 'nfc_device', (string)$id, ['user_id' => $userId ?: null]);
            em_flash('ok', 'Dispositivo NFC pareado. O identificador bruto não foi armazenado.');
        } catch (Throwable $e) {
            em_flash('error', $e->getMessage());
        }
        em_go('gateways');
    }

    if ($action === 'nfc-revoke') {
        Auth::requirePermission('nfc.manage');
        $id = (int)($_POST['id'] ?? 0);
        Database::transaction(function (PDO $tx) use ($id, $tenantId): void {
            $tx->prepare('UPDATE nfc_devices SET status="revoked",revoked_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$id, $tenantId]);
            $tx->prepare('UPDATE nfc_payment_intents SET status="failed" WHERE tenant_id=? AND nfc_device_id=? AND status="created"')->execute([$tenantId, $id]);
        });
        Auth::audit('nfc.revoked', 'nfc_device', (string)$id);
        em_flash('ok', 'Dispositivo revogado e cobranças pendentes invalidadas.');
        em_go('gateways');
    }
}

$tenant = $pdo->prepare('SELECT slug FROM tenants WHERE id=?');
$tenant->execute([$tenantId]);
$tenantSlug = (string)$tenant->fetchColumn();
$g = $pdo->prepare('SELECT id,provider,account_reference,config_encrypted,active,created_at,updated_at FROM payment_gateways WHERE tenant_id=? ORDER BY provider');
$g->execute([$tenantId]);
$gateways = [];
$configs = [];
foreach ($g->fetchAll() as $row) {
    $gateways[$row['provider']] = $row;
    $configs[$row['provider']] = Crypto::decryptJson($row['config_encrypted']);
}
$usersStmt = $pdo->prepare('SELECT id,name,role FROM users WHERE tenant_id=? AND status="active" ORDER BY name');
$usersStmt->execute([$tenantId]);
$users = $usersStmt->fetchAll();
$devicesStmt = $pdo->prepare('SELECT d.*,u.name user_name FROM nfc_devices d LEFT JOIN users u ON u.id=d.user_id WHERE d.tenant_id=? ORDER BY d.id DESC');
$devicesStmt->execute([$tenantId]);
$devices = $devicesStmt->fetchAll();
$providerLabels = ['stripe' => 'Stripe', 'pagbank' => 'PagBank', 'mercadopago' => 'Mercado Pago'];

em_header('Pagamentos e NFC', 'gateways');
?>
<section class="page-hero">
    <div>
        <span class="eyebrow">PAGAMENTOS</span>
        <h2>Conexões e recebimentos</h2>
        <p>Ative os provedores usados pela empresa. As credenciais ficam criptografadas e os detalhes técnicos permanecem recolhidos no uso diário.</p>
    </div>
    <div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=payments')) ?>">Ver transações</a></div>
</section>

<section class="gateway-overview" aria-label="Status dos gateways">
<?php foreach ($providerLabels as $provider => $label): $row = $gateways[$provider] ?? null; ?>
    <article class="gateway-status-card">
        <div class="gateway-provider-icon"><?= Security::e(mb_substr($label, 0, 1)) ?></div>
        <div><strong><?= Security::e($label) ?></strong><span><?= $row && $row['active'] ? 'Conectado e ativo' : 'Não habilitado' ?></span></div>
        <b class="connection-dot <?= $row && $row['active'] ? 'online' : '' ?>"></b>
    </article>
<?php endforeach; ?>
</section>

<section class="gateway-config-list">
<?php foreach ($providerLabels as $provider => $label): $row = $gateways[$provider] ?? null; $cfg = $configs[$provider] ?? []; ?>
    <details class="card gateway-config-card">
        <summary>
            <span><b><?= Security::e($label) ?></b><small><?= $row && $row['active'] ? 'Ativo' : 'Inativo' ?></small></span>
            <span class="gateway-config-action">Configurar</span>
        </summary>
        <form method="post" class="form-grid gateway-form">
            <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
            <input type="hidden" name="action" value="gateway-save">
            <input type="hidden" name="provider" value="<?= Security::e($provider) ?>">
            <label class="span-2"><?= $provider === 'stripe' ? 'Account ID (acct_...)' : ($provider === 'mercadopago' ? 'Collector ID' : 'Referência da conta PagBank') ?><input name="account_reference" value="<?= Security::e($row['account_reference'] ?? '') ?>" required></label>
            <?php if ($provider === 'stripe'): ?>
                <label class="span-2">Secret key<input type="password" name="secret_key" placeholder="Deixe vazio para manter a atual"></label>
            <?php elseif ($provider === 'mercadopago'): ?>
                <label class="span-2">Access token<input type="password" name="access_token" placeholder="Deixe vazio para manter o atual"></label>
            <?php else: ?>
                <label class="span-2">Token PagBank<input type="password" name="token" placeholder="Deixe vazio para manter o atual"></label>
                <label class="span-2">API base<input name="api_base" value="<?= Security::e((string)($cfg['api_base'] ?? '')) ?>" placeholder="https://api.pagseguro.com"></label>
                <details class="span-2 gateway-advanced">
                    <summary>Tap On / NFC Android</summary>
                    <div class="form-grid">
                        <label class="span-2">AppKey Tap On<input type="password" name="tap_on_app_key" placeholder="Deixe vazio para manter a atual"></label>
                        <label>Nome do app<input name="tap_on_app_name" value="<?= Security::e((string)($cfg['tap_on_app_name'] ?? 'EventMenu')) ?>"></label>
                        <label>Versão do app<input name="tap_on_app_version" value="<?= Security::e((string)($cfg['tap_on_app_version'] ?? '1.0.0')) ?>"></label>
                        <label class="span-2">E-mail da conta para consulta<input type="email" name="legacy_email" value="<?= Security::e((string)($cfg['legacy_email'] ?? '')) ?>"></label>
                        <label class="span-2">Token de consulta Tap On<input type="password" name="legacy_token" placeholder="Deixe vazio para manter o atual"></label>
                        <label class="span-2">Endpoint de consulta<input name="tap_on_query_base" value="<?= Security::e((string)($cfg['tap_on_query_base'] ?? '')) ?>" placeholder="https://ws.pagseguro.uol.com.br/v3/transactions"></label>
                        <label class="checkbox span-2"><input type="checkbox" name="tap_on_tax_pass_through"<?= em_checked($cfg['tap_on_tax_pass_through'] ?? false) ?>> Permitir repasse de taxas no Tap On</label>
                    </div>
                </details>
            <?php endif; ?>
            <label class="span-2">Segredo do webhook<input type="password" name="webhook_secret" placeholder="Deixe vazio para manter o atual"></label>
            <label class="checkbox span-2"><input type="checkbox" name="active"<?= em_checked($row['active'] ?? 0) ?>> Gateway ativo</label>
            <button class="primary span-2">Salvar <?= Security::e($label) ?></button>
        </form>
        <details class="technical-details gateway-webhook"><summary>Webhook desta integração</summary><code><?= Security::e(app_absolute_url('webhook.php?provider='.$provider.'&tenant='.urlencode($tenantSlug))) ?></code></details>
    </details>
<?php endforeach; ?>
</section>

<section class="card" style="margin-top:16px">
    <div class="section-head"><div><span class="eyebrow">TAP ON</span><h2>Dispositivos NFC PagBank</h2></div><span class="muted">Pareamento limitado e revogável</span></div>
    <div class="gateway-device-layout">
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
            <input type="hidden" name="action" value="nfc-pair">
            <label class="span-2">Nome do aparelho<input name="name" placeholder="Caixa 01"></label>
            <label class="span-2">Identificador do dispositivo<input name="device_identifier" required autocomplete="off"></label>
            <label class="span-2">Usuário autorizado<select name="user_id"><option value="0">Sem usuário fixo</option><?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"><?= Security::e($u['name'].' · '.$u['role']) ?></option><?php endforeach; ?></select></label>
            <button class="secondary span-2">Parear dispositivo</button>
            <p class="muted span-2">O servidor guarda somente o hash do identificador. Um pagamento NFC só é liberado depois da confirmação no PagBank.</p>
        </form>
        <div class="gateway-device-list">
            <?php if (!$devices): ?><div class="empty-state">Nenhum dispositivo pareado.</div><?php endif; ?>
            <?php foreach ($devices as $device): ?>
                <article class="gateway-device-card">
                    <div><strong><?= Security::e($device['name'] ?? 'Sem nome') ?></strong><span><?= Security::e($device['user_name'] ?? 'Sem usuário fixo') ?> · <?= Security::e($device['status']) ?></span><small><?= (int)$device['pairing_attempts'] ?>/5 tentativa(s)</small></div>
                    <?php if ($device['status'] !== 'revoked'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="nfc-revoke"><input type="hidden" name="id" value="<?= (int)$device['id'] ?>"><button class="secondary compact">Revogar</button></form><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php em_footer();
