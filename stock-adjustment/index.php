<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'stock-adjustment';
$error = '';
$success = '';

$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) $_POST['product_id'];
    $newQty = (float) $_POST['new_qty'];
    $reason = trim($_POST['reason']);
    $date = $_POST['transaction_date'];

    if (!$productId) {
        $error = 'Select a product.';
    } elseif ($reason === '') {
        $error = 'Reason is required.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        $pdo->beginTransaction();
        $reference = 'ADJ-' . str_pad((string) ($pdo->query('SELECT COUNT(*) FROM stock_transactions')->fetchColumn() + 1), 6, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare('INSERT INTO stock_transactions (reference, type, transaction_date, note, supplier_id, user_id) VALUES (?,?,?,?,NULL,?)');
        $stmt->execute([$reference, 'adjustment', $date, $reason, $_SESSION['user_id']]);
        $txId = $pdo->lastInsertId();

        $diff = abs($newQty - $product['current_stock']);
        $stmt = $pdo->prepare('INSERT INTO stock_transaction_items (transaction_id, product_id, qty, unit_price, subtotal) VALUES (?,?,?,0,0)');
        $stmt->execute([$txId, $productId, $diff]);

        $stmt = $pdo->prepare('UPDATE products SET current_stock = ? WHERE id = ?');
        $stmt->execute([$newQty, $productId]);

        $pdo->commit();
        $success = "Applied $reference — {$product['name']}: {$product['current_stock']} → $newQty.";

        // refresh products list so the dropdown shows the new stock
        $products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
    }
}

$recent = $pdo->query("SELECT t.*, p.name product_name, i.qty
                        FROM stock_transactions t
                        LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                        LEFT JOIN products p ON p.id = i.product_id
                        WHERE t.type = 'adjustment'
                        ORDER BY t.id DESC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4">Stock Adjustments</h4>
<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post">
      <div class="card p-3 mb-3">
        <div class="bracket-label mb-3">TRANSACTION_DETAILS</div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Transaction date</label>
            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Reason *</label>
            <input type="text" name="reason" class="form-control" placeholder="e.g. Physical count correction" required>
          </div>
        </div>
      </div>

      <div class="card p-3">
        <div class="bracket-label mb-3">ADJUSTMENT</div>
        <div class="mb-3">
          <label class="form-label">Product</label>
          <select name="product_id" id="adjProduct" class="form-select" required onchange="updatePreview()">
            <option value="">— Select product —</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" data-stock="<?= $p['current_stock'] ?>"><?= htmlspecialchars($p['name']) ?> · <?= htmlspecialchars($p['sku']) ?> (now: <?= $p['current_stock'] ?> pcs)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">New stock quantity</label>
          <input type="number" name="new_qty" id="adjQty" class="form-control" value="0" min="0" oninput="updatePreview()">
        </div>
        <div id="adjPreview" class="small text-secondary mb-3">Select a product to preview the change.</div>
        <div class="alert alert-warning small">Adjustments set the exact stock level and create a permanent audit record.</div>
        <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> Apply Adjustment</button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <div class="bracket-label mb-3">RECENT_ADJUSTMENTS</div>
      <?php if (!$recent): ?><p class="text-secondary small">No adjustments yet.</p><?php endif; ?>
      <?php foreach ($recent as $t): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between small">
            <span class="mono text-primary"><?= htmlspecialchars($t['reference']) ?></span>
            <span class="mono text-secondary"><?= $t['transaction_date'] ?></span>
          </div>
          <div class="small mt-1"><?= htmlspecialchars($t['product_name'] ?? '—') ?></div>
          <div class="text-secondary small"><?= htmlspecialchars($t['note']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
function updatePreview() {
  const sel = document.getElementById('adjProduct');
  const opt = sel.selectedOptions[0];
  const preview = document.getElementById('adjPreview');
  if (!opt || !opt.value) { preview.textContent = 'Select a product to preview the change.'; return; }
  const current = Number(opt.dataset.stock);
  const next = Number(document.getElementById('adjQty').value) || 0;
  const diff = next - current;
  preview.innerHTML = `${current} → <strong>${next}</strong> (${diff >= 0 ? '+' : ''}${diff} units)`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
