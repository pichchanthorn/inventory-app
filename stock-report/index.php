<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'stock-report';
$tab = $_GET['tab'] ?? 'overview';

// ---------- CSV export ----------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rows = $pdo->query("SELECT t.reference, t.transaction_date, t.type, COUNT(i.id) items, SUM(i.qty) qty, SUM(i.subtotal) value, t.note
                          FROM stock_transactions t
                          LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                          GROUP BY t.id ORDER BY t.id DESC")->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Reference', 'Date', 'Type', 'Products', 'Units', 'Value', 'Note']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['reference'], $r['transaction_date'], $r['type'], $r['items'], $r['qty'], number_format($r['value'], 2, '.', ''), $r['note']]);
    }
    fclose($out);
    exit;
}

$unitsIn  = (int) $pdo->query("SELECT COALESCE(SUM(i.qty),0) FROM stock_transactions t JOIN stock_transaction_items i ON i.transaction_id=t.id WHERE t.type='in'")->fetchColumn();
$unitsOut = (int) $pdo->query("SELECT COALESCE(SUM(i.qty),0) FROM stock_transactions t JOIN stock_transaction_items i ON i.transaction_id=t.id WHERE t.type='out'")->fetchColumn();
$txCount  = (int) $pdo->query('SELECT COUNT(*) FROM stock_transactions')->fetchColumn();
$byType   = $pdo->query('SELECT type, COUNT(*) c FROM stock_transactions GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);

$typeLabels = ['in' => __('nav_stock_in'), 'out' => __('nav_stock_out'), 'adjustment' => __('common_adjustment_label')];

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0"><?= __('nav_stock_reports') ?></h4>
  <a class="btn btn-primary" href="?export=csv"><i class="bi bi-download"></i> <?= __('stockreport_export_button') ?></a>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3"><div class="card p-3"><div class="bracket-label mb-2"><?= __('stockreport_units_in') ?></div><div class="fs-4 mono fw-bold" style="color:var(--good);"><?= $unitsIn ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="bracket-label mb-2"><?= __('stockreport_units_out') ?></div><div class="fs-4 mono fw-bold" style="color:var(--danger);"><?= $unitsOut ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="bracket-label mb-2"><?= __('stockreport_net_flow') ?></div><div class="fs-4 mono fw-bold"><?= ($unitsIn - $unitsOut >= 0 ? '+' : '') . ($unitsIn - $unitsOut) ?></div></div></div>
  <div class="col-md-3"><div class="card p-3"><div class="bracket-label mb-2"><?= __('stockreport_transactions') ?></div><div class="fs-4 mono fw-bold"><?= $txCount ?></div></div></div>
</div>

<ul class="nav nav-pills mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab==='overview'?'active':'' ?>" href="?tab=overview"><?= __('stockreport_tab_overview') ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='log'?'active':'' ?>" href="?tab=log"><?= __('stockreport_tab_log') ?></a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='product'?'active':'' ?>" href="?tab=product"><?= __('stockreport_tab_product') ?></a></li>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="card p-3">
    <div class="bracket-label mb-3"><?= __('stockreport_by_type_title') ?></div>
    <?php if (!$byType): ?><p class="text-secondary small mb-0"><?= __('common_no_transactions') ?></p><?php endif; ?>
    <?php foreach (['in' => __('nav_stock_in'), 'out' => __('nav_stock_out'), 'adjustment' => __('stockreport_adjustments_label')] as $key => $label): ?>
      <div class="d-flex justify-content-between border-bottom py-2">
        <span class="text-secondary"><?= $label ?></span>
        <span class="mono fw-semibold"><?= $byType[$key] ?? 0 ?></span>
      </div>
    <?php endforeach; ?>
  </div>

<?php elseif ($tab === 'log'):
  $rows = $pdo->query("SELECT t.*, COUNT(i.id) items, SUM(i.qty) qty, SUM(i.subtotal) value
                        FROM stock_transactions t
                        LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                        GROUP BY t.id ORDER BY t.id DESC")->fetchAll();
?>
  <div class="card">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th><?= __('common_reference') ?></th><th><?= __('common_date') ?></th><th><?= __('common_type') ?></th><th><?= __('stockreport_col_products') ?></th><th><?= __('stockreport_col_units') ?></th><th><?= __('common_value') ?></th><th><?= __('common_note') ?></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-secondary py-4"><?= __('common_no_transactions') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono text-primary"><?= htmlspecialchars($r['reference']) ?></td>
          <td class="mono"><?= $r['transaction_date'] ?></td>
          <td><span class="badge-stock <?= $r['type']==='out' ? 'badge-low' : 'badge-normal' ?>"><?= htmlspecialchars($typeLabels[$r['type']] ?? $r['type']) ?></span></td>
          <td><?= $r['items'] ?></td>
          <td><?= (int) $r['qty'] ?></td>
          <td>$<?= number_format($r['value'], 2) ?></td>
          <td class="text-secondary small"><?= htmlspecialchars($r['note']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else:
  $rows = $pdo->query('SELECT p.*, c.name category_name FROM products p LEFT JOIN categories c ON c.id = p.category_id ORDER BY p.name')->fetchAll();
?>
  <div class="card">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th><?= __('common_product') ?></th><th><?= __('common_category') ?></th><th><?= __('stockreport_col_current_stock') ?></th><th><?= __('stockreport_col_level') ?></th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-secondary py-4"><?= __('product_empty') ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $p): $low = $p['current_stock'] <= $p['min_stock']; ?>
        <tr>
          <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
          <td><?= $p['category_name'] ? htmlspecialchars($p['category_name']) : '<span class="text-secondary">—</span>' ?></td>
          <td><?= $p['current_stock'] ?></td>
          <td><span class="badge-stock <?= $low ? 'badge-low' : 'badge-normal' ?>"><?= $low ? __('stockreport_badge_low') : __('stockreport_badge_normal') ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
