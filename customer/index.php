<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'customer';
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
        $address = trim($_POST['address']);
        $note = trim($_POST['note']);
        if ($name === '') {
            $error = __('common_err_name_required');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO customers (name, phone, address, note, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $phone, $address, $note, $actorId, $actorId]);
                $newId = (int) $pdo->lastInsertId();
                logAudit($pdo, $actorId, 'create', 'customer', $newId, null, [
                    'name' => $name, 'phone' => $phone, 'address' => $address, 'note' => $note,
                ]);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/customer/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Customer create failed: ' . $e->getMessage());
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
            $address = trim($_POST['address']);
            $note = trim($_POST['note']);
            $actorId = (int) $_SESSION['user_id'];

            $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$id]);
            $before = $stmt->fetch();

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE customers SET name=?, phone=?, address=?, note=?, updated_by=? WHERE id=?');
                $stmt->execute([$name, $phone, $address, $note, $actorId, $id]);
                logAudit($pdo, $actorId, 'update', 'customer', $id, $before ?: null, [
                    'name' => $name, 'phone' => $phone, 'address' => $address, 'note' => $note,
                ]);
                $pdo->commit();
                // Edit modal can be opened both from this list and from
                // customer/view.php (Customer Information card there) - a
                // hidden from_view field tells us where to send the user
                // back to, same 'redirect to where you started' as the
                // record-payment flow on view.php.
                $redirect = !empty($_POST['from_view'])
                    ? BASE_URL . '/customer/view.php?id=' . $id
                    : BASE_URL . '/customer/index.php';
                header('Location: ' . $redirect);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Customer update failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAdmin()) {
    $id = (int) $_POST['id'];
    $actorId = (int) $_SESSION['user_id'];
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $before = $stmt->fetch();

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->execute([$id]);
        if ($before) {
            logAudit($pdo, $actorId, 'delete', 'customer', $id, $before, null);
        }
        $pdo->commit();
        header('Location: ' . BASE_URL . '/customer/index.php');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // MySQL/MariaDB error 1451: "Cannot delete or update a parent row: a
        // foreign key constraint fails" - customer_debts.customer_id has no
        // ON DELETE clause (RESTRICT by default, see migration 010's
        // comment on that column), so any customer with debt history hits
        // this. Same precise-message-instead-of-generic-fallback pattern as
        // product/index.php's own delete handler.
        if (($e->errorInfo[1] ?? null) === 1451) {
            $error = __('customer_err_delete_has_debts');
        } else {
            error_log('Customer delete failed: ' . $e->getMessage());
            $error = __('common_err_transaction_failed');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Customer delete failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

$search = trim($_GET['q'] ?? '');
$orderBy = sortOrderBy(['name' => 'c.name', 'balance' => 'outstanding_balance'], 'c.name ASC');

// outstanding_balance sums only unpaid/partially-paid debts' balance
// (the generated column from migration 010); overdue_count is how many of
// those are also past due_date, driving the row tint/badge below - both
// computed in SQL rather than in PHP so sorting by balance and the
// live-search re-fetch both stay a single query, matching every other
// list page's shape.
$sql = "SELECT c.*,
               COALESCE(SUM(CASE WHEN d.status != 'paid' THEN d.balance ELSE 0 END), 0) AS outstanding_balance,
               COALESCE(SUM(CASE WHEN d.status != 'paid' AND d.due_date IS NOT NULL AND d.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count
        FROM customers c
        LEFT JOIN customer_debts d ON d.customer_id = c.id";
if ($search !== '') {
    $sql .= ' WHERE c.name LIKE ? OR c.phone LIKE ?';
    $sql .= " GROUP BY c.id ORDER BY $orderBy";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $sql .= " GROUP BY c.id ORDER BY $orderBy";
    $stmt = $pdo->query($sql);
}
$customers = $stmt->fetchAll();

// Mobile-only "Sort by" <select> fallback - same pattern as
// supplier/index.php.
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
  <h4 class="mb-0"><?= __('customer_title') ?></h4>
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
    <option value="<?= htmlspecialchars(mobileSortUrl('balance')) ?>" <?= ($_GET['sort'] ?? '') === 'balance' ? 'selected' : '' ?>><?= __('customer_col_balance') ?></option>
  </select>
</form>

<div class="card" id="resultsArea">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr><th>#</th><th><?= sortHeader('name', __('common_name')) ?></th><th><?= __('common_phone') ?></th><th><?= sortHeader('balance', __('customer_col_balance')) ?></th><th class="text-end"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$customers): ?>
        <tr><td colspan="5" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('customer_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($customers as $i => $c):
        $overdue = $c['overdue_count'] > 0;
        $balance = (float) $c['outstanding_balance'];
      ?>
      <tr class="<?= $overdue ? 'row-overdue' : '' ?>">
        <td class="row-number"><?= $i + 1 ?></td>
        <td class="row-title">
          <?php if ($overdue): ?><span class="overdue-badge"><i class="bi bi-exclamation-triangle-fill"></i> <?= __('customer_overdue_badge') ?></span><?php endif; ?>
          <div class="fw-semibold"><?= htmlspecialchars($c['name']) ?></div>
        </td>
        <td data-label="<?= htmlspecialchars(__('common_phone')) ?>"><?= $c['phone'] !== '' && $c['phone'] !== null ? htmlspecialchars($c['phone']) : '<span class="text-secondary">—</span>' ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('customer_col_balance')) ?>">
          <?php if ($balance > 0): ?>
            <span class="badge-stock <?= $overdue ? 'badge-low' : 'badge-warn' ?>">$<?= number_format($balance, 2) ?></span>
          <?php else: ?>
            <span class="badge-stock badge-normal">$0.00</span>
          <?php endif; ?>
        </td>
        <td class="text-end row-actions">
          <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>/customer/view.php?id=<?= $c['id'] ?>">
            <i class="bi bi-eye"></i> <?= __('common_view') ?>
          </a>
          <?php if (canWrite()): ?>
          <button class="btn btn-sm btn-outline-primary"
                  data-bs-toggle="modal" data-bs-target="#editModal<?= $c['id'] ?>">
            <i class="bi bi-pencil"></i>
          </button>
          <?php endif; ?>
          <?php if (isAdmin()): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('<?= __('customer_delete_confirm') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php foreach ($customers as $i => $c): ?>
<div class="modal fade" id="editModal<?= $c['id'] ?>" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $c['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('customer_edit_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label"><?= __('common_phone') ?></label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($c['phone']) ?>"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_address') ?></label>
            <textarea name="address" class="form-control"><?= htmlspecialchars($c['address']) ?></textarea></div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"><?= htmlspecialchars($c['note']) ?></textarea></div>
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
          <h5 class="modal-title"><?= __('customer_create_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required></div>
          <div class="mb-3"><label class="form-label"><?= __('common_phone') ?></label>
            <input type="text" name="phone" class="form-control"></div>
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
