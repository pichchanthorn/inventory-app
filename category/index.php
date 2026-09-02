<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'category';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ---------- CREATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $name = trim($_POST['name']);
        $slug = trim($_POST['slug']);
        $note = trim($_POST['note']);

        if ($name === '' || $slug === '') {
            $error = __('category_err_required');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO categories (name, slug, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $slug, $note, $actorId, $actorId]);
                $newId = (int) $pdo->lastInsertId();
                logAudit($pdo, $actorId, 'create', 'category', $newId, null, ['name' => $name, 'slug' => $slug, 'note' => $note]);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/category/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Category create failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

// ---------- UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $id   = (int) $_POST['id'];
        $name = trim($_POST['name']);
        $slug = trim($_POST['slug']);
        $note = trim($_POST['note']);

        if ($name === '' || $slug === '') {
            $error = __('category_err_required');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch();

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, note = ?, updated_by = ? WHERE id = ?');
                $stmt->execute([$name, $slug, $note, $actorId, $id]);
                logAudit($pdo, $actorId, 'update', 'category', $id, $before ?: null, ['name' => $name, 'slug' => $slug, 'note' => $note]);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/category/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Category update failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

// ---------- DELETE (Admin only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    $id = (int) $_POST['id'];
    $actorId = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        if ($before) {
            logAudit($pdo, $actorId, 'delete', 'category', $id, $before, null);
        }
        $pdo->commit();
        header('Location: ' . BASE_URL . '/category/index.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Category delete failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

// ---------- SEARCH + LIST ----------
$search = trim($_GET['q'] ?? '');
$orderBy = sortOrderBy(['name' => 'name'], 'id DESC');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE name LIKE ? ORDER BY $orderBy");
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY $orderBy");
}
$categories = $stmt->fetchAll();

// Mobile-only "Sort by" <select> fallback: the table's <thead> (and with
// it, sortHeader()'s click-to-sort link) is hidden below the card-layout
// breakpoint - see .table-cards-mobile in assets/style.css - so this
// reuses the same ?sort=&dir= URL scheme against the same sortable
// column, just as a <select> instead of a header link. Same pattern as
// product/index.php, duplicated per-page rather than shared, matching
// this codebase's existing convention (e.g. the searchable-dropdown JS
// duplicated across stock-in/stock-out/pos).
function mobileSortUrl(string $column): string {
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = 'asc';
    return '?' . http_build_query($params);
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><?= __('category_title') ?></h4>
  <?php if (canWrite()): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-plus-lg"></i> <?= __('common_add') ?>
  </button>
  <?php endif; ?>
</div>

<form class="mb-3 d-flex gap-2 flex-wrap list-toolbar" method="get">
  <input type="text" name="q" id="searchInput" class="form-control search-input"
         placeholder="<?= __('common_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
  <select class="form-select form-select-sm d-md-none sort-select" onchange="if (this.value) location.href = this.value">
    <option value="" <?= empty($_GET['sort']) ? 'selected' : '' ?> disabled><?= __('common_sort_by') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('name')) ?>" <?= ($_GET['sort'] ?? '') === 'name' ? 'selected' : '' ?>><?= __('common_name') ?></option>
  </select>
</form>

<div class="card" id="resultsArea">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr><th>#</th><th><?= sortHeader('name', __('common_name')) ?></th><th><?= __('category_slug') ?></th><th><?= __('common_note') ?></th><th class="text-end"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$categories): ?>
        <tr><td colspan="5" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('category_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($categories as $i => $cat): ?>
      <tr>
        <td class="row-number"><?= $i + 1 ?></td>
        <td class="row-title"><?= htmlspecialchars($cat['name']) ?></td>
        <td data-label="<?= htmlspecialchars(__('category_slug')) ?>"><span class="slug-pill"><?= htmlspecialchars($cat['slug']) ?></span></td>
        <td data-label="<?= htmlspecialchars(__('common_note')) ?>"><?= htmlspecialchars($cat['note']) ?></td>
        <td class="text-end row-actions">
          <?php if (canWrite()): ?>
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $cat['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('<?= __('category_delete_confirm') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Edit modals (placed outside the table to keep the Bootstrap backdrop working correctly) -->
<?php foreach ($categories as $i => $cat): ?>
<div class="modal fade" id="editModal<?= $cat['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('category_edit_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($cat['name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('category_slug') ?></label>
            <input type="text" name="slug" class="form-control" value="<?= htmlspecialchars($cat['slug']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"><?= htmlspecialchars($cat['note']) ?></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Create modal -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('category_create_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('category_slug') ?></label>
            <input type="text" name="slug" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => initLiveSearch('searchInput', 'resultsArea'));</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
