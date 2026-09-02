<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/debt.php';
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
$customers = $pdo->query('SELECT * FROM customers ORDER BY name')->fetchAll();
$customersById = [];
foreach ($customers as $c) {
    $customersById[$c['id']] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        // Phase I2-B1: a per-form-render, single-use idempotency token -
        // deliberately separate from the CSRF token above (which is
        // session-scoped and intentionally reusable across many
        // submissions; this one is claimed exactly once, by whichever
        // request reaches recordStockOut()/recordCreditSale() first - see
        // includes/stock.php's claimIdempotencyToken()). A missing/empty
        // value (a stale cached page, or a request with it stripped)
        // falls back to a fresh random one rather than blocking the sale
        // outright - it just means that one submission has no duplicate
        // protection, same tradeoff as any other optional hardening.
        $idempotencyToken = trim($_POST['idempotency_token'] ?? '');
        if ($idempotencyToken === '') {
            $idempotencyToken = bin2hex(random_bytes(32));
        }

        $productIds = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $prices = $_POST['unit_price'] ?? [];
        $paymentMethod = ($_POST['payment_method'] ?? 'cash') === 'credit' ? 'credit' : 'cash';

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

            // Payment-method-specific validation. cash_received/customer
            // fields are only read/validated for the branch that actually
            // uses them - the other branch's fields are simply never
            // touched, so neither path can leak state into the other.
            $cashReceived = null;
            $customerId = null;
            $newCustomerName = null;
            $newCustomerPhone = null;
            $dueDate = '';
            if ($paymentMethod === 'credit') {
                $customerMode = ($_POST['customer_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
                if ($customerMode === 'new') {
                    $newCustomerName = trim($_POST['new_customer_name'] ?? '');
                    $newCustomerPhone = trim($_POST['new_customer_phone'] ?? '');
                    if ($newCustomerName === '') {
                        $error = __('pos_err_customer_required');
                    }
                } else {
                    $customerId = (int) ($_POST['customer_id'] ?? 0);
                    if ($customerId <= 0) {
                        $error = __('pos_err_customer_required');
                    }
                }
                $dueDate = trim($_POST['due_date'] ?? '');
            } else {
                $cashReceived = (float) ($_POST['cash_received'] ?? 0);
                if ($cashReceived < $total) {
                    $error = __('pos_err_insufficient_cash');
                }
            }

            if (!$error) {
                // A sale is always "now" - never a client-supplied, backdatable value.
                $today = date('Y-m-d');
                try {
                    if ($paymentMethod === 'credit') {
                        $result = recordCreditSale($pdo, $lines, $today, $_SESSION['user_id'], $customerId, $newCustomerName, $newCustomerPhone, $dueDate, $idempotencyToken);
                        $reference = $result['reference'];
                    } else {
                        $reference = recordStockOut($pdo, $lines, $today, '', $_SESSION['user_id'], 'sale', $cashReceived, $idempotencyToken);
                    }

                    $receiptLines = [];
                    foreach ($lines as $line) {
                        $p = $productsById[$line['product_id']] ?? null;
                        $receiptLines[] = [
                            'name' => $p['name'] ?? '',
                            'sku' => $p['sku'] ?? '',
                            'package' => $p['package_size'] ?? '',
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
                        // Neither applies to a credit sale - no cash changed
                        // hands, so there's nothing to record here (distinct
                        // from a genuinely-unrecorded legacy row; is_credit
                        // below is what tells receipt_view.php which case
                        // this is).
                        'cash_received' => $paymentMethod === 'credit' ? null : $cashReceived,
                        'change_due' => $paymentMethod === 'credit' ? null : ($cashReceived - $total),
                    ];
                    if ($paymentMethod === 'credit') {
                        $_SESSION['pos_last_sale']['is_credit'] = true;
                        $_SESSION['pos_last_sale']['customer_name'] = $result['customer_name'];
                        // Existing customer: looked up from the list already
                        // fetched above. New customer: not in that list yet
                        // (fetched before recordCreditSale()'s insert), so
                        // fall back to the phone just typed into the form.
                        $_SESSION['pos_last_sale']['customer_phone'] = $customersById[$result['customer_id']]['phone']
                            ?? ($newCustomerPhone !== null && $newCustomerPhone !== '' ? $newCustomerPhone : null);
                        $_SESSION['pos_last_sale']['due_date'] = $dueDate !== '' ? $dueDate : null;
                        $_SESSION['pos_last_sale']['debt_reference'] = $result['debt_reference'];
                        // A debt freshly created by this same request can only
                        // ever be fully unpaid - recordDebtPayment() runs in
                        // its own separate request, later, from
                        // customer/view.php - so these are safe invariants,
                        // not values that need querying customer_debts again.
                        $_SESSION['pos_last_sale']['paid_amount'] = 0.0;
                        $_SESSION['pos_last_sale']['balance'] = $total;
                        $_SESSION['pos_last_sale']['debt_status'] = 'open';
                    }
                    // Additive display-only conversion - no rate configured
                    // yet (fresh install, before an Admin visits Settings,
                    // or a placeholder 0 left by saving Settings' Business
                    // Information before ever setting a real rate) simply
                    // omits khr_total, which receipt_view.php treats as
                    // "don't show the KHR line" rather than an error.
                    $khrRate = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
                    if ($khrRate !== false && (float) $khrRate > 0) {
                        $_SESSION['pos_last_sale']['khr_total'] = $total * (float) $khrRate;
                    }
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
                } catch (IdempotencyConflictException $e) {
                    // This exact submission already succeeded once (a
                    // double-click, a browser retry, two tabs) - nothing
                    // was recorded a second time. Not an error the
                    // cashier needs to act on, just a heads-up.
                    $error = __('pos_err_duplicate_submission');
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

// Shop identity for the Sale Invoice header (includes/receipt_view.php) -
// same singleton app_settings row the exchange rate lives on. Any
// unconfigured field is simply omitted from the printed invoice.
$business = $pdo->query('SELECT business_name, business_address, business_phone, business_email FROM app_settings WHERE id = 1')->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('nav_pos') ?></h4>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<?php if ($sale): ?>
<div class="row g-3">
  <div class="col-lg-7 invoice-col">
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
      <input type="hidden" name="idempotency_token" value="<?= htmlspecialchars(bin2hex(random_bytes(32))) ?>">
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="bracket-label mb-0"><?= __('common_line_items') ?></div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRow()"><?= __('common_add_product') ?></button>
        </div>
        <table class="table table-cards-mobile pos-line-table" id="lineTable">
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

        <div class="mb-3">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="payment_method" id="posPaymentCash" value="cash" checked onclick="updatePaymentMethodUI()">
            <label class="form-check-label" for="posPaymentCash"><?= __('pos_payment_cash') ?></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="payment_method" id="posPaymentCredit" value="credit" onclick="updatePaymentMethodUI()">
            <label class="form-check-label" for="posPaymentCredit"><?= __('pos_payment_credit') ?></label>
          </div>
        </div>

        <div id="posCashFields" class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('pos_cash_received_label') ?></label>
            <input type="number" name="cash_received" id="posCashReceived" class="form-control" step="0.01" min="0" required oninput="updateChange()">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label"><?= __('pos_change_due_label') ?></label>
            <input type="text" class="form-control mono" id="posChangeDue" value="$0.00" disabled>
          </div>
        </div>

        <div id="posCreditFields" style="display:none;">
          <label class="form-label"><?= __('pos_customer_label') ?></label>
          <div class="mb-2">
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="customer_mode" id="posCustomerModeExisting" value="existing" checked onclick="updateCustomerModeUI()">
              <label class="form-check-label" for="posCustomerModeExisting"><?= __('pos_customer_mode_existing') ?></label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="customer_mode" id="posCustomerModeNew" value="new" onclick="updateCustomerModeUI()">
              <label class="form-check-label" for="posCustomerModeNew"><?= __('pos_customer_mode_new') ?></label>
            </div>
          </div>

          <div id="posCustomerExistingFields" class="mb-3">
            <div class="product-select" id="posCustomerSelect">
              <input type="hidden" name="customer_id">
              <input type="text" class="form-control product-search-input" placeholder="<?= __('pos_choose_customer_option') ?>" autocomplete="off">
              <div class="product-search-menu"></div>
            </div>
          </div>

          <div id="posCustomerNewFields" class="mb-3" style="display:none;">
            <input type="text" name="new_customer_name" class="form-control mb-2" placeholder="<?= __('pos_customer_name_label') ?>">
            <input type="text" name="new_customer_phone" class="form-control" placeholder="<?= __('pos_customer_phone_label') ?>">
          </div>

          <div class="mb-1">
            <label class="form-label"><?= __('pos_due_date_label') ?></label>
            <input type="date" name="due_date" class="form-control">
          </div>
        </div>

        <button class="btn btn-primary w-100 mt-2" id="posSubmitButton"><i class="bi bi-cash-coin"></i> <?= __('pos_submit_button') ?></button>
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
const CUSTOMERS = <?= json_encode($customers) ?>;
const T_CHOOSE_PRODUCT = <?= json_encode(__('common_choose_product_option')) ?>;
const T_CHOOSE_CUSTOMER = <?= json_encode(__('pos_choose_customer_option')) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_QTY = <?= json_encode(__('common_qty')) ?>;
const T_UNIT_PRICE = <?= json_encode(__('stockout_unit_price')) ?>;
const T_LINE_TOTAL = <?= json_encode(__('pos_line_total')) ?>;
const T_NO_PHONE = <?= json_encode(__('pos_customer_no_phone')) ?>;

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
// hidden input that actually gets submitted. Same component as
// stock-in/index.php and stock-out/index.php - see those for the
// original design/verification notes.
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

function addRow(productId = '', qty = 1, price = '') {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="row-title">
      <div class="product-select">
        <input type="hidden" name="product_id[]">
        <input type="text" class="form-control form-control-sm product-search-input" placeholder="${T_CHOOSE_PRODUCT}" autocomplete="off">
        <div class="product-search-menu"></div>
      </div>
    </td>
    <td class="row-qty" data-label="${T_QTY}"><input type="number" name="qty[]" class="form-control form-control-sm" value="${qty}" min="1" oninput="updateSubtotal()"></td>
    <td class="row-price" data-label="${T_UNIT_PRICE}"><input type="number" name="unit_price[]" class="form-control form-control-sm" value="${price}" step="0.01" oninput="updateSubtotal()"></td>
    <td class="text-end mono line-total row-total" data-label="${T_LINE_TOTAL}">$0.00</td>
    <td class="row-remove"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateSubtotal();">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
  const controls = wireProductSelect(tr.querySelector('.product-select'), product => fillPrice(tr, product));
  controls.setInitial(productId);
  updateSubtotal();
}
function fillPrice(row, product) {
  row.querySelector('[name="unit_price[]"]').value = product.sale_price || 0;
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

// ---- Customer combobox (Credit payment only) ----
// Parallel-named to wireProductSelect/renderSearchMenu above rather than
// reusing those names, since both comboboxes live on this same page and
// share the exact same .product-select/.product-search-* CSS - only the
// JS backing them differs, by which data source they search.
function customerLabel(c) {
  return c.phone ? `${c.name} (${c.phone})` : c.name;
}
function findCustomer(id) {
  return CUSTOMERS.find(c => String(c.id) === String(id));
}
function renderCustomerSearchMenu(menu, filterText, onSelect) {
  const q = filterText.trim().toLowerCase();
  const matches = q ? CUSTOMERS.filter(c => c.name.toLowerCase().includes(q)) : CUSTOMERS;
  menu.innerHTML = '';
  if (!matches.length) {
    const empty = document.createElement('div');
    empty.className = 'product-search-option disabled';
    empty.textContent = T_NO_RESULTS;
    menu.appendChild(empty);
    return;
  }
  matches.forEach((c, i) => {
    const opt = document.createElement('div');
    opt.className = 'product-search-option' + (i === 0 ? ' active' : '');
    opt.dataset.id = c.id;

    const nameEl = document.createElement('div');
    nameEl.className = 'product-search-option-name';
    nameEl.textContent = c.name;
    opt.appendChild(nameEl);

    const metaEl = document.createElement('div');
    metaEl.className = 'product-search-option-meta';
    const phoneEl = document.createElement('span');
    phoneEl.textContent = c.phone || T_NO_PHONE;
    metaEl.appendChild(phoneEl);
    opt.appendChild(metaEl);

    opt.addEventListener('mousedown', e => e.preventDefault());
    opt.addEventListener('click', () => onSelect(String(c.id)));
    menu.appendChild(opt);
  });
}
function wireCustomerSelect(container, onSelect) {
  const hidden = container.querySelector('input[type="hidden"]');
  const input = container.querySelector('.product-search-input');
  const menu = container.querySelector('.product-search-menu');

  function close() { menu.classList.remove('open'); }
  function open(filterText) { renderCustomerSearchMenu(menu, filterText, select); menu.classList.add('open'); }
  function select(id) {
    const c = findCustomer(id);
    if (!c) return;
    hidden.value = String(c.id);
    input.value = customerLabel(c);
    close();
    onSelect(c);
  }
  // On blur/Escape, snap the visible text back to whatever is actually
  // in the hidden field - typing never touches the hidden field itself.
  function revert() {
    const c = findCustomer(hidden.value);
    input.value = c ? customerLabel(c) : '';
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
}
wireCustomerSelect(document.getElementById('posCustomerSelect'), () => {});

// ---- Payment method / customer mode toggling ----
// Only the fields relevant to the selected payment method are required,
// so the browser doesn't block submission on a hidden field, and the
// server (the only real authority) only reads/validates the fields for
// whichever branch was actually POSTed - see pos/index.php's PHP above.
function updatePaymentMethodUI() {
  const isCredit = document.getElementById('posPaymentCredit').checked;
  document.getElementById('posCashFields').style.display = isCredit ? 'none' : '';
  document.getElementById('posCreditFields').style.display = isCredit ? '' : 'none';
  document.getElementById('posCashReceived').required = !isCredit;
}
function updateCustomerModeUI() {
  const isNew = document.getElementById('posCustomerModeNew').checked;
  document.getElementById('posCustomerExistingFields').style.display = isNew ? 'none' : '';
  document.getElementById('posCustomerNewFields').style.display = isNew ? '' : 'none';
}
updatePaymentMethodUI();
updateCustomerModeUI();

addRow();

// ---- Low-friction safeguard against a fat-fingered quantity (UI/UX
// Batch 1) ----
// Not a blanket confirmation on every sale (that would slow down normal,
// fast-paced checkout) - only fires when a single line's quantity is at
// least half of that product's current stock on hand, a self-scaling
// per-product signal that catches the "typed an extra digit" case (e.g.
// 100 instead of 10 against a stock of 52) without ever bothering a
// normal partial sale (2 of 40 in stock). Products already out of stock
// are skipped here - the server's own stock guard handles that case.
//
// stopPropagation() on cancel is required, not optional: footer.php's
// global submit handler (a document-level bubble listener that disables
// the submit button and shows a spinner) would otherwise still fire for
// a submission this script just blocked, leaving the button stuck
// disabled with no request actually in flight.
document.getElementById('posForm').addEventListener('submit', function (e) {
  let hasLargeQty = false;
  document.querySelectorAll('#lineBody tr').forEach(function (tr) {
    const productId = tr.querySelector('[name="product_id[]"]')?.value;
    const qty = parseFloat(tr.querySelector('[name="qty[]"]')?.value) || 0;
    const product = productId ? findProduct(productId) : null;
    if (product && product.current_stock > 0 && qty >= product.current_stock * 0.5) {
      hasLargeQty = true;
    }
  });
  if (hasLargeQty && !confirm(<?= json_encode(__('pos_confirm_large_qty')) ?>)) {
    e.preventDefault();
    e.stopPropagation();
  }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
