<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'purchase-order';

$success = $_SESSION['po_flash'] ?? '';
unset($_SESSION['po_flash']);
$error = $_SESSION['po_flash_error'] ?? '';
unset($_SESSION['po_flash_error']);

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name, s.phone AS supplier_phone,
                               cu.name AS created_by_name
                        FROM purchase_orders po
                        JOIN suppliers s ON s.id = po.supplier_id
                        LEFT JOIN users cu ON cu.id = po.created_by
                        WHERE po.id = ?');
$stmt->execute([$id]);
$po = $stmt->fetch();

require_once __DIR__ . '/../includes/header.php';

if (!$po) {
    ?>
    <div class="card p-4 text-center text-secondary">
      <i class="bi bi-exclamation-circle fs-3 d-block mb-2"></i>
      <?= __('po_err_not_found') ?>
      <div class="mt-3"><a href="<?= BASE_URL ?>/purchase-order/index.php" class="btn btn-outline-primary btn-sm"><?= __('po_back_to_list') ?></a></div>
    </div>
    <?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$itemStmt = $pdo->prepare('SELECT poi.*, p.name AS product_name, p.sku, p.package_size
                            FROM purchase_order_items poi
                            JOIN products p ON p.id = poi.product_id
                            WHERE poi.purchase_order_id = ?
                            ORDER BY poi.id');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$total = 0.0;
foreach ($items as $it) {
    $total += (float) $it['subtotal'];
}

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
?>

<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <h4 class="mb-0"><?= __('po_detail_title') ?> — <span class="mono"><?= htmlspecialchars($po['reference']) ?></span></h4>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/purchase-order/index.php" class="btn btn-outline-secondary btn-sm"><?= __('po_back_to_list') ?></a>
    <?php if (canWrite() && $po['status'] === 'draft'): ?>
    <a href="<?= BASE_URL ?>/purchase-order/edit.php?id=<?= $po['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> <?= __('po_edit_button') ?></a>
    <form method="post" action="<?= BASE_URL ?>/purchase-order/index.php" class="d-inline" onsubmit="return confirm('<?= __('po_delete_confirm') ?>')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= $po['id'] ?>">
      <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> <?= __('po_delete_button') ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-8">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
      <div class="row">
        <div class="col-sm-6 mb-2"><span class="text-secondary small d-block"><?= __('common_supplier') ?></span><?= htmlspecialchars($po['supplier_name']) ?></div>
        <div class="col-sm-6 mb-2"><span class="text-secondary small d-block"><?= __('common_status') ?></span><span class="badge-stock <?= $statusBadgeClass[$po['status']] ?>"><?= htmlspecialchars($statusLabels[$po['status']]) ?></span></div>
        <div class="col-sm-6 mb-2"><span class="text-secondary small d-block"><?= __('po_order_date_label') ?></span><span class="mono"><?= htmlspecialchars($po['order_date']) ?></span></div>
        <div class="col-sm-6 mb-2"><span class="text-secondary small d-block"><?= __('po_expected_date_label') ?></span><span class="mono"><?= $po['expected_date'] ? htmlspecialchars($po['expected_date']) : '—' ?></span></div>
        <?php if ($po['note']): ?>
        <div class="col-12 mb-0"><span class="text-secondary small d-block"><?= __('common_note') ?></span><?= nl2br(htmlspecialchars($po['note'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card p-3">
      <div class="bracket-label mb-2"><?= __('po_col_total') ?></div>
      <div class="fs-3 fw-bold mono">$<?= number_format($total, 2) ?></div>
      <?php if ($po['created_by_name']): ?>
      <div class="text-secondary small mt-2"><?= __('po_created_by_label') ?>: <?= htmlspecialchars($po['created_by_name']) ?></div>
      <?php endif; ?>
      <div class="text-secondary small"><?= htmlspecialchars($po['created_at']) ?></div>
    </div>
  </div>
</div>

<div class="card">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr>
        <th><?= __('common_product') ?></th>
        <th class="text-end"><?= __('common_qty') ?></th>
        <th class="text-end"><?= __('po_unit_cost') ?></th>
        <th class="text-end"><?= __('po_subtotal') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
      <tr>
        <td class="row-title">
          <?= htmlspecialchars($it['product_name']) ?>
          <?php if ($it['package_size']): ?><span class="text-secondary small"> — <?= htmlspecialchars($it['package_size']) ?></span><?php endif; ?>
          <div><span class="slug-pill"><?= htmlspecialchars($it['sku']) ?></span></div>
        </td>
        <td class="mono text-end" data-label="<?= htmlspecialchars(__('common_qty')) ?>"><?= (int) $it['ordered_qty'] ?></td>
        <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_unit_cost')) ?>">$<?= number_format((float) $it['unit_cost'], 2) ?></td>
        <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_subtotal')) ?>">$<?= number_format((float) $it['subtotal'], 2) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3" class="text-end fw-bold"><?= __('po_col_total') ?></td><td class="text-end fw-bold mono">$<?= number_format($total, 2) ?></td></tr>
    </tfoot>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
