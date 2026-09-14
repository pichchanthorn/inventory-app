<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/purchase_order.php';
require_once __DIR__ . '/../includes/sortable.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'purchase-order';
$error = '';

$success = $_SESSION['po_flash'] ?? '';
unset($_SESSION['po_flash']);
$error = $_SESSION['po_flash_error'] ?? '';
unset($_SESSION['po_flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $id = (int) $_POST['id'];
        try {
            deletePurchaseOrder($pdo, $id, (int) $_SESSION['user_id']);
            $_SESSION['po_flash'] = __('po_deleted_toast');
            header('Location: ' . BASE_URL . '/purchase-order/index.php');
            exit;
        } catch (PurchaseOrderNotFoundException $e) {
            $error = __('po_err_not_found');
        } catch (PurchaseOrderNotDraftException $e) {
            $error = __('po_err_not_draft');
        } catch (Throwable $e) {
            error_log('Purchase Order delete failed: ' . $e->getMessage());
            $error = __('common_err_transaction_failed');
        }
    }
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();

$search = trim($_GET['q'] ?? '');
$supplierFilter = isset($_GET['supplier_id']) && ctype_digit((string) $_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(po.reference LIKE ? OR s.name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($supplierFilter !== null) {
    $where[] = 'po.supplier_id = ?';
    $params[] = $supplierFilter;
}
if ($statusFilter !== '') {
    $where[] = 'po.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$orderBy = sortOrderBy([
    'reference' => 'po.reference',
    'supplier' => 's.name',
    'order_date' => 'po.order_date',
    'status' => 'po.status',
], 'po.id DESC');

$stmt = $pdo->prepare("SELECT po.*, s.name AS supplier_name,
                               (SELECT COALESCE(SUM(subtotal), 0) FROM purchase_order_items WHERE purchase_order_id = po.id) AS total
                        FROM purchase_orders po
                        JOIN suppliers s ON s.id = po.supplier_id
                        $whereSql
                        ORDER BY $orderBy");
$stmt->execute($params);
$purchaseOrders = $stmt->fetchAll();

$statusBadgeClass = [
    'draft' => 'badge-accent',
    'ordered' => 'badge-warn',
    'partially_received' => 'badge-warn',
    'received' => 'badge-normal',
    'cancelled' => 'badge-low',
];
$statusLabels = [
    'draft' => __('po_status_draft'),
    'ordered' => __('po_status_ordered'),
    'partially_received' => __('po_status_partially_received'),
    'received' => __('po_status_received'),
    'cancelled' => __('po_status_cancelled'),
];

function mobileSortUrl(string $column): string {
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = 'asc';
    return '?' . http_build_query($params);
}

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><?= __('po_list_title') ?></h4>
  <?php if (canWrite()): ?>
  <a class="btn btn-primary" href="<?= BASE_URL ?>/purchase-order/create.php">
    <i class="bi bi-plus-lg"></i> <?= __('po_create_button') ?>
  </a>
  <?php endif; ?>
</div>

<form class="mb-3 d-flex gap-2 flex-wrap list-toolbar" method="get">
  <input type="text" name="q" class="form-control search-input"
         placeholder="<?= __('common_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
  <select name="supplier_id" class="form-select" style="max-width:220px;" onchange="this.form.submit()">
    <option value=""><?= __('po_filter_all_suppliers') ?></option>
    <?php foreach ($suppliers as $s): ?>
    <option value="<?= $s['id'] ?>" <?= $supplierFilter === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" class="form-select" style="max-width:200px;" onchange="this.form.submit()">
    <option value=""><?= __('po_filter_all_statuses') ?></option>
    <?php foreach ($validStatuses as $st): ?>
    <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= htmlspecialchars($statusLabels[$st]) ?></option>
    <?php endforeach; ?>
  </select>
  <select class="form-select form-select-sm d-md-none sort-select" onchange="if (this.value) location.href = this.value">
    <option value="" <?= empty($_GET['sort']) ? 'selected' : '' ?> disabled><?= __('common_sort_by') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('reference')) ?>" <?= ($_GET['sort'] ?? '') === 'reference' ? 'selected' : '' ?>><?= __('common_reference') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('supplier')) ?>" <?= ($_GET['sort'] ?? '') === 'supplier' ? 'selected' : '' ?>><?= __('common_supplier') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('order_date')) ?>" <?= ($_GET['sort'] ?? '') === 'order_date' ? 'selected' : '' ?>><?= __('po_order_date_label') ?></option>
    <option value="<?= htmlspecialchars(mobileSortUrl('status')) ?>" <?= ($_GET['sort'] ?? '') === 'status' ? 'selected' : '' ?>><?= __('common_status') ?></option>
  </select>
</form>

<div class="card">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr>
        <th><?= sortHeader('reference', __('common_reference')) ?></th>
        <th><?= sortHeader('supplier', __('common_supplier')) ?></th>
        <th><?= sortHeader('order_date', __('po_order_date_label')) ?></th>
        <th><?= __('po_expected_date_label') ?></th>
        <th><?= sortHeader('status', __('common_status')) ?></th>
        <th class="text-end"><?= __('po_col_total') ?></th>
        <th class="text-end"><?= __('common_actions') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$purchaseOrders): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('po_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($purchaseOrders as $po): ?>
      <tr>
        <td class="row-title"><a href="<?= BASE_URL ?>/purchase-order/view.php?id=<?= $po['id'] ?>" class="mono text-primary text-decoration-none"><?= htmlspecialchars($po['reference']) ?></a></td>
        <td data-label="<?= htmlspecialchars(__('common_supplier')) ?>"><?= htmlspecialchars($po['supplier_name']) ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('po_order_date_label')) ?>"><?= htmlspecialchars($po['order_date']) ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('po_expected_date_label')) ?>"><?= $po['expected_date'] ? htmlspecialchars($po['expected_date']) : '<span class="text-secondary">—</span>' ?></td>
        <td data-label="<?= htmlspecialchars(__('common_actions')) ?>"><span class="badge-stock <?= $statusBadgeClass[$po['status']] ?>"><?= htmlspecialchars($statusLabels[$po['status']]) ?></span></td>
        <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_col_total')) ?>">$<?= number_format((float) $po['total'], 2) ?></td>
        <td class="text-end row-actions">
          <a href="<?= BASE_URL ?>/purchase-order/view.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
          <?php if (canWrite() && $po['status'] === 'draft'): ?>
          <a href="<?= BASE_URL ?>/purchase-order/edit.php?id=<?= $po['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
          <form method="post" class="d-inline" onsubmit="return confirm('<?= __('po_delete_confirm') ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $po['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
