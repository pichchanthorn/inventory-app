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

    if ($action === 'update_business') {
        // Same singleton-row pattern as the rate below, touching only
        // the business_* columns - a fresh row is created with a 0
        // placeholder rate if none exists yet (the exchange-rate form's
        // own ON DUPLICATE KEY UPDATE never overwrites these fields,
        // and vice versa, since each statement only lists the columns
        // it actually owns). All four fields are optional and shown on
        // the Sale Invoice header only when non-empty - see
        // includes/receipt_view.php.
        $businessName = trim($_POST['business_name'] ?? '');
        $businessAddress = trim($_POST['business_address'] ?? '');
        $businessPhone = trim($_POST['business_phone'] ?? '');
        $businessEmail = trim($_POST['business_email'] ?? '');

        $stmt = $pdo->prepare('INSERT INTO app_settings (id, usd_to_khr_rate, business_name, business_address, business_phone, business_email)
                                VALUES (1, 0, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE business_name = VALUES(business_name),
                                    business_address = VALUES(business_address),
                                    business_phone = VALUES(business_phone),
                                    business_email = VALUES(business_email)');
        $stmt->execute([
            $businessName !== '' ? $businessName : null,
            $businessAddress !== '' ? $businessAddress : null,
            $businessPhone !== '' ? $businessPhone : null,
            $businessEmail !== '' ? $businessEmail : null,
        ]);
        $_SESSION['settings_flash'] = __('settings_business_info_updated');
        header('Location: ' . BASE_URL . '/settings/index.php');
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

  <div class="col-lg-8">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_business">
      <div class="card p-3">
        <div class="bracket-label mb-3"><?= __('settings_business_info_title') ?></div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label"><?= __('settings_business_name_label') ?></label>
            <input type="text" name="business_name" class="form-control"
                   value="<?= $settings ? htmlspecialchars($settings['business_name'] ?? '') : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= __('settings_business_phone_label') ?></label>
            <input type="text" name="business_phone" class="form-control"
                   value="<?= $settings ? htmlspecialchars($settings['business_phone'] ?? '') : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= __('settings_business_address_label') ?></label>
            <input type="text" name="business_address" class="form-control"
                   value="<?= $settings ? htmlspecialchars($settings['business_address'] ?? '') : '' ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= __('settings_business_email_label') ?></label>
            <input type="email" name="business_email" class="form-control"
                   value="<?= $settings ? htmlspecialchars($settings['business_email'] ?? '') : '' ?>">
          </div>
        </div>
        <p class="text-secondary small mt-3 mb-3"><?= __('settings_business_info_hint') ?></p>
        <button class="btn btn-primary w-100"><?= __('common_save') ?></button>
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
