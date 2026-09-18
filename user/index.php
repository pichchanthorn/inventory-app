<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../config/db.php';

if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$activePage = 'user';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ---------- CREATE USER (Admin-created staff account) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'create_user') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $password = $_POST['password'];
    $roleId   = (int) $_POST['role_id'];
    $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

    if ($name === '' || $email === '' || $password === '') {
        $error = __('user_err_required');
    } elseif (strlen($password) < 6) {
        $error = __('user_err_password_short');
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = __('common_err_email_taken');
        } else {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role_id, must_change_password, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, $hashed, $roleId, $mustChange, $actorId, $actorId]);
                $newId = (int) $pdo->lastInsertId();

                // Fresh read-back, same reason as product/index.php's create -
                // never hand-build the snapshot from POST values, so a column
                // this form doesn't touch can never be silently omitted.
                // userAuditSnapshot() is the only thing allowed to shape it -
                // see includes/audit.php for why that's the password-exclusion
                // boundary, not this call site.
                $afterStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $afterStmt->execute([$newId]);
                $after = userAuditSnapshot($afterStmt->fetch());

                logAudit($pdo, $actorId, 'create', 'user', $newId, null, $after);
                $pdo->commit();

                // Stash the plaintext temp password as a one-time flash message so the
                // Admin can copy it — never stored anywhere after this request.
                $_SESSION['new_user_credentials'] = ['name' => $name, 'email' => $email, 'password' => $password];
                header('Location: ' . BASE_URL . '/user/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Create user failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

$newUserCredentials = $_SESSION['new_user_credentials'] ?? null;
unset($_SESSION['new_user_credentials']);

// ---------- UPDATE ROLE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'update_role') {
    $id     = (int) $_POST['id'];
    $roleId = (int) $_POST['role_id'];

    if ($id === (int) $_SESSION['user_id']) {
        $error = __('user_err_self_role');
    } else {
        $actorId = (int) $_SESSION['user_id'];
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $beforeRow = $stmt->fetch();

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE users SET role_id = ?, updated_by = ? WHERE id = ?');
            $stmt->execute([$roleId, $actorId, $id]);

            if ($beforeRow) {
                $afterStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $afterStmt->execute([$id]);
                $after = userAuditSnapshot($afterStmt->fetch());
                logAudit($pdo, $actorId, 'update', 'user', $id, userAuditSnapshot($beforeRow), $after);
            }
            $pdo->commit();
            header('Location: ' . BASE_URL . '/user/index.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Update role failed: ' . $e->getMessage());
            $error = __('common_err_transaction_failed');
        }
    }
}

