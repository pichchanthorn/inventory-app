<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/purchase_order.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/currency.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'purchase-order';
$error = '';

if (!canWrite()) {
    header('Location: ' . BASE_URL . '/purchase-order/index.php');
    exit;
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
$products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();

$khrRateRow = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
$khrRate = $khrRateRow !== false ? (float) $khrRateRow : null;

// Sticky values on a validation failure - re-render the form with
// whatever the user already typed rather than losing it, same
// Post/Redirect/Get-minus-the-redirect pattern every other create form
// in this app uses when validation fails.
$formSupplierId = '';
$formOrderDate = date('Y-m-d');
$formExpectedDate = '';
$formNote = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $idempotencyToken = trim($_POST['idempotency_token'] ?? '');
    if ($idempotencyToken === '') {
        $idempotencyToken = bin2hex(random_bytes(32));
    }

    $formSupplierId = trim($_POST['supplier_id'] ?? '');
    $formOrderDate = trim($_POST['order_date'] ?? '');
    $formExpectedDate = trim($_POST['expected_date'] ?? '');
    $formNote = trim($_POST['note'] ?? '');
    $productIds = $_POST['product_id'] ?? [];
    $qtys = $_POST['ordered_qty'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];
    $costCurrencies = $_POST['unit_cost_currency'] ?? [];

    try {
        if ($formSupplierId === '' || !ctype_digit($formSupplierId)) {
            throw new InvalidArgumentException(__('po_err_supplier_required'));
        }
        $orderDateObj = DateTime::createFromFormat('Y-m-d', $formOrderDate);
        if ($formOrderDate === '' || !$orderDateObj || $orderDateObj->format('Y-m-d') !== $formOrderDate) {
            throw new InvalidArgumentException(__('po_err_order_date_required'));
        }
        $expectedDate = null;
        if ($formExpectedDate !== '') {
            $expectedDateObj = DateTime::createFromFormat('Y-m-d', $formExpectedDate);
            if (!$expectedDateObj || $expectedDateObj->format('Y-m-d') !== $formExpectedDate) {
                throw new InvalidArgumentException(__('po_err_invalid_date'));
            }
            $expectedDate = $formExpectedDate;
        }

        $items = [];
        foreach ($productIds as $i => $pid) {
            $pid = trim((string) $pid);
            $qtyRaw = trim((string) ($qtys[$i] ?? ''));
            if ($pid === '' && $qtyRaw === '') {
                continue;
            }
            if (!isNonNegativeIntegerString($qtyRaw) || (int) $qtyRaw <= 0) {
                throw new InvalidArgumentException(__('po_err_invalid_qty'));
            }
            $unitCost = resolvePriceField(
                ['unit_cost' => $costs[$i] ?? 0, 'unit_cost_currency' => $costCurrencies[$i] ?? 'USD'],
                'unit_cost', $khrRate
            );
            $items[] = ['product_id' => (int) $pid, 'ordered_qty' => (int) $qtyRaw, 'unit_cost' => $unitCost];
        }

        if (!$items) {
            throw new InvalidArgumentException(__('po_err_add_product'));
        }

        $result = createPurchaseOrder(
            $pdo,
            (int) $formSupplierId,
            $formOrderDate,
            $expectedDate,
            $formNote !== '' ? $formNote : null,
            $items,
            (int) $_SESSION['user_id'],
            $idempotencyToken
        );

        $_SESSION['po_flash'] = __('po_created_toast') . ' (' . $result['reference'] . ')';
        header('Location: ' . BASE_URL . '/purchase-order/view.php?id=' . $result['id']);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PriceConversionException $e) {
        $error = $e->getMessage();
    } catch (IdempotencyConflictException $e) {
        $error = __('po_err_duplicate_submission');
    } catch (Throwable $e) {
        error_log('Purchase Order create failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('po_create_title') ?></h4>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<form method="post" id="poForm">
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_token" value="<?= htmlspecialchars(bin2hex(random_bytes(32))) ?>">
  <div class="card p-3 mb-3">
    <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= __('common_supplier') ?></label>
        <select name="supplier_id" class="form-select" required>
          <option value=""><?= __('po_select_supplier') ?></option>
          <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $formSupplierId === (string) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= __('po_order_date_label') ?></label>
        <input type="date" name="order_date" class="form-control" value="<?= htmlspecialchars($formOrderDate) ?>" required>
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= __('po_expected_date_label') ?></label>
        <input type="date" name="expected_date" class="form-control" value="<?= htmlspecialchars($formExpectedDate) ?>">
      </div>
    </div>
    <div class="mb-0">
      <label class="form-label"><?= __('common_note') ?></label>
      <input type="text" name="note" class="form-control" value="<?= htmlspecialchars($formNote) ?>">
    </div>
  </div>

  <div class="card p-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div class="bracket-label mb-0"><?= __('common_line_items') ?></div>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><?= __('common_add_product') ?></button>
    </div>
    <table class="table table-cards-mobile stockin-line-table" id="lineTable">
      <thead class="table-light"><tr><th><?= __('common_product') ?></th><th style="width:100px;"><?= __('common_qty') ?></th><th style="width:130px;"><?= __('po_unit_cost') ?></th><th style="width:110px;" class="text-end"><?= __('po_subtotal') ?></th><th style="width:40px;"></th></tr></thead>
      <tbody id="lineBody"></tbody>
      <tfoot>
        <tr><td colspan="3" class="text-end fw-bold"><?= __('po_col_total') ?></td><td class="text-end fw-bold mono" id="lineTotal">$0.00</td><td></td></tr>
      </tfoot>
    </table>
    <button id="poSubmitButton" class="btn btn-primary w-100 mt-2"><i class="bi bi-cart-check"></i> <?= __('po_create_button') ?></button>
  </div>
</form>

<script>
const PRODUCTS = <?= json_encode($products) ?>;
const T_CHOOSE_PRODUCT = <?= json_encode(__('common_choose_product_option')) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_QTY = <?= json_encode(__('common_qty')) ?>;
const T_UNIT_COST = <?= json_encode(__('po_unit_cost')) ?>;
const T_SUBTOTAL = <?= json_encode(__('po_subtotal')) ?>;
const EXCHANGE_RATE = <?= json_encode($khrRate) ?>;

function productLabel(p) {
  const size = p.package_size ? ` — ${p.package_size}` : '';
  return `${p.name}${size} (${T_NOW}: ${p.current_stock} ${T_PCS})`;
}
function findProduct(id) {
  return PRODUCTS.find(p => String(p.id) === String(id));
}

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
    opt.appendChild(metaEl);

    opt.addEventListener('mousedown', e => e.preventDefault());
    opt.addEventListener('click', () => onSelect(String(p.id)));
    menu.appendChild(opt);
  });
}

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
        </div>
        <div class="product-search-menu"></div>
      </div>
    </td>
    <td class="row-qty" data-label="${T_QTY}"><input type="number" name="ordered_qty[]" class="form-control form-control-sm" value="${qty}" min="1" oninput="updateRowSubtotal(this)"></td>
    <td class="row-price" data-label="${T_UNIT_COST}">
      <div class="input-group input-group-sm price-input-group">
        <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)" ${toggleDisabled}>$</button>
        <input type="number" name="unit_cost[]" class="form-control price-amount-input" value="${cost}" step="0.01" oninput="updateRowSubtotal(this)">
        <input type="hidden" name="unit_cost_currency[]" class="price-currency-input" value="USD">
      </div>
    </td>
    <td class="text-end mono row-total" data-label="${T_SUBTOTAL}">$0.00</td>
    <td class="row-remove"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(this)">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);

  const controls = wireProductSelect(tr.querySelector('.product-select'), () => {});
  controls.setInitial(productId);
  updateRowSubtotal(tr.querySelector('[name="ordered_qty[]"]'));
}

