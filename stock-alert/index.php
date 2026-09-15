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

// Phase P3-B1: the same single query, now also LEFT JOINing suppliers so
// each low-stock row carries its own supplier name - grouping happens in
// PHP below, over these already-fetched rows, so no per-product or
// per-group supplier lookup is ever issued (no N+1). The low-stock
// condition itself (current_stock <= min_stock) is deliberately
// unchanged, as is lowStockTier()'s severity formula.
//
// ORDER BY, outermost first:
//   1. (p.supplier_id IS NULL) ASC - supplier groups first, the "No
//      Supplier" group last. Same boolean-expression idiom already used
//      for CRITICAL-before-LOW below and for FEFO ordering in
//      includes/stock.php ((expiry_date IS NULL) ASC).
//   2. s.name ASC - supplier groups alphabetically by supplier name.
//   3. p.supplier_id ASC - tie-break only: suppliers.name carries no
//      UNIQUE constraint, so two distinct suppliers may share a name;
//      without this their rows could interleave non-deterministically.
//   4-6. the pre-existing within-group ordering, byte-unchanged:
//      CRITICAL (current_stock = 0) first, then lowest stock first, then
//      name - so ordering *inside* any one group is exactly what this
//      page has always rendered.
$rows = $pdo->query('
    SELECT p.*, c.name AS category_name, s.name AS supplier_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.current_stock <= p.min_stock
    ORDER BY (p.supplier_id IS NULL) ASC, s.name ASC, p.supplier_id ASC,
             (p.current_stock > 0) ASC, p.current_stock ASC, p.name ASC
')->fetchAll();

// Group in PHP over the rows the query already returned, in the order it
// returned them - a plain append-per-row, so each group's internal order
// (and the order of the groups themselves) comes entirely from the SQL
// ORDER BY above rather than from a second sort here. Products with no
// supplier collect under the 'none' key, which the ORDER BY guarantees
// is reached last, so it renders as the final section.
$groups = [];
foreach ($rows as $p) {
    $key = $p['supplier_id'] !== null ? 'supplier_' . (int) $p['supplier_id'] : 'none';
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'has_supplier' => $p['supplier_id'] !== null,
            'supplier_name' => $p['supplier_name'],
            'products' => [],
        ];
    }
    $groups[$key]['products'][] = $p;
}

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('stockalert_title') ?></h4>

<?php if (!$groups): ?>
<div class="card">
  <div class="text-center text-secondary py-4">
    <i class="bi bi-check-circle fs-3 d-block mb-2"></i><?= __('stockalert_empty') ?>
  </div>
</div>
<?php endif; ?>

<?php foreach ($groups as $group): ?>
<div class="card mb-3">
  <div class="p-3 border-bottom">
    <div class="bracket-label mb-0">
      <?php if ($group['has_supplier']): ?>
        <i class="bi bi-truck"></i> <?= htmlspecialchars($group['supplier_name']) ?>
      <?php else: ?>
        <i class="bi bi-question-circle"></i> <?= __('stockalert_no_supplier') ?>
      <?php endif; ?>
    </div>
    <?php if (!$group['has_supplier']): ?>
      <div class="text-secondary small mt-2"><?= __('stockalert_no_supplier_help') ?></div>
    <?php endif; ?>
  </div>
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
      <?php foreach ($group['products'] as $p):
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
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