// ---------- RESET PASSWORD (Admin sets a new temp password for an existing user) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'reset_password') {
    $id          = (int) $_POST['id'];
    $newPassword = $_POST['new_password'];
    $mustChange  = isset($_POST['must_change_password']) ? 1 : 0;

    if ($id === (int) $_SESSION['user_id']) {
        $error = __('user_err_self_reset');
    } elseif (strlen($newPassword) < 6) {
        $error = __('user_err_password_short');
    } else {
        // SELECT * (not just name/email) - the full row feeds
        // userAuditSnapshot() as the before-snapshot below. The raw
        // password hash passes through this array on its way, but
        // userAuditSnapshot() never reads that key - see includes/audit.php.
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if ($target) {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->beginTransaction();
                // Phase K2-D: password and password_changed_at move in
                // ONE statement inside the existing transaction, so the
                // two can never disagree - a new hash without a new
                // timestamp would leave every existing session of that
                // account alive, which is exactly the failure this
                // reset is meant to prevent.
                $stmt = $pdo->prepare('UPDATE users SET password = ?, must_change_password = ?, password_changed_at = CURRENT_TIMESTAMP(6), updated_by = ? WHERE id = ?');
                $stmt->execute([$hashed, $mustChange, $actorId, $id]);

                $afterStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $afterStmt->execute([$id]);
                $after = userAuditSnapshot($afterStmt->fetch());

                // Full before/after, same as every other action - not a
                // special truncated shape. This still satisfies "no
                // password field ever appears" because userAuditSnapshot()
                // excludes it unconditionally, and showing the rest of the
                // row unchanged is what proves this action touched nothing
                // but must_change_password (see B3 design doc §4).
                logAudit($pdo, $actorId, 'update', 'user', $id, userAuditSnapshot($target), $after);
                $pdo->commit();

                // Same one-time flash pattern as account creation — plaintext
                // password is shown once so the Admin can copy it, never stored.
                $_SESSION['password_reset_credentials'] = ['name' => $target['name'], 'email' => $target['email'], 'password' => $newPassword];
                header('Location: ' . BASE_URL . '/user/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Reset password failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

$passwordResetCredentials = $_SESSION['password_reset_credentials'] ?? null;
unset($_SESSION['password_reset_credentials']);

// ---------- TOGGLE ACTIVE (Admin deactivates/reactivates a user) ----------
// One action name for both directions - the form always submits the
// EXPLICIT target state it wants (new_state=0 for the Deactivate
// button, new_state=1 for the Reactivate button), never a blind flip of
// whatever the row currently is. That is what makes a repeated/stale
// submission a safe no-op below instead of toggling the account back
// the opposite way from what the Admin actually clicked twice.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'toggle_active') {
    $id = (int) $_POST['id'];
    $newActive = isset($_POST['new_state']) && (int) $_POST['new_state'] === 1 ? 1 : 0;

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $beforeRow = $stmt->fetch();

    if (!$beforeRow) {
        $error = __('common_err_transaction_failed');
    } elseif ((int) $beforeRow['is_active'] === $newActive) {
        // Already in the requested state (a stale page re-submitted, a
        // double-click, or two Admins acting on the same row at once) -
        // a harmless no-op. No UPDATE and no audit row: an audit event
        // must only ever record a transition that actually happened,
        // never a resubmission of the same state.
        header('Location: ' . BASE_URL . '/user/index.php');
        exit;
    } else {
        // Checked BEFORE the self-guard below, and independent of who
        // the actor is: reaching user/index.php at all already requires
        // an active Admin session, so a distinct actor deactivating a
        // distinct target Admin always counts at least two active
        // Admins (themselves plus the target) at this exact moment -
        // this guard can therefore only ever actually trip when the
        // target IS the sole active Admin, which is only reachable by
        // that Admin acting on themselves. Checking it first, rather
        // than after the self-guard, is what makes that one specific
        // case report the more informative "last Admin" reason instead
        // of the generic self-action one. Only checked on the
        // deactivate direction; reactivating can never reduce the
        // active-Admin count.
        if ($newActive === 0 && (int) $beforeRow['role_id'] === 1) {
            $activeAdminCount = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role_id = 1 AND is_active = 1')->fetchColumn();
            if ($activeAdminCount <= 1) {
                $error = __('user_err_last_active_admin');
            }
        }

        if ($error === '' && $id === (int) $_SESSION['user_id']) {
            $error = __('user_err_self_deactivate');
        }

        if ($error === '') {
            $actorId = (int) $_SESSION['user_id'];
            try {
                $pdo->beginTransaction();
                // must_change_password is deliberately untouched here -
                // reactivating an account must never silently change
                // any other field, only the one this action owns.
                $stmt = $pdo->prepare('UPDATE users SET is_active = ?, updated_by = ? WHERE id = ?');
                $stmt->execute([$newActive, $actorId, $id]);

                $afterStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
                $afterStmt->execute([$id]);
                $after = userAuditSnapshot($afterStmt->fetch());
                logAudit($pdo, $actorId, 'update', 'user', $id, userAuditSnapshot($beforeRow), $after);
                $pdo->commit();
                header('Location: ' . BASE_URL . '/user/index.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Toggle user active failed: ' . $e->getMessage());
                $error = __('common_err_transaction_failed');
            }
        }
    }
}

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$roleLabels = ['Admin' => __('role_admin'), 'User' => __('role_user'), 'Viewer' => __('role_viewer')];
$roleBadgeClass = ['Admin' => 'badge-accent', 'User' => 'badge-normal', 'Viewer' => 'badge-muted'];

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT users.*, roles.name AS role_name
        FROM users JOIN roles ON roles.id = users.role_id
        WHERE users.name LIKE ? OR users.email LIKE ?
        ORDER BY users.id';
$stmt = $pdo->prepare($sql);
$stmt->execute(["%$search%", "%$search%"]);
$users = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="bracket-label mb-2"><?= __('nav_administration') ?></div>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0"><?= __('nav_users') ?></h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
    <i class="bi bi-plus-lg"></i> <?= __('user_add_button') ?>
  </button>
</div>

<?php if ($error): ?><script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($error) ?>, 'error'));</script><?php endif; ?>

