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
                $reference = recordStockOut($pdo, $lines, $date, $note, $_SESSION['user_id']);
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
        <table class="table" id="lineTable">
          <thead class="table-light"><tr><th><?= __('common_product') ?></th><th style="width:100px;"><?= __('common_qty') ?></th><th style="width:130px;"><?= __('stockout_unit_price') ?></th><th style="width:40px;"></th></tr></thead>
          <tbody id="lineBody"></tbody>
        </table>
        <button class="btn text-white w-100 mt-2" style="background:var(--danger);"><i class="bi bi-upload"></i> <?= __('stockout_submit_button') ?></button>
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

<script>
const PRODUCTS = <?= json_encode($products) ?>;
const T_CHOOSE_PRODUCT = <?= json_encode(__('common_choose_product_option')) ?>;
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;

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

function addRow(productId = '', qty = 1, price = '') {
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <div class="product-select">
        <input type="hidden" name="product_id[]">
        <input type="text" class="form-control form-control-sm product-search-input" placeholder="${T_CHOOSE_PRODUCT}" autocomplete="off">
        <div class="product-search-menu"></div>
      </div>
    </td>
    <td><input type="number" name="qty[]" class="form-control form-control-sm" value="${qty}" min="1"></td>
    <td><input type="number" name="unit_price[]" class="form-control form-control-sm" value="${price}" step="0.01"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">✕</button></td>`;
  document.getElementById('lineBody').appendChild(tr);
  const controls = wireProductSelect(tr.querySelector('.product-select'), product => fillPrice(tr, product));
  controls.setInitial(productId);
}
function fillPrice(row, product) {
  row.querySelector('[name="unit_price[]"]').value = product.sale_price || 0;
}
addRow();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
