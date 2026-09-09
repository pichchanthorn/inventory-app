<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'stock-adjustment';
$error = '';

// Post/Redirect/Get: a successful Adjustment redirects here with the toast
// message stashed in the session, so a page refresh re-fetches this GET
// instead of resubmitting the POST and applying a duplicate adjustment.
$success = $_SESSION['stockadj_flash'] ?? '';
unset($_SESSION['stockadj_flash']);

$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

// Phase K4-6-2: server-rendered batch data for every product, embedded
// into the page the same no-AJAX way $products already is - the batch
// dropdown below is populated entirely from this, never fetched
// separately. Ordered the same way insertStockOutLineWithBatchConsumption()
// (includes/stock.php) already orders FEFO candidates - purely a display
// convenience here (earliest-expiring batch listed first), not a change
// to FEFO behavior itself, which this page never touches.
$allBatches = $pdo->query('SELECT * FROM product_batches ORDER BY product_id, (expiry_date IS NULL) ASC, expiry_date ASC, id ASC')->fetchAll();
$batchesByProduct = [];
foreach ($allBatches as $b) {
    $batchesByProduct[(int) $b['product_id']][] = $b;
}

// Human-readable batch identity for the tracked-adjustment success flash -
// same vocabulary (and the same "(no batch #)"/"(no expiry)"/"(Opening
// Balance)" placeholders) the batch dropdown's own JS labels use, so the
// flash message and the dropdown never describe the same batch two
// different ways.
function stockadjBatchLabel(array $batch): string
{
    $num = $batch['batch_number'] !== null ? $batch['batch_number'] : __('stockadj_batch_no_number');
    $exp = $batch['expiry_date'] !== null ? $batch['expiry_date'] : __('stockadj_batch_no_expiry');
    $label = $num . ', ' . $exp;
    if ($batch['origin'] === 'opening_balance') {
        $label .= ' ' . __('stockadj_batch_opening_balance');
    }
    return $label;
}

