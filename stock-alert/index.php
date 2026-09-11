<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock_alert.php';
require_once __DIR__ . '/../config/db.php';

// Phase L1 (Low Stock Alert / Reorder Management). Read-only, GET-only -
// no POST handler, no mutation, no CSRF token needed (same convention as
// stock-report/index.php and stock-transaction/view.php, the other
// read-only report pages in this app). Every authenticated role,
// including Viewer, may load this page - it surfaces the same
// current_stock <= min_stock signal the Dashboard KPI and Product list's
// ?filter=low_stock have always shown, just consolidated with a
// suggested reorder quantity and a quick link into Stock In.
$activePage = 'stock-alert';

// CRITICAL first, then LOW, ordered by how urgent each is within its own
// tier (lowest current_stock first) and finally by name for a fully
// deterministic result - expressed in SQL rather than sorted in PHP,
// since MySQL/MariaDB can express "CRITICAL (current_stock = 0) before
// LOW" directly via the boolean-expression idiom already used elsewhere
// in this app's FEFO ordering (see includes/stock.php's own
// (expiry_date IS NULL) ASC pattern).
$rows = $pdo->query('
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.current_stock <= p.min_stock
    ORDER BY (p.current_stock > 0) ASC, p.current_stock ASC, p.name ASC
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('stockalert_title') ?></h4>

<div class="card">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr>
        <th><?= __('common_product') ?></th>
        <th><?= __('common_category') ?></th>
        <th><?= __('stockalert_col_current_stock') ?></th>
        <th><?= __('stockalert_col_reorder_level') ?></th>
        <th><?= __('stockalert_col_suggested_qty') ?></th>
        <th><?= __('stockalert_col_severity') ?></th>
        <th class="text-end"><?= __('common_actions') ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4"><i class="bi bi-check-circle fs-3 d-block mb-2"></i><?= __('stockalert_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $p):
        $tier = lowStockTier((int) $p['current_stock'], (int) $p['min_stock']);
        $tierBadgeClass = ['critical' => 'badge-low', 'low' => 'badge-warn'][$tier];
        $tierLabel = ['critical' => __('common_severity_critical'), 'low' => __('common_severity_low')][$tier];
      ?>
      <tr>
        <td class="row-title">
          <div class="fw-semibold"><?= htmlspecialchars($p['name']) ?></div>
          <span class="slug-pill"><?= htmlspecialchars($p['sku']) ?></span>
          <?php if (!empty($p['package_size'])): ?><span class="text-secondary small ms-1"><?= htmlspecialchars($p['package_size']) ?></span><?php endif; ?>
        </td>
        <td data-label="<?= htmlspecialchars(__('common_category')) ?>"><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '<span class="text-secondary">—</span>' ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('stockalert_col_current_stock')) ?>"><?= (int) $p['current_stock'] ?> <?= __('common_pcs') ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('stockalert_col_reorder_level')) ?>"><?= (int) $p['min_stock'] ?></td>
        <td class="mono" data-label="<?= htmlspecialchars(__('stockalert_col_suggested_qty')) ?>">
          <?= $p['reorder_quantity'] !== null ? (int) $p['reorder_quantity'] : '<span class="text-secondary">' . htmlspecialchars(__('stockalert_reorder_not_set')) . '</span>' ?>
        </td>
        <td data-label="<?= htmlspecialchars(__('stockalert_col_severity')) ?>"><span class="badge-stock <?= $tierBadgeClass ?>"><?= $tierLabel ?></span></td>
        <td class="text-end row-actions">
          <a href="<?= BASE_URL ?>/stock-in/index.php?product_id=<?= (int) $p['id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-download"></i> <?= __('nav_stock_in') ?>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
