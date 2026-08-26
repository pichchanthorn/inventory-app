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
        <table class="table table-cards-mobile" id="lineTable">
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

<div class="modal fade" id="scanModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= __('stockin_scan_modal_title') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
      </div>
      <div class="modal-body">
        <div id="scanReader"></div>
        <div id="scanStatus" class="text-secondary small mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
      </div>
    </div>
  </div>
</div>

<!-- Loaded only on this page, not globally in header.php/footer.php,
     since barcode scanning is the only feature that needs it so far. -->
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const PRODUCTS = <?= json_encode($products) ?>;
const T_CHOOSE_PRODUCT = <?= json_encode(__('common_choose_product_option')) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_QTY = <?= json_encode(__('common_qty')) ?>;
const T_UNIT_COST = <?= json_encode(__('stockin_unit_cost')) ?>;
const T_SCAN_BARCODE = <?= json_encode(__('stockin_scan_barcode')) ?>;
const T_SCAN_NO_MATCH = <?= json_encode(__('stockin_scan_no_match')) ?>;
const T_SCAN_CAMERA_ERROR = <?= json_encode(__('stockin_scan_camera_error')) ?>;
const T_SCAN_LIB_ERROR = <?= json_encode(__('stockin_scan_lib_error')) ?>;
// Live-preview-only KHR<->USD conversion for the Unit Cost currency
// toggle - the server always resolves the real submitted value via
// resolvePriceField(), independently of this preview.
const EXCHANGE_RATE = <?= json_encode($khrRate) ?>;

function productLabel(p) {
  const size = p.package_size ? ` — ${p.package_size}` : '';
  return `${p.name}${size} (${T_NOW}: ${p.current_stock} ${T_PCS})`;
}
function findProduct(id) {
  return PRODUCTS.find(p => String(p.id) === String(id));
}

// Renders the filtered option list into `menu` and wires each option's
// click. mousedown preventDefault keeps the text input focused (so no
// blur/revert races the click) while the click itself does the select.
function renderSearchMenu(menu, filterText, onSelect) {
  const q = filterText.trim().toLowerCase();
  const matches = q ? PRODUCTS.filter(p => p.name.toLowerCase().includes(q)) : PRODUCTS;
  menu.innerHTML = '';
  if (!matches.length) {
    const empty = document.createElement('div');
    empty.className = 'product-search-option disabled';
    empty.textContent = T_NO_RESULTS;
    menu.appendChild(empty);
    return;
  }
  matches.forEach((p, i) => {
    const opt = document.createElement('div');
    opt.className = 'product-search-option' + (i === 0 ? ' active' : '');
    opt.dataset.id = p.id;

    // Two-line option: name on top, SKU (as a slug-pill, matching the
    // product list's card pattern) + package size + current stock as a
    // smaller muted line below - built via createElement/textContent
    // (never innerHTML) so a product name/SKU can never inject markup.
    const nameEl = document.createElement('div');
    nameEl.className = 'product-search-option-name';
    nameEl.textContent = p.name;
    opt.appendChild(nameEl);

    const metaEl = document.createElement('div');
    metaEl.className = 'product-search-option-meta';
    const skuEl = document.createElement('span');
    skuEl.className = 'slug-pill';
    skuEl.textContent = p.sku;
    metaEl.appendChild(skuEl);
    if (p.package_size) {
      const sizeEl = document.createElement('span');
      sizeEl.textContent = p.package_size;
      metaEl.appendChild(sizeEl);
    }
    const stockEl = document.createElement('span');
    stockEl.textContent = `${T_NOW}: ${p.current_stock} ${T_PCS}`;
    metaEl.appendChild(stockEl);
    opt.appendChild(metaEl);

    opt.addEventListener('mousedown', e => e.preventDefault());
    opt.addEventListener('click', () => onSelect(String(p.id)));
    menu.appendChild(opt);
  });
}

// Wires a .product-select container (hidden id input + visible search
// input + menu) into a searchable dropdown. onSelect(product) fires only
// on an actual selection, matching the old <select onchange> behavior -
// never on typing, so a typed-but-unselected string can never reach the
// hidden input that actually gets submitted.
function wireProductSelect(container, onSelect) {
  const hidden = container.querySelector('input[type="hidden"]');
  const input = container.querySelector('.product-search-input');
  const menu = container.querySelector('.product-search-menu');

  function close() { menu.classList.remove('open'); }
  function open(filterText) { renderSearchMenu(menu, filterText, select); menu.classList.add('open'); }
  function select(id) {
    const p = findProduct(id);
    if (!p) return;
    hidden.value = String(p.id);
    input.value = productLabel(p);
    close();
    onSelect(p);
  }
  // On blur/Escape, snap the visible text back to whatever is actually
  // in the hidden field - typing never touches the hidden field itself,
  // this just stops the input showing stale/typed text after the fact.
  function revert() {
    const p = findProduct(hidden.value);
    input.value = p ? productLabel(p) : '';
  }

  input.addEventListener('focus', () => open(''));
  input.addEventListener('input', () => open(input.value));
  input.addEventListener('blur', () => { close(); revert(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      if (!menu.classList.contains('open')) { open(input.value); return; }
      const items = Array.from(menu.querySelectorAll('.product-search-option:not(.disabled)'));
      if (!items.length) return;
      let idx = items.findIndex(el => el.classList.contains('active'));
      if (idx >= 0) items[idx].classList.remove('active');
      idx = e.key === 'ArrowDown' ? (idx + 1) % items.length : (idx <= 0 ? items.length - 1 : idx - 1);
      items[idx].classList.add('active');
      items[idx].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      if (menu.classList.contains('open')) {
        e.preventDefault();
        const active = menu.querySelector('.product-search-option.active');
        if (active) select(active.dataset.id);
      }
    } else if (e.key === 'Escape') {
      close();
      revert();
    }
  });

  return {
    setInitial(id) {
      if (!id) return;
      const p = findProduct(id);
      if (!p) return;
      hidden.value = String(p.id);
      input.value = productLabel(p);
    },
    // Used by barcode scanning to select a product the same way a manual
    // click on a search result would - fills the hidden id, the visible
    // label, and fires onSelect (so fillCost still runs).
    select(id) { select(id); }
  };
}

