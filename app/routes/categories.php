<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('catalog.manage');
$tenantId = em_require_tenant();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    em_post_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'category-save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
        $sortOrder = max(-9999, min(999999, (int)($_POST['sort_order'] ?? 0)));
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '') {
            em_flash('error', 'Informe o nome da categoria.');
            em_go('categories', $id > 0 ? ['edit' => $id] : ['new' => 1]);
        }

        $dup = $pdo->prepare('SELECT id FROM categories WHERE tenant_id=? AND LOWER(name)=LOWER(?) AND id<>? LIMIT 1');
        $dup->execute([$tenantId, $name, $id]);
        if ($dup->fetchColumn()) {
            em_flash('error', 'Já existe uma categoria com esse nome.');
            em_go('categories', $id > 0 ? ['edit' => $id] : ['new' => 1]);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE categories SET name=?,sort_order=?,active=? WHERE id=? AND tenant_id=?');
            $stmt->execute([$name, $sortOrder, $active, $id, $tenantId]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM categories WHERE id=? AND tenant_id=?');
                $check->execute([$id, $tenantId]);
                if (!$check->fetchColumn()) {
                    em_flash('error', 'Categoria não encontrada.');
                    em_go('categories');
                }
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO categories (tenant_id,name,sort_order,active) VALUES (?,?,?,?)');
            $stmt->execute([$tenantId, $name, $sortOrder, $active]);
            $id = (int)$pdo->lastInsertId();
        }

        Auth::audit('category.saved', 'category', (string)$id, ['name' => $name, 'sort_order' => $sortOrder, 'active' => $active]);
        em_flash('ok', 'Categoria salva.');
        em_go('categories');
    }

    if ($action === 'category-toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE categories SET active=? WHERE id=? AND tenant_id=?');
        $stmt->execute([$active, $id, $tenantId]);
        Auth::audit('category.status_changed', 'category', (string)$id, ['active' => $active]);
        em_flash('ok', $active ? 'Categoria ativada.' : 'Categoria ocultada do cardápio.');
        em_go('categories');
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id=? AND tenant_id=? LIMIT 1');
    $stmt->execute([$editId, $tenantId]);
    $edit = $stmt->fetch() ?: null;
    if (!$edit) {
        em_flash('error', 'Categoria não encontrada.');
        em_go('categories');
    }
}

$list = $pdo->prepare('SELECT c.*,COUNT(p.id) product_count FROM categories c LEFT JOIN products p ON p.tenant_id=c.tenant_id AND p.category_id=c.id WHERE c.tenant_id=? GROUP BY c.id,c.tenant_id,c.name,c.sort_order,c.active ORDER BY c.sort_order,c.name');
$list->execute([$tenantId]);
$categories = $list->fetchAll();
$activeCount = count(array_filter($categories, static fn(array $c): bool => (int)$c['active'] === 1));
$hiddenCount = count($categories) - $activeCount;
$productCount = array_sum(array_map(static fn(array $c): int => (int)$c['product_count'], $categories));
$showEditor = isset($_GET['new']) || $edit !== null;

em_header('Categorias do cardápio', 'categories');
?>
<section class="page-hero">
    <div>
        <span class="eyebrow">CATÁLOGO</span>
        <h2>Categorias do cardápio</h2>
        <p>Organize os produtos em grupos como Hambúrgueres, Bebidas, Combos e Sobremesas.</p>
    </div>
    <div class="hero-actions">
        <a class="button primary" href="<?= Security::e(app_url('?route=categories&new=1')) ?>">+ Nova categoria</a>
        <a class="button secondary" href="<?= Security::e(app_url('?route=products')) ?>">Produtos</a>
        <a class="button secondary" target="_blank" rel="noopener" href="<?= Security::e(app_url('menu.php?empresa=' . rawurlencode((string)($pdo->query('SELECT slug FROM tenants WHERE id=' . (int)$tenantId)->fetchColumn() ?: '')))) ?>">Ver cardápio</a>
    </div>
</section>

<section class="grid dashboard-metrics">
    <div class="card metric"><span class="muted">Categorias ativas</span><strong><?= $activeCount ?></strong></div>
    <div class="card metric"><span class="muted">Categorias ocultas</span><strong><?= $hiddenCount ?></strong></div>
    <div class="card metric"><span class="muted">Produtos categorizados</span><strong><?= $productCount ?></strong></div>
</section>

<?php if ($showEditor): ?>
<section class="card" style="margin-top:14px">
    <div class="section-head">
        <div><span class="eyebrow"><?= $edit ? 'EDITAR' : 'NOVA' ?></span><h2><?= $edit ? 'Editar categoria' : 'Criar categoria' ?></h2></div>
        <a class="button secondary compact" href="<?= Security::e(app_url('?route=categories')) ?>">Fechar</a>
    </div>
    <form method="post" class="form-grid">
        <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
        <input type="hidden" name="action" value="category-save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <label>Nome da categoria
            <input name="name" maxlength="120" required autofocus value="<?= Security::e((string)($edit['name'] ?? '')) ?>" placeholder="Ex.: Bebidas">
        </label>
        <label>Ordem no cardápio
            <input name="sort_order" type="number" min="-9999" max="999999" value="<?= (int)($edit['sort_order'] ?? ((count($categories) + 1) * 10)) ?>">
            <small>Menor número aparece primeiro.</small>
        </label>
        <label class="check"><input type="checkbox" name="active" value="1"<?= em_checked($edit ? (int)$edit['active'] : 1) ?>> Categoria ativa</label>
        <div class="actions span-2"><button class="primary" type="submit">Salvar categoria</button></div>
    </form>
</section>
<?php endif; ?>

<section class="card" style="margin-top:14px">
    <div class="section-head"><div><span class="eyebrow">ORGANIZAÇÃO</span><h2>Suas categorias</h2></div></div>
    <?php if (!$categories): ?>
        <div class="alert">Nenhuma categoria cadastrada. Toque em <strong>+ Nova categoria</strong> para começar.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Ordem</th><th>Categoria</th><th>Produtos</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><strong><?= (int)$category['sort_order'] ?></strong></td>
                        <td><strong><?= Security::e($category['name']) ?></strong></td>
                        <td><?= (int)$category['product_count'] ?></td>
                        <td><span class="status-pill <?= (int)$category['active'] === 1 ? 'active' : 'cancelled' ?>"><?= (int)$category['active'] === 1 ? 'Ativa' : 'Oculta' ?></span></td>
                        <td>
                            <div class="actions">
                                <a class="button secondary compact" href="<?= Security::e(app_url('?route=categories&edit=' . (int)$category['id'])) ?>">Editar</a>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="_csrf" value="<?= em_csrf() ?>">
                                    <input type="hidden" name="action" value="category-toggle">
                                    <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                                    <input type="hidden" name="active" value="<?= (int)$category['active'] === 1 ? 0 : 1 ?>">
                                    <button class="secondary compact" type="submit"><?= (int)$category['active'] === 1 ? 'Ocultar' : 'Ativar' ?></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
em_footer();
