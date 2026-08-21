<?php
// Consider restricting or removing public self-registration once Admin-created
// accounts are in use, so only approved staff can access the system.
//
// Self-registered accounts default to Viewer (role_id 3), not User —
// anonymous signups get read-only access until an Admin explicitly
// upgrades them via the Users page. Previously this defaulted to User
// (role_id 2), which granted write access (create/edit products,
// suppliers, stock transactions) to anyone who found the registration
// page. Viewer is a safer default, but it's still open self-registration;
// an invite/approval-gated flow would be a stronger fix if that matters
// for this deployment.
require_once __DIR__ . '/../config/base_url.php';
require_once __DIR__ . '/../includes/lang.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/db.php';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name  = trim($_POST['name']);
    $email = trim($_POST['email']);
    $pass  = $_POST['password'];

    if ($name === '' || $email === '' || $pass === '') {
        $error = __('register_err_fill_fields');
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = __('common_err_email_taken');
        } else {
            $hashed = password_hash($pass, PASSWORD_DEFAULT);
            // role_id 3 = Viewer (read-only) — see note at top of file.
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password, role_id) VALUES (?, ?, ?, 3)');
            $stmt->execute([$name, $email, $hashed]);
            header('Location: ' . BASE_URL . '/auth/login.php?registered=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($_SESSION['lang']) ?>">
<head>
<script>
  if (localStorage.getItem('theme') === 'light') {
    document.documentElement.classList.add('theme-light-pending');
  }
</script>
<meta charset="UTF-8">
<title>Register — Inventory</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body lang="<?= $_SESSION['lang'] ?>">
<script>
  if (document.documentElement.classList.contains('theme-light-pending')) {
    document.body.classList.add('theme-light');
  }
</script>
<a href="?lang=<?= $_SESSION['lang'] === 'km' ? 'en' : 'km' ?>" class="theme-toggle-btn text-decoration-none" style="position:absolute; top:20px; right:20px; width:auto; margin-bottom:0;">
  <?= $_SESSION['lang'] === 'km' ? 'EN' : 'ខ្មែរ' ?>
</a>
<div class="auth-wrap">
  <div class="auth-left">
    <div>
      <div class="bracket-label mb-2"><?= __('auth_tagline') ?></div>
      <span class="barcode"><i style="width:2px;height:60%"></i><i style="height:100%"></i><i style="width:2px;height:40%"></i><i style="height:80%"></i><i style="width:4px;height:55%"></i><i style="height:100%"></i><i style="width:2px;height:70%"></i></span>
    </div>
    <div>
      <h1><?= __('auth_hero_title') ?></h1>
      <p class="mt-3"><?= __('auth_hero_subtitle') ?></p>
    </div>
    <div class="mono" style="color:#5C6584; font-size:.78rem;">127.0.0.1:9000</div>
  </div>

  <div class="auth-right">
    <div class="auth-form">
      <h4 class="mb-4"><?= __('register_title') ?></h4>
      <div class="alert alert-info py-2"><?= __('register_viewer_notice') ?></div>
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label"><?= __('register_name') ?></label>
          <input type="text" name="name" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= __('register_email') ?></label>
          <input type="email" name="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label"><?= __('register_password') ?></label>
          <input type="password" name="password" id="registerPassword" class="form-control" required minlength="6" oninput="updatePasswordStrength(this.value)" autocomplete="new-password">
          <div class="password-strength-track mt-2"><div class="password-strength-fill" id="passwordStrengthFill"></div></div>
          <span class="password-strength-label" id="passwordStrengthLabel"></span>
        </div>
        <button class="btn btn-primary w-100"><?= __('register_button') ?></button>
        <p class="text-center mt-3 mb-0"><?= __('register_have_account') ?> <a href="<?= BASE_URL ?>/auth/login.php"><?= __('register_login_link') ?></a></p>
      </form>
    </div>
  </div>
</div>
<script>
// Purely a client-side UX hint - the server still enforces the 6-character
// minimum (and only that) independently in auth/register.php; this never
// blocks or relaxes submission, it just gives feedback as the user types.
const T_STRENGTH_WEAK   = <?= json_encode(__('register_strength_weak')) ?>;
const T_STRENGTH_FAIR   = <?= json_encode(__('register_strength_fair')) ?>;
const T_STRENGTH_GOOD   = <?= json_encode(__('register_strength_good')) ?>;
const T_STRENGTH_STRONG = <?= json_encode(__('register_strength_strong')) ?>;

function updatePasswordStrength(pw) {
  const fill = document.getElementById('passwordStrengthFill');
  const label = document.getElementById('passwordStrengthLabel');
  const levels = [
    { pct: 20,  cls: 'strength-weak',   text: T_STRENGTH_WEAK },
    { pct: 45,  cls: 'strength-weak',   text: T_STRENGTH_WEAK },
    { pct: 65,  cls: 'strength-fair',   text: T_STRENGTH_FAIR },
    { pct: 85,  cls: 'strength-good',   text: T_STRENGTH_GOOD },
    { pct: 100, cls: 'strength-strong', text: T_STRENGTH_STRONG },
  ];
  fill.className = 'password-strength-fill';
  label.className = 'password-strength-label';
  if (!pw) { fill.style.width = '0%'; label.textContent = ''; return; }

  let score = 0;
  if (pw.length >= 6) score++;
  if (pw.length >= 10) score++;
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
  if (/\d/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const level = levels[Math.min(score, levels.length - 1)];
  fill.style.width = level.pct + '%';
  fill.classList.add(level.cls);
  label.classList.add(level.cls);
  label.textContent = level.text;
}
</script>
</body>
</html>
