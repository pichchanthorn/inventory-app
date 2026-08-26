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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $productId = (int) $_POST['product_id'];
        $newQty = (float) $_POST['new_qty'];
        $reason = trim($_POST['reason']);
        $date = $_POST['transaction_date'];

        if (!$productId) {
            $error = __('stockadj_err_select_product');
        } elseif ($reason === '') {
            $error = __('stockadj_err_reason_required');
        } elseif ($newQty < 0) {
            $error = __('stockadj_err_negative_qty');
        } else {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            try {
                $reference = adjustStock($pdo, $productId, $newQty, $product['current_stock'], $reason, $date, $_SESSION['user_id']);
                $_SESSION['stockadj_flash'] = __('stockadj_applied_prefix') . " $reference — {$product['name']}: {$product['current_stock']} → $newQty.";
                header('Location: ' . BASE_URL . '/stock-adjustment/index.php');
                exit;
            } catch (StockConflictException $e) {
                // Optimistic-lock guard found current_stock had already changed
                // since it was read - don't overwrite that concurrent change.
                $error = __('stockadj_err_conflict');
            } catch (Throwable $e) {
                error_log('Stock Adjustment failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
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
        <div class="mb-3">
          <label class="form-label"><?= __('stockadj_new_qty_label') ?></label>
          <input type="number" name="new_qty" id="adjQty" class="form-control" value="0" min="0" oninput="updatePreview()">
        </div>
        <div id="adjPreview" class="small text-secondary mb-3"><?= __('stockadj_preview_hint') ?></div>
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
const T_NOW = <?= json_encode(__('common_now_label')) ?>;
const T_PCS = <?= json_encode(__('common_pcs')) ?>;
const T_NO_RESULTS = <?= json_encode(__('common_no_results_found')) ?>;
const T_SELECT_PREVIEW = <?= json_encode(__('stockadj_preview_hint')) ?>;
const T_UNITS = <?= json_encode(__('common_units_word')) ?>;

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
});

function updatePreview() {
  const preview = document.getElementById('adjPreview');
  if (!selectedProduct) { preview.textContent = T_SELECT_PREVIEW; return; }
  const current = selectedProduct.current_stock;
  const next = Number(document.getElementById('adjQty').value) || 0;
  const diff = next - current;
  preview.innerHTML = `${current} → <strong>${next}</strong> (${diff >= 0 ? '+' : ''}${diff} ${T_UNITS})`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
