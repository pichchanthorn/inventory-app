<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'pos';
$error = '';
// Post/Redirect/Get: a successful sale redirects here with the receipt
// stashed in the session (never persisted - cash_received/change_due
// aren't DB columns) so a page refresh re-fetches this GET instead of
// resubmitting the POST and creating a duplicate sale.
$sale = $_SESSION['pos_last_sale'] ?? null;
unset($_SESSION['pos_last_sale']);

$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
$productsById = [];
foreach ($products as $p) {
    $productsById[$p['id']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $productIds = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        $cashReceived = (float) ($_POST['cash_received'] ?? 0);

        $lines = [];
        foreach ($productIds as $i => $pid) {
            if ($pid !== '' && (float) $qtys[$i] > 0) {
                $lines[] = ['product_id' => (int) $pid, 'qty' => (float) $qtys[$i], 'price' => (float) $prices[$i]];
            }
        }

        if (!$lines) {
            $error = __('stockout_err_add_product');
        } elseif (array_filter($lines, fn($line) => $line['price'] < 0)) {
            $error = __('common_err_invalid_price');
        } else {
            // Server-side total — the only total that decides anything. Whatever
            // the client displayed is advisory only.
            $total = 0.0;
            foreach ($lines as $line) {
                $total += $line['qty'] * $line['price'];
            }

            if ($cashReceived < $total) {
                $error = __('pos_err_insufficient_cash');
            } else {
                // A sale is always "now" - never a client-supplied, backdatable value.
                $today = date('Y-m-d');
                try {
                    $reference = recordStockOut($pdo, $lines, $today, '', $_SESSION['user_id'], 'sale', $cashReceived);

                    $receiptLines = [];
                    foreach ($lines as $line) {
                        $p = $productsById[$line['product_id']] ?? null;
                        $receiptLines[] = [
                            'name' => $p['name'] ?? '',
                            'sku' => $p['sku'] ?? '',
                            'qty' => $line['qty'],
                            'price' => $line['price'],
                            'subtotal' => $line['qty'] * $line['price'],
                        ];
                    }
                    $_SESSION['pos_last_sale'] = [
                        'reference' => $reference,
                        'date' => $today,
                        'cashier' => $_SESSION['user_name'] ?? null,
                        'lines' => $receiptLines,
                        'total' => $total,
                        'cash_received' => $cashReceived,
                        'change_due' => $cashReceived - $total,
                    ];
                    header('Location: ' . BASE_URL . '/pos/index.php');
                    exit;
                } catch (StockConflictException $e) {
                    // The guarded UPDATE found insufficient stock at write time -
                    // re-fetch current values to build the same friendly message
                    // Stock Out uses for the identical failure.
                    $stmt = $pdo->prepare('SELECT name, current_stock FROM products WHERE id = ?');
                    $stmt->execute([$e->productId]);
                    $p = $stmt->fetch();
                    $have = $p['current_stock'] ?? 0;
                    $name = $p['name'] ?? '';
                    $requestedQty = 0;
                    foreach ($lines as $line) {
                        if ($line['product_id'] === $e->productId) {
                            $requestedQty = $line['qty'];
                            break;
                        }
                    }
                    $error = __('stockout_err_insufficient_prefix') . " $name (" . __('stockout_err_insufficient_have') . " $have, " . __('stockout_err_insufficient_requested') . " $requestedQty).";
                } catch (Throwable $e) {
                    error_log('POS sale failed: ' . $e->getMessage());
                    $error = __('common_err_transaction_failed');
                }
            }
        }
    }
}

