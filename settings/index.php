<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

// Admin-only (isAdmin(), not canWrite()) - system-level configuration,
// not a daily operation. A wrong/accidental rate change from a
// Cashier-level (canWrite()) user would immediately misprice every
// subsequent sale's KHR display, so this sits at the same tier as User
// Management rather than the operational pages.
if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$activePage = 'settings';
$error = '';

// Post/Redirect/Get: same session-flash pattern as Stock In/Out/Adjustment.
$success = $_SESSION['settings_flash'] ?? '';
unset($_SESSION['settings_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rate = (float) ($_POST['usd_to_khr_rate'] ?? 0);

    if ($rate <= 0) {
        $error = __('settings_err_invalid_rate');
    } else {
        // Always exactly one row (id=1) - insert it the first time, update
        // it every time after. Display-layer only: this never touches
        // products/stock_transactions, and nothing here is stored per-sale.
        $stmt = $pdo->prepare('INSERT INTO app_settings (id, usd_to_khr_rate) VALUES (1, ?)
                                ON DUPLICATE KEY UPDATE usd_to_khr_rate = VALUES(usd_to_khr_rate)');
        $stmt->execute([$rate]);
        $_SESSION['settings_flash'] = __('settings_rate_updated');
        header('Location: ' . BASE_URL . '/settings/index.php');
        exit;
    }
}

$settings = $pdo->query('SELECT * FROM app_settings WHERE id = 1')->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<h4 class="mb-4"><?= __('settings_title') ?></h4>
<?php if ($success): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($success) ?>, 'success'));</script><?php endif; ?>
<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <form method="post">
      <?= csrf_field() ?>
      <div class="card p-3">
        <div class="bracket-label mb-3"><?= __('settings_exchange_rate_title') ?></div>
        <div class="mb-1">
          <label class="form-label"><?= __('settings_usd_to_khr_label') ?></label>
          <div class="input-group">
            <span class="input-group-text">$1 =</span>
            <input type="number" name="usd_to_khr_rate" class="form-control" step="0.01" min="0.01"
                   value="<?= $settings ? htmlspecialchars($settings['usd_to_khr_rate']) : '' ?>" required>
            <span class="input-group-text">៛</span>
          </div>
          <p class="text-secondary small mt-2"><?= __('settings_exchange_rate_hint') ?></p>
        </div>
        <button class="btn btn-primary w-100"><?= __('common_save') ?></button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
