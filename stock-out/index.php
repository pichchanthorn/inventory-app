<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'stock-out';
$error = '';

// Post/Redirect/Get: a successful Stock Out redirects here with the toast
// message stashed in the session, so a page refresh re-fetches this GET
// instead of resubmitting the POST and creating a duplicate transaction.
$success = $_SESSION['stockout_flash'] ?? '';
unset($_SESSION['stockout_flash']);

$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        // Phase SIO-01: same per-form-render, single-use idempotency token
        // as POS (Phase I2-B1) and Stock In (Phase I3-B) - deliberately
        // separate from the CSRF token above, claimed exactly once by
        // whichever request reaches recordStockOut() first. A missing/
        // empty value falls back to a fresh random one rather than
        // blocking the submission outright - see pos/index.php for the
        // original reasoning. recordStockOut() has accepted this
        // parameter since Phase I2-B1 (added for POS's own cash-sale use
        // of this same function) - this page just needed to start passing
        // one, no changes to includes/stock.php required.
        $idempotencyToken = trim($_POST['idempotency_token'] ?? '');
        if ($idempotencyToken === '') {
            $idempotencyToken = bin2hex(random_bytes(32));
        }

        $date = $_POST['transaction_date'];
        $note = trim($_POST['note']);
        $productIds = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $prices = $_POST['unit_price'] ?? [];

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
            try {
                $reference = recordStockOut($pdo, $lines, $date, $note, $_SESSION['user_id'], 'out', null, $idempotencyToken);
                $_SESSION['stockout_flash'] = __('stockout_recorded_prefix') . " $reference " . __('stockout_recorded_suffix');
                header('Location: ' . BASE_URL . '/stock-out/index.php');
                exit;
            } catch (StockConflictException $e) {
                // The guarded UPDATE found insufficient stock at write time -
                // re-fetch current values to rebuild the existing friendly message.
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
            } catch (IdempotencyConflictException $e) {
                // This exact submission already succeeded once (a
                // double-click, a browser retry, two tabs) - nothing was
                // recorded a second time. Same handling as POS's/Stock
                // In's identical catch in pos/index.php/stock-in/index.php.
                $error = __('stockout_err_duplicate_submission');
            } catch (Throwable $e) {
                error_log('Stock Out failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

$recent = $pdo->query("SELECT t.*, COUNT(i.id) items, SUM(i.qty) total_qty, SUM(i.subtotal) total_value
                        FROM stock_transactions t
                        LEFT JOIN stock_transaction_items i ON i.transaction_id = t.id
                        WHERE t.type = 'out'
                        GROUP BY t.id ORDER BY t.id DESC LIMIT 5")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('nav_stock_out') ?></h4>
<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" id="stockOutForm">
      <?= csrf_field() ?>
      <input type="hidden" name="idempotency_token" value="<?= htmlspecialchars(bin2hex(random_bytes(32))) ?>">
      <div class="card p-3 mb-3">
        <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('common_transaction_date') ?></label>
            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('stockout_note_label') ?></label>
            <input type="text" name="note" class="form-control" placeholder="<?= __('stockout_note_placeholder') ?>">
          </div>
        </div>
      </div>

      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="bracket-label mb-0"><?= __('common_line_items') ?></div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><?= __('common_add_product') ?></button>
        </div>
        <table class="table table-cards-mobile stockout-line-table" id="lineTable">
          <thead class="table-light"><tr><th><?= __('common_product') ?></th><th style="width:100px;"><?= __('common_qty') ?></th><th style="width:130px;"><?= __('stockout_unit_price') ?></th><th style="width:40px;"></th></tr></thead>
          <tbody id="lineBody"></tbody>
        </table>
        <button id="stockOutSubmitButton" class="btn text-white w-100 mt-2" style="background:var(--danger);"><i class="bi bi-upload"></i> <?= __('stockout_submit_button') ?></button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <div class="bracket-label mb-3" style="color:var(--danger);"><?= __('stockout_recent_title') ?></div>
      <?php if (!$recent): ?><p class="text-secondary small text-center py-3"><i class="bi bi-inbox fs-4 d-block mb-2"></i><?= __('common_no_transactions') ?></p><?php endif; ?>
      <?php foreach ($recent as $t): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between small">
            <a href="<?= BASE_URL ?>/stock-transaction/view.php?ref=<?= urlencode($t['reference']) ?>" class="mono text-decoration-none" style="color:var(--danger);"><?= htmlspecialchars($t['reference']) ?></a>
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
        <h5 class="modal-title"><?= __('stockout_scan_modal_title') ?></h5>
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
const T_UNIT_PRICE = <?= json_encode(__('stockout_unit_price')) ?>;
const T_SCAN_BARCODE = <?= json_encode(__('stockout_scan_barcode')) ?>;
const T_SCAN_NO_MATCH = <?= json_encode(__('stockout_scan_no_match')) ?>;
const T_SCAN_CAMERA_ERROR = <?= json_encode(__('stockout_scan_camera_error')) ?>;
const T_SCAN_LIB_ERROR = <?= json_encode(__('stockout_scan_lib_error')) ?>;

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
    // label, and fires onSelect (so fillPrice still runs).
    select(id) { select(id); }
  };
}

function addRow(productId = '', qty = 1, price = '') {
  const tr = document.createElement('tr');
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
    <td class="row-price" data-label="${T_UNIT_PRICE}"><input type="number" name="unit_price[]" class="form-control form-control-sm" value="${price}" step="0.01"></td>
    <td class="row-remove"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
  const controls = wireProductSelect(tr.querySelector('.product-select'), product => fillPrice(tr, product));
  controls.setInitial(productId);
  tr._productSelectControls = controls;
}
function fillPrice(row, product) {
  // Stock Out covers non-sale movements (damaged, lost, internal use,
  // transfer) - it should default to what the product cost us, not what
  // POS would charge a customer. Same field stock-in/index.php's own
  // fillPrice() already uses.
  row.querySelector('[name="unit_price[]"]').value = product.cost_price || 0;
}
addRow();

// ---- Low-friction safeguard against a fat-fingered quantity (UI/UX
// Batch 2 - mirrors pos/index.php's identical Batch 1 safeguard) ----
// Not a blanket confirmation on every Stock Out (that would add friction
// to routine, frequent entries) - only fires when a single line's
// quantity is at least half of that product's current stock on hand, a
// self-scaling per-product signal that catches the "typed an extra
// digit" case (e.g. 100 instead of 10 against a stock of 52) without
// ever bothering a normal partial removal (2 of 40 in stock). Products
// already out of stock are skipped here - the server's own stock guard
// handles that case.
//
// preventDefault()+stopPropagation() on cancel keeps the submit button
// from being left stuck disabled - footer.php's global submit handler
// now also checks e.defaultPrevented (Batch 2's other fix) so
// stopPropagation() here is belt-and-suspenders, not strictly required,
// but kept for consistency with the exact proven POS pattern.
document.getElementById('stockOutForm').addEventListener('submit', function (e) {
  let hasLargeQty = false;
  document.querySelectorAll('#lineBody tr').forEach(function (tr) {
    const productId = tr.querySelector('[name="product_id[]"]')?.value;
    const qty = parseFloat(tr.querySelector('[name="qty[]"]')?.value) || 0;
    const product = productId ? findProduct(productId) : null;
    if (product && product.current_stock > 0 && qty >= product.current_stock * 0.5) {
      hasLargeQty = true;
    }
  });
  if (hasLargeQty && !confirm(<?= json_encode(__('stockout_confirm_large_qty')) ?>)) {
    e.preventDefault();
    e.stopPropagation();
  }
});

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
