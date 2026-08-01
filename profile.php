<?php
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/db.php';
$activePage = 'profile';
$infoMsg = ''; $infoErr = '';
$pwMsg = ''; $pwErr = '';

$stmt = $pdo->prepare('SELECT users.*, roles.name AS role_name FROM users JOIN roles ON roles.id = users.role_id WHERE users.id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// ---------- Update name / email / avatar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);

    if ($name === '' || $email === '') {
        $infoErr = 'Name and email are required.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $infoErr = 'This email is already used by another account.';
        } else {
            $avatarPath = $user['avatar'];

            // handle optional photo upload
            if (!empty($_FILES['avatar']['name']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $mime = mime_content_type($_FILES['avatar']['tmp_name']);

                if (!isset($allowed[$mime])) {
                    $infoErr = 'Please upload a JPG, PNG, or WEBP image.';
                } elseif ($_FILES['avatar']['size'] > 2 * 1024 * 1024) {
                    $infoErr = 'Image must be under 2MB.';
                } else {
                    $ext = $allowed[$mime];
                    $filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
                    $destDir = __DIR__ . '/uploads/avatars/';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

                    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destDir . $filename)) {
                        // remove the old photo file, if any
                        if ($avatarPath && file_exists(__DIR__ . '/' . $avatarPath)) {
                            @unlink(__DIR__ . '/' . $avatarPath);
                        }
                        $avatarPath = 'uploads/avatars/' . $filename;
                    } else {
                        $infoErr = 'Photo upload failed — please try again.';
                    }
                }
            }

            if ($infoErr === '') {
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, avatar = ? WHERE id = ?');
                $stmt->execute([$name, $email, $avatarPath, $user['id']]);
                $_SESSION['user_name'] = $name;
                $user['name'] = $name;
                $user['email'] = $email;
                $user['avatar'] = $avatarPath;
                $infoMsg = 'Profile updated.';
            }
        }
    }
}

// ---------- Remove avatar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_avatar'])) {
    if ($user['avatar'] && file_exists(__DIR__ . '/' . $user['avatar'])) {
        @unlink(__DIR__ . '/' . $user['avatar']);
    }
    $stmt = $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?');
    $stmt->execute([$user['id']]);
    $user['avatar'] = null;
    $infoMsg = 'Photo removed.';
}

// ---------- Update password ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current = $_POST['current_password'];
    $new     = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];

    if (!password_verify($current, $user['password'])) {
        $pwErr = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $pwErr = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $pwErr = 'New password and confirmation do not match.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['must_change_password'] = false;
        $pwMsg = 'Password changed.';
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="bracket-label mb-2">ACCOUNT</div>
<h4 class="mb-4">Profile</h4>

<?php if (!empty($_SESSION['must_change_password'])): ?>
  <div class="alert alert-warning">Your account was just created for you — please set your own password below before continuing.</div>
<?php endif; ?>

<?php if ($infoMsg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($infoMsg) ?></div><?php endif; ?>
<?php if ($infoErr): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($infoErr) ?></div><?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-4">
  <form method="post" enctype="multipart/form-data" id="avatarForm">
    <input type="hidden" name="update_info" value="1">
    <input type="hidden" name="name" value="<?= htmlspecialchars($user['name']) ?>">
    <input type="hidden" name="email" value="<?= htmlspecialchars($user['email']) ?>">
    <label class="profile-avatar" title="Change photo">
      <?php if (!empty($user['avatar'])): ?>
        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($user['avatar']) ?>">
      <?php else: ?>
        <span class="mono">
          <?php
            $parts = preg_split('/\s+/', trim($user['name']));
            echo htmlspecialchars(strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')));
          ?>
        </span>
      <?php endif; ?>
      <span class="profile-avatar-overlay"><i class="bi bi-camera"></i></span>
      <input type="file" name="avatar" accept="image/png, image/jpeg, image/webp" style="display:none;" onchange="document.getElementById('avatarForm').submit()">
    </label>
  </form>

  <div>
    <h4 class="mb-1"><?= htmlspecialchars($user['name']) ?></h4>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="slug-pill"><?= htmlspecialchars($user['role_name']) ?></span>
      <span style="color:var(--muted); font-size:.85rem;"><?= htmlspecialchars($user['email']) ?></span>
    </div>
    <div style="font-size:.72rem; color:var(--muted); margin-top:6px;">
      Member since <?= date('M Y', strtotime($user['created_at'])) ?>
      <?php if (!empty($user['avatar'])): ?>
        &nbsp;·&nbsp;
        <form method="post" class="d-inline">
          <input type="hidden" name="remove_avatar" value="1">
          <button type="submit" class="btn btn-link btn-sm p-0" style="color:var(--danger); font-size:.72rem; vertical-align:baseline;">Remove photo</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card p-4 h-100">
      <h6 class="mb-3">Profile information</h6>
      <form method="post">
        <input type="hidden" name="update_info" value="1">
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
        <button class="btn btn-primary">Save</button>
      </form>
      <div style="font-size:.72rem; color:var(--muted); margin-top:10px;">Photo: JPG, PNG or WEBP — under 2MB</div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card p-4 h-100">
      <h6 class="mb-3">Change password</h6>
      <?php if ($pwMsg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($pwMsg) ?></div><?php endif; ?>
      <?php if ($pwErr): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($pwErr) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="update_password" value="1">
        <div class="mb-3">
          <label class="form-label">Current password</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">New password</label>
          <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm new password</label>
          <input type="password" name="confirm_password" class="form-control" required minlength="6">
        </div>
        <button class="btn btn-primary">Update password</button>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
