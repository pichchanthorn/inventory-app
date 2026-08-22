<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'stock-in';
$error = '';

// Post/Redirect/Get: a successful Stock In redirects here with the toast
// message stashed in the session, so a page refresh re-fetches this GET
// instead of resubmitting the POST and creating a duplicate transaction.
$success = $_SESSION['stockin_flash'] ?? '';
unset($_SESSION['stockin_flash']);

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$products  = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

// Used both server-side (resolvePriceField, below) to convert a KHR entry
// to the USD value actually passed to recordStockIn(), and exposed to JS
// as a page-global constant purely for the live "≈" preview text - the
// client never computes the value that gets submitted.
$khrRateRow = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
$khrRate = $khrRateRow !== false ? (float) $khrRateRow : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $supplierId = $_POST['supplier_id'] !== '' ? (int) $_POST['supplier_id'] : null;
        $date = $_POST['transaction_date'];
        $note = trim($_POST['note']);
        $productIds = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $costs = $_POST['unit_cost'] ?? [];
        $costCurrencies = $_POST['unit_cost_currency'] ?? [];

        try {
            $lines = [];
            foreach ($productIds as $i => $pid) {
                if ($pid !== '' && (float) $qtys[$i] > 0) {
                    $cost = resolvePriceField(
                        ['unit_cost' => $costs[$i] ?? 0, 'unit_cost_currency' => $costCurrencies[$i] ?? 'USD'],
                        'unit_cost', $khrRate
                    );
                    $lines[] = ['product_id' => (int) $pid, 'qty' => (float) $qtys[$i], 'cost' => $cost];
                }
            }

            if (!$lines) {
                $error = __('stockin_err_add_product');
            } else {
                $reference = recordStockIn($pdo, $lines, $date, $supplierId, $note, $_SESSION['user_id']);
                $_SESSION['stockin_flash'] = __('stockin_recorded_prefix') . " $reference " . __('stockin_recorded_suffix');
                header('Location: ' . BASE_URL . '/stock-in/index.php');
                exit;
            }
        } catch (PriceConversionException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Stock In failed: ' . $e->getMessage());
            $error = __('common_err_transaction_failed');
        }
    }
}

