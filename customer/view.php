<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/stock.php';
require_once __DIR__ . '/../includes/debt.php';
require_once __DIR__ . '/../config/db.php';

$activePage = 'customer';
$error = '';
$id = (int) ($_GET['id'] ?? 0);

// Post/Redirect/Get: a successful payment redirects back to this same
// page with the toast message stashed in the session - same pattern as
// stock-in/index.php's $_SESSION['stockin_flash'], so a page refresh
// re-fetches this GET instead of resubmitting the POST.
$success = $_SESSION['customer_payment_flash'] ?? '';
unset($_SESSION['customer_payment_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!canWrite()) {
        $error = __('common_err_forbidden');
    } else {
        $debtId = (int) $_POST['debt_id'];
        $amount = (float) $_POST['amount'];
        $paymentDate = trim($_POST['payment_date']);
        $note = trim($_POST['note']);

        $stmt = $pdo->prepare('SELECT * FROM customer_debts WHERE id = ? AND customer_id = ?');
        $stmt->execute([$debtId, $id]);
        $debt = $stmt->fetch();

        if (!$debt) {
            $error = __('customer_view_not_found');
        } elseif ($amount <= 0) {
            $error = __('customer_err_invalid_amount');
        } elseif ($paymentDate === '') {
            $error = __('customer_err_payment_date_required');
        } elseif ($amount > (float) $debt['balance']) {
            $error = __('customer_err_overpayment');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            try {
                recordDebtPayment($pdo, $debtId, $amount, $paymentDate, $note, $actorId);
                $_SESSION['customer_payment_flash'] = __('customer_payment_recorded_msg');
                header('Location: ' . BASE_URL . '/customer/view.php?id=' . $id);
                exit;
            } catch (DebtOverpaymentException $e) {
                // Another payment landed between our balance check above and
                // the guarded UPDATE inside recordDebtPayment() - same
                // "the DB is the final word, not the PHP-side pre-check"
                // race handled by StockConflictException elsewhere.
                $error = __('customer_err_overpayment');
            } catch (Throwable $e) {
                error_log('Record debt payment failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

$debts = [];
if ($customer) {
    $stmt = $pdo->prepare('SELECT * FROM customer_debts WHERE customer_id = ? ORDER BY created_at DESC');
    $stmt->execute([$id]);
    $debts = $stmt->fetchAll();

    // One payment-history lookup per debt - fine at this app's scale (a
    // single shop's customer list), same "loop a small per-row query"
    // shape stock-transaction/view.php already uses for its line items.
    foreach ($debts as &$debt) {
        $payStmt = $pdo->prepare('SELECT p.*, u.name AS recorded_by_name
                                   FROM customer_debt_payments p
                                   LEFT JOIN users u ON u.id = p.created_by
                                   WHERE p.debt_id = ? ORDER BY p.payment_date DESC, p.id DESC');
        $payStmt->execute([$debt['id']]);
        $debt['payments'] = $payStmt->fetchAll();
    }
    unset($debt);
}

$statusBadgeClass = ['open' => 'badge-warn', 'partially_paid' => 'badge-accent', 'paid' => 'badge-normal'];
$statusLabel = ['open' => __('customer_status_open'), 'partially_paid' => __('customer_status_partially_paid'), 'paid' => __('customer_status_paid')];

require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>
<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>

<a href="<?= BASE_URL ?>/customer/index.php" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left"></i> <?= __('customer_back_button') ?></a>

<?php if (!$customer): ?>
  <div class="alert alert-warning"><?= __('customer_view_not_found') ?></div>
<?php else: ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card p-3">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h5 class="mb-0"><?= htmlspecialchars($customer['name']) ?></h5>
        <?php if (canWrite()): ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editCustomerModal">
          <i class="bi bi-pencil"></i>
        </button>
        <?php endif; ?>
      </div>
      <div class="mb-2"><span class="text-secondary small text-uppercase"><?= __('common_phone') ?></span><br>
        <?= $customer['phone'] !== '' && $customer['phone'] !== null ? htmlspecialchars($customer['phone']) : '<span class="text-secondary">—</span>' ?></div>
      <div class="mb-2"><span class="text-secondary small text-uppercase"><?= __('common_address') ?></span><br>
        <?= $customer['address'] !== '' && $customer['address'] !== null ? nl2br(htmlspecialchars($customer['address'])) : '<span class="text-secondary">—</span>' ?></div>
      <div><span class="text-secondary small text-uppercase"><?= __('common_note') ?></span><br>
        <?= $customer['note'] !== '' && $customer['note'] !== null ? nl2br(htmlspecialchars($customer['note'])) : '<span class="text-secondary">—</span>' ?></div>
    </div>
  </div>

  <div class="col-lg-8">
    <h5 class="mb-3"><?= __('customer_debts_title') ?></h5>
    <div class="card">
      <table class="table mb-0 align-middle table-cards-mobile">
        <thead class="table-light">
          <tr>
            <th>#</th><th><?= __('common_reference') ?></th><th><?= __('common_date') ?></th>
            <th><?= __('customer_col_total') ?></th><th><?= __('customer_col_paid') ?></th><th><?= __('customer_col_balance') ?></th>
            <th><?= __('customer_col_due_date') ?></th><th><?= __('customer_col_status') ?></th><th class="text-end"><?= __('common_actions') ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$debts): ?>
            <tr><td colspan="9" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('customer_debts_empty') ?></td></tr>
          <?php endif; ?>
          <?php foreach ($debts as $i => $debt):
            $overdue = $debt['status'] !== 'paid' && $debt['due_date'] !== null && $debt['due_date'] < date('Y-m-d');
          ?>
          <tr class="<?= $overdue ? 'row-overdue' : '' ?>">
            <td class="row-number"><?= $i + 1 ?></td>
            <td class="row-title">
              <?php if ($overdue): ?><span class="overdue-badge"><i class="bi bi-exclamation-triangle-fill"></i> <?= __('customer_overdue_badge') ?></span><?php endif; ?>
              <span class="slug-pill"><?= htmlspecialchars($debt['reference']) ?></span>
            </td>
            <td data-label="<?= htmlspecialchars(__('common_date')) ?>"><?= htmlspecialchars(substr($debt['created_at'], 0, 10)) ?></td>
            <td class="mono" data-label="<?= htmlspecialchars(__('customer_col_total')) ?>">$<?= number_format($debt['total_amount'], 2) ?></td>
            <td class="mono" data-label="<?= htmlspecialchars(__('customer_col_paid')) ?>">$<?= number_format($debt['paid_amount'], 2) ?></td>
            <td class="mono" data-label="<?= htmlspecialchars(__('customer_col_balance')) ?>">$<?= number_format($debt['balance'], 2) ?></td>
            <td data-label="<?= htmlspecialchars(__('customer_col_due_date')) ?>"><?= $debt['due_date'] ? htmlspecialchars($debt['due_date']) : __('customer_due_date_none') ?></td>
            <td data-label="<?= htmlspecialchars(__('customer_col_status')) ?>"><span class="badge-stock <?= $statusBadgeClass[$debt['status']] ?>"><?= $statusLabel[$debt['status']] ?></span></td>
            <td class="text-end row-actions">
              <?php if ($debt['payments']): ?>
              <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#payHist<?= $debt['id'] ?>">
                <i class="bi bi-clock-history"></i> <?= __('customer_payment_history_toggle') ?>
              </button>
              <?php endif; ?>
              <?php if (canWrite() && $debt['status'] !== 'paid'): ?>
              <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#payModal<?= $debt['id'] ?>">
                <i class="bi bi-cash"></i> <?= __('customer_record_payment_button') ?>
              </button>
              <?php endif; ?>
            </td>
          </tr>

          <?php if ($debt['payments']): ?>
          <tr class="payment-history-row">
            <td colspan="9" style="border-top:0;">
              <div class="collapse" id="payHist<?= $debt['id'] ?>">
                <table class="table table-sm mb-0">
                  <thead><tr><th><?= __('common_date') ?></th><th><?= __('customer_payment_amount_label') ?></th><th><?= __('common_note') ?></th><th><?= __('customer_payment_col_by') ?></th></tr></thead>
                  <tbody>
                    <?php foreach ($debt['payments'] as $p): ?>
                    <tr>
                      <td><?= htmlspecialchars($p['payment_date']) ?></td>
                      <td class="mono">$<?= number_format($p['amount'], 2) ?></td>
                      <td><?= $p['note'] !== null && $p['note'] !== '' ? htmlspecialchars($p['note']) : '<span class="text-secondary">—</span>' ?></td>
                      <td><?= $p['recorded_by_name'] ? htmlspecialchars($p['recorded_by_name']) : '<span class="text-secondary">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
          <?php endif; ?>

          <?php if (canWrite() && $debt['status'] !== 'paid'): ?>
          <div class="modal fade" id="payModal<?= $debt['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content">
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="record_payment">
                  <input type="hidden" name="debt_id" value="<?= $debt['id'] ?>">
                  <div class="modal-header">
                    <h5 class="modal-title"><?= __('customer_record_payment_title') ?> — <?= htmlspecialchars($debt['reference']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
                  </div>
                  <div class="modal-body">
                    <div class="mb-3"><label class="form-label"><?= __('customer_payment_amount_label') ?> (<?= __('customer_col_balance') ?>: $<?= number_format($debt['balance'], 2) ?>)</label>
                      <input type="number" step="0.01" min="0.01" max="<?= $debt['balance'] ?>" name="amount" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label"><?= __('customer_payment_date_label') ?></label>
                      <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="mb-3"><label class="form-label"><?= __('customer_payment_note_label') ?></label>
                      <input type="text" name="note" class="form-control"></div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
                    <button class="btn btn-primary"><?= __('common_save') ?></button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (canWrite()): ?>
<div class="modal fade" id="editCustomerModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="<?= BASE_URL ?>/customer/index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?= $customer['id'] ?>">
        <input type="hidden" name="from_view" value="1">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('customer_edit_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($customer['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label"><?= __('common_phone') ?></label>
            <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($customer['phone']) ?>"></div>
          <div class="mb-3"><label class="form-label"><?= __('common_address') ?></label>
            <textarea name="address" class="form-control"><?= htmlspecialchars($customer['address']) ?></textarea></div>
          <div class="mb-3"><label class="form-label"><?= __('common_note') ?></label>
            <textarea name="note" class="form-control"><?= htmlspecialchars($customer['note']) ?></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('common_save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
