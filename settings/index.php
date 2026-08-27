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
    $action = $_POST['action'] ?? 'update_rate';

    if ($action === 'backup') {
        // Same isAdmin() gate at the top of this file covers this branch
        // too - it runs unconditionally before REQUEST_METHOD is even
        // checked, so a non-Admin POSTing here directly never reaches
        // this point at all.
        require_once __DIR__ . '/../includes/backup.php';
        $filename = 'pctn-backup-' . date('Y-m-d_His') . '.sql';
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        streamDatabaseBackup($pdo, $dsn, $user, $pass);
        exit;
    }

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
      <input type="hidden" name="action" value="update_rate">
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

  <div class="col-lg-6">
    <form method="post" id="backupForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="backup">
      <div class="card p-3">
        <div class="bracket-label mb-3"><?= __('settings_backup_title') ?></div>
        <p class="text-secondary small"><?= __('settings_backup_hint') ?></p>
        <button class="btn btn-primary w-100"><i class="bi bi-download"></i> <?= __('settings_backup_button') ?></button>
      </div>
    </form>
  </div>
</div>

<script>
// The global submit-button spinner (footer.php) normally clears itself via
// page navigation, but a file-download response never navigates the page
// away - without this, the button would stay stuck showing a spinner
// after a successful download until the page is manually refreshed.
document.getElementById('backupForm').addEventListener('submit', function () {
  const btn = this.querySelector('button[type="submit"], button:not([type])');
  if (!btn) return;
  setTimeout(() => {
    if (btn.disabled && btn.dataset.originalHtml) {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.originalHtml;
    }
  }, 4000);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
