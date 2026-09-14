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

$id = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
$stmt->execute([$id]);
$po = $stmt->fetch();

if (!$po) {
    $_SESSION['po_flash_error'] = __('po_err_not_found');
    header('Location: ' . BASE_URL . '/purchase-order/index.php');
    exit;
}
if (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
    $_SESSION['po_flash_error'] = __('po_err_not_receivable');
    header('Location: ' . BASE_URL . '/purchase-order/view.php?id=' . $id);
    exit;
}

$khrRateRow = $pdo->query('SELECT usd_to_khr_rate FROM app_settings WHERE id = 1')->fetchColumn();
$khrRate = $khrRateRow !== false ? (float) $khrRateRow : null;

$formDate = date('Y-m-d');
$formNote = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $idempotencyToken = trim($_POST['idempotency_token'] ?? '');
    if ($idempotencyToken === '') {
        $idempotencyToken = bin2hex(random_bytes(32));
    }

    $formDate = trim($_POST['receive_date'] ?? '');
    $formNote = trim($_POST['note'] ?? '');
    $poItemIds = $_POST['purchase_order_item_id'] ?? [];
    $qtys = $_POST['receive_qty'] ?? [];
    $costs = $_POST['unit_cost'] ?? [];
    $costCurrencies = $_POST['unit_cost_currency'] ?? [];
    $batchNumbers = $_POST['batch_number'] ?? [];
    $expiryDates = $_POST['expiry_date'] ?? [];

    try {
        $dateObj = DateTime::createFromFormat('Y-m-d', $formDate);
        if ($formDate === '' || !$dateObj || $dateObj->format('Y-m-d') !== $formDate) {
            throw new InvalidArgumentException(__('po_err_invalid_date'));
        }

        $receiptLines = [];
        foreach ($poItemIds as $i => $poItemIdRaw) {
            $qtyRaw = trim((string) ($qtys[$i] ?? ''));
            if ($qtyRaw === '' || $qtyRaw === '0') {
                // A blank/zero quantity on an outstanding line simply
                // means "not receiving this line in this delivery" - the
                // same "empty row is just not submitted" tolerance
                // stock-in/index.php's own line-parsing loop already has,
                // never an error.
                continue;
            }
            if (!isNonNegativeIntegerString($qtyRaw) || (int) $qtyRaw <= 0) {
                throw new InvalidArgumentException(__('po_err_invalid_qty'));
            }
            $unitCost = resolvePriceField(
                ['unit_cost' => $costs[$i] ?? 0, 'unit_cost_currency' => $costCurrencies[$i] ?? 'USD'],
                'unit_cost', $khrRate
            );
            $batchNumber = trim((string) ($batchNumbers[$i] ?? ''));
            $batchNumber = $batchNumber === '' ? null : $batchNumber;
            $expiryDate = trim((string) ($expiryDates[$i] ?? ''));
            $expiryDate = $expiryDate === '' ? null : $expiryDate;
            if ($batchNumber !== null && strlen($batchNumber) > 60) {
                throw new InvalidArgumentException(__('stockin_err_batch_number_too_long'));
            }

            $receiptLines[] = [
                'purchase_order_item_id' => (int) $poItemIdRaw,
                'qty' => (int) $qtyRaw,
                'unit_cost' => $unitCost,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
            ];
        }

        if (!$receiptLines) {
            throw new InvalidArgumentException(__('po_err_add_product'));
        }

        $result = receivePurchaseOrder(
            $pdo,
            $id,
            $receiptLines,
            $formDate,
            $formNote !== '' ? $formNote : null,
            (int) $_SESSION['user_id'],
            $idempotencyToken
        );

        $_SESSION['po_flash'] = __('po_received_toast') . ' (' . $result['stock_reference'] . ')';
        header('Location: ' . BASE_URL . '/purchase-order/view.php?id=' . $id);
        exit;
    } catch (PurchaseOrderNotFoundException $e) {
        $error = __('po_err_not_found');
    } catch (PurchaseOrderNotReceivableException $e) {
        $error = __('po_err_not_receivable');
    } catch (PurchaseOrderItemMismatchException $e) {
        $error = __('po_err_item_mismatch');
    } catch (PurchaseOrderOverReceiveException $e) {
        $error = __('po_err_over_receive');
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PriceConversionException $e) {
        $error = $e->getMessage();
    } catch (IdempotencyConflictException $e) {
        $error = __('po_err_duplicate_submission');
    } catch (Throwable $e) {
        error_log('Purchase Order receive failed: ' . $e->getMessage());
        $error = __('common_err_transaction_failed');
    }
}