// True non-negative integer string (no sign, no decimal point, no
// leading/trailing junk) - deliberately NOT (int) $raw, which would
// silently truncate "5.7" to 5 instead of rejecting it. Used for both the
// batch target quantity and expected_qty.
function isNonNegativeIntegerString(string $raw): bool
{
    return $raw !== '' && ctype_digit($raw);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $date = $_POST['transaction_date'] ?? '';

        if (!$productId) {
            $error = __('stockadj_err_select_product');
        } elseif ($reason === '') {
            $error = __('stockadj_err_reason_required');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            // Phase K4-6-2: which flow applies is decided from the
            // product's OWN track_batches column, read fresh here - never
            // from a client-supplied flag - the same "server re-derives
            // which branch applies" principle batchAdjustStock()/
            // adjustStock() themselves already use internally under their
            // own FOR UPDATE lock. Whatever this reads, the field(s) for
            // the OTHER flow are simply ignored if a bypassed request
            // includes them.
            if ($product !== false && (int) $product['track_batches'] === 1) {
                // ---- Tracked flow: batch-specific adjustment (K4-6-2) ----
                $batchIdRaw = trim($_POST['batch_id'] ?? '');
                $newQtyRaw = trim($_POST['batch_new_qty'] ?? '');
                $expectedQtyRaw = trim($_POST['batch_expected_qty'] ?? '');
                // Never silently treat a missing/non-numeric selection as
                // batch id 0 - ctype_digit() also rejects "0" itself only
                // in the sense that batch ids are always positive
                // AUTO_INCREMENT values, so a raw value of "0" cannot be a
                // real batch either.
                $batchId = ctype_digit($batchIdRaw) ? (int) $batchIdRaw : 0;

                if ($batchId <= 0) {
                    $error = __('stockadj_err_batch_not_selected');
                } elseif (!isNonNegativeIntegerString($newQtyRaw)) {
                    $error = __('stockadj_err_batch_invalid_integer');
                } elseif (!isNonNegativeIntegerString($expectedQtyRaw)) {
                    // Hidden field, populated only by this page's own JS
                    // from the server-rendered batch snapshot below -
                    // reaching here with a malformed value means a
                    // bypassed/hand-crafted request, not normal use, but
                    // it is still validated rather than trusted.
                    $error = __('stockadj_err_batch_invalid_integer');
                } else {
                    $newQty = (int) $newQtyRaw;
                    $expectedQty = (int) $expectedQtyRaw;

                    try {
                        $reference = batchAdjustStock($pdo, $productId, $batchId, $newQty, $expectedQty, $reason, $date, $_SESSION['user_id']);

                        $batchStmt = $pdo->prepare('SELECT * FROM product_batches WHERE id = ?');
                        $batchStmt->execute([$batchId]);
                        $adjustedBatch = $batchStmt->fetch();
                        $batchLabel = $adjustedBatch !== false ? stockadjBatchLabel($adjustedBatch) : '';

                        $_SESSION['stockadj_flash'] = __('stockadj_applied_prefix') . " $reference — {$product['name']} ($batchLabel): $expectedQty → $newQty.";
                        header('Location: ' . BASE_URL . '/stock-adjustment/index.php');
                        exit;
                    } catch (UntrackedProductBatchAdjustmentNotSupportedException $e) {
                        // Structurally unreachable through this page (we
                        // only take this branch when track_batches=1 was
                        // just read), kept as defense-in-depth against a
                        // race where tracking was disabled between our
                        // read above and batchAdjustStock()'s own lock -
                        // same convention as the untracked branch below
                        // keeping its own now-structurally-defensive
                        // TrackedStockAdjustmentNotSupportedException catch.
                        $error = __('stockadj_err_batch_untracked');
                    } catch (ProductBatchNotFoundException $e) {
                        $error = __('stockadj_err_batch_not_found');
                    } catch (BatchAdjustmentConflictException $e) {
                        $error = __('stockadj_err_batch_conflict');
                    } catch (InvalidBatchAdjustmentQuantityException $e) {
                        $error = __('stockadj_err_batch_negative_qty');
                    } catch (Throwable $e) {
                        error_log('Batch Stock Adjustment failed: ' . $e->getMessage());
                        $error = __('common_err_transaction_failed');
                    }
                }
            } else {
                // ---- Untracked flow: legacy product-level adjustment,
                // byte-for-byte the same behavior as before K4-6-2 ----
                $newQty = (float) ($_POST['new_qty'] ?? -1);

                if ($newQty < 0) {
                    $error = __('stockadj_err_negative_qty');
                } else {
                    try {
                        $reference = adjustStock($pdo, $productId, $newQty, $product['current_stock'], $reason, $date, $_SESSION['user_id']);
                        $_SESSION['stockadj_flash'] = __('stockadj_applied_prefix') . " $reference — {$product['name']}: {$product['current_stock']} → $newQty.";
                        header('Location: ' . BASE_URL . '/stock-adjustment/index.php');
                        exit;
                    } catch (StockConflictException $e) {
                        // Optimistic-lock guard found current_stock had already changed
                        // since it was read - don't overwrite that concurrent change.
                        $error = __('stockadj_err_conflict');
                    } catch (TrackedStockAdjustmentNotSupportedException $e) {
                        // Phase K4-5: adjustStock() itself rejects a track_batches=1
                        // product before any mutation - see includes/stock.php for
                        // why. Structurally unreachable through this branch under
                        // normal operation now that K4-6-2 routes track_batches=1
                        // products to the tracked flow above instead - kept as
                        // defense-in-depth against the same kind of race the
                        // tracked branch's own defensive catch above guards
                        // against (tracking enabled between our read and
                        // adjustStock()'s own lock).
                        $error = __('stockadj_err_tracked_not_supported');
                    } catch (Throwable $e) {
                        error_log('Stock Adjustment failed: ' . $e->getMessage());
                        $error = __('common_err_transaction_failed');
                    }
                }
            }
        }
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

<h4 class="mb-4"><?= __('nav_stock_adjustments') ?></h4>
<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post">
      <?= csrf_field() ?>
      <div class="card p-3 mb-3">
        <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('common_transaction_date') ?></label>
            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('stockadj_reason_label') ?></label>
            <input type="text" name="reason" class="form-control" placeholder="<?= __('stockadj_reason_placeholder') ?>" required>
          </div>
        </div>
      </div>

      <div class="card p-3">
        <div class="bracket-label mb-3"><?= __('stockadj_section_title') ?></div>
        <div class="mb-3">
          <label class="form-label"><?= __('stockadj_product_label') ?></label>
          <div class="product-select" id="adjProductSelect">
            <input type="hidden" name="product_id">
            <input type="text" class="form-control product-search-input" placeholder="<?= __('stockadj_select_product') ?>" autocomplete="off">
            <div class="product-search-menu"></div>
          </div>
        </div>

        <!-- Untracked product: legacy product-level adjustment, unchanged
             markup/behavior from before K4-6-2. Hidden instead of shown
             once a tracked product is selected. -->
        <div id="untrackedAdjustmentSection">
          <div class="mb-3">
            <label class="form-label"><?= __('stockadj_new_qty_label') ?></label>
            <input type="number" name="new_qty" id="adjQty" class="form-control" value="0" min="0" oninput="updatePreview()">
          </div>
          <div id="adjPreview" class="small text-secondary mb-3"><?= __('stockadj_preview_hint') ?></div>
        </div>

        <!-- Tracked product (K4-6-2): batch-specific adjustment. Batch
             identity (number/expiry/origin) is display-only here - never
             editable from this screen. Selection posts product_batches.id
             (the <select>'s own value), never batch_number/expiry_date. -->
        <div id="trackedAdjustmentSection" style="display:none;">
          <div class="mb-3">
            <label class="form-label"><?= __('stockadj_batch_label') ?></label>
            <select class="form-select" id="adjBatchSelect" name="batch_id" onchange="onBatchChange()"></select>
            <div id="adjBatchEmptyState" class="small text-secondary mt-2" style="display:none;"><?= __('stockadj_batch_none_available') ?></div>
          </div>
          <input type="hidden" id="batchExpectedQtyHidden" name="batch_expected_qty" value="">
          <div id="adjBatchQtyGroup" class="mb-3" style="display:none;">
            <div class="small text-secondary mb-1"><?= __('stockadj_batch_current_qty_label') ?>: <span id="adjBatchCurrentQty">—</span></div>
            <label class="form-label"><?= __('stockadj_batch_target_qty_label') ?></label>
            <input type="number" name="batch_new_qty" id="adjBatchNewQty" class="form-control" value="0" min="0" oninput="updateBatchPreview()">
          </div>
          <div id="adjBatchPreview" class="small text-secondary mb-3"><?= __('stockadj_preview_hint') ?></div>
        </div>

        <div class="alert alert-warning small"><?= __('stockadj_warning') ?></div>
        <button class="btn btn-primary w-100"><i class="bi bi-arrow-repeat"></i> <?= __('stockadj_submit_button') ?></button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card p-3">
      <div class="bracket-label mb-3"><?= __('stockadj_recent_title') ?></div>
      <?php if (!$recent): ?><p class="text-secondary small text-center py-3"><i class="bi bi-inbox fs-4 d-block mb-2"></i><?= __('stockadj_empty') ?></p><?php endif; ?>
      <?php foreach ($recent as $t): ?>
        <div class="border-bottom pb-2 mb-2">
          <div class="d-flex justify-content-between small">
            <a href="<?= BASE_URL ?>/stock-transaction/view.php?ref=<?= urlencode($t['reference']) ?>" class="mono text-primary text-decoration-none"><?= htmlspecialchars($t['reference']) ?></a>
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
const PRODUCTS = <?= json_encode($products) ?>;
const BATCHES_BY_PRODUCT = <?= json_encode($batchesByProduct) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_SELECT_PREVIEW = <?= json_encode(__('stockadj_preview_hint')) ?>;
const T_UNITS = <?= json_encode(__('common_units_word')) ?>;
const T_SELECT_BATCH = <?= json_encode(__('stockadj_select_batch')) ?>;
const T_NO_BATCH_NUMBER = <?= json_encode(__('stockadj_batch_no_number')) ?>;
const T_NO_EXPIRY = <?= json_encode(__('stockadj_batch_no_expiry')) ?>;
const T_OPENING_BALANCE = <?= json_encode(__('stockadj_batch_opening_balance')) ?>;

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
    }
  };
}

