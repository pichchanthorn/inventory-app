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
          <select name="product_id" id="adjProduct" class="form-select" required onchange="updatePreview()">
            <option value=""><?= __('stockadj_select_product') ?></option>
            <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" data-stock="<?= $p['current_stock'] ?>"><?= htmlspecialchars($p['name']) ?><?= $p['package_size'] ? ' — ' . htmlspecialchars($p['package_size']) : '' ?> (<?= __('common_now_label') ?>: <?= $p['current_stock'] ?> <?= __('common_pcs') ?>)</option>
            <?php endforeach; ?>
          </select>
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
const T_SELECT_PREVIEW = <?= json_encode(__('stockadj_preview_hint')) ?>;
const T_UNITS = <?= json_encode(__('common_units_word')) ?>;

function updatePreview() {
  const sel = document.getElementById('adjProduct');
  const opt = sel.selectedOptions[0];
  const preview = document.getElementById('adjPreview');
  if (!opt || !opt.value) { preview.textContent = T_SELECT_PREVIEW; return; }
  const current = Number(opt.dataset.stock);
  const next = Number(document.getElementById('adjQty').value) || 0;
  const diff = next - current;
  preview.innerHTML = `${current} → <strong>${next}</strong> (${diff >= 0 ? '+' : ''}${diff} ${T_UNITS})`;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
