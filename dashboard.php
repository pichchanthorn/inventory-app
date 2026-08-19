<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/db.php';
$activePage = 'dashboard';

$totalProducts = $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$totalUnits    = $pdo->query('SELECT COALESCE(SUM(current_stock),0) FROM products')->fetchColumn();
$totalValue    = $pdo->query('SELECT COALESCE(SUM(current_stock * cost_price),0) FROM products')->fetchColumn();
$lowStock      = $pdo->query('SELECT COUNT(*) FROM products WHERE current_stock <= min_stock')->fetchColumn();

// ---------- Stock movement chart: units in vs out per day, last 7 days ----------
$movementRows = $pdo->query("
    SELECT t.transaction_date, t.type, SUM(i.qty) AS total_qty
    FROM stock_transactions t
    JOIN stock_transaction_items i ON i.transaction_id = t.id
    WHERE t.type IN ('in','out') AND t.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY t.transaction_date, t.type
")->fetchAll();

$movementByDate = [];
for ($i = 6; $i >= 0; $i--) {
    $movementByDate[date('Y-m-d', strtotime("-$i days"))] = ['in' => 0, 'out' => 0];
}
foreach ($movementRows as $row) {
    if (isset($movementByDate[$row['transaction_date']])) {
        $movementByDate[$row['transaction_date']][$row['type']] = (float) $row['total_qty'];
    }
}
$movementLabels = array_map(fn($d) => date('M j', strtotime($d)), array_keys($movementByDate));
$movementIn     = array_values(array_map(fn($v) => $v['in'], $movementByDate));
$movementOut    = array_values(array_map(fn($v) => $v['out'], $movementByDate));
$hasMovementData = (array_sum($movementIn) + array_sum($movementOut)) > 0;

// ---------- Category chart: product count per category ----------
$categoryRows = $pdo->query('
    SELECT c.name AS category_name, COUNT(*) AS product_count
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    GROUP BY c.id, c.name
    ORDER BY product_count DESC
')->fetchAll();

$categoryLabels = array_map(fn($r) => $r['category_name'] ?? __('dashboard_uncategorized'), $categoryRows);
$categoryCounts = array_map(fn($r) => (int) $r['product_count'], $categoryRows);

require_once __DIR__ . '/includes/header.php';
?>
<h4 class="mb-4"><?= __('dashboard_title') ?></h4>
<div class="row g-3">
  <div class="col-md-3">
    <div class="card p-3 kpi-card">
      <div class="kpi-card-icon"><i class="bi bi-box"></i></div>
      <div class="bracket-label mb-2"><i class="bi bi-box kpi-icon"></i><?= __('dashboard_total_products') ?></div>
      <div class="fs-3 mono fw-bold"><?= $totalProducts ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3 kpi-card">
      <div class="kpi-card-icon"><i class="bi bi-boxes"></i></div>
      <div class="bracket-label mb-2"><i class="bi bi-boxes kpi-icon"></i><?= __('dashboard_units_in_stock') ?></div>
      <div class="fs-3 mono fw-bold"><?= $totalUnits ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3 kpi-card">
      <div class="kpi-card-icon"><i class="bi bi-cash-coin"></i></div>
      <div class="bracket-label mb-2"><i class="bi bi-cash-stack kpi-icon"></i><?= __('dashboard_inventory_value') ?></div>
      <div class="fs-3 mono fw-bold">$<?= number_format($totalValue, 2) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card p-3 kpi-card <?= $lowStock > 0 ? 'border-danger' : '' ?>">
      <div class="kpi-card-icon icon-danger"><i class="bi bi-exclamation-triangle"></i></div>
      <div class="bracket-label mb-2" style="color:var(--danger);"><i class="bi bi-exclamation-triangle kpi-icon"></i><?= __('dashboard_low_stock') ?></div>
      <div class="fs-3 mono fw-bold text-danger"><?= $lowStock ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mt-3">
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('dashboard_chart_movement_title') ?></div>
      <?php if ($hasMovementData): ?>
        <canvas id="movementChart" height="220" role="img" aria-label="<?= htmlspecialchars(__('dashboard_chart_movement_title')) ?>"></canvas>
      <?php else: ?>
        <div class="text-secondary small text-center py-4">
          <i class="bi bi-graph-up fs-4 d-block mb-2"></i>
          <?= __('dashboard_movement_empty') ?>
          <div class="mt-2">
            <a href="<?= BASE_URL ?>/stock-in/index.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> <?= __('nav_stock_in') ?></a>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('dashboard_chart_category_title') ?></div>
      <?php if ($categoryLabels): ?>
        <canvas id="categoryChart" height="220" role="img" aria-label="<?= htmlspecialchars(__('dashboard_chart_category_title')) ?>"></canvas>
      <?php else: ?>
        <p class="text-secondary small text-center py-4"><i class="bi bi-inbox fs-4 d-block mb-2"></i><?= __('product_empty') ?></p>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const MOVEMENT_LABELS = <?= json_encode($movementLabels) ?>;
const MOVEMENT_IN = <?= json_encode($movementIn) ?>;
const MOVEMENT_OUT = <?= json_encode($movementOut) ?>;
const CATEGORY_LABELS = <?= json_encode($categoryLabels) ?>;
const CATEGORY_COUNTS = <?= json_encode($categoryCounts) ?>;
const T_STOCK_IN = <?= json_encode(__('nav_stock_in')) ?>;
const T_STOCK_OUT = <?= json_encode(__('nav_stock_out')) ?>;

let movementChart = null;
let categoryChart = null;

function chartTheme() {
  const cs = getComputedStyle(document.body);
  const read = (name) => cs.getPropertyValue(name).trim();
  return {
    accent: read('--accent'), good: read('--good'), danger: read('--danger'),
    warn: read('--warn'), muted: read('--muted'), line: read('--line'),
  };
}

function renderCharts() {
  if (typeof Chart === 'undefined') return;
  const c = chartTheme();
  const categoryPalette = [c.accent, c.good, c.danger, c.warn, '#A78BFA', '#F472B6', '#60A5FA'];

  const movementEl = document.getElementById('movementChart');
  if (movementEl) {
    if (movementChart) movementChart.destroy();
    movementChart = new Chart(movementEl, {
      type: 'bar',
      data: {
        labels: MOVEMENT_LABELS,
        datasets: [
          { label: T_STOCK_IN, data: MOVEMENT_IN, backgroundColor: c.good, borderRadius: 3, maxBarThickness: 28 },
          { label: T_STOCK_OUT, data: MOVEMENT_OUT, backgroundColor: c.danger, borderRadius: 3, maxBarThickness: 28 },
        ],
      },
      options: {
        responsive: true,
        plugins: { legend: { labels: { color: c.muted } } },
        scales: {
          x: { ticks: { color: c.muted }, grid: { color: c.line } },
          y: { ticks: { color: c.muted, stepSize: 1, precision: 0 }, grid: { color: c.line }, beginAtZero: true, suggestedMax: 5 },
        },
      },
    });
  }

  const categoryEl = document.getElementById('categoryChart');
  if (categoryEl) {
    if (categoryChart) categoryChart.destroy();
    categoryChart = new Chart(categoryEl, {
      type: 'doughnut',
      data: {
        labels: CATEGORY_LABELS,
        datasets: [{ data: CATEGORY_COUNTS, backgroundColor: categoryPalette, borderColor: c.line, borderWidth: 2 }],
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { color: c.muted, boxWidth: 12 } } },
      },
    });
  }
}

document.addEventListener('DOMContentLoaded', renderCharts);
document.addEventListener('themechange', renderCharts);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