let selectedProduct = null;
wireProductSelect(document.getElementById('adjProductSelect'), product => {
  selectedProduct = product;
  updatePreview();
  renderBatchSection(product);
});

function updatePreview() {
  const preview = document.getElementById('adjPreview');
  if (!selectedProduct) { preview.textContent = T_SELECT_PREVIEW; return; }
  if (selectedProduct.track_batches) { return; } // tracked products use adjBatchPreview instead - see renderBatchSection()
  const current = selectedProduct.current_stock;
  const next = Number(document.getElementById('adjQty').value) || 0;
  const diff = next - current;
  preview.innerHTML = `${current} → <strong>${next}</strong> (${diff >= 0 ? '+' : ''}${diff} ${T_UNITS})`;
}

// ---- K4-6-2: batch-specific adjustment section ----

function batchLabel(b) {
  const num = b.batch_number ? b.batch_number : T_NO_BATCH_NUMBER;
  const exp = b.expiry_date ? b.expiry_date : T_NO_EXPIRY;
  const originSuffix = b.origin === 'opening_balance' ? ` (${T_OPENING_BALANCE})` : '';
  return `${num} · ${exp} · ${b.qty_on_hand} ${T_PCS}${originSuffix}`;
}

function renderBatchSection(product) {
  const untrackedSection = document.getElementById('untrackedAdjustmentSection');
  const trackedSection = document.getElementById('trackedAdjustmentSection');

  if (!product || !product.track_batches) {
    untrackedSection.style.display = '';
    trackedSection.style.display = 'none';
    return;
  }
  untrackedSection.style.display = 'none';
  trackedSection.style.display = '';

  const batches = BATCHES_BY_PRODUCT[String(product.id)] || [];
  const select = document.getElementById('adjBatchSelect');
  const emptyState = document.getElementById('adjBatchEmptyState');
  const qtyGroup = document.getElementById('adjBatchQtyGroup');

  select.innerHTML = '';
  if (!batches.length) {
    select.style.display = 'none';
    emptyState.style.display = '';
    qtyGroup.style.display = 'none';
    document.getElementById('batchExpectedQtyHidden').value = '';
    updateBatchPreview();
    return;
  }
  select.style.display = '';
  emptyState.style.display = 'none';

  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = T_SELECT_BATCH;
  select.appendChild(placeholder);

  batches.forEach(b => {
    const opt = document.createElement('option');
    opt.value = String(b.id);
    opt.textContent = batchLabel(b);
    opt.dataset.qty = String(b.qty_on_hand);
    select.appendChild(opt);
  });
  select.value = '';
  qtyGroup.style.display = 'none';
  document.getElementById('batchExpectedQtyHidden').value = '';
  updateBatchPreview();
}