function addRow(productId = '', qty = 1, cost = '') {
  const tr = document.createElement('tr');
  const toggleDisabled = EXCHANGE_RATE ? '' : 'disabled';
  tr.innerHTML = `
    <td class="row-title">
      <div class="product-select">
        <div class="product-select-row">
          <input type="hidden" name="product_id[]">
          <input type="text" class="form-control form-control-sm product-search-input" placeholder="${T_CHOOSE_PRODUCT}" autocomplete="off">
          <button type="button" class="btn btn-outline-secondary btn-sm scan-barcode-btn" data-bs-toggle="modal" data-bs-target="#scanModal" onclick="openScanner(this)" title="${T_SCAN_BARCODE}" aria-label="${T_SCAN_BARCODE}"><i class="bi bi-upc-scan"></i></button>
        </div>
        <div class="product-search-menu"></div>
      </div>
    </td>
    <td class="row-qty" data-label="${T_QTY}"><input type="number" name="qty[]" class="form-control form-control-sm" value="${qty}" min="1"></td>
    <td class="row-price" data-label="${T_UNIT_COST}">
      <div class="input-group input-group-sm price-input-group">
        <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)" ${toggleDisabled}>$</button>
        <input type="number" name="unit_cost[]" class="form-control price-amount-input" value="${cost}" step="0.01" oninput="updatePricePreview(this)">
        <input type="hidden" name="unit_cost_currency[]" class="price-currency-input" value="USD">
      </div>
      <div class="text-secondary small price-preview"></div>
    </td>
    <td class="row-remove"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
  const controls = wireProductSelect(tr.querySelector('.product-select'), product => fillCost(tr, product));
  controls.setInitial(productId);
  tr._productSelectControls = controls;
}
function fillCost(row, product) {
  const input = row.querySelector('[name="unit_cost[]"]');
  const currencyInput = row.querySelector('[name="unit_cost_currency[]"]');
  const btn = row.querySelector('.currency-toggle-btn');
  input.value = product.cost_price || 0;
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

// ---- Barcode scanning (camera-based, Html5Qrcode) ----
// The scan button on a row just remembers which <tr> to fill and opens
// the shared #scanModal; the actual camera lifecycle is driven off the
// modal's own show/hide events so Cancel, the X button, and a backdrop
// click all stop the camera the same way - no separate cleanup path to
// keep in sync.
let scanTargetRow = null;
let scanInstance = null;
let scanHandled = false;

function openScanner(btn) {
  scanTargetRow = btn.closest('tr');
}

const scanModalEl = document.getElementById('scanModal');
const scanStatusEl = document.getElementById('scanStatus');

scanModalEl.addEventListener('shown.bs.modal', () => {
  scanHandled = false;
  scanStatusEl.textContent = '';
  if (typeof Html5Qrcode === 'undefined') {
    scanStatusEl.textContent = T_SCAN_LIB_ERROR;
    return;
  }
  scanInstance = new Html5Qrcode('scanReader');
  scanInstance.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 250, height: 150 } },
    onScanDecoded,
    () => {} // per-frame "no barcode in this frame" - expected continuously while aiming, not an error
  ).catch(() => {
    // Permission denied, no camera, or insecure context (camera requires
    // HTTPS or localhost) - the manual product dropdown on the row is
    // completely unaffected by this failing.
    scanStatusEl.textContent = T_SCAN_CAMERA_ERROR;
  });
});

scanModalEl.addEventListener('hidden.bs.modal', () => {
  if (scanInstance) {
    scanInstance.stop().then(() => scanInstance.clear()).catch(() => {});
    scanInstance = null;
  }
  scanTargetRow = null;
});

function onScanDecoded(decodedText) {
  if (scanHandled) return; // camera keeps decoding frames during the async stop() below
  const code = decodedText.trim();
  const product = PRODUCTS.find(p => (p.barcode && p.barcode === code) || (p.sku && p.sku === code));
  if (!product) {
    scanStatusEl.textContent = `${T_SCAN_NO_MATCH} ${code}`;
    return; // keep the scanner running so the user can try again
  }
  scanHandled = true;
  if (scanTargetRow && scanTargetRow._productSelectControls) {
    scanTargetRow._productSelectControls.select(String(product.id));
  }
  bootstrap.Modal.getInstance(scanModalEl)?.hide();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