<?php if ($newUserCredentials): ?>
  <div class="alert alert-success">
    <div class="fw-semibold mb-2"><?= __('user_created_banner_title') ?></div>
    <div class="mono" style="font-size:.85rem;">
      <?= __('common_name') ?>:&nbsp;<?= htmlspecialchars($newUserCredentials['name']) ?><br>
      <?= __('common_email') ?>:&nbsp;<?= htmlspecialchars($newUserCredentials['email']) ?><br>
      <?= __('user_temp_password_display') ?>&nbsp;<strong><?= htmlspecialchars($newUserCredentials['password']) ?></strong>
    </div>
  </div>
<?php endif; ?>

<?php if ($passwordResetCredentials): ?>
  <div class="alert alert-success">
    <div class="fw-semibold mb-2"><?= __('user_password_reset_banner_title') ?></div>
    <div class="mono" style="font-size:.85rem;">
      <?= __('common_name') ?>:&nbsp;<?= htmlspecialchars($passwordResetCredentials['name']) ?><br>
      <?= __('common_email') ?>:&nbsp;<?= htmlspecialchars($passwordResetCredentials['email']) ?><br>
      <?= __('user_temp_password_display') ?>&nbsp;<strong><?= htmlspecialchars($passwordResetCredentials['password']) ?></strong>
    </div>
  </div>
<?php endif; ?>