function removeLine(btn) {
  btn.closest('tr').remove();
  updateGrandTotal();
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
  updateRowSubtotal(input);
}

// Client-side preview only - the server always recomputes the
// authoritative subtotal/total from the resolved USD unit_cost, exactly
// like every other price-entry form in this app.
function updateRowSubtotal(input) {
  const tr = input.closest('tr');
  const qty = parseFloat(tr.querySelector('[name="ordered_qty[]"]').value) || 0;
  const costInput = tr.querySelector('[name="unit_cost[]"]');
  const currency = tr.querySelector('[name="unit_cost_currency[]"]').value;
  let costUsd = parseFloat(costInput.value) || 0;
  if (currency === 'KHR' && EXCHANGE_RATE) {
    costUsd = costUsd / EXCHANGE_RATE;
  }
  tr.querySelector('.row-total').textContent = '$' + (qty * costUsd).toFixed(2);
  updateGrandTotal();
}

function updateGrandTotal() {
  let total = 0;
  document.querySelectorAll('#lineBody tr').forEach(tr => {
    const qty = parseFloat(tr.querySelector('[name="ordered_qty[]"]')?.value) || 0;
    const costInput = tr.querySelector('[name="unit_cost[]"]');
    const currency = tr.querySelector('[name="unit_cost_currency[]"]')?.value;
    let costUsd = parseFloat(costInput?.value) || 0;
    if (currency === 'KHR' && EXCHANGE_RATE) {
      costUsd = costUsd / EXCHANGE_RATE;
    }
    total += qty * costUsd;
  });
  document.getElementById('lineTotal').textContent = '$' + total.toFixed(2);
}

addRow();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
