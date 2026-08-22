<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

// Read-only lookup for a past Stock In / Stock Out / Adjustment
// transaction's line-item detail, by reference. No canWrite() gate - same
// reasoning as pos/receipt.php: Viewer already sees this same
// transaction's summary row in Stock Reports' Transaction Log (and its
// aggregate in the originating module's own Recent sidebar), so this is
// strictly more detail on data they can already read, not a new category
// of access.
//
// 'sale' rows are deliberately excluded from this lookup - they stay
// exclusively on pos/receipt.php, which this page does not touch or
// replace.
$activePage = 'stock-report';
$ref = trim($_GET['ref'] ?? '');

$stmt = $pdo->prepare("SELECT t.*, u.name AS performed_by, s.name AS supplier_name
                        FROM stock_transactions t
                        LEFT JOIN users u ON u.id = t.user_id
                        LEFT JOIN suppliers s ON s.id = t.supplier_id
                        WHERE t.type IN ('in','out','adjustment') AND t.reference = ?");
$stmt->execute([$ref]);
$row = $stmt->fetch();

$typeTitles = ['in' => __('stockin_detail_title'), 'out' => __('stockout_detail_title'), 'adjustment' => __('stockadj_detail_title')];

$tx = null;
if ($row) {
    // Highlight the sidebar nav item matching where this transaction came
    // from, rather than a generic/unrelated one.
    $activePage = $row['type'] === 'adjustment' ? 'stock-adjustment' : 'stock-' . $row['type'];

    $stmt = $pdo->prepare('SELECT i.*, p.name, p.sku, p.package_size
                            FROM stock_transaction_items i
                            JOIN products p ON p.id = i.product_id
                            WHERE i.transaction_id = ?
                            ORDER BY i.id');
    $stmt->execute([$row['id']]);

    // Adjustment lines always carry unit_price/subtotal = 0 (adjustStock()
    // never prices them) - showing a $0.00 "total" for those would be
    // misleading, so the view drops the price/subtotal columns and total
    // entirely for that type rather than displaying meaningless zeros.
    $showPrices = $row['type'] !== 'adjustment';

    $total = 0.0;
    $lines = [];
    foreach ($stmt->fetchAll() as $item) {
        $total += (float) $item['subtotal'];
        $lines[] = [
            'name' => $item['name'],
            'sku' => $item['sku'],
            'package_size' => $item['package_size'],
            'qty' => $item['qty'],
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
        ];
    }

    $tx = [
        'reference' => $row['reference'],
        'type' => $row['type'],
        'title' => $typeTitles[$row['type']],
        'date' => $row['transaction_date'],
        'performed_by' => $row['performed_by'],
        'supplier' => $row['type'] === 'in' ? $row['supplier_name'] : null,
        'note' => $row['note'] !== null && $row['note'] !== '' ? $row['note'] : null,
        'show_prices' => $showPrices,
        'lines' => $lines,
        'total' => $total,
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= $tx['title'] ?? __('nav_stock_reports') ?></h4>

<?php if (!$tx): ?>
  <div class="alert alert-warning"><?= __('txdetail_not_found') ?></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7">
    <?php
    $txDetailSecondaryAction = '<a href="' . BASE_URL . '/stock-report/index.php?tab=log" class="btn btn-primary flex-fill"><i class="bi bi-arrow-left"></i> ' . htmlspecialchars(__('nav_stock_reports')) . '</a>';
    require __DIR__ . '/../includes/transaction_detail.php';
    ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