<form class="mb-3" method="get">
  <input type="text" name="q" class="form-control" style="max-width:300px"
         placeholder="<?= __('user_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card">
  <table class="table mb-0 align-middle table-cards-mobile">
    <thead class="table-light">
      <tr><th>#</th><th><?= __('common_name') ?></th><th><?= __('common_email') ?></th><th><?= __('user_col_role') ?></th><th><?= __('common_status') ?></th><th style="width:260px;"><?= __('user_col_change_role') ?></th><th style="width:170px;"><?= __('common_actions') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="7" class="text-center text-secondary py-4"><i class="bi bi-inbox fs-3 d-block mb-2"></i><?= __('user_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($users as $i => $u): ?>
      <tr>
        <td class="row-number"><?= $i + 1 ?></td>
        <td class="row-title"><?= htmlspecialchars($u['name']) ?></td>
        <td data-label="<?= htmlspecialchars(__('common_email')) ?>"><?= htmlspecialchars($u['email']) ?></td>
        <td data-label="<?= htmlspecialchars(__('user_col_role')) ?>"><span class="badge-stock <?= $roleBadgeClass[$u['role_name']] ?? 'badge-muted' ?>"><?= htmlspecialchars($roleLabels[$u['role_name']] ?? $u['role_name']) ?></span></td>
        <td data-label="<?= htmlspecialchars(__('common_status')) ?>"><span class="badge-stock <?= $u['is_active'] ? 'badge-normal' : 'badge-low' ?>"><?= $u['is_active'] ? __('user_status_active') : __('user_status_inactive') ?></span></td>
        <td data-label="<?= htmlspecialchars(__('user_col_change_role')) ?>">
          <?php if ($u['id'] === (int) $_SESSION['user_id']): ?>
            <span class="text-secondary small"><?= __('user_this_is_you') ?></span>
          <?php else: ?>
            <form method="post" class="d-flex gap-2 role-change-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_role">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <select name="role_id" class="form-select form-select-sm">
                <?php foreach ($roles as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $r['id']==$u['role_id']?'selected':'' ?>><?= htmlspecialchars($roleLabels[$r['name']] ?? $r['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary"><?= __('common_save') ?></button>
            </form>
          <?php endif; ?>
        </td>
        <td class="row-actions">
          <?php if ($u['id'] !== (int) $_SESSION['user_id']): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resetPwModal<?= $u['id'] ?>">
              <i class="bi bi-key"></i> <?= __('user_reset_password_button') ?>
            </button>
            <form method="post" class="d-inline toggle-active-form" data-confirm="<?= htmlspecialchars($u['is_active'] ? __('user_confirm_deactivate') : __('user_confirm_reactivate')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <input type="hidden" name="new_state" value="<?= $u['is_active'] ? 0 : 1 ?>">
              <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                <i class="bi <?= $u['is_active'] ? 'bi-slash-circle' : 'bi-check-circle' ?>"></i>
                <?= $u['is_active'] ? __('user_deactivate_button') : __('user_reactivate_button') ?>
              </button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Reset password modals (placed outside the table to keep the Bootstrap backdrop working correctly) -->
<?php foreach ($users as $i => $u): ?>
<?php if ($u['id'] !== (int) $_SESSION['user_id']): ?>
<div class="modal fade" id="resetPwModal<?= $u['id'] ?>" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('user_reset_password_modal_title') ?> — <?= htmlspecialchars($u['name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= __('user_temp_password_label') ?></label>
            <input type="text" name="new_password" class="form-control" required minlength="6">
            <div style="font-size:.72rem; color:var(--muted); margin-top:6px;"><?= __('user_temp_password_hint') ?></div>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="must_change_password" id="resetMustChange<?= $u['id'] ?>" checked>
            <label class="form-check-label" for="resetMustChange<?= $u['id'] ?>"><?= __('user_force_reset_label') ?></label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('user_reset_password_submit') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<!-- Create user modal -->
<div class="modal fade" id="createUserModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_user">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('user_modal_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= __('common_close') ?>"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= __('common_name') ?></label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('common_email') ?></label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('user_temp_password_label') ?></label>
            <input type="text" name="password" class="form-control" required minlength="6">
            <div style="font-size:.72rem; color:var(--muted); margin-top:6px;"><?= __('user_temp_password_hint') ?></div>
          </div>
          <div class="mb-3">
            <label class="form-label"><?= __('user_role_label') ?></label>
            <select name="role_id" class="form-select">
              <?php foreach ($roles as $r): ?>
              <option value="<?= $r['id'] ?>" <?= $r['name']==='User' ? 'selected' : '' ?>><?= htmlspecialchars($roleLabels[$r['name']] ?? $r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-check">
            <input type="checkbox" class="form-check-input" name="must_change_password" id="mustChangePassword" checked>
            <label class="form-check-label" for="mustChangePassword"><?= __('user_force_reset_label') ?></label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('common_cancel') ?></button>
          <button class="btn btn-primary"><?= __('user_create_button') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($error): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  new bootstrap.Modal(document.getElementById('createUserModal')).show();
});
</script>
<?php endif; ?>

<script>
// Same localized confirm() as the delete actions elsewhere in the app,
// but wired via addEventListener + explicit stopPropagation() (like the
// POS/Debt Payment safeguards in this same batch) rather than a static
// onsubmit="return confirm(...)" attribute - the latter's "return false"
// only calls preventDefault(), it does not stop the event from still
// reaching footer.php's global submit handler (a document-level bubble
// listener that disables the submit button and shows a spinner), which
// left the Save button stuck disabled after Cancel with no request
// actually in flight.
document.querySelectorAll('form.role-change-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm(<?= json_encode(__('user_confirm_role_change')) ?>)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
});
document.querySelectorAll('form.toggle-active-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm(form.dataset.confirm)) {
      e.preventDefault();
      e.stopPropagation();
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