function onBatchChange() {
  const select = document.getElementById('adjBatchSelect');
  const opt = select.options[select.selectedIndex];
  const expectedHidden = document.getElementById('batchExpectedQtyHidden');
  const currentQtyEl = document.getElementById('adjBatchCurrentQty');
  const qtyGroup = document.getElementById('adjBatchQtyGroup');

  if (!opt || opt.value === '') {
    expectedHidden.value = '';
    currentQtyEl.textContent = '—';
    qtyGroup.style.display = 'none';
    updateBatchPreview();
    return;
  }

  // The batch's quantity as embedded in this page's own server-rendered
  // BATCHES_BY_PRODUCT (page-load time), never re-read from anywhere
  // else - this is exactly what gets submitted as expected_qty, so it
  // must be captured here (on selection) and left untouched until the
  // user picks a different batch.
  expectedHidden.value = opt.dataset.qty;
  currentQtyEl.textContent = `${opt.dataset.qty} ${T_PCS}`;
  qtyGroup.style.display = '';
  document.getElementById('adjBatchNewQty').value = opt.dataset.qty;
  updateBatchPreview();
}

function updateBatchPreview() {
  const preview = document.getElementById('adjBatchPreview');
  const expectedHidden = document.getElementById('batchExpectedQtyHidden');
  if (expectedHidden.value === '') { preview.textContent = T_SELECT_PREVIEW; return; }
  const current = Number(expectedHidden.value);
  const next = Number(document.getElementById('adjBatchNewQty').value) || 0;
  const diff = next - current;
  preview.innerHTML = `${current} → <strong>${next}</strong> (${diff >= 0 ? '+' : ''}${diff} ${T_UNITS})`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
