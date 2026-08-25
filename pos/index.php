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
                    // Additive display-only conversion - no rate configured
                    // yet (fresh install, before an Admin visits Settings)
                    // simply omits khr_total, which receipt_view.php treats
                    // as "don't show the KHR line" rather than an error.
                    $khrRate = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
                    if ($khrRate !== false) {
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
        <table class="table table-cards-mobile" id="lineTable">
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
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_QTY = <?= json_encode(__('common_qty')) ?>;
const T_UNIT_PRICE = <?= json_encode(__('stockout_unit_price')) ?>;
const T_LINE_TOTAL = <?= json_encode(__('pos_line_total')) ?>;

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
    opt.textContent = productLabel(p);
    opt.dataset.id = p.id;
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

addRow();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
