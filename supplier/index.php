<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'supplier';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $note = trim($_POST['note']);
        if ($name === '') {
            $error = __('common_err_name_required');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO suppliers (name, phone, email, address, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $phone, $email, $address, $note, $actorId, $actorId]);
                $newId = (int) $pdo->lastInsertId();
                logAudit($pdo, $actorId, 'create', 'supplier', $newId, null, [
                    'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'note' => $note,
                ]);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/supplier/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Supplier create failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $id = (int) $_POST['id'];
        $name = trim($_POST['name']);
        if ($name === '') {
            $error = __('common_err_name_required');
        } else {
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address']);
            $note = trim($_POST['note']);
            $actorId = (int) $_SESSION['user_id'];

            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch();

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE suppliers SET name=?, phone=?, email=?, address=?, note=?, updated_by=? WHERE id=?');
                $stmt->execute([$name, $phone, $email, $address, $note, $actorId, $id]);
                logAudit($pdo, $actorId, 'update', 'supplier', $id, $before ?: null, [
                    'name' => $name, 'phone' => $phone, 'email' => $email, 'address' => $address, 'note' => $note,
                ]);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/supplier/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Supplier update failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    $id = (int) $_POST['id'];
    $actorId = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $stmt->execute([$id]);
        if ($before) {
            logAudit($pdo, $actorId, 'delete', 'supplier', $id, $before, null);
        }
        $pdo->commit();
        header('Location: ' . BASE_URL . '/supplier/index.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Supplier delete failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

$search = trim($_GET['q'] ?? '');
$orderBy = sortOrderBy(['name' => 'name'], 'id DESC');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE name LIKE ? ORDER BY $orderBy");
    $stmt->execute(["%$search%"]);
} else {
    $stmt = $pdo->query("SELECT * FROM suppliers ORDER BY $orderBy");
}
$suppliers = $stmt->fetchAll();

// Mobile-only "Sort by" <select> fallback - same pattern as
// product/index.php and category/index.php, see category/index.php for
// the full rationale.
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
  <h4 class="mb-0"><?= __('supplier_title') ?></h4>
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
      <tr><th>#</th><th><?= sortHeader('name', __('common_name')) ?></th><th><?= __('common_phone') ?></th><th><?= __('common_email') ?></th><th><?= __('common_address') ?></th><th><?= __('common_note') ?></th><th class="text-end"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$suppliers): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('supplier_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($suppliers as $i => $s): ?>
      <tr>
        <td class="row-number"><?= $i + 1 ?></td>
        <td class="row-title"><?= htmlspecialchars($s['name']) ?></td>
        <td data-label="<?= htmlspecialchars(__('common_phone')) ?>"><?= htmlspecialchars($s['phone']) ?></td>
        <td data-label="<?= htmlspecialchars(__('common_email')) ?>"><?= htmlspecialchars($s['email']) ?></td>
        <td data-label="<?= htmlspecialchars(__('common_address')) ?>"><?= htmlspecialchars($s['address']) ?></td>
        <td data-label="<?= htmlspecialchars(__('common_note')) ?>"><?= htmlspecialchars($s['note']) ?></td>
        <td class="text-end row-actions">
          <?php if (canWrite()): ?>
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $s['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('<?= __('supplier_delete_confirm') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($suppliers as $i => $s): ?>
<div class="modal fade" id="editModal<?= $s['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $s['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('supplier_edit_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($s['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label"><?= __('common_phone') ?></label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($s['phone']) ?>"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_email') ?></label>
            <input type="text" name="email" class="form-control" value="<?= htmlspecialchars($s['email']) ?>"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_address') ?></label>
            <textarea name="address" class="form-control"><?= htmlspecialchars($s['address']) ?></textarea></div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"><?= htmlspecialchars($s['note']) ?></textarea></div>
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

<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('supplier_create_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label"><?= __('common_phone') ?></label>
            <input type="text" name="phone" class="form-control"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_email') ?></label>
            <input type="text" name="email" class="form-control"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_address') ?></label>
            <textarea name="address" class="form-control"></textarea></div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"></textarea></div>
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