// Outstanding lines only (remaining > 0) - re-fetched fresh on every
// render (including after a failed POST above) so the form never shows
// a line that a concurrent receipt from another session has since
// completed.
$itemStmt = $pdo->prepare('SELECT poi.*, p.name AS product_name, p.sku, p.package_size, p.track_batches, p.cost_price
                            FROM purchase_order_items poi
                            JOIN products p ON p.id = poi.product_id
                            WHERE poi.purchase_order_id = ? AND poi.received_qty < poi.ordered_qty
                            ORDER BY poi.id');
$itemStmt->execute([$id]);
$outstandingItems = $itemStmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('po_receive_title') ?> — <span class="mono"><?= htmlspecialchars($po['reference']) ?></span></h4>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<?php if (!$outstandingItems): ?>
<div class="card p-4 text-center text-secondary">
  <i class="bi bi-check-circle fs-3 d-block mb-2"></i>
  <?= __('po_receive_nothing_outstanding') ?>
  <div class="mt-3"><a href="<?= BASE_URL ?>/purchase-order/view.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><?= __('po_back_to_list') ?></a></div>
</div>
<?php else: ?>

<form method="post" id="poReceiveForm">
  <?= csrf_field() ?>
  <input type="hidden" name="idempotency_token" value="<?= htmlspecialchars(bin2hex(random_bytes(32))) ?>">
  <div class="card p-3 mb-3">
    <div class="bracket-label mb-3"><?= __('common_transaction_details') ?></div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= __('common_transaction_date') ?></label>
        <input type="date" name="receive_date" class="form-control" value="<?= htmlspecialchars($formDate) ?>" required>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= __('common_note') ?></label>
        <input type="text" name="note" class="form-control" value="<?= htmlspecialchars($formNote) ?>" placeholder="<?= htmlspecialchars(sprintf(__('po_receive_note_placeholder'), $po['reference'])) ?>">
      </div>
    </div>
  </div>

  <div class="card p-3">
    <div class="bracket-label mb-3"><?= __('po_receive_outstanding_lines') ?></div>
    <table class="table table-cards-mobile stockin-line-table" id="lineTable">
      <thead class="table-light">
        <tr>
          <th><?= __('common_product') ?></th>
          <th class="text-end"><?= __('po_col_ordered_qty') ?></th>
          <th class="text-end"><?= __('po_col_received_qty') ?></th>
          <th class="text-end"><?= __('po_col_remaining_qty') ?></th>
          <th style="width:110px;"><?= __('po_receive_qty_label') ?></th>
          <th style="width:130px;"><?= __('po_unit_cost') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($outstandingItems as $it):
          $remaining = (int) $it['ordered_qty'] - (int) $it['received_qty'];
          $toggleDisabled = $khrRate ? '' : 'disabled';
        ?>
        <tr>
          <td class="row-title">
            <?= htmlspecialchars($it['product_name']) ?>
            <?php if ($it['package_size']): ?><span class="text-secondary small"> — <?= htmlspecialchars($it['package_size']) ?></span><?php endif; ?>
            <div><span class="slug-pill"><?= htmlspecialchars($it['sku']) ?></span></div>
            <input type="hidden" name="purchase_order_item_id[]" value="<?= $it['id'] ?>">
          </td>
          <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_col_ordered_qty')) ?>"><?= (int) $it['ordered_qty'] ?></td>
          <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_col_received_qty')) ?>"><?= (int) $it['received_qty'] ?></td>
          <td class="mono text-end" data-label="<?= htmlspecialchars(__('po_col_remaining_qty')) ?>"><?= $remaining ?></td>
          <td data-label="<?= htmlspecialchars(__('po_receive_qty_label')) ?>">
            <input type="number" name="receive_qty[]" class="form-control form-control-sm" value="<?= $remaining ?>" min="0" max="<?= $remaining ?>">
          </td>
          <td data-label="<?= htmlspecialchars(__('po_unit_cost')) ?>">
            <div class="input-group input-group-sm price-input-group">
              <button type="button" class="btn btn-outline-secondary currency-toggle-btn" onclick="toggleCurrency(this)" <?= $toggleDisabled ?>>$</button>
              <input type="number" name="unit_cost[]" class="form-control price-amount-input" value="<?= number_format((float) $it['unit_cost'], 2, '.', '') ?>" step="0.01">
              <input type="hidden" name="unit_cost_currency[]" class="price-currency-input" value="USD">
            </div>
          </td>
        </tr>
        <?php if ((int) $it['track_batches'] === 1): ?>
        <tr class="batch-fields-row">
          <td colspan="6" style="border-top:0;">
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small mb-1"><?= __('stockin_batch_number_label') ?></label>
                <input type="text" name="batch_number[]" class="form-control form-control-sm" maxlength="60" placeholder="<?= __('stockin_batch_number_placeholder') ?>">
              </div>
              <div class="col-6">
                <label class="form-label small mb-1"><?= __('stockin_expiry_date_label') ?></label>
                <input type="date" name="expiry_date[]" class="form-control form-control-sm">
              </div>
            </div>
          </td>
        </tr>
        <?php else: ?>
        <tr class="d-none"><td colspan="6"><input type="hidden" name="batch_number[]" value=""><input type="hidden" name="expiry_date[]" value=""></td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button id="poReceiveSubmitButton" class="btn btn-primary w-100 mt-2"><i class="bi bi-box-seam"></i> <?= __('po_receive_button') ?></button>
  </div>
</form>

<script>
const EXCHANGE_RATE = <?= json_encode($khrRate) ?>;
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
}
</script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
