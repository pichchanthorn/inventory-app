<?php
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';

if (!isAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$activePage = 'user';
$error = '';

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
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role_id, must_change_password) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, $hashed, $roleId, $mustChange]);

            // Stash the plaintext temp password as a one-time flash message so the
            // Admin can copy it — never stored anywhere after this request.
            $_SESSION['new_user_credentials'] = ['name' => $name, 'email' => $email, 'password' => $password];
            header('Location: ' . BASE_URL . '/user/index.php');
            exit;
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
        $stmt = $pdo->prepare('UPDATE users SET role_id = ? WHERE id = ?');
        $stmt->execute([$roleId, $id]);
        header('Location: ' . BASE_URL . '/user/index.php');
        exit;
    }
}

$roles = $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll();
$roleLabels = ['Admin' => __('role_admin'), 'User' => __('role_user'), 'Viewer' => __('role_viewer')];

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

<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

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

<form class="mb-3" method="get">
  <input type="text" name="q" class="form-control" style="max-width:300px"
         placeholder="<?= __('user_search_placeholder') ?>" value="<?= htmlspecialchars($search) ?>">
</form>

<div class="card">
  <table class="table mb-0 align-middle">
    <thead class="table-light">
      <tr><th>#</th><th><?= __('common_name') ?></th><th><?= __('common_email') ?></th><th><?= __('user_col_role') ?></th><th style="width:260px;"><?= __('user_col_change_role') ?></th></tr>
    </thead>
    <tbody>
      <?php if (!$users): ?>
        <tr><td colspan="5" class="text-center text-secondary py-4"><?= __('user_empty') ?></td></tr>
      <?php endif; ?>
      <?php foreach ($users as $i => $u): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($u['name']) ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><span class="slug-pill"><?= htmlspecialchars($roleLabels[$u['role_name']] ?? $u['role_name']) ?></span></td>
        <td>
          <?php if ($u['id'] === (int) $_SESSION['user_id']): ?>
            <span class="text-secondary small"><?= __('user_this_is_you') ?></span>
          <?php else: ?>
            <form method="post" class="d-flex gap-2">
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
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Create user modal -->
<div class="modal fade" id="createUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="create_user">
        <div class="modal-header">
          <h5 class="modal-title"><?= __('user_modal_title') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