$recentSales = $pdo->query("SELECT t.*, COUNT(i.id) items, SUM(i.qty) total_qty, SUM(i.subtotal) total_value
                        FROM stock_transactions t
                        LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                        WHERE t.type = 'sale'
                        GROUP BY t.id ORDER BY t.id DESC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('nav_pos') ?></h4>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<?php if ($sale): ?>
<div class="row g-3">
  <div class="col-lg-7">
    <?php
    $receiptSecondaryAction = '<a href="' . BASE_URL . '/pos/index.php" class="btn btn-primary flex-fill"><i class="bi bi-plus-lg"></i> ' . htmlspecialchars(__('pos_new_sale_button')) . '</a>';
    require __DIR__ . '/../includes/receipt_view.php';
    ?>
  </div>
</div>

<?php else: ?>
<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" id="posForm">
      <?= csrf_field() ?>
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="bracket-label mb-0"><?= __('common_line_items') ?></div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><?= __('common_add_product') ?></button>
        </div>
        <table class="table" id="lineTable">
          <thead class="table-light">
            <tr><th><?= __('common_product') ?></th><th style="width:100px;"><?= __('common_qty') ?></th><th style="width:130px;"><?= __('stockout_unit_price') ?></th><th style="width:110px;" class="text-end"><?= __('pos_line_total') ?></th><th style="width:40px;"></th></tr>
          </thead>
          <tbody id="lineBody"></tbody>
        </table>
        <div class="d-flex justify-content-end">
          <div class="text-end" style="min-width:220px;">
            <div class="d-flex justify-content-between py-1 border-top pt-2">
              <span class="text-secondary"><?= __('pos_subtotal_label') ?></span>
              <span class="mono fw-bold" id="posSubtotal">$0.00</span>
            </div>
          </div>
        </div>
      </div>

      <div class="card p-3 mt-3">
        <div class="bracket-label mb-3"><?= __('pos_payment_section_title') ?></div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('pos_cash_received_label') ?></label>
            <input type="number" name="cash_received" id="posCashReceived" class="form-control" step="0.01" min="0" required oninput="updateChange()">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('pos_change_due_label') ?></label>
            <input type="text" class="form-control mono" id="posChangeDue" value="$0.00" disabled>
          </div>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-cash-coin"></i> <?= __('pos_submit_button') ?></button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('pos_recent_sales_title') ?></div>
      <?php if (!$recentSales): ?><p class="text-secondary small text-center py-3"><i class="bi bi-inbox fs-4 d-block mb-2"></i><?= __('common_no_transactions') ?></p><?php endif; ?>
      <?php foreach ($recentSales as $t): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between small">
            <a href="<?= BASE_URL ?>/pos/receipt.php?ref=<?= urlencode($t['reference']) ?>" class="mono text-primary text-decoration-none"><?= htmlspecialchars($t['reference']) ?></a>
            <span class="mono text-secondary"><?= $t['transaction_date'] ?></span>
          </div>
          <div class="small mt-1"><?= $t['items'] ?> <?= __('common_products_word') ?> · <?= (int)$t['total_qty'] ?> <?= __('common_units_word') ?> · <strong>$<?= number_format($t['total_value'], 2) ?></strong></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const PRODUCTS = <?= json_encode($products) ?>;
const T_CHOOSE_PRODUCT = <?= json_encode(__('common_choose_product_option')) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;

function productOptions(selected) {
  let html = `<option value="">${T_CHOOSE_PRODUCT}</option>`;
  PRODUCTS.forEach(p => {
    html += `<option value="${p.id}" data-price="${p.sale_price}" ${String(p.id)===String(selected)?'selected':''}>${p.name} · ${p.sku} (${T_NOW}: ${p.current_stock} ${T_PCS})</option>`;
  });
  return html;
}

function addRow(productId = '', qty = 1, price = '') {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select name="product_id[]" class="form-select form-select-sm" onchange="fillPrice(this)">${productOptions(productId)}</select></td>
    <td><input type="number" name="qty[]" class="form-control form-control-sm" value="${qty}" min="1" oninput="updateSubtotal()"></td>
    <td><input type="number" name="unit_price[]" class="form-control form-control-sm" value="${price}" step="0.01" oninput="updateSubtotal()"></td>
    <td class="text-end mono line-total">$0.00</td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateSubtotal();">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
  updateSubtotal();
}
function fillPrice(sel) {
  const opt = sel.selectedOptions[0];
  const row = sel.closest('tr');
  row.querySelector('[name="unit_price[]"]').value = opt.dataset.price || 0;
  updateSubtotal();
}

// Purely a live display convenience for the cashier - the server
// independently recomputes the total from validated line data and never
// trusts these figures.
function updateSubtotal() {
  let subtotal = 0;
  document.querySelectorAll('#lineBody tr').forEach(tr => {
    const qty = parseFloat(tr.querySelector('[name="qty[]"]').value) || 0;
    const price = parseFloat(tr.querySelector('[name="unit_price[]"]').value) || 0;
    const lineTotal = qty * price;
    tr.querySelector('.line-total').textContent = '$' + lineTotal.toFixed(2);
    subtotal += lineTotal;
  });
  document.getElementById('posSubtotal').textContent = '$' + subtotal.toFixed(2);
  updateChange();
}

function updateChange() {
  const subtotal = parseFloat(document.getElementById('posSubtotal').textContent.replace('$', '')) || 0;
  const cash = parseFloat(document.getElementById('posCashReceived').value) || 0;
  const change = cash - subtotal;
  const changeEl = document.getElementById('posChangeDue');
  changeEl.value = '$' + change.toFixed(2);
  changeEl.classList.toggle('text-danger', change < 0);
}

addRow();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