$recent = $pdo->query("SELECT t.*, COUNT(i.id) items, SUM(i.qty) total_qty, SUM(i.subtotal) total_value
                        FROM stock_transactions t
                        LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                        WHERE t.type = 'in'
                        GROUP BY t.id ORDER BY t.id DESC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('nav_stock_in') ?></h4>
<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" id="stockInForm">
      <?= csrf_field() ?>
      <div class="card p-3 mb-3">
        <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('common_supplier') ?></label>
            <select name="supplier_id" class="form-select">
              <option value=""><?= __('stockin_select_supplier') ?></option>
              <?php foreach ($suppliers as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('common_transaction_date') ?></label>
            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
        </div>
        <div class="mb-0">
          <label class="form-label"><?= __('common_note') ?></label>
          <input type="text" name="note" class="form-control" placeholder="<?= __('stockin_note_placeholder') ?>">
        </div>
      </div>

      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="bracket-label mb-0"><?= __('common_line_items') ?></div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><?= __('common_add_product') ?></button>
        </div>
        <table class="table" id="lineTable">
          <thead class="table-light"><tr><th><?= __('common_product') ?></th><th style="width:100px;"><?= __('common_qty') ?></th><th style="width:130px;"><?= __('stockin_unit_cost') ?></th><th style="width:40px;"></th></tr></thead>
          <tbody id="lineBody"></tbody>
        </table>
        <button class="btn btn-primary w-100 mt-2"><i class="bi bi-download"></i> <?= __('stockin_submit_button') ?></button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('stockin_recent_title') ?></div>
      <?php if (!$recent): ?><p class="text-secondary small text-center py-3"><i class="bi bi-inbox fs-4 d-block mb-2"></i><?= __('common_no_transactions') ?></p><?php endif; ?>
      <?php foreach ($recent as $t): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between small">
            <a href="<?= BASE_URL ?>/stock-transaction/view.php?ref=<?= urlencode($t['reference']) ?>" class="mono text-primary text-decoration-none"><?= htmlspecialchars($t['reference']) ?></a>
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
// Live-preview-only KHR<->USD conversion for the Unit Cost currency
// toggle - the server always resolves the real submitted value via
// resolvePriceField(), independently of this preview.
const EXCHANGE_RATE = <?= json_encode($khrRate) ?>;

function productOptions(selected) {
  let html = `<option value="">${T_CHOOSE_PRODUCT}</option>`;
  PRODUCTS.forEach(p => {
    const size = p.package_size ? ` — ${p.package_size}` : '';
    html += `<option value="${p.id}" data-cost="${p.cost_price}" ${String(p.id)===String(selected)?'selected':''}>${p.name}${size} (${T_NOW}: ${p.current_stock} ${T_PCS})</option>`;
  });
  return html;
}

function addRow(productId = '', qty = 1, cost = '') {
  const tr = document.createElement('tr');
  const toggleDisabled = EXCHANGE_RATE ? '' : 'disabled';
  tr.innerHTML = `
    <td><select name="product_id[]" class="form-select form-select-sm" onchange="fillCost(this)">${productOptions(productId)}</select></td>
    <td><input type="number" name="qty[]" class="form-control form-control-sm" value="${qty}" min="1"></td>
    <td>
      <div class="input-group input-group-sm price-input-group">
        <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)" ${toggleDisabled}>$</button>
        <input type="number" name="unit_cost[]" class="form-control price-amount-input" value="${cost}" step="0.01" oninput="updatePricePreview(this)">
        <input type="hidden" name="unit_cost_currency[]" class="price-currency-input" value="USD">
      </div>
      <div class="text-secondary small price-preview"></div>
    </td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
}
function fillCost(sel) {
  const opt = sel.selectedOptions[0];
  const row = sel.closest('tr');
  const input = row.querySelector('[name="unit_cost[]"]');
  const currencyInput = row.querySelector('[name="unit_cost_currency[]"]');
  const btn = row.querySelector('.currency-toggle-btn');
  input.value = opt.dataset.cost || 0;
  // Auto-fill always resets to USD (the product's stored cost is USD) -
  // otherwise a row left toggled to KHR from a previous product would
  // silently reinterpret the freshly-filled USD figure as Riel.
  currencyInput.value = 'USD';
  btn.textContent = '$';
  input.step = '0.01';
  updatePricePreview(input);
}

function toggleCurrency(btn) {
  if (btn.disabled) return;
  const group = btn.closest('.price-input-group');
  const input = group.querySelector('.price-amount-input');
  const currencyInput = group.querySelector('.price-currency-input');
  const current = parseFloat(input.value) || 0;
  if (currencyInput.value === 'USD') {
    currencyInput.value = 'KHR';
    btn.textContent = '៛';
    input.value = Math.round(current * EXCHANGE_RATE);
    input.step = '1';
  } else {
    currencyInput.value = 'USD';
    btn.textContent = '$';
    input.value = (current / EXCHANGE_RATE).toFixed(2);
    input.step = '0.01';
  }
  updatePricePreview(input);
}

function updatePricePreview(input) {
  const group = input.closest('.price-input-group');
  const currencyInput = group.querySelector('.price-currency-input');
  const preview = group.parentElement.querySelector('.price-preview');
  if (!preview) return;
  const amount = parseFloat(input.value) || 0;
  if (!EXCHANGE_RATE || amount <= 0) { preview.textContent = ''; return; }
  preview.textContent = currencyInput.value === 'USD'
    ? `≈ ៛${Math.round(amount * EXCHANGE_RATE).toLocaleString()}`
    : `≈ $${(amount / EXCHANGE_RATE).toFixed(2)}`;
}

addRow();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
