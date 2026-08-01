<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/db.php';
$activePage = 'dashboard';

$totalProducts = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalUnits    = $pdo->query('SELECT COALESCE(SUM(current_stock),0) FROM products')->fetchColumn();
$totalValue    = $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price),0) FROM products')->fetchColumn();
$lowStock      = $pdo->query('SELECT COUNT(*) FROM products WHERE current_stock <= min_stock')->fetchColumn();

require_once __DIR__ . '/includes/header.php';
?>
<h4 class="mb-4">Dashboard</h4>
<div class="row g-3">
  <div class="col-md-3">
    <div class="card p-3">
      <div class="bracket-label mb-2">TOTAL_PRODUCTS</div>
      <div class="fs-3 mono fw-bold"><?= $totalProducts ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3">
      <div class="bracket-label mb-2">UNITS_IN_STOCK</div>
      <div class="fs-3 mono fw-bold"><?= $totalUnits ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3">
      <div class="bracket-label mb-2">INVENTORY_VALUE</div>
      <div class="fs-3 mono fw-bold">$<?= number_format($totalValue, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3 <?= $lowStock > 0 ? 'border-danger' : '' ?>">
      <div class="bracket-label mb-2" style="color:var(--danger);">LOW_STOCK</div>
      <div class="fs-3 mono fw-bold text-danger"><?= $lowStock ?></div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
