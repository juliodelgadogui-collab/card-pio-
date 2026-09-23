<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Auth;
use EventMenu\Core\Migrator;
use EventMenu\Core\Security;
use EventMenu\Services\DatabaseRepairService;

if (!Auth::check()) app_redirect('?route=login');
Auth::enforceCurrentUser();
if (!Auth::isSuperAdmin()) { http_response_code(403); exit('Acesso restrito ao ADM Geral.'); }

$service = new DatabaseRepairService();
$error = null;
$message = null;
$backup = null;
$actions = [];
$applied = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCsrf($_POST['_csrf'] ?? null)) {
        $error = 'CSRF inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'repair') {
                $result = $service->repair();
                $backup = (string)($result['backup'] ?? '');
                $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
                Auth::audit('system.database_repair', 'system', null, ['backup'=>$backup,'actions'=>$actions]);
                $message = 'Reparo estrutural concluído. Confira o diagnóstico abaixo antes de aplicar as atualizações normais.';
            } elseif ($action === 'repair_update') {
                $result = $service->repair();
                $backup = (string)($result['backup'] ?? '');
                $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
                $applied = Migrator::run();
                Auth::audit('system.database_repair_update', 'system', null, ['backup'=>$backup,'actions'=>$actions,'migrations'=>$applied]);
                $message = 'Banco reparado e atualizações executadas.';
            } elseif ($action === 'migrate') {
                $applied = Migrator::run();
                Auth::audit('system.migrations_after_repair', 'system', null, ['applied'=>$applied]);
                $message = $applied ? 'Atualizações aplicadas: ' . implode(', ', $applied) : 'Não havia atualizações pendentes.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

try {
    $diagnosis = $service->diagnose();
} catch (Throwable $e) {
    $diagnosis = ['driver'=>'desconhecido','healthy'=>false,'integrity'=>null,'issues'=>[$e->getMessage()],'checks'=>[],'migrations'=>[]];
    $error ??= $e->getMessage();
}

$healthy = (bool)($diagnosis['healthy'] ?? false);
$integrity = (string)($diagnosis['integrity'] ?? '');
$checks = is_array($diagnosis['checks'] ?? null) ? $diagnosis['checks'] : [];
$migrations = is_array($diagnosis['migrations'] ?? null) ? $diagnosis['migrations'] : [];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Reparador de banco · EventMenu</title>
<link rel="stylesheet" href="<?= Security::e(app_url('assets/app.css')) ?>">
<style>
body{background:#08111f;color:#eef2f7}.repair-wrap{width:min(980px,94vw);margin:28px auto 70px}.repair-card{background:#111b2a;border:1px solid #26364a;border-radius:22px;padding:22px;box-shadow:0 24px 80px rgba(0,0,0,.28);margin-bottom:18px}.repair-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.brand{font-weight:800;font-size:20px}.brand span{color:#f7b733}.badge{display:inline-flex;padding:7px 11px;border-radius:999px;font-size:13px;font-weight:700}.ok{background:#123b2a;color:#83e6b0}.bad{background:#4b2025;color:#ffb7bd}.warn{background:#4a3515;color:#ffd98a}.muted{color:#9cabbc}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.metric{background:#0c1522;border:1px solid #223247;border-radius:16px;padding:14px}.metric strong{display:block;margin-bottom:6px}.checks{width:100%;border-collapse:collapse;margin-top:10px}.checks td{padding:11px 8px;border-bottom:1px solid #223247;vertical-align:top}.checks td:first-child{width:38%;font-weight:700}.dot{font-weight:900;margin-right:7px}.dot.okc{color:#4ade80}.dot.badc{color:#fb7185}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{border:0;border-radius:12px;padding:12px 16px;font-weight:800;cursor:pointer}.btn-primary{background:#f7b733;color:#1a1407}.btn-danger{background:#e05260;color:white}.btn-secondary{background:#26364a;color:#eef2f7}.alert{border-radius:14px;padding:13px 15px;margin:12px 0}.alert.error{background:#4b2025;color:#ffd4d8}.alert.success{background:#123b2a;color:#b4f7cf}.alert.info{background:#162d49;color:#c9e3ff}.small{font-size:13px}.migration-list{display:flex;gap:8px;flex-wrap:wrap}.migration{padding:6px 9px;border-radius:10px;background:#0c1522;border:1px solid #223247;font-size:12px}.migration.yes{border-color:#245d42;color:#9ce6bc}.migration.no{border-color:#654733;color:#ffd09d}a{color:#8dc8ff}
</style>
</head>
<body>
<main class="repair-wrap">
  <section class="repair-card">
    <div class="repair-head">
      <div><div class="brand">EventMenu <span>Premium</span></div><h1>Reparador de banco de dados</h1><p class="muted">Ferramenta de recuperação estrutural para banco SQLite legado. Não apaga pedidos, empresas, produtos ou pagamentos.</p></div>
      <span class="badge <?= $healthy ? 'ok' : 'bad' ?>"><?= $healthy ? 'Banco consistente' : 'Reparo necessário' ?></span>
    </div>
    <?php if($error):?><div class="alert error"><strong>Erro:</strong> <?= Security::e($error) ?></div><?php endif;?>
    <?php if($message):?><div class="alert success"><?= Security::e($message) ?></div><?php endif;?>
    <?php if($backup):?><div class="alert info"><strong>Backup criado antes do reparo:</strong> <?= Security::e($backup) ?></div><?php endif;?>
    <?php if($actions):?><div class="alert info"><strong>Ações executadas:</strong><br><?= Security::e(implode(' • ',$actions)) ?></div><?php endif;?>
    <div class="grid">
      <div class="metric"><strong>Driver</strong><span><?= Security::e((string)($diagnosis['driver'] ?? '')) ?></span></div>
      <div class="metric"><strong>Integridade física</strong><span><?= Security::e($integrity ?: 'não verificada') ?></span></div>
      <div class="metric"><strong>Problemas estruturais</strong><span><?= count($diagnosis['issues'] ?? []) ?></span></div>
    </div>
  </section>

  <section class="repair-card">
    <h2>Diagnóstico estrutural</h2>
    <table class="checks"><tbody>
    <?php foreach($checks as $check): $ok=(bool)($check['ok']??false); ?>
      <tr><td><span class="dot <?= $ok?'okc':'badc' ?>"><?= $ok?'●':'●' ?></span><?= Security::e((string)($check['label']??'')) ?></td><td><?= Security::e((string)($check['detail']??'')) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </section>

  <section class="repair-card">
    <h2>Histórico de migrations</h2>
    <p class="muted small">Esta lista é apenas informativa. O reparador não confia somente nela: ele confere a estrutura real das tabelas e colunas.</p>
    <div class="migration-list">
    <?php foreach($migrations as $name=>$done):?><span class="migration <?= $done?'yes':'no' ?>"><?= Security::e((string)$name) ?> · <?= $done?'registrada':'pendente' ?></span><?php endforeach;?>
    </div>
  </section>

  <section class="repair-card">
    <h2>Ações</h2>
    <p class="muted">Antes de qualquer reparo, a página cria automaticamente uma cópia do SQLite em <code>storage/backups</code>. Se o <em>integrity_check</em> indicar corrupção física, o reparo é bloqueado.</p>
    <div class="actions">
      <form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="repair"><button class="btn btn-danger">Criar backup e reparar estrutura</button></form>
      <form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="repair_update"><button class="btn btn-primary">Reparar e concluir atualizações</button></form>
      <form method="post"><input type="hidden" name="_csrf" value="<?= Security::e(Security::csrfToken()) ?>"><input type="hidden" name="action" value="migrate"><button class="btn btn-secondary">Executar somente migrations</button></form>
    </div>
    <p style="margin-top:18px"><a href="<?= Security::e(app_url('update.php')) ?>">Abrir atualização normal</a> · <a href="<?= Security::e(app_url('')) ?>">Voltar ao painel</a></p>
  </section>
</main>
</body>
</html>
