<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

// Read-only lookup for any past sale's receipt, by reference. No
// canWrite() gate - Viewer already sees this same sale's summary row in
// Stock Reports' transaction log, so this is strictly more detail on
// data they can already read, not a new category of access.
$activePage = 'pos';
$ref = trim($_GET['ref'] ?? '');

$stmt = $pdo->prepare("SELECT t.*, u.name AS cashier_name
                        FROM stock_transactions t
                        LEFT JOIN users u ON u.id = t.user_id
                        WHERE t.type = 'sale' AND t.reference = ?");
$stmt->execute([$ref]);
$tx = $stmt->fetch();

$sale = null;
if ($tx) {
    $stmt = $pdo->prepare('SELECT i.*, p.name, p.sku, p.package_size
                            FROM stock_transaction_items i
                            JOIN products p ON p.id = i.product_id
                            WHERE i.transaction_id = ?
                            ORDER BY i.id');
    $stmt->execute([$tx['id']]);

    $total = 0.0;
    $receiptLines = [];
    foreach ($stmt->fetchAll() as $item) {
        $total += (float) $item['subtotal'];
        $receiptLines[] = [
            'name' => $item['name'],
            'sku' => $item['sku'],
            'package' => $item['package_size'],
            'qty' => $item['qty'],
            'price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
        ];
    }

    // NULL means "not recorded" (a pre-migration row) - not a real zero -
    // and stays NULL through to the shared partial, which renders it as such
    // (unless this turns out to be a credit sale - see below).
    $cashReceived = $tx['cash_received'] !== null ? (float) $tx['cash_received'] : null;
    $sale = [
        'reference' => $tx['reference'],
        'date' => $tx['transaction_date'],
        'cashier' => $tx['cashier_name'],
        'lines' => $receiptLines,
        'total' => $total,
        'cash_received' => $cashReceived,
        'change_due' => $cashReceived !== null ? $cashReceived - $total : null,
    ];

    // A credit sale (Batch 2 of the Debt/Customer Credit feature) is a
    // normal 'sale' stock_transaction with a customer_debts row linking
    // back to it - see database/migrations/010_add_customer_debt_
    // tracking.sql. If one exists for this transaction, the shared
    // receipt partial shows the "paid later" block instead of cash_
    // received/change_due, matching pos/index.php's fresh-sale receipt.
    // Unlike the fresh-sale flash (always newly created and unpaid),
    // this is a past sale being looked up any time after checkout, so
    // paid_amount/balance/status are read live from customer_debts
    // (its own GENERATED ALWAYS columns) rather than assumed.
    $stmt = $pdo->prepare('SELECT d.reference AS debt_reference, d.due_date, d.paid_amount, d.balance, d.status, c.name AS customer_name, c.phone AS customer_phone
                            FROM customer_debts d
                            JOIN customers c ON c.id = d.customer_id
                            WHERE d.stock_transaction_id = ?');
    $stmt->execute([$tx['id']]);
    $debt = $stmt->fetch();
    if ($debt) {
        $sale['is_credit'] = true;
        $sale['customer_name'] = $debt['customer_name'];
        $sale['customer_phone'] = $debt['customer_phone'];
        $sale['due_date'] = $debt['due_date'];
        $sale['debt_reference'] = $debt['debt_reference'];
        $sale['paid_amount'] = (float) $debt['paid_amount'];
        $sale['balance'] = (float) $debt['balance'];
        $sale['debt_status'] = $debt['status'];
    }

    // Additive display-only conversion, same as pos/index.php's own
    // fresh-sale computation - see the comment there. A zero/unset rate
    // simply omits khr_total, which receipt_view.php treats as "don't
    // show the KHR line" rather than an error.
    $khrRate = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
    if ($khrRate !== false && (float) $khrRate > 0) {
        $sale['khr_total'] = $total * (float) $khrRate;
    }
}

// Shop identity for the Sale Invoice header - see pos/index.php's
// identical fetch for the fresh-sale view.
$business = $pdo->query('SELECT business_name, business_address, business_phone, business_email FROM app_settings WHERE id = 1')->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('pos_receipt_view_title') ?></h4>

<?php if (!$sale): ?>
  <div class="alert alert-warning"><?= __('pos_receipt_not_found') ?></div>
<?php else: ?>
<div class="row g-3">
  <div class="col-lg-7 invoice-col">
    <?php
    $receiptSecondaryAction = '<a href="' . BASE_URL . '/stock-report/index.php?tab=log" class="btn btn-primary flex-fill"><i class="bi bi-arrow-left"></i> ' . htmlspecialchars(__('nav_stock_reports')) . '</a>';
    require __DIR__ . '/../includes/receipt_view.php';
    ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
